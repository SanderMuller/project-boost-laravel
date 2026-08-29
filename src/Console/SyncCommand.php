<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use Laravel\Roster\ProjectScan;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\BoostSync;
use SanderMuller\BoostCore\Sync\SyncReporter;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\ProjectBoostLaravel\Coexistence\BoostJsonOutcome;
use SanderMuller\ProjectBoostLaravel\Coexistence\BoostJsonRemoval;
use SanderMuller\ProjectBoostLaravel\Coexistence\BoostJsonRemover;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\GatesGuidelines;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\LoadsBoostConfig;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\ResolvesAiRoot;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;
use SanderMuller\ProjectBoostLaravel\Discovery\VersionResolver;
use SanderMuller\ProjectBoostLaravel\Reconcile\GuidanceReconciler;
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
 *
 * @internal The `project-boost:sync` CLI contract (name, options, exit codes)
 *           is the public promise — see PUBLIC_API.md. The class is not an
 *           extension point.
 */
final class SyncCommand extends Command
{
    use GatesGuidelines;
    use LoadsBoostConfig;
    use ResolvesAiRoot;

    /** @var string */
    protected $signature = 'project-boost:sync
        {--dry-run : Preview the full SyncEngine pipeline (laravel/boost + host + scanned vendors + remote skills) in check mode.}
        {--show-untagged : Also print the laravel/boost injection-set discovery tables (skills + guidelines, all rows including untagged).}
        {--keep-boost-json : Leave laravel/boost\'s boost.json in place. By default a successful sync removes it, which stops `boost:update` (and therefore `herd link`) from re-seeding behind this command.}';

    /** @var string */
    protected $description = 'Sync laravel/boost-bundled skills through boost-core (with Blade rendering + sidecar tags + project withTags filter).';

    public function handle(): int
    {
        $blade = new BladeRenderer();
        $manifestPath = dirname(__DIR__, 2) . '/resources/boost/laravel-boost-tags.yaml';
        $manifest = LaravelBoostTagManifest::fromFile($manifestPath);
        $aiRoot = $this->resolveLaravelBoostAiRoot();

        // Scan the host project once and share it with both the version
        // resolver (per-major skill dedupe) and the guideline install-gate
        // (suppresses guidelines for packages the host hasn't installed).
        $scan = class_exists(ProjectScan::class) ? ProjectScan::scan(base_path()) : null;

        $skillReader = new LaravelBoostAssetReader(
            laravelBoostAiRoot: $aiRoot,
            tagManifest: $manifest,
            bladeRenderer: $blade,
        );
        $guidelineReader = new LaravelBoostGuidelineReader(
            laravelBoostAiRoot: $aiRoot,
            tagManifest: $manifest,
            bladeRenderer: $blade,
            installGate: $this->guidelineGate($scan, $aiRoot, base_path()),
        );

        $allSkills = $skillReader->readSkills();
        $allGuidelines = $guidelineReader->readGuidelines();

        // A dropped skill, guideline, or asset still lets the sync report
        // success, so the readers' out-params have to be spoken aloud here —
        // an emitted SKILL.md whose `rules/*.md` links go nowhere otherwise
        // looks identical to a healthy one.
        foreach ([...$skillReader->renderErrors(), ...$guidelineReader->renderErrors()] as $renderError) {
            $this->warn($renderError);
        }

        // Blade skipped for want of a renderer is not an error — it is the
        // documented no-renderer path. Both commands wire a BladeRenderer, so
        // this fires only if that wiring is ever removed.
        $skippedAssets = $skillReader->skippedBladeAssets();
        if ($skippedAssets > 0) {
            $this->warn(sprintf(
                '%d laravel/boost skill asset(s) skipped: no Blade renderer is wired, so files the skills link were not rendered.',
                $skippedAssets,
            ));
        }

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
        $skills = (new VersionResolver($scan))->resolve($allSkills);

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
        $config = $this->loadBoostConfigOrHint($projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $this->warnIfForeignSeeded($config, $projectRoot);

        $this->info(sprintf(
            'Injecting %d laravel/boost skills + %d guidelines into SyncEngine (Blade-rendered).',
            count($skills),
            count($guidelines),
        ));

        $result = $this->invokeSyncEngine($projectRoot, $skills, $guidelines, checkOnly: false);

        $exit = $this->renderResult($result, checkOnly: false);

        // Only after a clean sync: the state file describes emission this command
        // has now taken over. On a failed sync laravel/boost's own path stays the
        // fallback, so its state must survive.
        if ($exit === self::SUCCESS) {
            $this->reportBoostJsonRemoval($projectRoot, $config, dryRun: false, notTakenOver: $this->takeoverGap($skills, $guidelines, $result));
        }

        return $exit;
    }

    /**
     * Retire laravel/boost's `boost.json` once this sync owns the guidance and
     * skills it describes — see {@see BoostJsonRemover} for why that is safe, why it
     * matters (it makes the automatic `herd link` → `boost:update` re-seed inert),
     * why the file is archived rather than deleted, and why it stays put until its
     * agent list has been adopted. `--keep-boost-json` opts out.
     *
     * `$notTakenOver` carries the reason this sync did NOT take over what the state
     * file describes ({@see takeoverGap()}), or null when it did.
     */
    private function reportBoostJsonRemoval(string $projectRoot, BoostConfig $config, bool $dryRun, ?string $notTakenOver): void
    {
        if ($this->option('keep-boost-json')) {
            return;
        }

        if ($notTakenOver !== null) {
            if (is_file($projectRoot . '/' . BoostJsonRemover::FILE)) {
                $this->warn(sprintf(
                    'boost.json kept: %s, so this sync has not taken over what that file describes. Retiring it here '
                    . 'would stop `boost:update` from re-seeding content nothing else emits.',
                    $notTakenOver,
                ));
            }

            return;
        }

        $outcome = (new BoostJsonRemover())->retire($projectRoot, $config, $dryRun);

        match ($outcome->status) {
            BoostJsonRemoval::ARCHIVED => $this->line(sprintf(
                '  <fg=green>archived</> boost.json → %s <fg=gray>(laravel/boost install state — this sync owns the guidance + skills it described. '
                . '`boost:update`, which `herd link` runs automatically, now refuses to run instead of re-seeding. Nothing else reads it: not the MCP server, not this command. '
                . 'Restore it from there, or run `php artisan boost:install` to regenerate; keep it in place next time with `--keep-boost-json`.)</>',
                $outcome->archivePath,
            )),
            BoostJsonRemoval::WOULD_ARCHIVE => $this->line(sprintf(
                '  <fg=green>would-archive</> boost.json → %s <fg=gray>(a real sync moves it there so `boost:update` / `herd link` stop re-seeding. Keep it with `--keep-boost-json`.)</>',
                $outcome->archivePath,
            )),
            BoostJsonRemoval::AGENTS_NOT_ADOPTED => $this->warnAgentsNotAdopted($outcome),
            BoostJsonRemoval::NO_ARCHIVE_LOCATION => $this->warn(
                'boost.json kept: there is no safe place to archive it. Either gitignore management is off (creating a '
                . 'state directory would leave an untracked one behind), a path in the way is a symlink, or the archive '
                . 'name is taken by different content. Move or delete the file yourself to stop `boost:update` — and the '
                . '`herd link` trigger — from re-seeding.',
            ),
            BoostJsonRemoval::SYMLINK => $this->warn(
                'boost.json is a symlink — left untouched. Remove it by hand if you want `boost:update` (and the `herd link` trigger) to stop re-seeding.',
            ),
            BoostJsonRemoval::FOREIGN => $this->line(
                '  <fg=gray>kept boost.json — it records no agent list, so it is not laravel/boost\'s live install state (another tool\'s file, or one `boost:update` already refuses to act on).</>',
            ),
            BoostJsonRemoval::FAILED => $this->warn(
                'Could not archive boost.json (permission or filesystem error). It is still in place, so `boost:update` will keep re-seeding.',
            ),
            BoostJsonRemoval::ABSENT => null,
        };

        if ($outcome->unsupportedAgents !== []) {
            $this->warn(sprintf(
                'boost.json also recorded agent(s) boost-core has no case for — %s. Nothing this package emits reaches '
                . "them, and retiring the file ends laravel/boost's updates for them too. Keep the file with "
                . '`--keep-boost-json` if those agents still matter to you.',
                implode(', ', $outcome->unsupportedAgents),
            ));
        }
    }

    /**
     * Why this sync did not take over laravel/boost's emission — or null when it did.
     *
     * Two gaps leave `boost:update` as the only path to that content, so retiring its
     * state file would disable the fallback with nothing in its place: an injection set
     * that is empty (laravel/boost export-ignores its `.ai` payload, so a prefer-dist
     * install has none), and a guidance file boost-core skipped because the path is a
     * live symlink — not an error, so the sync still exits 0, but that agent's file was
     * never written.
     *
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function takeoverGap(array $skills, array $guidelines, SyncResult $result): ?string
    {
        if ($skills === [] && $guidelines === []) {
            return 'this sync injected no laravel/boost skills or guidelines';
        }

        $skippedSymlinks = $result->countByAction(WriteAction::SKIPPED_SYMLINK);

        return $skippedSymlinks > 0
            ? sprintf('%d output path(s) were skipped as symlinks, so their guidance was never written', $skippedSymlinks)
            : null;
    }

    /**
     * The file still records agents this project's own config does not declare, and
     * nothing imports them automatically — retiring it would destroy the only record
     * of that choice. Say which agents, and name the command that adopts them
     * (boost-core's install picker pre-selects exactly this set).
     */
    private function warnAgentsNotAdopted(BoostJsonOutcome $outcome): void
    {
        $this->warn(sprintf(
            'boost.json kept: it lists agent(s) your boost config does not — %s. Run `vendor/bin/boost install` '
            . '(its picker pre-selects them) or add them to `withAgents([...])`, then sync again; the file is '
            . 'archived once nothing would be lost. `--keep-boost-json` silences this step entirely.',
            implode(', ', $outcome->unadoptedAgents),
        ));
    }

    /**
     * Warn (but don't block — matching boost-core's warn-and-overwrite default)
     * when an agent guidance file carries laravel/boost-seeded content this sync
     * will wholesale-overwrite, pointing the operator at `project-boost:reconcile`
     * to capture any hand-edits first.
     */
    private function warnIfForeignSeeded(BoostConfig $config, string $projectRoot): void
    {
        $atRisk = (new GuidanceReconciler())->analyze($config, $projectRoot)->atRiskFiles();
        if ($atRisk === []) {
            return;
        }

        $this->warn(sprintf(
            '%d agent guidance file(s) carry laravel/boost-seeded content this sync overwrites:',
            count($atRisk),
        ));
        foreach ($atRisk as $file) {
            $this->line('  • ' . $file->relativePath . ($file->hasResidual() ? ' <fg=yellow>(has hand-edits)</>' : ''));
        }

        $this->line('Run `php artisan project-boost:reconcile` first to preserve hand-edits. Continuing…');
        $this->newLine();
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
        $config = $this->loadBoostConfigOrHint($projectRoot);
        if (! $config instanceof BoostConfig) {
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

        $exit = $this->renderResult($result, checkOnly: true);

        if ($exit === self::SUCCESS) {
            $this->reportBoostJsonRemoval($projectRoot, $config, dryRun: true, notTakenOver: $this->takeoverGap($skills, $guidelines, $result));
        }

        return $exit;
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function invokeSyncEngine(string $projectRoot, array $skills, array $guidelines, bool $checkOnly): SyncResult
    {
        return BoostSync::make()->sync(
            projectRoot: $projectRoot,
            checkOnly: $checkOnly,
            injectedVendorSkills: ['laravel/boost' => $skills],
            // Register BladeRenderer for the engine's own loaders so a host's
            // `.ai/guidelines/*.blade.php` (and `.ai/skills/`) render instead of
            // being silently skipped — boost-core ships only the `.md`
            // PassthroughRenderer, so without this an operator's host Blade
            // guideline vanishes from the output with no warning. `extraSkillRenderers`
            // APPENDS to the host's `boost.php` `withSkillRenderers()`, and the
            // dispatcher is first-registered-wins, so a host that registered its
            // own `.blade.php` renderer keeps it (no collision). This runs on the
            // artisan path where the container is bootstrapped, so the renderer's
            // container guard is satisfied.
            extraSkillRenderers: [new BladeRenderer()],
            injectedVendorGuidelines: ['laravel/boost' => $guidelines],
        );
    }

    /**
     * Render through boost-core's `SyncReporter` so this command and
     * `vendor/bin/boost sync` describe one `SyncResult` identically — two
     * entry points wording the same run differently is the divergence this
     * package exists to prevent.
     *
     * The EXIT decision stays ours, but only for DRIFT. `PUBLIC_API.md`
     * documents `0` for a `--dry-run` with pending changes, so `render()` (not
     * `report()`) is the call and `driftIsFailure: false` keeps the wording
     * neutral to match. Every other finding is a real failure: a conventions
     * schema error or a leaked `boost:conv` token makes the reporter print a
     * fatal error, and returning SUCCESS under it would contradict what the
     * operator just read and let CI accept invalid emitted output.
     *
     * The per-file list is printed here only for a REAL sync: boost-core's
     * report carries no write list of its own, but its drift branch does, so
     * printing ours in check mode listed every planned path twice.
     */
    private function renderResult(SyncResult $result, bool $checkOnly): int
    {
        if (! $checkOnly) {
            foreach ($result->writes as $written) {
                $this->line("  <fg=green>{$written->action->value}</> {$written->relativePath}");
            }
        }

        // Emitters are ours in both modes — the reporter's drift list covers
        // writes only, so these are never duplicated.

        foreach ($result->emitters as $emitter) {
            $path = $emitter->relativePath ?? $emitter->fqcn;
            $this->line("  <fg=cyan>emitter:{$emitter->action->value}</> {$path}");
        }

        $outcome = (new SyncReporter($this->commandInvocations(), driftIsFailure: false))
            ->render($this->output, $result, $checkOnly, base_path());

        $fatal = $outcome->hasErrors || $outcome->hasConventionsError || $outcome->hasTokenLeak;

        return $fatal ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Where the report's follow-up advice should send an operator. Unmapped
     * names fall back to `vendor/bin/boost <name>`, which in a wrapper project
     * is the entry point that reports a materially thinner set — so `tags`
     * maps to `project-boost:where`, this package's nearest equivalent, rather
     * than being left to that fallback. The equivalent need not be a command of
     * the same name; it only has to be the right thing to run.
     *
     * @return array<string, string>
     */
    private function commandInvocations(): array
    {
        return [
            'sync' => 'php artisan project-boost:sync',
            'tags' => 'php artisan project-boost:where',
            'where' => 'php artisan project-boost:where',
        ];
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
