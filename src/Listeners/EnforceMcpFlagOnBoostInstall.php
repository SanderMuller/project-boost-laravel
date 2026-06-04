<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Listeners;

use Closure;
use Illuminate\Console\Events\CommandStarting;

/**
 * Belt-and-suspenders guard against the parallel-writer collision.
 *
 * laravel/boost's `boost:install` command uses `new GuidelineWriter()`
 * and `new SkillWriter()` directly (not via the service container), so
 * container-level rebinding can't no-op those writers. The next-best
 * intervention is at the command boundary: when `boost:install` starts
 * AND `--mcp` was not passed AND the suppress flag is enabled, force
 * `--mcp` onto the input before the command's `handle()` reads it.
 *
 * laravel/boost's installer short-circuits its feature-selection step
 * (which gates the guideline + skill writers) when `--mcp` is set, so
 * forcing the flag achieves the same outcome the config comment
 * promises — `CLAUDE.md` / `.cursor/rules/*` / `.{agent}/skills/`
 * remain owned by this package's `project-boost:sync`.
 *
 * Off by default. Users who only ever invoke `project-boost:install`
 * (which always passes `--mcp`) don't need this; the flag is for
 * defensive teams who want to harden against muscle-memory `php
 * artisan boost:install` calls.
 *
 * Note: this only protects against the guideline + skill writers.
 * laravel/boost's integrations multiselect (cloud/sail/nightwatch)
 * still runs after `--mcp`'s short-circuit, so picking those still
 * triggers their per-integration writers — that's a separate
 * concern, documented in the README's NOTE callout under "First run".
 *
 * The flag-resolver is constructor-injected so unit tests can drive
 * the listener without bootstrapping the Laravel application. In
 * production, Laravel's container resolves it via the default null
 * argument and the listener falls back to `config()`.
 *
 * @phpstan-type FlagResolver Closure(): bool
 *
 * @internal
 */
final readonly class EnforceMcpFlagOnBoostInstall
{
    /**
     * @param  FlagResolver|null  $isEnabled
     */
    public function __construct(
        private ?Closure $isEnabled = null,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'boost:install') {
            return;
        }

        if (! $this->resolveFlag()) {
            return;
        }

        $input = $event->input;

        if (! $input->hasOption('mcp')) {
            return;
        }

        if ($input->getOption('mcp') === true) {
            return;
        }

        $input->setOption('mcp', true);
    }

    private function resolveFlag(): bool
    {
        if ($this->isEnabled instanceof Closure) {
            return ($this->isEnabled)();
        }

        // Accept any truthy value to match Laravel's project-wide `env()`
        // convention — `=true` and `=1` (and `=yes`, etc.) all activate
        // the flag. `env()` coerces `"true"` to bool true but leaves `"1"`
        // as a raw string, so a strict `=== true` would silently reject
        // a setup that the user reasonably expects to work.
        return (bool) config('project-boost-laravel.suppress_upstream_writers', false);
    }
}
