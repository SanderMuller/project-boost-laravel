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

/** @param  list<array{0: Packages, 1: bool, 2?: string}>  $packages */
function readerRoster(array $packages): Roster
{
    $roster = new Roster();
    foreach ($packages as $entry) {
        [$enum, $direct] = $entry;
        $version = $entry[2] ?? '1.0.0';
        $roster->add((new Package($enum, $enum->value, $version))->setDirect($direct));
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

test('install gate keeps the host-major per-major guideline files for an installed package', function (): void {
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'livewire/core.blade.php');
    writeGuideline($root, 'livewire/3/testing.blade.php');

    // Host on Livewire 3 → the livewire/3 fragment is the host major.
    $gate = LaravelBoostGuidelineGate::fromRoster(
        readerRoster([[Packages::LIVEWIRE, true, '3.0.0']]),
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

test('install gate scopes laravel to host major and php to the declared floor', function (): void {
    // Laravel = exact host major; php = cumulative <= floor.
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'laravel/core.blade.php');
    writeGuideline($root, 'laravel/11/core.blade.php');
    writeGuideline($root, 'laravel/12/core.blade.php');
    writeGuideline($root, 'php/core.blade.php');
    writeGuideline($root, 'php/8.5/core.blade.php');

    // Host on Laravel 12, PHP floor 8.3.
    $gate = LaravelBoostGuidelineGate::fromRoster(
        readerRoster([[Packages::LARAVEL, true, '12.0.0']]),
        $root,
        '8.3',
    );

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());
    sort($names);

    // laravel-12 kept (host major); laravel-11 dropped (wrong major);
    // php-core kept (core); php-8.5 dropped (8.5 > floor 8.3).
    expect($names)->toBe(['laravel-12-core', 'laravel-core', 'php-core']);
});

test('install gate keeps php version fragments at or below the declared floor', function (): void {
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'php/core.blade.php');
    writeGuideline($root, 'php/8.4/core.blade.php');
    writeGuideline($root, 'php/8.5/core.blade.php');
    writeGuideline($root, 'php/8.6/core.blade.php');

    // PHP floor 8.5 → keep 8.4 + 8.5 (cumulative), drop 8.6 (above range).
    $gate = LaravelBoostGuidelineGate::fromRoster(readerRoster([]), $root, '8.5');

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());
    sort($names);

    expect($names)->toBe(['php-8.4-core', 'php-8.5-core', 'php-core']);
});

test('reader skips guidelines that render empty (filled() mirror)', function (): void {
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'php/core.blade.php', 'real content');
    writeGuideline($root, 'php/8.5/core.blade.php', '   '); // whitespace-only → empty

    // Floor 8.5 keeps php/8.5, but it renders empty → reader drops it anyway.
    $gate = LaravelBoostGuidelineGate::fromRoster(readerRoster([]), $root, '8.5');

    $reader = new LaravelBoostGuidelineReader(
        $root,
        new LaravelBoostTagManifest(),
        passthroughBladeRenderer(),
        $gate,
    );

    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());

    expect($names)->toBe(['php-core']);
});

test('emits guidelines in deterministic lexicographic order regardless of write order', function (): void {
    // Cross-OS determinism: Symfony Finder yields in filesystem-iteration order
    // (APFS hash order vs ext4 readdir order) unless sortByName() is set. Without
    // it, the guideline set reaches SyncEngine in a different order per OS and
    // CLAUDE.md regenerates with a content-free section reorder — observed as a
    // 157-line churn diff + CI auto-fix loop on a downstream consumer. The reader
    // must hand back a stable, OS-independent order. Files are written here in
    // reverse-lexicographic order so a naive (unsorted) walk would surface it.
    $root = guidelineFixtureRoot();
    writeGuideline($root, 'phpunit/core.blade.php');
    writeGuideline($root, 'pest/core.blade.php');
    writeGuideline($root, 'inertia-laravel/core.blade.php');
    writeGuideline($root, 'foundation.blade.php');

    $reader = new LaravelBoostGuidelineReader($root, new LaravelBoostTagManifest(), passthroughBladeRenderer());

    // No sort() on the result — assert the reader's NATIVE emission order is
    // already lexicographic by source path.
    $names = array_map(fn (Guideline $g): string => $g->name, $reader->readGuidelines());

    expect($names)->toBe(['foundation', 'inertia-laravel-core', 'pest-core', 'phpunit-core']);
});
