<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use Laravel\Roster\ProjectScan;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\BoostSync;
use SanderMuller\BoostCore\Sync\SyncReporter;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\GatesGuidelines;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\LoadsBoostConfig;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\ResolvesAiRoot;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;
use SanderMuller\ProjectBoostLaravel\Discovery\VersionResolver;
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;
use SanderMuller\ProjectBoostLaravel\Rendering\InjectedSkillStatus;

/**
 * `project-boost:where` — list every laravel/boost-bundled skill +
 * guideline this package injects into boost-core's SyncEngine, with
 * tag-filter eligibility per the project's current `boost.php`.
 *
 * Symmetric with boost-core's `boost where` (which enumerates host +
 * scanned-vendor + remote-skill origins). The injection seam this
 * package uses (`SyncEngine::sync(injectedVendorSkills: ...)`) is
 * runtime-only and invisible to `boost where`'s static origin tracing,
 * so this command fills the gap: it shows what laravel/boost shipped,
 * what got tag-filtered out for the current project shape, and which
 * are actually landing in agent dirs.
 *
 * Use when answering "is `livewire-development` shipping for me?" or
 * "what tags do I need to add to pick up `inertia-vue-development`?".
 *
 * @internal The `project-boost:where` CLI contract is the public promise — see
 *           PUBLIC_API.md. The class is not an extension point.
 */
final class WhereCommand extends Command
{
    use GatesGuidelines;
    use LoadsBoostConfig;
    use ResolvesAiRoot;

    /** @var string */
    protected $signature = 'project-boost:where';

    /** @var string */
    protected $description = 'List laravel/boost-bundled skills + guidelines this package injects, with tag-filter eligibility for the current boost.php.';

    public function handle(): int
    {
        $projectRoot = base_path();

        if (! $this->loadBoostConfigOrHint($projectRoot) instanceof BoostConfig) {
            return self::FAILURE;
        }

        $blade = new BladeRenderer();
        $manifestPath = dirname(__DIR__, 2) . '/resources/boost/laravel-boost-tags.yaml';
        $manifest = LaravelBoostTagManifest::fromFile($manifestPath);
        $aiRoot = $this->resolveLaravelBoostAiRoot();

        // Scan the host project once and share it with both the skill version
        // resolver and the guideline install-gate — so `where` reports the same
        // gated guideline set `sync` actually emits, not the full unfiltered set.
        $scan = class_exists(ProjectScan::class) ? ProjectScan::scan($projectRoot) : null;

        $skillReader = new LaravelBoostAssetReader(
            laravelBoostAiRoot: $aiRoot,
            tagManifest: $manifest,
            bladeRenderer: $blade,
        );
        $guidelineReader = new LaravelBoostGuidelineReader(
            laravelBoostAiRoot: $aiRoot,
            tagManifest: $manifest,
            bladeRenderer: $blade,
            installGate: $this->guidelineGate($scan, $aiRoot, $projectRoot),
        );

        $skills = (new VersionResolver($scan))->resolve($skillReader->readSkills());
        $guidelines = $this->dedupedGuidelines($guidelineReader->readGuidelines());

        if ($skills === [] && $guidelines === []) {
            $this->warn('No laravel/boost skills or guidelines found at vendor/laravel/boost/.ai. Is laravel/boost installed?');

            return self::SUCCESS;
        }

        $result = BoostSync::make()->sync(
            projectRoot: $projectRoot,
            checkOnly: true,
            injectedVendorSkills: ['laravel/boost' => $skills],
            injectedVendorGuidelines: ['laravel/boost' => $guidelines],
        );

        // boost-core owns the inverse of `AgentTarget::skillRelativePathForName()`.
        // Deriving shipped/shadowed here meant pattern-matching an emit path,
        // which is coupled to a layout no promise covers — the frozen contract
        // is `skillsDirectoryRelative()`, not the fact that its value ends in
        // `/skills`. A layout change inside that promise matched nothing, and
        // the status column silently reported every skill as not shipping.
        $status = InjectedSkillStatus::from($result);

        // A skill whose source fails to render is EXCLUDED from the resolved
        // set rather than reported, so it never reaches `$result->writes` and
        // the status column would blame the tag filter for a renderer failure —
        // sending the operator to fix a `withTags()` that was never the cause.
        // On a degraded run the negative statuses are not attributable, so say
        // the errors and withhold the reason instead of inventing one.
        if ($status->isDegraded()) {
            // Delegate to boost-core's renderer rather than listing the errors
            // here. It covers BOTH channels — an ERRORED emitter does not
            // appear in `$result->errors` — and a hand-rolled second copy is
            // exactly what reintroduced that gap in `boost doctor`.
            (new SyncReporter())->renderErrors($this->output, $result, checkOnly: true);

            $this->newLine();
            $this->warn('Statuses below are incomplete: a skill that failed to render is reported as not shipping, without a reason.');
        }

        $this->renderSkillsTable($skills, $status);
        $this->renderGuidelinesTable($guidelines);

        $shippedCount = count(array_filter($skills, static fn (Skill $s): bool => $status->isShipped($s->name)));
        $filteredCount = count($skills) - $shippedCount;

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>laravel/boost injection set · %d skills (%d ship, %d tag-filtered) · %d guidelines (after dedupe).</>',
            count($skills),
            $shippedCount,
            $filteredCount,
            count($guidelines),
        ));

        // `vendor/bin/boost where` is the only view of those origins — this
        // package has no equivalent — but it renders the BARE pipeline, with
        // none of the laravel/boost skills listed above. Naming that keeps the
        // pointer useful without implying the two listings are comparable.
        $this->line('<fg=gray>Host / scanned-vendor / remote-skill origins are listed only by `vendor/bin/boost where`, which shows the bare pipeline — none of the injected skills above appear there.</>');

        return self::SUCCESS;
    }

    /**
     * @param  list<Guideline>  $allGuidelines
     * @return list<Guideline>
     */
    private function dedupedGuidelines(array $allGuidelines): array
    {
        $byName = [];
        foreach ($allGuidelines as $guideline) {
            $byName[$guideline->name] = $guideline;
        }

        return array_values($byName);
    }

    /**
     * @param  list<Skill>  $skills
     */
    private function renderSkillsTable(array $skills, InjectedSkillStatus $status): void
    {
        if ($skills === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>laravel/boost-injected skills</>');

        $rows = [];
        foreach ($skills as $skill) {
            $type = str_ends_with($skill->sourcePath, '.blade.php') ? 'blade' : 'md';
            $tags = $skill->tags === [] ? '<untagged>' : implode(' ', $skill->tags);
            $rows[] = [$skill->name, $type, $tags, $status->cellFor($skill, $tags)];
        }

        $this->table(['Skill', 'Type', 'Tags', 'Status'], $rows);
    }

    /**
     * @param  list<Guideline>  $guidelines
     */
    private function renderGuidelinesTable(array $guidelines): void
    {
        if ($guidelines === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>laravel/boost-injected guidelines</>');

        $rows = [];
        foreach ($guidelines as $g) {
            $tags = $g->tags === [] ? '<untagged>' : implode(' ', $g->tags);
            $rows[] = [$g->name, $tags];
        }

        $this->table(['Guideline', 'Tags'], $rows);
    }
}
