<?php declare(strict_types=1);

use Laravel\Boost\Install\Concerns\DiscoverPackagePaths;
use Laravel\Boost\Support\PackageRegistry;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineGate;

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

test('exclusion and must-be-direct lists still match laravel/boost', function (): void {
    // This gate is a 1:1 mirror of DiscoverPackagePaths. Nothing else notices
    // when boost changes the policy underneath us — and unlike the class/enum
    // removal that forced the roster 1.0 rewrite, a changed array drifts
    // silently: no fatal, just guidelines quietly emitted or suppressed.
    $boost = (new ReflectionClass(DiscoverPackagePaths::class))->getDefaultProperties();
    $gate = (new ReflectionClass(LaravelBoostGuidelineGate::class))->getConstants();

    expect($gate['EXCLUDED_PACKAGES'])->toBe($boost['excludedPackages'])
        ->and($gate['MUST_BE_DIRECT'])->toBe($boost['mustBeDirect']);
});

test('core segments are always allowed regardless of roster', function (): void {
    $root = gateAiRoot([]);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(scanWithPackages([]), $root);

    expect($gate->allows('foundation'))->toBeTrue()
        ->and($gate->allows('php'))->toBeTrue()
        ->and($gate->allows('boost'))->toBeTrue()
        ->and($gate->allows('deployments'))->toBeTrue();
});

test('denies a package dir the host has not installed', function (): void {
    $root = gateAiRoot(['phpunit', 'inertia-laravel']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::PHPUNIT, true]]),
        $root,
    );

    expect($gate->allows('phpunit'))->toBeTrue()
        ->and($gate->allows('inertia-laravel'))->toBeFalse();
});

test('PEST shadows PHPUNIT when both installed (priority exclusion)', function (): void {
    $root = gateAiRoot(['pest', 'phpunit']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::PEST, true], [PackageRegistry::PHPUNIT, true]]),
        $root,
    );

    expect($gate->allows('pest'))->toBeTrue()
        ->and($gate->allows('phpunit'))->toBeFalse();
});

test('FLUXUI_PRO shadows FLUXUI_FREE when both installed', function (): void {
    $root = gateAiRoot(['fluxui-pro', 'fluxui-free']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::FLUXUI_PRO, true], [PackageRegistry::FLUXUI_FREE, true]]),
        $root,
    );

    expect($gate->allows('fluxui-pro'))->toBeTrue()
        ->and($gate->allows('fluxui-free'))->toBeFalse();
});

test('SAIL is excluded from package discovery even when installed', function (): void {
    $root = gateAiRoot(['sail']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::SAIL, true]]),
        $root,
    );

    expect($gate->allows('sail'))->toBeFalse();
});

test('LIVEWIRE counts only as a direct requirement', function (): void {
    $root = gateAiRoot(['livewire']);

    $indirect = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::LIVEWIRE, false]]),
        $root,
    );
    $direct = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::LIVEWIRE, true]]),
        $root,
    );

    expect($indirect->allows('livewire'))->toBeFalse()
        ->and($direct->allows('livewire'))->toBeTrue();
});

test('passes non-composer-package segments like herd (no false guidance loss)', function (): void {
    // herd is a runtime/dev tool, not a composer package — laravel/boost gates
    // herd-core on runtime detection (.test URL + Herd binary), which a
    // package-presence gate has no signal for. Dropping it loses guidance with
    // no withExcludedGuidelines add-back lever, so the gate must pass it.
    // (collectiq regression report against 0.4.0.)
    $root = gateAiRoot(['herd', 'inertia-laravel']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(scanWithPackages([]), $root);

    expect($gate->allows('herd'))->toBeTrue()
        ->and($gate->allows('enforce-tests'))->toBeTrue()
        ->and($gate->allows('inertia-laravel'))->toBeFalse();
});

test('a package whose dir is absent from .ai composes no version fragment', function (): void {
    // Pennant installed but the fixture ships no pennant dir, so it never
    // enters $allowedPackageDirs and gets no entry in $hostMajors — no
    // `pennant/<major>/…` fragment can match.
    //
    // The top-level `pennant` segment passes through rather than being denied:
    // the known-package universe is derived from the dirs laravel/boost ships,
    // and a dir that doesn't exist can't be walked by the reader in the first
    // place, so the question is unreachable in production. Denying it would
    // mean guessing about a segment we have no guideline file for.
    $root = gateAiRoot(['phpunit']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([['laravel/pennant', true], [PackageRegistry::PHPUNIT, true]]),
        $root,
    );

    expect($gate->allows('pennant', '1'))->toBeFalse()
        ->and($gate->allows('phpunit'))->toBeTrue();
});

test('gates a js-ecosystem package dir on the npm side of the scan', function (): void {
    // `.ai/` carries npm-package guideline dirs too (inertia-*, tailwindcss),
    // and boost's own discovery concats php + js. Scanning only php would
    // silently drop every one of them.
    $root = gateAiRoot(['inertia-vue', 'inertia-react']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([], [['@inertiajs/vue3', true, '2.1.0']]),
        $root,
    );

    expect($gate->allows('inertia-vue'))->toBeTrue()
        ->and($gate->allows('inertia-vue', '2'))->toBeTrue()
        ->and($gate->allows('inertia-react'))->toBeFalse();
});

test('a package Roster resolved without a version composes no version fragment', function (): void {
    // Package::major() is null for an empty version. The dir still emits its
    // top-level core guideline — we know the package is there — but no
    // `<pkg>/<major>/…` fragment can be claimed for a major we don't know.
    $root = gateAiRoot(['laravel']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::LARAVEL, true, '']]),
        $root,
    );

    expect($gate->allows('laravel'))->toBeTrue()
        ->and($gate->allows('laravel', '12'))->toBeFalse()
        ->and($gate->allows('laravel', ''))->toBeFalse();
});

test('an unreadable .ai root yields an empty universe, so nothing is denied', function (): void {
    // Never-lossy fallback: with no dirs to enumerate the gate has no basis to
    // call any segment a known-but-uninstalled package.
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([]),
        sys_get_temp_dir() . '/project-boost-laravel-absent-' . bin2hex(random_bytes(8)),
    );

    expect($gate->allows('phpunit'))->toBeTrue()
        ->and($gate->allows('foundation'))->toBeTrue();
});

test('scopes package version-major fragments to the host installed major', function (): void {
    // laravel/boost composes only `<dir>/{majorVersion}` for a package guideline
    // dir, so laravel/12 emits on a Laravel 12 host while laravel/11 is dropped.
    $root = gateAiRoot(['laravel']);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(
        scanWithPackages([[PackageRegistry::LARAVEL, true, '12.0.0']]),
        $root,
    );

    expect($gate->allows('laravel'))->toBeTrue()           // top-level core
        ->and($gate->allows('laravel', '12'))->toBeTrue()  // host major
        ->and($gate->allows('laravel', '11'))->toBeFalse(); // wrong major
});

test('scopes php version dirs cumulative-downward to the declared floor', function (): void {
    // php/8.x is cumulative (8.4 features usable on 8.5), so keep every
    // php/<v> with v <= floor; drop versions above the supported range.
    $root = gateAiRoot([]);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(scanWithPackages([]), $root, '8.5');

    expect($gate->allows('php'))->toBeTrue()            // php/core: core segment
        ->and($gate->allows('php', '8.4'))->toBeTrue()  // <= floor
        ->and($gate->allows('php', '8.5'))->toBeTrue()  // == floor
        ->and($gate->allows('php', '8.6'))->toBeFalse(); // > floor
});

test('a lower php floor drops higher version dirs', function (): void {
    $root = gateAiRoot([]);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(scanWithPackages([]), $root, '8.3');

    expect($gate->allows('php', '8.3'))->toBeTrue()
        ->and($gate->allows('php', '8.4'))->toBeFalse()
        ->and($gate->allows('php', '8.5'))->toBeFalse();
});

test('keeps all php version dirs when the floor is unknown (never-lossy)', function (): void {
    $root = gateAiRoot([]);
    $gate = LaravelBoostGuidelineGate::fromProjectScan(scanWithPackages([]), $root);

    expect($gate->allows('php', '8.4'))->toBeTrue()
        ->and($gate->allows('php', '8.5'))->toBeTrue()
        ->and($gate->allows('php', '8.6'))->toBeTrue();
});

test('parsePhpFloor extracts the lowest major.minor from a require.php constraint', function (): void {
    expect(LaravelBoostGuidelineGate::parsePhpFloor('^8.3'))->toBe('8.3')
        ->and(LaravelBoostGuidelineGate::parsePhpFloor('>=8.2'))->toBe('8.2')
        ->and(LaravelBoostGuidelineGate::parsePhpFloor('^8.2 || ^8.4'))->toBe('8.2')
        ->and(LaravelBoostGuidelineGate::parsePhpFloor('8.5.*'))->toBe('8.5')
        ->and(LaravelBoostGuidelineGate::parsePhpFloor('^8'))->toBeNull();
});
