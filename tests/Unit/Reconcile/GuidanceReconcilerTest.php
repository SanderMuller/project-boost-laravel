<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\ProjectBoostLaravel\Reconcile\CaptureResult;
use SanderMuller\ProjectBoostLaravel\Reconcile\GuidanceFileAnalysis;
use SanderMuller\ProjectBoostLaravel\Reconcile\GuidanceReconciler;
use SanderMuller\ProjectBoostLaravel\Reconcile\ReconcileStatus;

/**
 * Unit coverage for the pure reconcile engine — no Laravel app needed.
 * `GuidanceReconciler` reads agent guidance files from disk, splits
 * laravel/boost's `<laravel-boost-guidelines>` marker body from any
 * hand-authored residual, and captures the residual + backups.
 */
$root = '';

beforeEach(function () use (&$root): void {
    $root = sys_get_temp_dir() . '/pbl-reconcile-' . bin2hex(random_bytes(6));
    mkdir($root, 0o755, recursive: true);
});

afterEach(function () use (&$root): void {
    if ($root !== '' && is_dir($root)) {
        (new Filesystem())->deleteDirectory($root);
    }

    $root = '';
});

/**
 * Build a real config through boost-core's `@api` `BoostConfig::load()` path —
 * write a `boost.php` and load it — rather than constructing `BoostConfig`
 * directly (its constructor + the builder's `build()` are `@internal`).
 *
 * @param  list<Agent>  $agents
 */
function reconcileConfig(string $root, array $agents): BoostConfig
{
    $cases = implode(', ', array_map(static fn (Agent $a): string => 'Agent::' . $a->name, $agents));

    file_put_contents($root . '/boost.php', <<<PHP
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([{$cases}]);
        PHP);

    return BoostConfig::load($root);
}

it('classifies a marker file with hand-edits as foreign-seeded-with-residual', function () use (&$root): void {
    file_put_contents($root . '/AGENTS.md', <<<'MD'
        # My project notes

        Always run the linter before committing.

        <laravel-boost-guidelines>
        === foundation rules ===
        Use strict types.
        </laravel-boost-guidelines>
        MD);

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->files)->toHaveCount(1);
    $file = $plan->files[0];
    expect($file->relativePath)->toBe('AGENTS.md')
        ->and($file->status)->toBe(ReconcileStatus::FOREIGN_SEEDED_WITH_RESIDUAL)
        ->and($file->markerBody)->toContain('foundation rules')
        ->and($file->residual)->toContain('My project notes')
        ->and($file->residual)->toContain('run the linter')
        ->and($file->residual)->not->toContain('laravel-boost-guidelines')
        ->and($file->isAtRisk())->toBeTrue();
});

it('classifies a marker-only file as foreign-seeded with no residual', function () use (&$root): void {
    file_put_contents($root . '/AGENTS.md', <<<'MD'
        <laravel-boost-guidelines>
        === foundation rules ===
        Use strict types.
        </laravel-boost-guidelines>
        MD);

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->files[0]->status)->toBe(ReconcileStatus::FOREIGN_SEEDED)
        ->and($plan->files[0]->hasResidual())->toBeFalse()
        ->and($plan->files[0]->isAtRisk())->toBeTrue();
});

it('classifies a markerless file as clean (boost-owned, safe)', function () use (&$root): void {
    file_put_contents($root . '/AGENTS.md', "# Boost-owned\n\nSome generated guidance.\n");

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->files[0]->status)->toBe(ReconcileStatus::CLEAN)
        ->and($plan->files[0]->isAtRisk())->toBeFalse()
        ->and($plan->hasAtRiskFiles())->toBeFalse();
});

it('classifies an absent file as absent', function () use (&$root): void {
    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->files[0]->status)->toBe(ReconcileStatus::ABSENT)
        ->and($plan->hasAtRiskFiles())->toBeFalse();
});

it('captures residual into .ai/guidelines and backs up the file verbatim', function () use (&$root): void {
    $original = <<<'MD'
        # Team conventions

        Prefer value objects.

        <laravel-boost-guidelines>
        === foundation rules ===
        Use strict types.
        </laravel-boost-guidelines>
        MD;
    file_put_contents($root . '/AGENTS.md', $original);

    $reconciler = new GuidanceReconciler();
    $config = reconcileConfig($root, [Agent::CLAUDE_CODE]);
    $plan = $reconciler->analyze($config, $root);
    $result = $reconciler->capture($plan, $config, $root . '/.boost-reconcile');

    // Verbatim backup
    expect($result->backups)->toHaveCount(1)
        ->and(file_get_contents($root . '/.boost-reconcile/AGENTS.md'))->toBe($original);

    // Residual captured to .ai/guidelines/reconciled.md (re-derivable by sync)
    expect($result->capturedGuidelinePath)->toBe($root . '/.ai/guidelines/reconciled.md');
    $captured = (string) file_get_contents((string) $result->capturedGuidelinePath);
    expect($captured)->toContain('Team conventions')
        ->and($captured)->toContain('Prefer value objects')
        ->and($captured)->toContain('project-boost:reconcile')
        ->and($captured)->not->toContain('foundation rules'); // marker body is NOT captured (sync re-derives it)
});

it('preserves operator edits to reconciled.md on a repeat capture, without duplicating residual', function () use (&$root): void {
    $body = <<<'MD'
        # Conventions

        Prefer value objects.

        <laravel-boost-guidelines>
        === foundation rules ===
        x
        </laravel-boost-guidelines>
        MD;
    file_put_contents($root . '/AGENTS.md', $body);

    $reconciler = new GuidanceReconciler();
    $config = reconcileConfig($root, [Agent::CLAUDE_CODE]);

    // First capture
    $reconciler->capture($reconciler->analyze($config, $root), $config, $root . '/.boost-reconcile');
    $capturePath = $root . '/.ai/guidelines/reconciled.md';

    // Operator edits the capture file (the docs tell them to review/split it).
    file_put_contents($capturePath, file_get_contents($capturePath) . "\nOPERATOR ADDED THIS LINE.\n");

    // A second accidental foreign-seed + reconcile with the SAME residual.
    file_put_contents($root . '/AGENTS.md', $body);
    $reconciler->capture($reconciler->analyze($config, $root), $config, $root . '/.boost-reconcile');

    $captured = (string) file_get_contents($capturePath);
    expect($captured)->toContain('OPERATOR ADDED THIS LINE.')          // edit survived
        ->and(substr_count($captured, 'Prefer value objects'))->toBe(1); // residual not duplicated
});

it('deduplicates identical residual across two agent files into one capture', function () use (&$root): void {
    $body = <<<'MD'
        # Shared notes

        Same hand-edit in both files.

        <laravel-boost-guidelines>
        === foundation rules ===
        x
        </laravel-boost-guidelines>
        MD;
    file_put_contents($root . '/CLAUDE.md', $body);
    file_put_contents($root . '/AGENTS.md', $body);

    $reconciler = new GuidanceReconciler();
    $config = reconcileConfig($root, [Agent::CLAUDE_CODE, Agent::CODEX]);
    $plan = $reconciler->analyze($config, $root);
    $result = $reconciler->capture($plan, $config, $root . '/.boost-reconcile');

    expect($plan->atRiskFiles())->toHaveCount(2)
        ->and($result->backups)->toHaveCount(2);

    // One capture file; the identical residual appears once, not twice.
    $captured = (string) file_get_contents((string) $result->capturedGuidelinePath);
    expect(substr_count($captured, 'Same hand-edit in both files'))->toBe(1);
});

it('also checks a legacy CLAUDE.md seeded by laravel/boost before v2.10 when Claude Code is active', function () use (&$root): void {
    file_put_contents($root . '/CLAUDE.md', <<<'MD'
        # Legacy notes

        <laravel-boost-guidelines>
        === foundation rules ===
        Use strict types.
        </laravel-boost-guidelines>
        MD);

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->atRiskFiles())->toHaveCount(1);
    $file = $plan->atRiskFiles()[0];
    expect($file->relativePath)->toBe('CLAUDE.md')
        ->and($file->legacy)->toBeTrue()
        ->and($file->status)->toBe(ReconcileStatus::FOREIGN_SEEDED_WITH_RESIDUAL)
        ->and($file->residual)->toContain('Legacy notes')
        ->and($file->agents)->toBe([Agent::CLAUDE_CODE]);
});

it('ignores a legacy CLAUDE.md when Claude Code is not active', function () use (&$root): void {
    file_put_contents($root . '/CLAUDE.md', "<laravel-boost-guidelines>\nx\n</laravel-boost-guidelines>\n");

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CODEX]), $root);

    expect($plan->hasAtRiskFiles())->toBeFalse()
        ->and(array_map(static fn (GuidanceFileAnalysis $file): string => $file->relativePath, $plan->files))->toBe(['AGENTS.md']);
});

it('leaves a markerless CLAUDE.md out of the plan', function () use (&$root): void {
    file_put_contents($root . '/CLAUDE.md', "# Hand-written\n\n@AGENTS.md\n");

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect(array_map(static fn (GuidanceFileAnalysis $file): string => $file->relativePath, $plan->files))->toBe(['AGENTS.md'])
        ->and($plan->hasAtRiskFiles())->toBeFalse();
});

it('keeps a legacy CLAUDE.md on capture, and replaces it with an @AGENTS.md import once AGENTS.md exists', function () use (&$root): void {
    $original = <<<'MD'
        # Legacy notes

        <laravel-boost-guidelines>
        === foundation rules ===
        Use strict types.
        </laravel-boost-guidelines>
        MD;
    file_put_contents($root . '/CLAUDE.md', $original);

    $reconciler = new GuidanceReconciler();
    $config = reconcileConfig($root, [Agent::CLAUDE_CODE]);
    $backupDir = $root . '/.boost-reconcile';
    $plan = $reconciler->analyze($config, $root);
    $result = $reconciler->capture($plan, $config, $backupDir);

    expect(file_get_contents($backupDir . '/CLAUDE.md'))->toBe($original)
        ->and(file_get_contents($root . '/CLAUDE.md'))->toBe($original)
        ->and((string) file_get_contents((string) $result->capturedGuidelinePath))->toContain('Legacy notes');

    // No AGENTS.md yet: an import would point at nothing, so the file stays.
    expect($reconciler->retireLegacyFiles($plan, $result, $backupDir, $root))
        ->toBeEmpty()
        ->and(file_get_contents($root . '/CLAUDE.md'))->toBe($original);

    file_put_contents($root . '/AGENTS.md', "# Synced\n");

    expect($reconciler->retireLegacyFiles($plan, $result, $backupDir, $root))->toBe(['CLAUDE.md'])
        ->and(file_get_contents($root . '/CLAUDE.md'))->toBe("@AGENTS.md\n")
        ->and($reconciler->analyze($config, $root)->hasAtRiskFiles())->toBeFalse();
});

it('does not replace a legacy CLAUDE.md that has no backup', function () use (&$root): void {
    $original = "<laravel-boost-guidelines>\nx\n</laravel-boost-guidelines>\n";
    file_put_contents($root . '/CLAUDE.md', $original);
    file_put_contents($root . '/AGENTS.md', "# Synced\n");

    $reconciler = new GuidanceReconciler();
    $plan = $reconciler->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($reconciler->retireLegacyFiles($plan, new CaptureResult([], null), $root . '/.boost-reconcile', $root))
        ->toBeEmpty()
        ->and(file_get_contents($root . '/CLAUDE.md'))->toBe($original);
});

it('does not capture an existing @AGENTS.md import in a legacy CLAUDE.md as residual', function () use (&$root): void {
    file_put_contents($root . '/CLAUDE.md', <<<'MD'
        @AGENTS.md

        <laravel-boost-guidelines>
        x
        </laravel-boost-guidelines>
        MD);

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->atRiskFiles()[0]->status)->toBe(ReconcileStatus::FOREIGN_SEEDED)
        ->and($plan->atRiskFiles()[0]->residual)->toBeNull();
});

it('leaves a symlinked CLAUDE.md out of the plan, so a capture never writes through the link', function () use (&$root): void {
    file_put_contents($root . '/AGENTS.md', "# Notes\n\n<laravel-boost-guidelines>\nx\n</laravel-boost-guidelines>\n");
    symlink($root . '/AGENTS.md', $root . '/CLAUDE.md');

    $reconciler = new GuidanceReconciler();
    $config = reconcileConfig($root, [Agent::CLAUDE_CODE]);
    $plan = $reconciler->analyze($config, $root);
    $reconciler->capture($plan, $config, $root . '/.boost-reconcile');

    expect(array_map(static fn (GuidanceFileAnalysis $file): string => $file->relativePath, $plan->atRiskFiles()))->toBe(['AGENTS.md'])
        ->and(is_link($root . '/CLAUDE.md'))->toBeTrue()
        ->and(file_get_contents($root . '/AGENTS.md'))->toContain('<laravel-boost-guidelines>');
});

it('does not capture a CRLF @AGENTS.md import in a legacy CLAUDE.md as residual', function () use (&$root): void {
    file_put_contents($root . '/CLAUDE.md', "@AGENTS.md\r\n\r\n<laravel-boost-guidelines>\r\nx\r\n</laravel-boost-guidelines>\r\n");

    $plan = (new GuidanceReconciler())->analyze(reconcileConfig($root, [Agent::CLAUDE_CODE]), $root);

    expect($plan->atRiskFiles()[0]->residual)->toBeNull();
});

it('does not report a legacy CLAUDE.md as replaced when it cannot be written', function () use (&$root): void {
    file_put_contents($root . '/CLAUDE.md', "<laravel-boost-guidelines>\nx\n</laravel-boost-guidelines>\n");
    file_put_contents($root . '/AGENTS.md', "# Synced\n");

    $reconciler = new GuidanceReconciler();
    $config = reconcileConfig($root, [Agent::CLAUDE_CODE]);
    $backupDir = $root . '/.boost-reconcile';
    $plan = $reconciler->analyze($config, $root);
    $result = $reconciler->capture($plan, $config, $backupDir);

    chmod($root . '/CLAUDE.md', 0o444);

    try {
        expect($reconciler->retireLegacyFiles($plan, $result, $backupDir, $root))
            ->toBeEmpty();
    } finally {
        chmod($root . '/CLAUDE.md', 0o644);
    }
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can write a read-only file.');
