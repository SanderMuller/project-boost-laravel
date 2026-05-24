<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

/**
 * Sync laravel/boost-bundled skills through boost-core's SyncEngine.
 *
 * Pipeline:
 *  1. `LaravelBoostAssetReader` walks `vendor/laravel/boost/.ai/<pkg>/skill/<name>/`,
 *     attaches sidecar tags, returns `Skill[]` stamped with `sourceVendor=laravel/boost`.
 *     Both `.md` and `.blade.php` skills load — the Blade frontmatter parses
 *     fine; rendering happens later via BladeRenderer during the SyncEngine call.
 *  2. Dedupe versioned variants (e.g. `pest/3` and `pest/4` both ship a
 *     `pest-testing` skill) — pick the lex-last sourcePath. TODO: Roster-aware.
 *  3. Hand the resulting `Skill[]` to `SyncEngine::sync(injectedVendorSkills: ...)`
 *     under the vendor name `laravel/boost`, and append the package's
 *     `BladeRenderer` to the renderer registry via `extraSkillRenderers`.
 *     boost-core's per-sync dispatcher then renders the `.blade.php` skills
 *     through laravel/boost's RendersBladeGuidelines trait at agent fan-out.
 */
final class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'project-boost:sync
        {--dry-run : Discover + tag-filter only; do not invoke SyncEngine.}
        {--show-untagged : Include untagged skills in the dry-run table.}';

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

        if ($allSkills === [] && $allGuidelines === []) {
            $this->warn('No laravel/boost skills or guidelines found at vendor/laravel/boost/.ai. Is laravel/boost installed?');

            return self::SUCCESS;
        }

        // Dedupe versioned variants within the same skill name.
        // laravel/boost ships `.ai/pest/3/skill/pest-testing/SKILL.blade.php`
        // AND `.ai/pest/4/skill/pest-testing/SKILL.blade.php` — same name,
        // different paths. SkillResolver flags two same-name entries from
        // the same vendor as a collision. Pick the lex-last sourcePath
        // (rough proxy for "highest major"). TODO: Roster-aware selection.
        usort($allSkills, static fn (Skill $a, Skill $b): int => strcmp($a->sourcePath, $b->sourcePath));
        $byName = [];
        foreach ($allSkills as $skill) {
            $byName[$skill->name] = $skill;
        }

        $skills = array_values($byName);

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

        // BladeRenderer is used INSIDE the asset readers (above) since
        // injected skills/guidelines bypass the file walker / dispatcher.
        // We don't need extraSkillRenderers here unless the host also
        // publishes raw .blade.php files under .ai/skills/ — rare; revisit
        // if a user reports that case.
        $result = SyncEngine::default()->sync(
            projectRoot: $projectRoot,
            injectedVendorSkills: ['laravel/boost' => $skills],
            injectedVendorGuidelines: ['laravel/boost' => $guidelines],
        );

        foreach ($result->writes as $written) {
            $this->line("  <fg=green>{$written->action->value}</> {$written->relativePath}");
        }

        if ($result->hasErrors()) {
            $this->newLine();
            $this->error('Errors during sync:');
            foreach ($result->errors as $err) {
                $this->line("  - {$err}");
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>Sync complete · %d writes</>',
            count($result->writes),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function reportDryRun(array $skills, array $guidelines): int
    {
        $this->newLine();
        $this->line('<fg=cyan>Discovered laravel/boost skills (dry-run)</>');

        $rows = [];
        foreach ($skills as $skill) {
            $tags = $skill->tags === [] ? '<untagged>' : implode(' ', $skill->tags);
            if (! $this->option('show-untagged') && $skill->tags === []) {
                continue;
            }

            $type = str_ends_with($skill->sourcePath, '.blade.php') ? 'blade' : 'md';
            $rows[] = [$skill->name, $type, $tags];
        }

        if ($rows !== []) {
            $this->table(['Skill', 'Type', 'Tags'], $rows);
        }

        $this->newLine();
        $this->line('<fg=cyan>Discovered laravel/boost guidelines (dry-run)</>');

        $gRows = [];
        foreach ($guidelines as $g) {
            $tags = $g->tags === [] ? '<untagged>' : implode(' ', $g->tags);
            if (! $this->option('show-untagged') && $g->tags === []) {
                continue;
            }

            $gRows[] = [$g->name, $tags];
        }

        if ($gRows !== []) {
            $this->table(['Guideline', 'Tags'], $gRows);
        }

        $this->line(sprintf('<fg=gray>%d skills · %d guidelines (after dedupe)</>', count($skills), count($guidelines)));

        return self::SUCCESS;
    }
}
