<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use Laravel\Boost\Support\PackageRegistry;
use Laravel\Roster\Package;
use Laravel\Roster\ProjectScan;
use Symfony\Component\Finder\Finder;

/**
 * Install-gate for laravel/boost guideline emission. Mirrors laravel/boost's
 * `GuidelineComposer` detection: it suppresses guidelines for composer packages
 * the host hasn't installed, applying the same priority + exclusion rules
 * laravel/boost uses —
 *
 *  - `PEST` excludes `PHPUNIT`, `FLUXUI_PRO` excludes `FLUXUI_FREE` (priority);
 *  - `BOOST` + `SAIL` are excluded from package discovery (boost is a core
 *    guideline already; sail is opt-in);
 *  - `MCP` + `LIVEWIRE` only count when a direct requirement.
 *
 * Without this gate the reader Finder-walks the whole vendor `.ai/` tree and
 * emits every package's `core.blade.php` unconditionally — so a Livewire +
 * Filament + PHPUnit app receives inertia / pest / sail guidelines it never
 * installed, and `pest-core` actively contradicts `phpunit-core`. (Reported by
 * the collectiq proving consumer.)
 *
 * Construct via `fromProjectScan()` in production. `permissive()` (allow-all) is
 * the graceful fallback when laravel/roster can't resolve the host's packages — it
 * preserves the pre-gate emit-all behaviour rather than risk dropping legit
 * guidelines when detection is unavailable.
 *
 * The gate operates on the top-level `.ai/` path segment of each guideline (the
 * `<pkg>` dir for `<pkg>/core.blade.php`, or the basename for a loose
 * `<name>.blade.php`). It is deliberately a DENY-list, not an allow-list: a
 * segment is suppressed only when it maps to a known composer package the host
 * hasn't installed (or that priority / exclusion filtering removed). Segments
 * outside the composer-package universe pass through — `herd` and
 * `enforce-tests` are gated by laravel/boost on RUNTIME detection (Herd binary,
 * `.test` URL, install-time config), not composer presence, so a
 * package-presence gate has no signal to judge them. Denying them would lose
 * guidance with no `withExcludedGuidelines` add-back lever (an asymmetry: the
 * config can remove a fragment but never re-add one), so they pass. `sail`
 * stays gated because it IS a composer package (`PackageRegistry::SAIL`) excluded from
 * discovery — matching laravel/boost's Sail-is-opt-in behaviour.
 *
 * Version-major sub-fragments are scoped via the `$versionMajor` arg to
 * `allows()`, on two different axes:
 *
 *  - **Package dirs (exact major).** laravel/boost's `GuidelineComposer` reads
 *    only `<dir>/{$package->major()}` — the host's installed major —
 *    because `laravel/11` vs `laravel/12` are alternative complete sets. So
 *    `laravel/12/core` emits on a Laravel 12 host; `laravel/11/core` drops.
 *  - **`php` dirs (cumulative floor).** `php/8.4` lists features NEW in 8.4,
 *    all usable on any later PHP, so they're cumulative-downward. Keep every
 *    `php/<v>` with `v <= phpFloor` (the project's declared `require.php`
 *    minimum — the range the code must support). laravel/boost composes no
 *    php/8.x itself, but the content is real and version-relevant, so the
 *    wrapper surfaces the supported subset. Unknown floor → keep all.
 *
 * @internal
 */
final readonly class LaravelBoostGuidelineGate
{
    /**
     * Non-package guideline segments laravel/boost always composes
     * (`getCoreGuidelines`): `foundation` (loose file), `boost/core`,
     * `php/core`, `deployments/core`. Always allowed regardless of Roster.
     *
     * @var list<string>
     */
    private const array CORE_SEGMENTS = ['foundation', 'php', 'boost', 'deployments'];

    /**
     * Guideline dirs laravel/boost gates on RUNTIME detection rather than
     * composer presence (`GuidelineComposer::getConditionalGuidelines()`), so
     * the package gate has no signal to judge them and must let them pass.
     * `herd` is the only such top-level dir: `enforce-tests` is a loose
     * `.blade.php` (never a dir, so it never enters the scanned universe),
     * `laravel/{style,api,localization}` are sub-paths of the `laravel`
     * package dir, and `sail` is deliberately gated — it IS a composer
     * package, excluded from discovery to preserve boost's opt-in behaviour.
     *
     * @var list<string>
     */
    private const array RUNTIME_GATED_SEGMENTS = ['herd'];

    /**
     * Excluded from package discovery: boost is loaded as a core
     * guideline; sail requires explicit opt-in. Mirrors laravel/boost
     * `DiscoverPackagePaths::$excludedPackages`.
     *
     * @var list<string>
     */
    private const array EXCLUDED_PACKAGES = [PackageRegistry::BOOST, PackageRegistry::SAIL];

    /**
     * Only counted when a direct requirement — fixes every consumer inheriting
     * MCP / Livewire guidelines through an indirect dependency. Mirrors
     * laravel/boost `DiscoverPackagePaths::$mustBeDirect`.
     *
     * @var list<string>
     */
    private const array MUST_BE_DIRECT = [PackageRegistry::MCP, PackageRegistry::LIVEWIRE];

    /**
     * @param  array<string, true>|null  $allowedPackageDirs  normalized package
     *   dir names that survived install + priority + exclusion filtering, keyed
     *   for O(1) lookup. Null = permissive (emit all — Roster unavailable).
     * @param  array<string, true>  $knownPackageDirs  every package guideline
     *   dir laravel/boost actually ships under the `.ai/` root — the universe
     *   of composer-package guideline dirs. A segment is denied only if it is
     *   in this universe but NOT in
     *   `$allowedPackageDirs`; segments outside it (e.g. `herd`, `enforce-tests`
     *   — gated by laravel/boost on runtime detection, not composer presence)
     *   pass through, since the package gate has no signal to judge them and
     *   dropping them would lose guidance with no add-back lever.
     * @param  array<string, string>  $hostMajors  installed-and-allowed package
     *   dir name → the host's installed major version (`Package::major()`).
     *   Drives version-major sub-fragment scoping: `<pkg>/<version>/…` emits
     *   only when `<version>` equals the host's major for `<pkg>`. Dirs absent
     *   here (uninstalled packages, or packages Roster resolved without a
     *   version) have no version subdir composed.
     * @param  string|null  $phpFloor  the project's declared PHP floor
     *   (`major.minor`, e.g. `8.3` from a `require.php` of `^8.3`). The `php`
     *   version dirs are cumulative-downward — `php/8.4` lists features new in
     *   8.4, all usable on any later PHP — so `php/<v>` emits for every
     *   `v <= phpFloor` (the range the code must support). Null → keep all php
     *   version fragments (never-lossy fallback when the floor can't be read).
     */
    private function __construct(
        private ?array $allowedPackageDirs,
        private array $knownPackageDirs = [],
        private array $hostMajors = [],
        private ?string $phpFloor = null,
    ) {}

    /**
     * Allow-all fallback for when laravel/roster can't resolve the host's
     * packages. Preserves pre-gate behaviour.
     */
    public static function permissive(): self
    {
        return new self(null);
    }

    /**
     * Build the gate from the host's project scan. Mirrors laravel/boost
     * `DiscoverPackagePaths::discoverPackagePaths()` + `shouldExcludePackage()`:
     * map each installed package to its guideline dir, drop the
     * excluded/priority-shadowed/indirect ones, keep those whose dir actually
     * exists under the laravel/boost `.ai/` root.
     *
     * Both ecosystems are scanned: boost's own `DiscoverPackagePaths::packages()`
     * concats php + js, and the js side is what carries the `@inertiajs/*` and
     * `tailwindcss` guideline dirs.
     */
    public static function fromProjectScan(ProjectScan $scan, string $aiRoot, ?string $phpFloor = null): self
    {
        $allowed = [];
        $hostMajors = [];

        $packages = $scan->php()->packages()->concat($scan->js()->packages());

        foreach ($packages as $package) {
            if (self::shouldExcludePackage($scan, $package)) {
                continue;
            }

            $dir = self::normalizePackageName($package->name());
            if (! is_dir($aiRoot . '/' . $dir)) {
                continue;
            }

            $allowed[$dir] = true;

            // `major()` is null for a package Roster resolved without a
            // version. Leaving the dir out of $hostMajors (rather than
            // storing '') means no `<pkg>/<version>/…` fragment matches,
            // which is the correct read: we don't know the host's major.
            $major = $package->major();
            if ($major !== null) {
                $hostMajors[$dir] = (string) $major;
            }
        }

        return new self($allowed, self::knownPackageDirs($aiRoot), $hostMajors, $phpFloor);
    }

    /**
     * Lowest `major.minor` in a composer `require.php` constraint — the PHP
     * floor the project must support. `^8.3` → `8.3`, `>=8.2` → `8.2`,
     * `^8.2 || ^8.4` → `8.2`. Null when the constraint carries no `major.minor`
     * token (the gate then keeps all php-version fragments — never-lossy).
     */
    public static function parsePhpFloor(string $constraint): ?string
    {
        if (preg_match_all('/\d+\.\d+/', $constraint, $matches) === 0) {
            return null;
        }

        $floor = $matches[0][0];
        foreach ($matches[0] as $version) {
            if (version_compare($version, $floor, '<')) {
                $floor = $version;
            }
        }

        return $floor;
    }

    /**
     * The universe of composer-package guideline dirs — every dir laravel/boost
     * ships under `.ai/`, minus the core and runtime-gated segments. Used to
     * distinguish "known package the host hasn't installed" (deny) from "not a
     * composer package at all" (pass through).
     *
     * Scanned rather than hardcoded because nothing upstream enumerates it:
     * `PackageRegistry` keeps its name → dir map private, exposing only the
     * `guidelineName()` mapper. Scanning also keeps a dir boost adds in a
     * future release gated correctly without a release here.
     *
     * @return array<string, true>
     */
    private static function knownPackageDirs(string $aiRoot): array
    {
        if (! is_dir($aiRoot)) {
            return [];
        }

        $skip = [...self::CORE_SEGMENTS, ...self::RUNTIME_GATED_SEGMENTS];

        $finder = (new Finder())
            ->in($aiRoot)
            ->depth(0)
            ->directories();

        $dirs = [];
        foreach ($finder as $dir) {
            if (in_array($dir->getFilename(), $skip, true)) {
                continue;
            }

            $dirs[$dir->getFilename()] = true;
        }

        return $dirs;
    }

    /**
     * @param  string  $segment  top-level `.ai/` path segment for a guideline
     *   (dir name for `<pkg>/core.blade.php`, or basename without extension for
     *   a loose `<name>.blade.php`).
     * @param  string|null  $versionMajor  the version sub-segment for a
     *   `<pkg>/<version>/…` fragment (e.g. `12` for `laravel/12/core`, `8.3`
     *   for `php/8.3/core`), or null for a top-level guideline.
     */
    public function allows(string $segment, ?string $versionMajor = null): bool
    {
        if ($this->allowedPackageDirs === null) {
            return true;
        }

        if ($versionMajor !== null) {
            // `php` version dirs are CUMULATIVE-downward — `php/8.4` lists
            // features new in 8.4, all usable on any later PHP — so keep every
            // `php/<v>` with `v <= phpFloor` (the range the code must support).
            // Unknown floor → keep all (never-lossy). laravel/boost itself
            // composes no php/8.x, but the per-version content exists and is
            // genuinely useful for the host's supported range, so the wrapper
            // surfaces the applicable subset rather than inheriting that gap.
            if ($segment === 'php') {
                return $this->phpFloor === null
                    || version_compare($versionMajor, $this->phpFloor, '<=');
            }

            // PACKAGE version dirs are EXACT-major — `laravel/11` vs `laravel/12`
            // are alternative complete sets, not cumulative. laravel/boost's
            // `getPackageGuidelines` reads only `<dir>/{$package->major()}`.
            // Emit iff `<pkg>` is installed+allowed and `<version>` is its host
            // major; wrong major (or any other non-package version dir) drops.
            return ($this->hostMajors[$segment] ?? null) === $versionMajor;
        }

        if (in_array($segment, self::CORE_SEGMENTS, true)) {
            return true;
        }

        // Deny only a segment that maps to a known composer package the host
        // hasn't installed (or that priority/exclusion filtered out). Segments
        // outside the composer-package universe — `herd`, `enforce-tests`, and
        // anything laravel/boost gates on runtime detection rather than package
        // presence — pass through: the gate has no signal to judge them, and
        // dropping a shipped fragment loses guidance with no add-back lever.
        return ! isset($this->knownPackageDirs[$segment]) || isset($this->allowedPackageDirs[$segment]);
    }

    /**
     * Mirror of laravel/boost `DiscoverPackagePaths::shouldExcludePackage()`.
     */
    private static function shouldExcludePackage(ProjectScan $scan, Package $package): bool
    {
        if (in_array($package->name(), self::EXCLUDED_PACKAGES, true)) {
            return true;
        }

        foreach (self::packagePriorities() as $priorityPackage => $shadowedPackages) {
            if (in_array($package->name(), $shadowedPackages, true)
                && self::usesPackage($scan, $priorityPackage)) {
                return true;
            }
        }

        return ! $package->isDirect() && in_array($package->name(), self::MUST_BE_DIRECT, true);
    }

    /**
     * Mirror of laravel/boost `DiscoverPackagePaths::usesPackage()` — check the
     * php ecosystem first, then js.
     */
    private static function usesPackage(ProjectScan $scan, string $package): bool
    {
        if ($scan->php()->uses($package)) {
            return true;
        }

        return $scan->js()->uses($package);
    }

    /**
     * When a higher-priority package is present, the lower-priority package is
     * excluded from guidelines — keyed by the winning package's composer name,
     * valued with the composer names it shadows. Mirrors laravel/boost
     * `DiscoverPackagePaths::getPackagePriorities()`.
     *
     * @return array<string, list<string>>
     */
    private static function packagePriorities(): array
    {
        return [
            PackageRegistry::PEST => [PackageRegistry::PHPUNIT],
            PackageRegistry::FLUXUI_PRO => [PackageRegistry::FLUXUI_FREE],
        ];
    }

    /**
     * Composer/npm package name → guideline dir. Delegates to laravel/boost's
     * own mapper so the two can't drift — the mapping is not a slugify
     * (`livewire/flux-pro` → `fluxui-pro`, `@inertiajs/vue3` → `inertia-vue`),
     * so a local reimplementation silently mis-gates every renamed package.
     */
    private static function normalizePackageName(string $name): string
    {
        return PackageRegistry::guidelineName($name);
    }
}
