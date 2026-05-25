<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;

/**
 * Thin wrapper around laravel/boost's `boost:install` that scopes it to
 * `--mcp` only — so laravel/boost still wires the MCP client config (the
 * one thing only it can do), but skips `GuidelineWriter` + `SkillWriter`.
 * This package then owns the actual `.{agent}/skills/` + `CLAUDE.md` fanout
 * via `project-boost:sync`.
 *
 * Without this wrapper, running interactive `boost:install` re-introduces
 * the parallel-writer collision the companion package exists to avoid.
 *
 * Caveat: laravel/boost's installer still runs interactive `multiselect`
 * prompts for integrations (cloud/sail/nightwatch) and agents even with
 * `--mcp` — `--mcp` only short-circuits feature selection, not the
 * downstream pickers. Requires a TTY; selecting an integration like
 * `cloud` re-engages laravel/boost's writer for that integration's
 * artifacts. CI / non-TTY use needs a different strategy (write
 * `.mcp.json` directly, or wrap with input piped from `printf`).
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'project-boost:install
        {--no-sync : Skip the project-boost:sync step after installing MCP config.}';

    /** @var string */
    protected $description = 'Install laravel/boost MCP client config and run project-boost:sync.';

    public function handle(): int
    {
        $this->info('Wiring laravel/boost MCP config (skipping its guidelines/skills writers — companion owns those)…');

        $mcpExit = $this->call('boost:install', ['--mcp' => true]);
        if ($mcpExit !== self::SUCCESS) {
            $this->error('boost:install --mcp failed. Aborting.');

            return self::FAILURE;
        }

        if ($this->option('no-sync')) {
            $this->line('Skipping project-boost:sync (per --no-sync). Run it manually when ready.');

            return self::SUCCESS;
        }

        return $this->call('project-boost:sync');
    }
}
