<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Coexistence;

/**
 * What {@see BoostJsonRemover} did, plus the detail the command needs to explain it.
 *
 * @internal
 */
final readonly class BoostJsonOutcome
{
    /**
     * @param  list<string>  $unadoptedAgents  laravel/boost agent names (its spelling) that the
     *                                         project's own config does not declare — the reason a
     *                                         {@see BoostJsonRemoval::AGENTS_NOT_ADOPTED} file is kept
     * @param  ?string  $archivePath  project-relative path the file was (or would be) moved to
     */
    public function __construct(
        public BoostJsonRemoval $status,
        public array $unadoptedAgents = [],
        public ?string $archivePath = null,
    ) {}
}
