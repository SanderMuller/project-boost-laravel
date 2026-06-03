<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use Laravel\Boost\Contracts\SupportsMcp;
use Laravel\Boost\Install\Agents\Agent as LaravelBoostAgent;
use Laravel\Boost\Install\AgentsDetector;
use Laravel\Boost\Install\McpWriter;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent as BoostCoreAgent;
use Throwable;

/**
 * Wrapper around laravel/boost's `boost:install`:
 *
 *  - TTY mode: delegate to `boost:install --mcp` so laravel/boost wires
 *    the MCP client config but its `GuidelineWriter` + `SkillWriter` stay
 *    dormant. Picks up its integrations + agents `multiselect` prompts.
 *  - Non-TTY mode (auto-detected when STDIN isn't a TTY, or explicit
 *    `--no-interaction`): skip `boost:install` entirely, read agents from
 *    `boost.php`, and invoke laravel/boost's `McpWriter` per-agent
 *    directly. Same output (MCP config files written), zero interactive
 *    prompts, runs in CI / Docker.
 *
 * Either mode runs `project-boost:sync` afterwards unless `--no-sync`.
 *
 * Caveat for TTY mode: laravel/boost's installer still runs `multiselect`
 * for integrations (cloud/sail/nightwatch) and agents even with `--mcp` —
 * `--mcp` only short-circuits feature selection, not the downstream
 * pickers. Selecting an integration like `cloud` re-engages laravel/boost's
 * writer for that integration's artifacts. The `suppress_upstream_writers`
 * config flag intercepts `boost:install` invocations missing `--mcp` as a
 * belt-and-suspenders guard for those.
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
        if ($this->isNonInteractive()) {
            return $this->runNonInteractive();
        }

        return $this->runInteractive();
    }

    /**
     * Symfony Console's `$input->isInteractive()` reflects the explicit
     * `--no-interaction` flag but defaults to true even when STDIN isn't
     * a TTY (CI runners, Docker, piped invocations). Probe the actual
     * stream too so the wrapper genuinely auto-detects non-interactive
     * environments without users having to remember the flag.
     */
    private function isNonInteractive(): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        if (defined('STDIN') && function_exists('stream_isatty')) {
            return ! @stream_isatty(STDIN);
        }

        return false;
    }

    private function runInteractive(): int
    {
        $this->info('Wiring laravel/boost MCP config (skipping its guidelines/skills writers — companion owns those)…');

        $mcpExit = $this->call('boost:install', ['--mcp' => true]);
        if ($mcpExit !== self::SUCCESS) {
            $this->error('boost:install --mcp failed. Aborting.');

            return self::FAILURE;
        }

        return $this->maybeRunSync();
    }

    /**
     * Non-interactive flow: load `boost.php`, resolve laravel/boost's
     * agent objects from the names declared there, run `McpWriter`
     * per MCP-capable agent. No multiselect prompts; safe in CI.
     *
     * The agent enum-name translation only covers one mismatch:
     * boost-core uses `claude-code` (kebab-case), laravel/boost uses
     * `claude_code` (snake_case). Every other agent name matches.
     */
    private function runNonInteractive(): int
    {
        $projectRoot = base_path();

        if (! is_file($projectRoot . '/boost.php')) {
            $this->error("No boost.php found at {$projectRoot}/boost.php.");
            $this->line('Non-interactive install reads agents from boost.php. Create one with at least:');
            $this->line('  return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');

            return self::FAILURE;
        }

        $this->info("Non-interactive: writing MCP config for agents declared in boost.php (skipping laravel/boost's install command).");

        try {
            $config = BoostConfig::load($projectRoot);
        } catch (Throwable $throwable) {
            $this->error('Failed to load boost.php: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        if ($config->agents === []) {
            $this->warn('boost.php declares no agents — nothing to install. Add `withAgents([Agent::CLAUDE_CODE, ...])`.');

            return self::SUCCESS;
        }

        // Laravel/boost's AgentsDetector returns a numerically-keyed collection
        // of Agent instances. Re-key by `name()` for direct lookup against the
        // boost-core enum values declared in boost.php.
        $availableAgents = resolve(AgentsDetector::class)
            ->getAgents()
            ->keyBy(fn (LaravelBoostAgent $agent): string => $agent->name());

        $wrote = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($config->agents as $declared) {
            $name = $this->translateAgentName($declared);
            $agent = $availableAgents->get($name);

            if (! $agent instanceof LaravelBoostAgent) {
                $this->line(sprintf('  <fg=gray>skip</> %s (no laravel/boost agent registered for this name)', $declared->value));
                ++$skipped;

                continue;
            }

            if (! $agent instanceof SupportsMcp) {
                $this->line(sprintf('  <fg=gray>skip</> %s (laravel/boost agent does not support MCP)', $declared->value));
                ++$skipped;

                continue;
            }

            try {
                (new McpWriter($agent))
                    ->write();
                $this->line(sprintf('  <fg=green>wrote</> MCP config for %s', $declared->value));
                ++$wrote;
            } catch (Throwable $e) {
                $this->line(sprintf('  <fg=red>fail</> %s: %s', $declared->value, $e->getMessage()));
                ++$failed;
            }
        }

        $this->newLine();
        $this->line(sprintf('<fg=gray>MCP config · wrote=%d · skipped=%d · failed=%d</>', $wrote, $skipped, $failed));

        if ($failed > 0) {
            return self::FAILURE;
        }

        return $this->maybeRunSync();
    }

    private function maybeRunSync(): int
    {
        if ($this->option('no-sync') === true) {
            $this->line('Skipping project-boost:sync (per --no-sync). Run it manually when ready.');

            return self::SUCCESS;
        }

        return $this->call('project-boost:sync');
    }

    /**
     * Translate boost-core's Agent enum to laravel/boost's `name()` string.
     *
     * Only one mismatch in the 9-agent matrix: boost-core's
     * `Agent::CLAUDE_CODE` (value `claude-code`) maps to laravel/boost's
     * `claude_code`. Every other agent uses identical naming.
     */
    private function translateAgentName(BoostCoreAgent $agent): string
    {
        return match ($agent) {
            BoostCoreAgent::CLAUDE_CODE => 'claude_code',
            default => $agent->value,
        };
    }
}
