<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Listeners;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use SanderMuller\ProjectBoostLaravel\Console\InstallCommand;

/**
 * After a bare `php artisan boost:install`, nudge the operator toward
 * `project-boost:reconcile`.
 *
 * laravel/boost's installer seeds its guidelines DIRECTLY into the agent
 * guidance files (`CLAUDE.md` / `AGENTS.md` / …) inside `<laravel-boost-guidelines>`
 * markers. The canonical wrapper flow then re-derives that guidance through
 * `project-boost:sync` — but a markerless sync wholesale-overwrites the file, so
 * any operator hand-edits are lost unless captured first. `project-boost:reconcile`
 * is that capture step.
 *
 * Suppressed while `project-boost:install` is driving (it owns the post-install
 * sequence and surfaces the same guidance through `project-boost:sync`'s
 * foreign-seed warning), so the nudge only fires for a standalone
 * `boost:install` that bypassed the wrapper.
 *
 * The suppression check is constructor-injectable so unit tests can drive the
 * listener without bootstrapping the application; in production it falls back to
 * the `project-boost.installing` container flag set by {@see InstallCommand}.
 *
 * @phpstan-type SuppressionResolver Closure(): bool
 *
 * @internal
 */
final readonly class SuggestReconcileAfterBoostInstall
{
    /**
     * @param  SuppressionResolver|null  $isSuppressed
     */
    public function __construct(
        private ?Closure $isSuppressed = null,
    ) {}

    public function handle(CommandFinished $event): void
    {
        if ($event->command !== 'boost:install' || $event->exitCode !== 0) {
            return;
        }

        // `boost:install --mcp` keeps laravel/boost's GuidelineWriter dormant —
        // no guidance is seeded, so there is nothing to reconcile. Nudging there
        // (the README's recommended manual-MCP flow, and the flag this package's
        // own `project-boost:install` + suppress-guard force) would be a false
        // data-loss warning. Only nudge for an install that actually seeds.
        $input = $event->input;
        if ($input->hasOption('mcp') && $input->getOption('mcp') === true) {
            return;
        }

        if ($this->suppressed()) {
            return;
        }

        $output = $event->output;
        $output->writeln('');
        $output->writeln('  <fg=yellow>laravel/boost seeded its guidelines into your agent files (CLAUDE.md / AGENTS.md / …).</>');
        $output->writeln('  If you have hand-edited those files, run <fg=cyan>php artisan project-boost:reconcile</> to capture');
        $output->writeln('  your edits before <fg=cyan>project-boost:sync</> — otherwise a markerless sync overwrites them.');
    }

    private function suppressed(): bool
    {
        if ($this->isSuppressed instanceof Closure) {
            return ($this->isSuppressed)();
        }

        return app()->bound('project-boost.installing');
    }
}
