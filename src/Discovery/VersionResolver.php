<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use Laravel\Boost\Support\PackageRegistry;
use Laravel\Roster\Package;
use Laravel\Roster\ProjectScan;
use SanderMuller\BoostCore\Skills\Skill;

/**
 * Picks the right version of a laravel/boost-bundled skill when the
 * vendor ships multiple per-major variants under
 * `vendor/laravel/boost/.ai/<package>/<major>/skill/<name>/`. The two
 * canonical examples are `pest/3` vs `pest/4` and `livewire/2` vs
 * `livewire/3`.
 *
 * Resolution strategy, in order:
 *  1. If `laravel/roster` is available AND the host has an installed
 *     package whose guideline dir matches the variants' `<package>`
 *     path segment, pick the variant whose major matches that package's
 *     `major()`. Deterministic; matches the host's actual installed
 *     package.
 *  2. Otherwise (Roster missing, host doesn't have the package, package
 *     resolved without a version, no variant matches the detected
 *     major), fall back to the previous lex-last `sourcePath` proxy.
 *     Picks `pest/4` over `pest/3` correctly today but flips at
 *     `pest/10` — known limitation, only triggered when Roster can't
 *     resolve.
 *
 * Skills without a per-major path segment (`.ai/folio/skill/...`) are
 * returned as-is — there's nothing to dedupe.
 *
 * @internal
 */
final readonly class VersionResolver
{
    public function __construct(
        private ?ProjectScan $scan = null,
    ) {}

    /**
     * Group `$skills` by name and pick one variant per name.
     *
     * @param  list<Skill>  $skills
     * @return list<Skill>
     */
    public function resolve(array $skills): array
    {
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name][] = $skill;
        }

        $resolved = [];
        foreach ($byName as $variants) {
            $resolved[] = count($variants) === 1
                ? $variants[0]
                : $this->pickBest($variants);
        }

        return $resolved;
    }

    /**
     * @param  list<Skill>  $variants
     */
    private function pickBest(array $variants): Skill
    {
        $hostMajor = $this->lookupHostMajor($variants);
        if ($hostMajor !== null) {
            foreach ($variants as $variant) {
                if ($this->extractMajor($this->normalize($variant->sourcePath)) === $hostMajor) {
                    return $variant;
                }
            }
        }

        usort($variants, static fn (Skill $a, Skill $b): int => strcmp($a->sourcePath, $b->sourcePath));

        /** @var Skill $last */
        $last = end($variants);

        return $last;
    }

    /**
     * @param  list<Skill>  $variants
     */
    private function lookupHostMajor(array $variants): ?string
    {
        if (! $this->scan instanceof ProjectScan) {
            return null;
        }

        $guidelineDir = $this->detectPackageGroup($variants);
        if ($guidelineDir === null) {
            return null;
        }

        $major = $this->findByGuidelineDir($this->scan, $guidelineDir)?->major();

        return $major === null ? null : (string) $major;
    }

    /**
     * Find the host's installed package whose guideline dir is `$guidelineDir`.
     *
     * Nothing upstream inverts `PackageRegistry::guidelineName()` (composer
     * name → dir) — `rosterName()` yields `PEST`, not `pestphp/pest` — so this
     * maps each installed package FORWARD through `guidelineName()` rather
     * than looking one up by name. Both ecosystems are searched, php first:
     * `.ai/` carries js-package dirs too (`inertia-react`, `tailwindcss`).
     */
    private function findByGuidelineDir(ProjectScan $scan, string $guidelineDir): ?Package
    {
        $packages = $scan->php()->packages()->concat($scan->js()->packages());

        foreach ($packages as $package) {
            if (PackageRegistry::guidelineName($package->name()) === $guidelineDir) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Returns the shared package directory name if all variants live
     * under the same `<package>/<major>` segment, otherwise null.
     *
     * @param  list<Skill>  $variants
     */
    private function detectPackageGroup(array $variants): ?string
    {
        $packages = [];
        foreach ($variants as $variant) {
            if (preg_match('#/\.ai/([^/]+)/\d+/skill/#', $this->normalize($variant->sourcePath), $matches) === 1) {
                $packages[$matches[1]] = true;
            }
        }

        if (count($packages) !== 1) {
            return null;
        }

        return array_key_first($packages);
    }

    private function extractMajor(string $sourcePath): ?string
    {
        if (preg_match('#/\.ai/[^/]+/(\d+)/skill/#', $this->normalize($sourcePath), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Normalize Windows-style backslash separators to forward slashes
     * before regex matching. SplFileInfo on Windows emits native
     * separators, which would otherwise silently bypass the
     * Roster-resolution branch and fall through to lex-sort — and
     * lex-sort still mis-orders `pest/10` vs `pest/3` once upstream
     * ships double-digit majors.
     */
    private function normalize(string $sourcePath): string
    {
        return str_replace('\\', '/', $sourcePath);
    }
}
