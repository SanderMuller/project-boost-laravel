<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
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
 */
final class WhereCommand extends Command
{
    /** @var string */
    protected $signature = 'project-boost:where';

    /** @var string */
    protected $description = 'List laravel/boost-bundled skills + guidelines this package injects, with tag-filter eligibility for the current boost.php.';

    public function handle(): int
    {
        $projectRoot = base_path();
        if (! is_file($projectRoot . '/boost.php')) {
            $this->error("No boost.php found at {$projectRoot}/boost.php.");
            $this->line('Create one with at least:');
            $this->line('  return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');

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

        $shippedNames = $this->shippedSkillNames($projectRoot, $skills, $guidelines);

        $this->renderSkillsTable($skills, $shippedNames);
        $this->renderGuidelinesTable($guidelines, $shippedNames);

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
     * Run SyncEngine in check mode with the injected set; collect the
     * skill names that would actually land in agent dirs (i.e. survived
     * `withTags()` + collision resolution). Anything in the injection
     * set but not in the would-write paths is tag-filtered out.
     *
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @return list<string>
     */
    private function shippedSkillNames(string $projectRoot, array $skills, array $guidelines): array
    {
        $result = SyncEngine::default()->sync(
            projectRoot: $projectRoot,
            checkOnly: true,
            injectedVendorSkills: ['laravel/boost' => $skills],
            injectedVendorGuidelines: ['laravel/boost' => $guidelines],
        );

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
     * @param  list<Skill>  $skills
     * @param  list<string>  $shippedNames
     */
    private function renderSkillsTable(array $skills, array $shippedNames): void
    {
        if ($skills === []) {
            return;
        }

        $shipped = array_flip($shippedNames);

        $this->newLine();
        $this->line('<fg=cyan>laravel/boost-injected skills</>');

        $rows = [];
        foreach ($skills as $skill) {
            $type = str_ends_with($skill->sourcePath, '.blade.php') ? 'blade' : 'md';
            $tags = $skill->tags === [] ? '<untagged>' : implode(' ', $skill->tags);
            $status = isset($shipped[$skill->name])
                ? '<fg=green>ship</>'
                : '<fg=yellow>filtered (declare: ' . $tags . ')</>';
            $rows[] = [$skill->name, $type, $tags, $status];
        }

        $this->table(['Skill', 'Type', 'Tags', 'Status'], $rows);
    }

    /**
     * @param  list<Guideline>  $guidelines
     * @param  list<string>  $shippedNames  Unused for guidelines today (no per-guideline write path), kept for signature symmetry.
     */
    private function renderGuidelinesTable(array $guidelines, array $shippedNames): void
    {
        if ($guidelines === []) {
            return;
        }

        unset($shippedNames);

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
