<?php declare(strict_types=1);
use Laravel\Roster\Ecosystems\Ecosystem;
use Laravel\Roster\Ecosystems\JsEcosystem;
use Laravel\Roster\Enums\PackageSource;
use Laravel\Roster\Package;
use Laravel\Roster\PackageCollection;
use Laravel\Roster\ProjectScan;
use Laravel\Roster\Support\EnumSet;
use SanderMuller\ProjectBoostLaravel\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a
| specific PHPUnit test case class. By default, that class is
| `PHPUnit\Framework\TestCase`. For Laravel-aware packages, the bootstrap
| phase replaces this with `Orchestra\Testbench\TestCase` for Feature
| tests, or a project-specific test case.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Project-specific custom expectations go here. Pest's documentation has
| examples: https://pestphp.com/docs/custom-expectations
|
*/

// expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Global helper functions used across multiple test files go here. Prefer
| Pest's higher-order test syntax over loose helpers where possible.
|
*/

/**
 * Build a `ProjectScan` fixture from a flat list of php-ecosystem packages.
 *
 * Each entry is `[composer name, direct, optional version (default 1.0.0)]` —
 * e.g. `[['pestphp/pest', true], ['laravel/framework', true, '12.0.0']]`. Use
 * the `Laravel\Boost\Support\PackageRegistry` constants for the names the gate
 * treats specially, so a rename upstream surfaces here.
 *
 * Only the php ecosystem is populated; `$js` covers the npm side for the few
 * cases that need it (`@inertiajs/*`, `tailwindcss`). The detector EnumSets are
 * all empty — nothing under test reads them.
 *
 * @param  list<array{0: string, 1: bool, 2?: string}>  $php
 * @param  list<array{0: string, 1: bool, 2?: string}>  $js
 */
function scanWithPackages(array $php, array $js = []): ProjectScan
{
    return new ProjectScan(
        '/fixture',
        new Ecosystem(packageCollection($php)),
        new JsEcosystem(packageCollection($js), null),
        new EnumSet([]),
        new EnumSet([]),
        new EnumSet([]),
        new EnumSet([]),
        new EnumSet([]),
    );
}

/**
 * @param  list<array{0: string, 1: bool, 2?: string}>  $packages
 * @return PackageCollection<int, Package>
 */
function packageCollection(array $packages): PackageCollection
{
    $collection = new PackageCollection();

    foreach ($packages as $entry) {
        [$name, $direct] = $entry;

        $collection->push(new Package(
            name: $name,
            version: $entry[2] ?? '1.0.0',
            source: PackageSource::Composer,
            dev: false,
            direct: $direct,
        ));
    }

    return $collection;
}
