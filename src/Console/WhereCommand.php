<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\BoostSync;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\LoadsBoostConfig;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;
use SanderMuller\ProjectBoostLaravel\Discovery\VersionResolver;
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

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
    use LoadsBoostConfig;

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

        $skills = VersionResolver::withHostRoster($projectRoot)->resolve($skillReader->readSkills());
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

        $shippedNames = $this->shippedSkillNamesFromResult($result);
        $shadowedBy = $this->shadowIndex($result);

        $this->renderSkillsTable($skills, $shippedNames, $shadowedBy);
        $this->renderGuidelinesTable($guidelines);

        $shippedCount = count(array_intersect(array_map(static fn (Skill $s): string => $s->name, $skills), $shippedNames));
        $filteredCount = count($skills) - $shippedCount;

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>laravel/boost injection set · %d skills (%d ship, %d tag-filtered) · %d guidelines (after dedupe).</>',
            count($skills),
            $shippedCount,
            $filteredCount,
            count($guidelines),
        ));

        $this->line('<fg=gray>For host / scanned-vendor / remote-skill origins, run `vendor/bin/boost where`.</>');

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
     * Collect the skill names from a check-mode SyncResult that would
     * actually land in agent dirs. Anything in the injection set but
     * not in the would-write paths is either tag-filtered, host-shadowed,
     * or collision-lost — disambiguated via `$result->hostShadows` in
     * the renderer.
     *
     * @return list<string>
     */
    private function shippedSkillNamesFromResult(SyncResult $result): array
    {
        $names = [];
        foreach ($result->writes as $written) {
            if ($written->action !== WriteAction::WOULD_WRITE && $written->action !== WriteAction::UNCHANGED) {
                continue;
            }

            if (preg_match('#/skills/([^/]+)/SKILL\.md$#', $written->relativePath, $matches) !== 1) {
                continue;
            }

            $names[$matches[1]] = true;
        }

        return array_keys($names);
    }

    /**
     * Map of skill name → shadowing vendor, built from boost-core's
     * `$result->hostShadows` (list of `{skill, shadowedVendor}` entries).
     * Lets the status column attribute a non-shipping skill to a host
     * override rather than mislabel it as `filtered (declare: …)`.
     *
     * @return array<string, string>
     */
    private function shadowIndex(SyncResult $result): array
    {
        $index = [];
        foreach ($result->hostShadows as $shadow) {
            $index[$shadow['skill']] = $shadow['shadowedVendor'];
        }

        return $index;
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<string>  $shippedNames
     * @param  array<string, string>  $shadowedBy
     */
    private function renderSkillsTable(array $skills, array $shippedNames, array $shadowedBy): void
    {
        if ($skills === []) {
            return;
        }

        $shipped = array_fill_keys($shippedNames, true);

        $this->newLine();
        $this->line('<fg=cyan>laravel/boost-injected skills</>');

        $rows = [];
        foreach ($skills as $skill) {
            $type = str_ends_with($skill->sourcePath, '.blade.php') ? 'blade' : 'md';
            $tags = $skill->tags === [] ? '<untagged>' : implode(' ', $skill->tags);
            $status = $this->renderStatus($skill, $shipped, $shadowedBy, $tags);
            $rows[] = [$skill->name, $type, $tags, $status];
        }

        $this->table(['Skill', 'Type', 'Tags', 'Status'], $rows);
    }

    /**
     * Three-way attribution for a skill's status:
     *   ship                          — the skill survived the pipeline
     *   shadowed by <vendor>          — lost to a host/scanned skill of the same name
     *   filtered (declare: <tags>)    — tag-filter excluded it; user can add tags
     *   excluded                      — not shipping for some other reason
     *                                   (untagged skill that still didn't land —
     *                                   usually `withExcludedSkills` or a renderer
     *                                   issue; tag advice wouldn't help)
     *
     * @param  array<string, true>  $shipped
     * @param  array<string, string>  $shadowedBy
     */
    private function renderStatus(Skill $skill, array $shipped, array $shadowedBy, string $tagsLabel): string
    {
        if (isset($shipped[$skill->name])) {
            return '<fg=green>ship</>';
        }

        if (isset($shadowedBy[$skill->name])) {
            return sprintf('<fg=yellow>shadowed by %s</>', $shadowedBy[$skill->name]);
        }

        if ($skill->tags === []) {
            return '<fg=yellow>excluded</>';
        }

        return '<fg=yellow>filtered (declare: ' . $tagsLabel . ')</>';
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
