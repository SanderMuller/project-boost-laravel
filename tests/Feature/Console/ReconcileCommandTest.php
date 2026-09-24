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
    foreach ([base_path('boost.php'), base_path('.config/boost.php'), base_path('CLAUDE.md'), base_path('AGENTS.md')] as $file) {
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

/**
 * Seeds `CLAUDE.md`, where laravel/boost before v2.10 put its guidelines for
 * Claude Code. boost-core 1.12+ writes Claude Code guidance to `AGENTS.md`.
 */
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

    // No sync ran, so the legacy CLAUDE.md stays readable.
    expect(file_get_contents(base_path('CLAUDE.md')))->toContain('foundation rules')
        ->and(Artisan::output())->toContain('Run project-boost:reconcile again after that sync');

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

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('with an `@AGENTS.md` import');

    $agents = (string) file_get_contents(base_path('AGENTS.md'));
    expect($agents)->toContain('Team conventions')                 // hand-edit survived into the synced guidance
        ->and($agents)->toContain('value objects')
        ->and($agents)->not->toContain('<laravel-boost-guidelines>'); // boost-owned: markerless

    // The legacy CLAUDE.md now imports AGENTS.md, so Claude Code reads the synced guidance.
    expect(file_get_contents(base_path('CLAUDE.md')))->toBe("@AGENTS.md\n");

    // The next sync has nothing to warn about: boost-core's shadow check stays quiet.
    Artisan::call('project-boost:sync');
    expect(Artisan::output())->not->toContain('but `CLAUDE.md` exists')
        ->and(Artisan::output())->not->toContain('laravel/boost-seeded content');
});

it('project-boost:sync warns when guidance is foreign-seeded', function (): void {
    seedForeignGuidance();

    $exit = Artisan::call('project-boost:sync');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('laravel/boost-seeded content')
        ->and($output)->toContain('Claude Code reads it instead of AGENTS.md')
        ->and($output)->toContain('project-boost:reconcile')
        ->and($output)->toContain('has hand-edits');
});
