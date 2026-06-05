<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\ProjectBoostLaravel\Console\Concerns\LoadsBoostConfig;
use SanderMuller\ProjectBoostLaravel\Reconcile\GuidanceReconciler;
use SanderMuller\ProjectBoostLaravel\Reconcile\ReconcilePlan;
use SanderMuller\ProjectBoostLaravel\Reconcile\ReconcileStatus;

/**
 * `project-boost:reconcile` — a diff-first guided takeover for projects where
 * laravel/boost's `boost:install` seeded guidelines directly into the agent
 * guidance files (`CLAUDE.md` / `AGENTS.md` / `GEMINI.md` / …), which a
 * markerless boost-core sync would wholesale-overwrite.
 *
 * Flow: detect laravel/boost-seeded guidance → show what is at risk → CAPTURE
 * the hand-authored residual into `.ai/guidelines/` (so the next sync
 * re-composes it) and back up every at-risk file verbatim → run
 * `project-boost:sync`. The capture-before-overwrite step is the whole point —
 * it is what prevents the data loss.
 *
 * The canonical sequence on a wrapper project is: `boost:install` once
 * (laravel/boost wires MCP + seeds guidance) → `project-boost:reconcile` once to
 * absorb that seed safely → `project-boost:sync` from then on. Never run bare
 * `vendor/bin/boost sync` on a wrapper project — it bypasses the laravel/boost
 * injection and overwrites the guidance with a smaller set.
 *
 * @internal The `project-boost:reconcile` CLI contract (name, options, exit
 *           codes) is the public promise — see PUBLIC_API.md. The class is not
 *           an extension point.
 */
final class ReconcileCommand extends Command
{
    use LoadsBoostConfig;

    /** @var string */
    protected $signature = 'project-boost:reconcile
        {--dry-run : Analyze and print the plan without backing up, capturing, or syncing.}
        {--force : Skip the confirmation prompt — capture and sync without asking.}
        {--no-sync : Capture and back up, but do not run project-boost:sync afterwards.}';

    /** @var string */
    protected $description = 'Capture laravel/boost-seeded agent guidance before syncing, so a markerless sync never clobbers it.';

    public function handle(GuidanceReconciler $reconciler): int
    {
        $projectRoot = base_path();

        $config = $this->loadBoostConfigOrHint($projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $plan = $reconciler->analyze($config, $projectRoot);

        if (! $plan->hasAtRiskFiles()) {
            $this->info('No laravel/boost-seeded guidance to reconcile — your agent guidance files are safe to regenerate.');
            $this->line('<fg=gray>Run `php artisan project-boost:sync` to sync.</>');

            return self::SUCCESS;
        }

        $this->renderPlan($plan);

        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->line('<fg=gray>Dry run — nothing written. Re-run without --dry-run to capture + sync.</>');

            return self::SUCCESS;
        }

        if (! $this->shouldProceed($plan)) {
            $this->line('Aborted — nothing was written.');

            return self::SUCCESS;
        }

        $result = $reconciler->capture($plan, $config, $projectRoot . '/.boost-reconcile');

        $this->newLine();
        $this->info(sprintf('Backed up %d at-risk file(s) verbatim to .boost-reconcile/.', count($result->backups)));

        if ($result->capturedGuidelinePath !== null) {
            $this->line(sprintf(
                'Captured hand-authored content into <fg=cyan>%s</> — review/split it; the next sync composes it into every agent file.',
                $this->relative($result->capturedGuidelinePath, $projectRoot),
            ));
        } else {
            $this->line('<fg=gray>No hand-authored residual outside the laravel/boost marker — nothing to capture into .ai/guidelines/ (sync re-derives the marker content).</>');
        }

        if ((bool) $this->option('no-sync')) {
            $this->newLine();
            $this->line('<fg=gray>Skipped project-boost:sync (--no-sync). Run it when ready.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Running project-boost:sync to re-derive guidance (now including the captured content)…');

        return $this->call('project-boost:sync');
    }

    private function renderPlan(ReconcilePlan $plan): void
    {
        $this->line('<fg=cyan>laravel/boost-seeded agent guidance</>');

        $rows = [];
        foreach ($plan->atRiskFiles() as $file) {
            $rows[] = [
                $file->relativePath,
                implode(' ', array_map(static fn (Agent $a): string => $a->value, $file->agents)),
                $this->statusLabel($file->status),
                $file->hasResidual() ? sprintf('%d line(s)', substr_count((string) $file->residual, "\n") + 1) : '—',
            ];
        }

        $this->table(['Guidance file', 'Agents', 'Status', 'Hand-authored'], $rows);

        $this->line('A markerless `boost sync` would wholesale-overwrite these files. Reconcile will:');
        $this->line('  • back up each file verbatim to <fg=cyan>.boost-reconcile/</>');
        $this->line('  • capture hand-authored content (outside laravel/boost\'s marker) into <fg=cyan>.ai/guidelines/</> so sync re-derives it');
        $this->line('  • then run `project-boost:sync`');
    }

    private function statusLabel(ReconcileStatus $status): string
    {
        return match ($status) {
            ReconcileStatus::FOREIGN_SEEDED_WITH_RESIDUAL => '<fg=yellow>foreign-seeded + hand-edits</>',
            ReconcileStatus::FOREIGN_SEEDED => '<fg=green>foreign-seeded (re-derivable)</>',
            ReconcileStatus::CLEAN => 'clean',
            ReconcileStatus::ABSENT => 'absent',
        };
    }

    private function shouldProceed(ReconcilePlan $plan): bool
    {
        if ((bool) $this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm(
            sprintf('Capture + back up %d file(s), then sync?', count($plan->atRiskFiles())),
            true,
        );
    }

    private function relative(string $absolute, string $projectRoot): string
    {
        $prefix = rtrim($projectRoot, '/') . '/';

        return str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;
    }
}
