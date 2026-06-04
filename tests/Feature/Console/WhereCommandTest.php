<?php declare(strict_types=1);

/**
 * Feature test for `project-boost:where`'s guideline install-gating.
 *
 * Regression: `where` built its `LaravelBoostGuidelineReader` WITHOUT the
 * install-gate that `project-boost:sync` applies, so it over-reported
 * guidelines (inertia/livewire/sail/…) that `sync` suppresses for a host that
 * doesn't have those packages. Both commands now share the gate via the
 * `GatesGuidelines` concern, so `where` reports what `sync` actually emits.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$whereCwdRef = null;

beforeEach(function () use (&$whereCwdRef): void {
    $cwd = getcwd();
    $whereCwdRef = $cwd === false ? null : $cwd;
    chdir(base_path());
    cleanWhereFixtures();
});

afterEach(function () use (&$whereCwdRef): void {
    cleanWhereFixtures();
    if (is_string($whereCwdRef)) {
        chdir($whereCwdRef);
        $whereCwdRef = null;
    }
});

function cleanWhereFixtures(): void
{
    foreach ([base_path('boost.php'), base_path('.config/boost.php')] as $file) {
        if (file_exists($file)) {
            File::delete($file);
        }
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
