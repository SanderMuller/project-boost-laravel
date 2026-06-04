<?php declare(strict_types=1);

/**
 * Feature test for `project-boost:where`'s guideline install-gating.
 *
 * Regression: `where` built its `LaravelBoostGuidelineReader` WITHOUT the
 * install-gate that `project-boost:sync` applies, so it over-reported
 * guidelines (inertia/livewire/sail/…) that `sync` suppresses for a host that
 * doesn't have those packages. Both commands now share the gate via the
 * `GatesGuidelines` concern, so `where` reports what `sync` actually emits.
 *
 * The fixture is a hermetic `.ai` tree pointed at via the
 * `project-boost-laravel.laravel_boost_ai_root` override — NOT the real
 * `vendor/laravel/boost/.ai`, which laravel/boost export-ignores (so it is
 * absent from a prefer-dist Composer install, e.g. CI). `foundation` is a core
 * guideline that always ships; `inertia-laravel`/`livewire` map to composer
 * packages the testbench host lacks, so the gate suppresses them.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$whereCwdRef = null;
$whereAiRoot = null;

beforeEach(function () use (&$whereCwdRef, &$whereAiRoot): void {
    $cwd = getcwd();
    $whereCwdRef = $cwd === false ? null : $cwd;
    chdir(base_path());

    $whereAiRoot = base_path('tests-fixture-ai');
    cleanWhereFixtures($whereAiRoot);

    // Minimal hermetic `.ai` guideline tree (no skills needed for this gate
    // assertion). `foundation.blade.php` is a loose core guideline; the package
    // dirs each ship a `core.blade.php` the gate keys on by directory name.
    File::ensureDirectoryExists($whereAiRoot . '/inertia-laravel');
    File::ensureDirectoryExists($whereAiRoot . '/livewire');
    file_put_contents($whereAiRoot . '/foundation.blade.php', 'Foundation guideline body.');
    file_put_contents($whereAiRoot . '/inertia-laravel/core.blade.php', 'Inertia guideline body.');
    file_put_contents($whereAiRoot . '/livewire/core.blade.php', 'Livewire guideline body.');

    config(['project-boost-laravel.laravel_boost_ai_root' => $whereAiRoot]);
});

afterEach(function () use (&$whereCwdRef, &$whereAiRoot): void {
    cleanWhereFixtures($whereAiRoot);
    $whereAiRoot = null;
    if (is_string($whereCwdRef)) {
        chdir($whereCwdRef);
        $whereCwdRef = null;
    }
});

function cleanWhereFixtures(?string $aiRoot): void
{
    foreach ([base_path('boost.php'), base_path('.config/boost.php')] as $file) {
        if (file_exists($file)) {
            File::delete($file);
        }
    }

    if (is_string($aiRoot) && is_dir($aiRoot)) {
        File::deleteDirectory($aiRoot);
    }
}

it('install-gates guidelines like sync — does not list guidelines for packages the host lacks', function (): void {
    file_put_contents(base_path('boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);
        PHP);

    $exit = Artisan::call('project-boost:where');
    $output = Artisan::output();

    // The testbench host has neither inertia-laravel nor livewire installed, so
    // the gate suppresses their guidelines — `where` would have listed them
    // before the fix (no gate). The ungated `foundation` core guideline always
    // ships, proving guidelines ARE being read + listed (not just empty output).
    expect($exit)->toBe(0)
        ->and($output)->toContain('foundation')
        ->and($output)->not->toContain('inertia-laravel-core')
        ->and($output)->not->toContain('livewire-core');
});
