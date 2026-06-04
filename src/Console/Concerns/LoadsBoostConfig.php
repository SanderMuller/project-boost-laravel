<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console\Concerns;

use Illuminate\Console\Command;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use Throwable;

/**
 * Shared boost-config pre-flight for this package's artisan commands.
 *
 * Resolves the project's boost config through boost-core's `@api`
 * `BoostConfig::load()` — which honours BOTH the legacy root `boost.php` and
 * the canonical `.config/boost.php` layout (boost-core >= 0.17) — and turns
 * every failure into a clean, actionable message instead of an uncaught fatal.
 *
 * The fatal path matters: these commands run from the `post-install-cmd` /
 * `post-update-cmd` composer hooks, so an uncaught error during config load
 * aborts the consumer's whole `composer update` with a raw stack trace. The
 * most common trigger is an upgrade — a `boost.php` still using the pre-0.20
 * variadic `withTags(Tag::Php, ...)` throws a `TypeError` at require time
 * (boost-core 0.20 made it `withTags([...])`), and that fatal precedes
 * boost-core's own AST auto-migration. Catching it here turns the scary
 * composer abort into a one-line migration nudge.
 *
 * @internal
 *
 * @mixin Command
 */
trait LoadsBoostConfig
{
    /**
     * Load the project's boost config, or print an actionable hint and return
     * null. Callers treat null as a clean `self::FAILURE`.
     */
    private function loadBoostConfigOrHint(string $projectRoot): ?BoostConfig
    {
        try {
            return BoostConfig::load($projectRoot);
        } catch (BoostConfigNotFoundException) {
            $this->error('No boost config found (expected boost.php or .config/boost.php).');
            $this->line('Create one with at least:');
            $this->line('  return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');
        } catch (Throwable $throwable) {
            $this->error('Failed to load your boost config: ' . $throwable->getMessage());

            if (str_contains($throwable->getMessage(), 'withTags')) {
                $this->line('boost-core 0.20 changed `withTags(...)` to take an array. Update your config:');
                $this->line('  ->withTags([Tag::Php, Tag::Jira])   // not ->withTags(Tag::Php, Tag::Jira)');
                $this->line('See boost-core UPGRADING (0.19 → 0.20).');
            }
        }

        return null;
    }
}
