<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Reconcile;

/**
 * Classification of an agent-guidance file (`CLAUDE.md` / `AGENTS.md` /
 * `GEMINI.md` / …) for the `project-boost:reconcile` takeover.
 *
 * The only false-positive-free at-risk signal available without coupling to
 * boost-core internals is laravel/boost's `<laravel-boost-guidelines>` marker —
 * laravel/boost ALWAYS wraps its installed guidelines in it (even when
 * appending), and boost-core never writes it. A file carrying that marker is
 * therefore definitively foreign-seeded, and a subsequent markerless boost-core
 * sync would wholesale-overwrite it.
 *
 * @internal
 */
enum ReconcileStatus: string
{
    /** The guidance file does not exist yet — nothing to reconcile. */
    case ABSENT = 'absent';

    /**
     * The file exists but carries no `<laravel-boost-guidelines>` marker — it is
     * boost-owned (or empty). `project-boost:sync` reproduces it, so it is safe.
     */
    case CLEAN = 'clean';

    /**
     * Foreign-seeded by laravel/boost, with nothing outside the marker block.
     * The marker body is laravel/boost's bundled guidelines, which
     * `project-boost:sync` re-derives via injection — low risk, backed up for
     * safety but no `.ai/guidelines/` capture needed.
     */
    case FOREIGN_SEEDED = 'foreign-seeded';

    /**
     * Foreign-seeded AND carrying hand-authored content OUTSIDE the marker
     * block. That residual is not re-derived by any sync — it is the genuinely
     * at-risk content, captured into `.ai/guidelines/` before syncing.
     */
    case FOREIGN_SEEDED_WITH_RESIDUAL = 'foreign-seeded-with-residual';
}
