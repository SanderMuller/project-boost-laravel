<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use Laravel\Roster\Enums\Packages;
use Laravel\Roster\Package;
use Laravel\Roster\Roster;

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
 * Construct via `fromRoster()` in production. `permissive()` (allow-all) is the
 * graceful fallback when laravel/roster can't resolve the host's packages — it
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
 * stays gated because it IS a composer package (`Packages::SAIL`) excluded from
 * discovery — matching laravel/boost's Sail-is-opt-in behaviour.
 *
 * Version-major sub-fragments are host-major-scoped via the `$versionMajor`
 * arg to `allows()`. laravel/boost's `GuidelineComposer` composes only the
 * host's installed major for a PACKAGE guideline dir (`<dir>/{majorVersion}`)
 * and composes NO version subdir for non-package dirs (it emits `php/core` but
 * never `php/8.x`). So `laravel/12/core` emits on a Laravel 12 host while
 * `laravel/11/core` is dropped, and every `php/8.x/core` is dropped (php is not
 * a Composer package — only its top-level `php/core` emits).
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
     * Excluded from Roster-based discovery: boost is loaded as a core
     * guideline; sail requires explicit opt-in. Mirrors laravel/boost
     * `DiscoverPackagePaths::$excludedPackages`.
     *
     * @var list<Packages>
     */
    private const array EXCLUDED_PACKAGES = [Packages::BOOST, Packages::SAIL];

    /**
     * Only counted when a direct requirement — fixes every consumer inheriting
     * MCP / Livewire guidelines through an indirect dependency. Mirrors
     * laravel/boost `DiscoverPackagePaths::$mustBeDirect`.
     *
     * @var list<Packages>
     */
    private const array MUST_BE_DIRECT = [Packages::MCP, Packages::LIVEWIRE];

    /**
     * @param  array<string, true>|null  $allowedPackageDirs  normalized package
     *   dir names that survived install + priority + exclusion filtering, keyed
     *   for O(1) lookup. Null = permissive (emit all — Roster unavailable).
     * @param  array<string, true>  $knownPackageDirs  every dir name that maps
     *   to a `Packages` enum case — the universe of composer-package guideline
     *   dirs. A segment is denied only if it is in this universe but NOT in
     *   `$allowedPackageDirs`; segments outside it (e.g. `herd`, `enforce-tests`
     *   — gated by laravel/boost on runtime detection, not composer presence)
     *   pass through, since the package gate has no signal to judge them and
     *   dropping them would lose guidance with no add-back lever.
     * @param  array<string, string>  $hostMajors  installed-and-allowed package
     *   dir name → the host's installed major version (`Package::majorVersion()`).
     *   Drives version-major sub-fragment scoping: `<pkg>/<version>/…` emits
     *   only when `<version>` equals the host's major for `<pkg>`. Dirs absent
     *   here (non-packages like `php`, or uninstalled packages) have no
     *   version subdir composed.
     */
    private function __construct(
        private ?array $allowedPackageDirs,
        private array $knownPackageDirs = [],
        private array $hostMajors = [],
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
     * Build the gate from the host's installed-package roster. Mirrors
     * laravel/boost `DiscoverPackagePaths::discoverPackagePaths()` +
     * `shouldExcludePackage()`: map each installed package to its guideline
     * dir, drop the excluded/priority-shadowed/indirect ones, keep those whose
     * dir actually exists under the laravel/boost `.ai/` root.
     */
    public static function fromRoster(Roster $roster, string $aiRoot): self
    {
        $allowed = [];
        $hostMajors = [];

        foreach ($roster->packages() as $package) {
            if (self::shouldExcludePackage($roster, $package)) {
                continue;
            }

            $dir = self::normalizePackageName($package->name());
            if (is_dir($aiRoot . '/' . $dir)) {
                $allowed[$dir] = true;
                $hostMajors[$dir] = $package->majorVersion();
            }
        }

        return new self($allowed, self::knownPackageDirs(), $hostMajors);
    }

    /**
     * Every guideline dir name that maps to a `Packages` enum case — the
     * universe of composer-package guideline dirs laravel/boost can ship.
     * Used to distinguish "known package the host hasn't installed" (deny)
     * from "not a composer package at all" (pass through).
     *
     * @return array<string, true>
     */
    private static function knownPackageDirs(): array
    {
        $dirs = [];
        foreach (Packages::cases() as $case) {
            $dirs[self::normalizePackageName($case->name)] = true;
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
            // Version-major sub-fragment. laravel/boost's GuidelineComposer
            // composes only the host's installed major for a PACKAGE guideline
            // dir (`getPackageGuidelines` reads `<dir>/{$package->majorVersion()}`
            // only), and composes NO version subdir for non-package dirs (it
            // emits `php/core` but never `php/8.x`). So a version fragment emits
            // iff `<pkg>` is an installed+allowed package and `<version>` is its
            // host major; everything else (wrong major, or a non-package dir
            // like `php`) is suppressed.
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
        return ! (isset($this->knownPackageDirs[$segment]) && ! isset($this->allowedPackageDirs[$segment]));
    }

    /**
     * Mirror of laravel/boost `DiscoverPackagePaths::shouldExcludePackage()`.
     */
    private static function shouldExcludePackage(Roster $roster, Package $package): bool
    {
        if (in_array($package->package(), self::EXCLUDED_PACKAGES, true)) {
            return true;
        }

        foreach (self::packagePriorities() as $priorityPackage => $shadowedPackages) {
            if (in_array($package->package()->value, $shadowedPackages, true)
                && $roster->uses(Packages::from($priorityPackage))) {
                return true;
            }
        }

        return $package->indirect() && in_array($package->package(), self::MUST_BE_DIRECT, true);
    }

    /**
     * When a higher-priority package is present, the lower-priority package is
     * excluded from guidelines — keyed by the winning package's enum value,
     * valued with the enum values it shadows. Mirrors laravel/boost
     * `DiscoverPackagePaths::getPackagePriorities()`.
     *
     * @return array<string, list<string>>
     */
    private static function packagePriorities(): array
    {
        return [
            Packages::PEST->value => [Packages::PHPUNIT->value],
            Packages::FLUXUI_PRO->value => [Packages::FLUXUI_FREE->value],
        ];
    }

    private static function normalizePackageName(string $name): string
    {
        return str_replace('_', '-', strtolower($name));
    }
}
