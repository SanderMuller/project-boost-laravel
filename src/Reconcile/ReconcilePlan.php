<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Reconcile;

/**
 * The full result of analyzing a project's agent-guidance files for
 * `project-boost:reconcile` — one {@see GuidanceFileAnalysis} per unique
 * guidance file the configured agents target.
 *
 * @internal
 */
final readonly class ReconcilePlan
{
    /**
     * @param  list<GuidanceFileAnalysis>  $files
     */
    public function __construct(
        public array $files,
    ) {}

    /**
     * Files a markerless boost-core sync would wholesale-overwrite — i.e. those
     * carrying laravel/boost-seeded content. These get backed up; their residual
     * (if any) is captured into `.ai/guidelines/`.
     *
     * @return list<GuidanceFileAnalysis>
     */
    public function atRiskFiles(): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (GuidanceFileAnalysis $f): bool => $f->isAtRisk(),
        ));
    }

    public function hasAtRiskFiles(): bool
    {
        return $this->atRiskFiles() !== [];
    }

    public function hasResidual(): bool
    {
        foreach ($this->files as $file) {
            if ($file->hasResidual()) {
                return true;
            }
        }

        return false;
    }
}
