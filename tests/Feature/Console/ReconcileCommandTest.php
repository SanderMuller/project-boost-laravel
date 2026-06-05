<?php declare(strict_types=1);

/**
 * Feature coverage for `project-boost:reconcile` — the guided takeover that
 * captures laravel/boost-seeded agent guidance before a markerless sync would
 * wholesale-overwrite it, plus the `project-boost:sync` foreign-seed warning.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$reconcileCwd = '';

beforeEach(function () use (&$reconcileCwd): void {
    $cwd = getcwd();
    $reconcileCwd = $cwd === false ? '' : $cwd;
    chdir(base_path());
    cleanReconcileFixtures();

    file_put_contents(base_path('boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);
        PHP);
});

afterEach(function () use (&$reconcileCwd): void {
    cleanReconcileFixtures();
    if ($reconcileCwd !== '') {
        chdir($reconcileCwd);
        $reconcileCwd = '';
    }
});

function cleanReconcileFixtures(): void
{
    foreach ([base_path('boost.php'), base_path('.config/boost.php'), base_path('CLAUDE.md')] as $file) {
        if (file_exists($file)) {
            File::delete($file);
        }
    }

    foreach ([base_path('.boost-reconcile'), base_path('.ai')] as $dir) {
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
}

function seedForeignGuidance(): void
{
    file_put_contents(base_path('CLAUDE.md'), <<<'MD'
        # Team conventions

        Always prefer value objects over arrays.

        <laravel-boost-guidelines>
        === foundation rules ===
        Use strict types.
        </laravel-boost-guidelines>
        MD);
}

it('reports nothing to reconcile when no guidance is foreign-seeded', function (): void {
    $exit = Artisan::call('project-boost:reconcile', ['--no-sync' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No laravel/boost-seeded guidance');
});

it('dry-run shows the plan but writes nothing', function (): void {
    seedForeignGuidance();

    $exit = Artisan::call('project-boost:reconcile', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('CLAUDE.md')
        ->and($output)->toContain('Dry run')
        ->and(is_dir(base_path('.boost-reconcile')))->toBeFalse()
        ->and(file_exists(base_path('.ai/guidelines/reconciled.md')))->toBeFalse();
});

it('captures residual + backs up the file, then can skip sync', function (): void {
    seedForeignGuidance();

    $exit = Artisan::call('project-boost:reconcile', ['--no-sync' => true]);

    expect($exit)->toBe(0);

    // Verbatim backup
    expect(file_exists(base_path('.boost-reconcile/CLAUDE.md')))->toBeTrue()
        ->and(file_get_contents(base_path('.boost-reconcile/CLAUDE.md')))->toContain('foundation rules');

    // Hand-authored residual captured for re-derivation; marker body excluded
    $captured = (string) file_get_contents(base_path('.ai/guidelines/reconciled.md'));
    expect($captured)->toContain('Team conventions')
        ->and($captured)->toContain('value objects')
        ->and($captured)->not->toContain('foundation rules');
});

it('end-to-end: captures, then sync regenerates guidance WITHOUT losing the hand-edits', function (): void {
    seedForeignGuidance();

    // Full run: capture residual into .ai/guidelines/, then sync regenerates the
    // (markerless) guidance files from .ai/guidelines/ — including the captured
    // hand-edits. This is the data-loss-prevention guarantee end to end.
    $exit = Artisan::call('project-boost:reconcile', ['--force' => true]);

    expect($exit)->toBe(0);

    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    expect($claude)->toContain('Team conventions')                 // hand-edit survived the wholesale rewrite
        ->and($claude)->toContain('value objects')
        ->and($claude)->not->toContain('<laravel-boost-guidelines>'); // boost-owned now: markerless
});

it('project-boost:sync warns when guidance is foreign-seeded', function (): void {
    seedForeignGuidance();

    $exit = Artisan::call('project-boost:sync');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('laravel/boost-seeded content')
        ->and($output)->toContain('project-boost:reconcile')
        ->and($output)->toContain('has hand-edits');
});
