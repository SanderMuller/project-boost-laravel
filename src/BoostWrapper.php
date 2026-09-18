<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel;

use Laravel\Roster\ProjectScan;
use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Contracts\BoostWrapperContract;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\ProjectBoostLaravel\Discovery\SkillAssetScope;
use SanderMuller\ProjectBoostLaravel\Discovery\VersionResolver;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Declares the emit surface this wrapper injects so boost-core's bare-CLI
 * cleanup-pass doesn't flag wrapper-injected skill files as stale-to-delete.
 *
 * `project-boost:sync` injects laravel/boost-bundled skills into
 * `BoostSync::sync(injectedVendorSkills: ['laravel/boost' => ...])`. The
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
 *  - each skill's ASSET siblings are declared alongside its `SKILL.md`.
 *    boost-core emits an injected skill's assets under
 *    `<skill-dir>/<name>/<asset-relative-path>`, so an unclaimed asset is
 *    reaped by the cleanup pass. Blade asset names are rewritten to `.md`,
 *    matching what `LaravelBoostAssetReader` emits;
 *  - the full skill universe is declared (pre tag-filter), because a NAME this
 *    sync didn't emit simply has no file to preserve, whereas under-declaring
 *    would let bare-CLI delete a real injected file. The contract is explicit
 *    that the filtered set isn't reproducible without the wrapper's runtime
 *    args, so the universe is the correct claim;
 *  - ASSETS, however, are claimed for the version-RESOLVED variant only. That
 *    asymmetry is deliberate: a claim exempts a path from stale cleanup for
 *    every sync, not just bare-CLI, and an obsolete variant's asset DOES exist
 *    on disk from an earlier sync. Claiming `pest/3`'s assets after the host
 *    upgrades to Pest 4 would strand them there forever. So this resolves the
 *    variant through the same `VersionResolver` the sync uses.
 *
 * Guideline files (`CLAUDE.md` / `AGENTS.md` / `GEMINI.md`) are intentionally
 * NOT returned — they use ManagedRegion + operator-tracking, not wholesale
 * replacement, so they're outside this contract's stale-cleanup surface.
 *
 * @internal Not consumer-callable. boost-core discovers it by class-name +
 *           `BoostWrapperContract`; its name/namespace/contract stay pinned by
 *           that discovery (and `BoostWrapperTest`) despite `@internal`.
 */
final class BoostWrapper implements BoostWrapperContract
{
    /**
     * @param  list<string>  $activeAgents
     * @return list<string>
     */
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array
    {
        $skillFiles = self::injectedSkillPaths($projectRoot);
        if ($skillFiles === []) {
            return [];
        }

        // Keyed by path for O(1) dedupe — Copilot + Codex (+ Cursor/Amp/etc.)
        // share the `.agents/skills/` pool, so the same skill resolves to one
        // path across all shared-pool agents.
        $paths = [];

        foreach (self::activeTargets($activeAgents) as $target) {
            foreach ($skillFiles as $name => $assetPaths) {
                $skillDir = $target->skillsDirectoryRelative();
                $paths[$skillDir . '/' . $target->skillRelativePathForName($name)] = true;

                foreach ($assetPaths as $assetPath) {
                    $paths[$skillDir . '/' . $name . '/' . $assetPath] = true;
                }
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
     * Each name maps to the asset relative paths of the ONE variant the sync
     * emits — resolved through `VersionResolver`, not unioned across variants
     * (see the class docblock for why the asymmetry with names is deliberate).
     *
     * @return array<string, list<string>>
     */
    private static function injectedSkillPaths(string $projectRoot): array
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

        $entriesByName = [];
        foreach ($finder as $file) {
            $entriesByName[self::skillNameFromFile($file)][] = $file->getPathname();
        }

        $resolver = new VersionResolver(self::projectScan($projectRoot));

        $skills = [];
        foreach ($entriesByName as $name => $entries) {
            $entry = count($entries) === 1 ? $entries[0] : $resolver->pickSourcePath($entries);

            $skills[$name] = array_keys(self::assetPaths($entry));
        }

        return $skills;
    }

    /**
     * The emit-relative asset paths beside one entry file — the same
     * {@see SkillAssetScope} partition `LaravelBoostAssetReader` applies, minus
     * the rendering (this runs in the bare-CLI cleanup pass, with no app
     * booted). A `.blade.php` asset is claimed under its rendered `.md` name.
     *
     * Over-declaring stays safe here: a Blade asset the sync skipped for want
     * of a renderer simply has no file to preserve.
     *
     * @return array<string, true>
     */
    private static function assetPaths(string $entryPath): array
    {
        $finder = (new Finder())
            ->files()
            ->in(dirname($entryPath))
            ->ignoreDotFiles(true)
            ->filter(SkillAssetScope::isAsset(...));

        $paths = [];
        foreach ($finder as $file) {
            $paths[SkillAssetScope::emitRelativePath($file)] = true;
        }

        return $paths;
    }

    /**
     * Roster's scan is what lets the resolver match the host's installed major.
     *
     * Both failure modes fall back to `null`, which drops the resolver to its
     * lex-last proxy: Roster absent (some bare-CLI contexts), or a scan that
     * throws on a host's unreadable composer.json / lock. The throw must not
     * escape — boost-core answers a throwing `injectedEmitPaths()` by dropping
     * the wrapper's whole claim, and a bare `boost sync` would then reap every
     * injected skill file in that project. Silent fallback matches the
     * `Agent::tryFrom` precedent above.
     */
    private static function projectScan(string $projectRoot): ?ProjectScan
    {
        if (! class_exists(ProjectScan::class)) {
            return null;
        }

        try {
            return ProjectScan::scan($projectRoot);
        } catch (Throwable) {
            return null;
        }
    }

    private static function skillNameFromFile(SplFileInfo $file): string
    {
        return basename(dirname($file->getPathname()));
    }

    /**
     * The `AgentTarget` for each active agent, resolved through boost-core's
     * `@api` `Agent` enum (`Agent::target()`) so the skill-dir layout stays in
     * lockstep without naming the concrete `@internal` target classes.
     *
     * `tryFrom` (not `from`) keeps the original silent-skip semantics: an agent
     * identifier boost-core's core enum doesn't know (e.g. a host-registered
     * custom agent) is dropped rather than throwing inside boost-core's
     * stale-cleanup pass.
     *
     * @param  list<string>  $activeAgents
     * @return list<AgentTarget>
     */
    private static function activeTargets(array $activeAgents): array
    {
        return array_values(array_filter(array_map(
            static fn (string $agent): ?AgentTarget => Agent::tryFrom($agent)?->target(),
            $activeAgents,
        )));
    }
}
