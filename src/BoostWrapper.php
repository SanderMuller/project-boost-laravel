<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Contracts\BoostWrapperContract;
use SanderMuller\BoostCore\Skills\Skill;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Declares the emit surface this wrapper injects so boost-core's bare-CLI
 * cleanup-pass doesn't flag wrapper-injected skill files as stale-to-delete.
 *
 * `project-boost:sync` injects laravel/boost-bundled skills into
 * `SyncEngine::sync(injectedVendorSkills: ['laravel/boost' => ...])`. The
 * resulting `<skill-dir>/<name>/SKILL.md` files live on disk after the sync.
 * A bare `vendor/bin/boost sync` (no wrapper injection args) produces an empty
 * injection set, so its cleanup pass would otherwise classify every one of
 * those files as stale and delete it. boost-core 0.11.0's
 * `BoostWrapperContract` lets this wrapper declare those paths so bare-CLI
 * preserves them.
 *
 * The path set is computed per the contract:
 *  - skill NAMES come from laravel/boost's `.ai/<pkg>/[<major>/]skill/<name>/`
 *    dirs (same `basename(dirname(...))` derivation `LaravelBoostAssetReader`
 *    uses), so no Blade rendering or app bootstrap is needed — this runs in
 *    boost-core's bare-CLI cleanup pass;
 *  - per-agent emit DIRS come from boost-core's own `AgentTarget`
 *    implementations (public API) rather than hard-coded strings, keeping the
 *    wrapper in lockstep with boost-core's emit layout across versions;
 *  - the full skill universe is declared (pre tag-filter / version-dedupe).
 *    Over-declaring is safe — a path that this sync didn't emit simply has no
 *    file to preserve — whereas under-declaring would let bare-CLI delete a
 *    real injected file. The contract is explicit that the filtered set isn't
 *    reproducible without the wrapper's runtime args, so the universe is the
 *    correct claim.
 *
 * Guideline files (`CLAUDE.md` / `AGENTS.md` / `GEMINI.md`) are intentionally
 * NOT returned — they use ManagedRegion + operator-tracking, not wholesale
 * replacement, so they're outside this contract's stale-cleanup surface.
 */
final class BoostWrapper implements BoostWrapperContract
{
    /**
     * @param  list<string>  $activeAgents
     * @return list<string>
     */
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array
    {
        $skillNames = self::injectedSkillNames($projectRoot);
        if ($skillNames === []) {
            return [];
        }

        // Keyed by path for O(1) dedupe — Copilot + Codex (+ Cursor/Amp/etc.)
        // share the `.agents/skills/` pool, so the same skill resolves to one
        // path across all shared-pool agents.
        $paths = [];

        foreach (self::activeTargets($activeAgents) as $target) {
            foreach ($skillNames as $name) {
                $skill = new Skill(
                    name: $name,
                    description: null,
                    frontmatter: [],
                    body: '',
                    sourcePath: '',
                    sourceVendor: 'laravel/boost',
                );

                $paths[$target->skillsDirectoryRelative() . '/' . $target->skillRelativePath($skill)] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Distinct laravel/boost-bundled skill names, derived from the vendor
     * `.ai/<pkg>/[<major>/]skill/<name>/SKILL.{md,blade.php}` dir layout —
     * matching `LaravelBoostAssetReader::skillNameFromPath()`. Version variants
     * (`pest/3` + `pest/4`) collapse to one name, which is the single path the
     * version-resolved sync emits.
     *
     * @return list<string>
     */
    private static function injectedSkillNames(string $projectRoot): array
    {
        $aiRoot = $projectRoot . '/vendor/laravel/boost/.ai';
        if (! is_dir($aiRoot)) {
            return [];
        }

        $finder = (new Finder())
            ->in($aiRoot)
            ->path('/\/skill\//')
            ->name(['SKILL.md', 'SKILL.blade.php'])
            ->files();

        $names = [];
        foreach ($finder as $file) {
            $names[self::skillNameFromFile($file)] = true;
        }

        return array_keys($names);
    }

    private static function skillNameFromFile(SplFileInfo $file): string
    {
        return basename(dirname($file->getPathname()));
    }

    /**
     * The `AgentTarget` for each active agent, resolved by matching each
     * target's `agent()` enum value against `$activeAgents`. Uses boost-core's
     * own target set (public API) so the skill-dir layout stays in lockstep.
     *
     * @param  list<string>  $activeAgents
     * @return list<AgentTarget>
     */
    private static function activeTargets(array $activeAgents): array
    {
        $targets = [
            new ClaudeCodeTarget(),
            new CursorTarget(),
            new CopilotTarget(),
            new CodexTarget(),
            new GeminiTarget(),
            new JunieTarget(),
            new KiroTarget(),
            new OpenCodeTarget(),
            new AmpTarget(),
        ];

        return array_values(array_filter(
            $targets,
            static fn (AgentTarget $target): bool => in_array($target->agent()->value, $activeAgents, true),
        ));
    }
}
