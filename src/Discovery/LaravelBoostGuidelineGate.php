<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use Laravel\Roster\Enums\Packages;
use Laravel\Roster\Package;
use Laravel\Roster\Roster;

/**
 * Install-gate for laravel/boost guideline emission. Mirrors laravel/boost's
 * `GuidelineComposer` detection: only the core guidelines plus the guidelines
 * for packages actually installed in the host project are emitted, applying the
 * same priority + exclusion rules laravel/boost uses —
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
 * `<name>.blade.php`); conditional guidelines laravel/boost gates on
 * interactive install config (`herd`, `sail`, `enforce-tests`, the
 * `laravel/style|api|localization` variants) are not in the allow-set and so
 * stay out — matching laravel/boost, which only emits them when its
 * `GuidelineConfig` conditions hold.
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
     */
    private function __construct(
        private ?array $allowedPackageDirs,
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

        foreach ($roster->packages() as $package) {
            if (self::shouldExcludePackage($roster, $package)) {
                continue;
            }

            $dir = self::normalizePackageName($package->name());
            if (is_dir($aiRoot . '/' . $dir)) {
                $allowed[$dir] = true;
            }
        }

        return new self($allowed);
    }

    /**
     * @param  string  $segment  top-level `.ai/` path segment for a guideline
     *   (dir name for `<pkg>/core.blade.php`, or basename without extension for
     *   a loose `<name>.blade.php`).
     */
    public function allows(string $segment): bool
    {
        if ($this->allowedPackageDirs === null) {
            return true;
        }

        if (in_array($segment, self::CORE_SEGMENTS, true)) {
            return true;
        }

        return isset($this->allowedPackageDirs[$segment]);
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
