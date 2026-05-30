<?php declare(strict_types=1);

use Laravel\Roster\Enums\Packages;
use Laravel\Roster\Package;
use Laravel\Roster\Roster;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineGate;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;

function guidelineFixtureRoot(): string
{
    $root = sys_get_temp_dir() . '/project-boost-laravel-greader-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, true);

    return $root;
}

function writeGuideline(string $root, string $relative, string $content = 'body'): void
{
    $path = "{$root}/{$relative}";
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents($path, $content);
}

function passthroughBladeRenderer(): SkillRenderer
{
    return new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return $raw;
        }
    };
}

/** @param  list<array{0: Packages, 1: bool}>  $packages */
function readerRoster(array $packages): Roster
{
    $roster = new Roster();
    foreach ($packages as [$enum, $direct]) {
        $roster->add((new Package($enum, $enum->value, '1.0.0'))->setDirect($direct));
    }

    return $roster;
}

function removeReaderFixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }

    @rmdir($path);
}

afterEach(function (): void {
    $dirs = glob(sys_get_temp_dir() . '/project-boost-laravel-greader-*');
    if ($dirs === false) {
        return;
    }

    foreach ($dirs as $dir) {
        removeReaderFixture($dir);
    }
});

test('with no gate, emits every guideline the Finder walks (pre-gate behaviour)', function (): void {
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'foundation.blade.php');
    writeGuideline($root, 'phpunit/core.blade.php');
    writeGuideline($root, 'pest/core.blade.php');
    writeGuideline($root, 'inertia-laravel/core.blade.php');

    $reader = new LaravelBoostGuidelineReader($root, new LaravelBoostTagManifest(), passthroughBladeRenderer());

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());
    sort($names);

    expect($names)->toBe(['foundation', 'inertia-laravel-core', 'pest-core', 'phpunit-core']);
});

test('install gate suppresses guidelines for packages the host has not installed', function (): void {
    // The collectiq reproducer: a PHPUnit app must NOT receive pest / inertia
    // guidelines (pest-core actively contradicts phpunit-core).
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'foundation.blade.php');
    writeGuideline($root, 'phpunit/core.blade.php');
    writeGuideline($root, 'pest/core.blade.php');
    writeGuideline($root, 'inertia-laravel/core.blade.php');

    $gate = LaravelBoostGuidelineGate::fromRoster(
        readerRoster([[Packages::PHPUNIT, true]]),
        $root,
    );

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());
    sort($names);

    expect($names)->toBe(['foundation', 'phpunit-core']);
});

test('install gate keeps non-composer-package guidelines like herd while gating uninstalled packages', function (): void {
    // Regression: herd is not a composer package, so the gate must not drop
    // herd-core (laravel/boost gates it on runtime detection). collectiq lost
    // herd-core on 0.4.0 despite serving via Herd.
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'foundation.blade.php');
    writeGuideline($root, 'herd/core.blade.php');
    writeGuideline($root, 'phpunit/core.blade.php');
    writeGuideline($root, 'inertia-laravel/core.blade.php');

    $gate = LaravelBoostGuidelineGate::fromRoster(
        readerRoster([[Packages::PHPUNIT, true]]),
        $root,
    );

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());
    sort($names);

    // herd-core kept (non-package), inertia gated (uninstalled package).
    expect($names)->toBe(['foundation', 'herd-core', 'phpunit-core']);
});

test('install gate applies PEST-over-PHPUNIT priority to per-package guidelines', function (): void {
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'pest/core.blade.php');
    writeGuideline($root, 'phpunit/core.blade.php');

    $gate = LaravelBoostGuidelineGate::fromRoster(
        readerRoster([[Packages::PEST, true], [Packages::PHPUNIT, true]]),
        $root,
    );

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());

    expect($names)->toBe(['pest-core']);
});

test('install gate keeps per-major guideline files for an installed package', function (): void {
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'livewire/core.blade.php');
    writeGuideline($root, 'livewire/3/testing.blade.php');

    $gate = LaravelBoostGuidelineGate::fromRoster(
        readerRoster([[Packages::LIVEWIRE, true]]),
        $root,
    );

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());
    sort($names);

    expect($names)->toBe(['livewire-3-testing', 'livewire-core']);
});
