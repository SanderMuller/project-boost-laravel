<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;
use SanderMuller\ProjectBoostLaravel\Discovery\VersionResolver;
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

/**
 * Sync laravel/boost-bundled skills through boost-core's SyncEngine.
 *
 * Pipeline:
 *  1. `LaravelBoostAssetReader` + `LaravelBoostGuidelineReader` walk
 *     `vendor/laravel/boost/.ai/`, attach sidecar tags, render `.blade.php`
 *     via this package's `BladeRenderer` INSIDE the readers (so frontmatter
 *     + body are already plain markdown by the time they leave discovery),
 *     and return `Skill[]` / `Guideline[]` stamped with `sourceVendor=laravel/boost`.
 *  2. Dedupe versioned variants (e.g. `pest/3` and `pest/4` both ship a
 *     `pest-testing` skill) — pick the lex-last sourcePath. TODO: Roster-aware.
 *  3. Hand the resulting collections to
 *     `SyncEngine::sync(injectedVendorSkills, injectedVendorGuidelines)`
 *     under the vendor name `laravel/boost`. `extraSkillRenderers` is NOT
 *     passed — Blade is already rendered in step 1, and registering a
 *     duplicate `.blade.php` renderer would collide with a host project
 *     that registered its own via `boost.php`'s `withSkillRenderers()`.
 */
final class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'project-boost:sync
        {--dry-run : Preview the full SyncEngine pipeline (laravel/boost + host + scanned vendors + remote skills) in check mode.}
        {--show-untagged : Also print the laravel/boost injection-set discovery tables (skills + guidelines, all rows including untagged).}';

    /** @var string */
    protected $description = 'Sync laravel/boost-bundled skills through boost-core (with Blade rendering + sidecar tags + project withTags filter).';

    public function handle(): int
    {
        $blade = new BladeRenderer();
        $manifestPath = dirname(__DIR__, 2) . '/resources/boost/laravel-boost-tags.yaml';
        $manifest = LaravelBoostTagManifest::fromFile($manifestPath);

        $skillReader = new LaravelBoostAssetReader(
            laravelBoostAiRoot: base_path('vendor/laravel/boost/.ai'),
            tagManifest: $manifest,
            bladeRenderer: $blade,
        );
        $guidelineReader = new LaravelBoostGuidelineReader(
            laravelBoostAiRoot: base_path('vendor/laravel/boost/.ai'),
            tagManifest: $manifest,
            bladeRenderer: $blade,
        );

        $allSkills = $skillReader->readSkills();
        $allGuidelines = $guidelineReader->readGuidelines();

        // Empty laravel/boost discovery is not fatal — boost-core still has
        // host `.ai/skills/`, scanned vendors, and remote skills to process.
        // Just warn and fall through with empty injection arrays.
        if ($allSkills === [] && $allGuidelines === []) {
            $this->warn('No laravel/boost skills or guidelines found at vendor/laravel/boost/.ai. Is laravel/boost installed? Continuing with host + scanned-vendor + remote-skill discovery only.');
        }

        // Dedupe versioned variants within the same skill name. laravel/boost
        // ships e.g. `.ai/pest/3/skill/pest-testing/SKILL.blade.php` AND
        // `.ai/pest/4/skill/pest-testing/SKILL.blade.php` — same name,
        // different paths. SkillResolver in boost-core flags two same-name
        // entries from the same vendor as a collision, so we must pick one
        // before injection. VersionResolver uses laravel/roster to match
        // the host's installed major when possible; falls back to lex-last
        // sourcePath when Roster can't resolve.
        $skills = VersionResolver::withHostRoster(base_path())->resolve($allSkills);

        // Guideline dedupe — same shape as skills. core.blade.php for a
        // package is one name; per-major guideline files include the major
        // in the name so naturally distinct (no dedupe needed beyond what
        // the reader already emits per file).
        $guidelinesByName = [];
        foreach ($allGuidelines as $guideline) {
            $guidelinesByName[$guideline->name] = $guideline;
        }

        $guidelines = array_values($guidelinesByName);

        if ($this->option('dry-run')) {
            return $this->reportDryRun($skills, $guidelines);
        }

        return $this->runSync($skills, $guidelines);
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function runSync(array $skills, array $guidelines): int
    {
        $projectRoot = base_path();
        if (! is_file($projectRoot . '/boost.php')) {
            $this->error("No boost.php found at {$projectRoot}/boost.php.");
            $this->line('Create one with at least:');
            $this->line('  return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Injecting %d laravel/boost skills + %d guidelines into SyncEngine (Blade-rendered).',
            count($skills),
            count($guidelines),
        ));

        $result = $this->invokeSyncEngine($projectRoot, $skills, $guidelines, checkOnly: false);

        return $this->renderResult($result, checkOnly: false);
    }

    /**
     * Dry-run runs the full SyncEngine pipeline in check mode so the planned
     * write set is a faithful preview of live sync — including writes from
     * boost-core's host/vendor/remote discovery, not just the laravel/boost
     * injection set this command contributes. `--show-untagged` additionally
     * surfaces the injection-side discovery tables for debugging.
     *
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function reportDryRun(array $skills, array $guidelines): int
    {
        $projectRoot = base_path();
        if (! is_file($projectRoot . '/boost.php')) {
            $this->error("No boost.php found at {$projectRoot}/boost.php.");
            $this->line('Create one with at least:');
            $this->line('  return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');

            return self::FAILURE;
        }

        if ($this->option('show-untagged')) {
            $this->renderInjectionTables($skills, $guidelines);
        }

        $this->newLine();
        $this->info(sprintf(
            'Planned writes (dry-run · %d laravel/boost skills + %d guidelines injected; full pipeline preview).',
            count($skills),
            count($guidelines),
        ));

        $result = $this->invokeSyncEngine($projectRoot, $skills, $guidelines, checkOnly: true);

        return $this->renderResult($result, checkOnly: true);
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function invokeSyncEngine(string $projectRoot, array $skills, array $guidelines, bool $checkOnly): SyncResult
    {
        return SyncEngine::default()->sync(
            projectRoot: $projectRoot,
            checkOnly: $checkOnly,
            injectedVendorSkills: ['laravel/boost' => $skills],
            injectedVendorGuidelines: ['laravel/boost' => $guidelines],
        );
    }

    private function renderResult(SyncResult $result, bool $checkOnly): int
    {
        foreach ($result->writes as $written) {
            $this->line("  <fg=green>{$written->action->value}</> {$written->relativePath}");
        }

        foreach ($result->emitters as $emitter) {
            $path = $emitter->relativePath ?? $emitter->fqcn;
            $this->line("  <fg=cyan>emitter:{$emitter->action->value}</> {$path}");
        }

        // Surface boost-core's canonical delete-attribution warning. Helper
        // returns null when nothing was deleted or in check-mode (which lists
        // would-delete inline already), so the call is unconditional.
        $attribution = $result->renderDeleteAttribution();
        if ($attribution !== null) {
            $this->newLine();
            $this->warn($attribution);
        }

        if ($result->hasErrors()) {
            $this->newLine();
            $this->error($checkOnly ? 'Errors during dry-run:' : 'Errors during sync:');
            foreach ($result->errors as $err) {
                $this->line("  - {$err}");
            }

            foreach ($result->emitters as $emitter) {
                if ($emitter->action !== EmitterAction::ERRORED) {
                    continue;
                }

                $this->line(sprintf('  - emitter %s (%s): %s', $emitter->fqcn, $emitter->vendor, $emitter->reason ?? 'no reason given'));
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($this->renderSummary($result, $checkOnly));

        return self::SUCCESS;
    }

    /**
     * rsync-style breakdown — "N writes" by itself counted every event
     * (unchanged, skipped-symlink, would-*) which mislabelled idempotent
     * runs as having written N files. Split per action so the headline
     * count matches what actually changed on disk. Emitter counts are
     * surfaced separately because `SyncResult::writes` excludes them.
     */
    private function renderSummary(SyncResult $result, bool $checkOnly): string
    {
        $emitterWrote = $result->countEmittersByAction($checkOnly ? EmitterAction::WOULD_WRITE : EmitterAction::WROTE);
        $emitterSuffix = $result->emitters === []
            ? ''
            : sprintf(' · emitters(%s=%d, unchanged=%d, skipped=%d)',
                $checkOnly ? 'would-write' : 'wrote',
                $emitterWrote,
                $result->countEmittersByAction(EmitterAction::UNCHANGED),
                $result->countEmittersByAction(EmitterAction::SKIPPED) + $result->countEmittersByAction(EmitterAction::DISABLED),
            );

        if ($checkOnly) {
            return sprintf(
                '<fg=gray>Plan · would-write=%d · would-delete=%d · unchanged=%d · skipped-symlink=%d (%d skill/guideline events)%s</>',
                $result->countByAction(WriteAction::WOULD_WRITE),
                $result->countByAction(WriteAction::WOULD_DELETE),
                $result->countByAction(WriteAction::UNCHANGED),
                $result->countByAction(WriteAction::SKIPPED_SYMLINK),
                count($result->writes),
                $emitterSuffix,
            );
        }

        return sprintf(
            '<fg=gray>Sync complete · wrote=%d · deleted=%d · unchanged=%d · skipped-symlink=%d (%d skill/guideline events)%s</>',
            $result->countByAction(WriteAction::WROTE),
            $result->countByAction(WriteAction::DELETED),
            $result->countByAction(WriteAction::UNCHANGED),
            $result->countByAction(WriteAction::SKIPPED_SYMLINK),
            count($result->writes),
            $emitterSuffix,
        );
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function renderInjectionTables(array $skills, array $guidelines): void
    {
        $this->newLine();
        $this->line('<fg=cyan>Discovered laravel/boost skills (injection set)</>');

        $rows = [];
        foreach ($skills as $skill) {
            $tags = $skill->tags === [] ? '<untagged>' : implode(' ', $skill->tags);
            $type = str_ends_with($skill->sourcePath, '.blade.php') ? 'blade' : 'md';
            $rows[] = [$skill->name, $type, $tags];
        }

        if ($rows !== []) {
            $this->table(['Skill', 'Type', 'Tags'], $rows);
        }

        $this->newLine();
        $this->line('<fg=cyan>Discovered laravel/boost guidelines (injection set)</>');

        $gRows = [];
        foreach ($guidelines as $g) {
            $tags = $g->tags === [] ? '<untagged>' : implode(' ', $g->tags);
            $gRows[] = [$g->name, $tags];
        }

        if ($gRows !== []) {
            $this->table(['Guideline', 'Tags'], $gRows);
        }

        $this->line(sprintf('<fg=gray>%d skills · %d guidelines (after dedupe)</>', count($skills), count($guidelines)));
    }
}
