<?php declare(strict_types=1);

/**
 * Feature tests for `project-boost:install` covering the TTY vs
 * non-TTY branching matrix. Runs against the Testbench-bootstrapped
 * workbench app + laravel/boost's actual ServiceProvider so writer
 * resolution and AgentsDetector lookup exercise real wiring.
 *
 * Each test that materialises a `boost.php` or any generated MCP
 * config under `base_path()` cleans up in its own teardown — the
 * workbench is shared state, can't leak between tests.
 */

use Illuminate\Support\Facades\File;

// Module-level cwd stack shared by reference between beforeEach + afterEach.
// Avoids `$this->originalCwd` which phpstan can't see through Pest's
// closure-binding mechanics — `$this` resolves to `Pest\PendingCalls\TestCall`
// for static analysis even though Pest binds the closure to the TestCase
// at runtime.
$cwdRef = null;

beforeEach(function () use (&$cwdRef): void {
    // laravel/boost's `FileWriter` resolves `.mcp.json` (and other agent
    // config files) against PHP's cwd, not `base_path()`. In a Pest /
    // Testbench feature test the package's repo root IS the cwd, so a
    // straight artisan call would write the test's `.mcp.json` into the
    // tracked package files. chdir to the testbench `base_path()` for
    // the duration of each test so writes land in the workbench
    // (gitignored) instead.
    $currentCwd = getcwd();
    $cwdRef = $currentCwd === false ? null : $currentCwd;
    chdir(base_path());
    cleanWorkbenchFixtures();
});

afterEach(function () use (&$cwdRef): void {
    cleanWorkbenchFixtures();
    if (is_string($cwdRef)) {
        chdir($cwdRef);
        $cwdRef = null;
    }
});

function cleanWorkbenchFixtures(): void
{
    foreach ([
        base_path('boost.php'),
        base_path('.mcp.json'),
    ] as $file) {
        if (file_exists($file)) {
            File::delete($file);
        }
    }

    foreach ([
        base_path('.claude'),
        base_path('.cursor'),
        base_path('.codex'),
    ] as $dir) {
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
}

function writeBoostPhp(string $body): void
{
    file_put_contents(base_path('boost.php'), <<<PHP
        <?php declare(strict_types=1);

        use SanderMuller\\BoostCore\\Config\\BoostConfig;
        use SanderMuller\\BoostCore\\Enums\\Agent;

        return {$body};
        PHP);
}

describe('project-boost:install · non-interactive', function (): void {
    it('fails with a hint when boost.php is missing', function (): void {
        $this->artisan('project-boost:install', ['--no-interaction' => true])
            ->expectsOutputToContain('No boost.php found')
            ->expectsOutputToContain('Create one with at least')
            ->assertExitCode(1);
    });

    it('warns and exits success when boost.php declares no agents', function (): void {
        writeBoostPhp('BoostConfig::configure()->withAgents([])');

        $this->artisan('project-boost:install', [
            '--no-interaction' => true,
            '--no-sync' => true,
        ])
            ->expectsOutputToContain('declares no agents')
            ->assertExitCode(0);
    });

    it('writes .mcp.json for a CLAUDE_CODE agent and skips sync when --no-sync', function (): void {
        writeBoostPhp('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');

        // `expectsOutputToContain` consumes one line per call (walks the
        // output buffer sequentially); chaining matches to the same line
        // silently fails. One assertion per distinct line.
        $this->artisan('project-boost:install', [
            '--no-interaction' => true,
            '--no-sync' => true,
        ])
            ->expectsOutputToContain('wrote MCP config for claude-code')
            ->expectsOutputToContain('Skipping project-boost:sync')
            ->assertExitCode(0);

        // `.mcp.json` is written by laravel/boost's `FileWriter` to a path
        // relative to PHP's cwd. The `beforeEach` chdir'd to `base_path()`,
        // so the file should land in the workbench root.
        expect(file_exists(base_path('.mcp.json')))->toBeTrue()
            ->and(file_get_contents(base_path('.mcp.json')))
            ->toContain('laravel-boost');
    });

    it('reports rsync-style summary line with per-action counts', function (): void {
        writeBoostPhp('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');

        // Single assertion on the unique-to-summary fragment that pins
        // all three counts on one line. `expectsOutputToContain` matches
        // one output line; checking each `wrote=` / `skipped=` / `failed=`
        // separately would consume distinct lines, not the same one.
        $this->artisan('project-boost:install', [
            '--no-interaction' => true,
            '--no-sync' => true,
        ])
            ->expectsOutputToContain('MCP config · wrote=1 · skipped=0 · failed=0')
            ->assertExitCode(0);
    });
});
