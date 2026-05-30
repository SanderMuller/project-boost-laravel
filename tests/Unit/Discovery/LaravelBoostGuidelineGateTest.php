<?php declare(strict_types=1);

use Laravel\Roster\Enums\Packages;
use Laravel\Roster\Package;
use Laravel\Roster\Roster;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineGate;

/**
 * Build a Roster with the given packages. Each entry is [Packages enum, direct].
 *
 * @param  list<array{0: Packages, 1: bool}>  $packages
 */
function gateRoster(array $packages): Roster
{
    $roster = new Roster();
    foreach ($packages as [$enum, $direct]) {
        $roster->add((new Package($enum, $enum->value, '1.0.0'))->setDirect($direct));
    }

    return $roster;
}

/**
 * Create a fixture `.ai/` root containing the given package dirs (each with a
 * `core.blade.php`) plus the always-present loose core files.
 *
 * @param  list<string>  $packageDirs
 */
function gateAiRoot(array $packageDirs): string
{
    $root = sys_get_temp_dir() . '/project-boost-laravel-gate-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, true);

    // Loose + dir-based core guidelines laravel/boost always composes.
    file_put_contents("{$root}/foundation.blade.php", 'foundation');
    foreach (['php', 'boost', 'deployments'] as $core) {
        mkdir("{$root}/{$core}", 0o755, true);
        file_put_contents("{$root}/{$core}/core.blade.php", $core);
    }

    foreach ($packageDirs as $dir) {
        mkdir("{$root}/{$dir}", 0o755, true);
        file_put_contents("{$root}/{$dir}/core.blade.php", $dir);
    }

    return $root;
}

function removeGateFixture(string $path): void
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
    $dirs = glob(sys_get_temp_dir() . '/project-boost-laravel-gate-*');
    if ($dirs === false) {
        return;
    }

    foreach ($dirs as $dir) {
        removeGateFixture($dir);
    }
});

test('permissive gate allows everything', function (): void {
    $gate = LaravelBoostGuidelineGate::permissive();

    expect($gate->allows('foundation'))->toBeTrue()
        ->and($gate->allows('inertia-laravel'))->toBeTrue()
        ->and($gate->allows('sail'))->toBeTrue()
        ->and($gate->allows('anything-at-all'))->toBeTrue();
});

test('core segments are always allowed regardless of roster', function (): void {
    $root = gateAiRoot([]);
    $gate = LaravelBoostGuidelineGate::fromRoster(gateRoster([]), $root);

    expect($gate->allows('foundation'))->toBeTrue()
        ->and($gate->allows('php'))->toBeTrue()
        ->and($gate->allows('boost'))->toBeTrue()
        ->and($gate->allows('deployments'))->toBeTrue();
});

test('denies a package dir the host has not installed', function (): void {
    $root = gateAiRoot(['phpunit', 'inertia-laravel']);
    $gate = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::PHPUNIT, true]]),
        $root,
    );

    expect($gate->allows('phpunit'))->toBeTrue()
        ->and($gate->allows('inertia-laravel'))->toBeFalse();
});

test('PEST shadows PHPUNIT when both installed (priority exclusion)', function (): void {
    $root = gateAiRoot(['pest', 'phpunit']);
    $gate = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::PEST, true], [Packages::PHPUNIT, true]]),
        $root,
    );

    expect($gate->allows('pest'))->toBeTrue()
        ->and($gate->allows('phpunit'))->toBeFalse();
});

test('FLUXUI_PRO shadows FLUXUI_FREE when both installed', function (): void {
    $root = gateAiRoot(['fluxui-pro', 'fluxui-free']);
    $gate = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::FLUXUI_PRO, true], [Packages::FLUXUI_FREE, true]]),
        $root,
    );

    expect($gate->allows('fluxui-pro'))->toBeTrue()
        ->and($gate->allows('fluxui-free'))->toBeFalse();
});

test('SAIL is excluded from package discovery even when installed', function (): void {
    $root = gateAiRoot(['sail']);
    $gate = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::SAIL, true]]),
        $root,
    );

    expect($gate->allows('sail'))->toBeFalse();
});

test('LIVEWIRE counts only as a direct requirement', function (): void {
    $root = gateAiRoot(['livewire']);

    $indirect = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::LIVEWIRE, false]]),
        $root,
    );
    $direct = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::LIVEWIRE, true]]),
        $root,
    );

    expect($indirect->allows('livewire'))->toBeFalse()
        ->and($direct->allows('livewire'))->toBeTrue();
});

test('a package whose dir is absent from .ai is not allowed', function (): void {
    // Pennant installed but the fixture ships no pennant dir.
    $root = gateAiRoot(['phpunit']);
    $gate = LaravelBoostGuidelineGate::fromRoster(
        gateRoster([[Packages::PENNANT, true], [Packages::PHPUNIT, true]]),
        $root,
    );

    expect($gate->allows('pennant'))->toBeFalse()
        ->and($gate->allows('phpunit'))->toBeTrue();
});
