<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Reconcile;

/**
 * The outcome of {@see GuidanceReconciler::capture()} — what was backed up and
 * whether any hand-authored residual was captured into `.ai/guidelines/`.
 *
 * @internal
 */
final readonly class CaptureResult
{
    /**
     * @param  list<string>  $backups  absolute paths of the verbatim file
     *   backups written under the reconcile backup directory.
     * @param  ?string  $capturedGuidelinePath  absolute path of the
     *   `.ai/guidelines/` file the deduplicated residual was written to, or null
     *   when there was no residual to capture.
     */
    public function __construct(
        public array $backups,
        public ?string $capturedGuidelinePath,
    ) {}
}
