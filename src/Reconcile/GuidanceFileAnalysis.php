<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Reconcile;

use SanderMuller\BoostCore\Enums\Agent;

/**
 * The reconcile analysis of a single agent-guidance file — its on-disk
 * state, the laravel/boost-seeded marker body (if any), and the
 * hand-authored residual outside the marker that a sync would lose.
 *
 * @internal
 */
final readonly class GuidanceFileAnalysis
{
    /**
     * @param  string  $relativePath  guidance file path relative to the project
     *   root (e.g. `CLAUDE.md`, `.github/copilot-instructions.md`).
     * @param  ?string  $markerBody  the trimmed content INSIDE the
     *   `<laravel-boost-guidelines>` block, or null when the file carries no
     *   marker. This is laravel/boost's bundled guidelines (sync re-derives it).
     * @param  ?string  $residual  the trimmed hand-authored content OUTSIDE the
     *   marker block, or null when there is none. Not re-derived by any sync —
     *   the genuinely at-risk content.
     * @param  list<Agent>  $agents  the configured agents whose guidelines path
     *   resolves to this file (several agents can share one file, e.g. AGENTS.md).
     */
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public ReconcileStatus $status,
        public ?string $markerBody,
        public ?string $residual,
        public array $agents,
    ) {}

    public function isAtRisk(): bool
    {
        return $this->status === ReconcileStatus::FOREIGN_SEEDED
            || $this->status === ReconcileStatus::FOREIGN_SEEDED_WITH_RESIDUAL;
    }

    public function hasResidual(): bool
    {
        return $this->residual !== null && $this->residual !== '';
    }
}
