<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Coexistence;

/**
 * Outcome of the `boost.json` retirement step.
 *
 * @internal
 */
enum BoostJsonRemoval
{
    /** No `boost.json` at the project root — the steady state after the first sync. */
    case ABSENT;

    /** A `boost.json` exists but does not look like laravel/boost's — left alone. */
    case FOREIGN;

    /** The path is a symlink — never followed, never unlinked. Reported, not touched. */
    case SYMLINK;

    /**
     * Kept because it still records agents the project's own config does not: retiring
     * it would destroy the only record of that choice. {@see BoostJsonOutcome::$unadoptedAgents}
     * names them.
     */
    case AGENTS_NOT_ADOPTED;

    /**
     * There is no boost state directory to archive into — the project runs with
     * gitignore management off, so creating one would leave an untracked directory
     * behind. The file is kept rather than deleted or parked somewhere unignored.
     */
    case NO_ARCHIVE_LOCATION;

    /** Dry-run: a real sync would archive the file. */
    case WOULD_ARCHIVE;

    /** Moved into the boost state directory — recoverable, and out of `boost:update`'s way. */
    case ARCHIVED;

    /** Archiving failed (permissions, read-only mount). The file is left in place. */
    case FAILED;
}
