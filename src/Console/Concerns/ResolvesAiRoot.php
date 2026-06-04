<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console\Concerns;

/**
 * Resolves the laravel/boost asset root (`.ai/`) that `project-boost:sync` and
 * `project-boost:where` read skills + guidelines from.
 *
 * Honours the `project-boost-laravel.laravel_boost_ai_root` config override,
 * falling back to the standard vendor layout `base_path('vendor/laravel/boost/.ai')`.
 * The override exists for tests (a hermetic fixture tree — laravel/boost
 * export-ignores its `.ai/` payload, so it is absent from a prefer-dist install)
 * and non-standard vendor layouts. Both commands resolve through this single
 * seam so they never drift on which root they read.
 *
 * @internal
 */
trait ResolvesAiRoot
{
    private function resolveLaravelBoostAiRoot(): string
    {
        $override = config('project-boost-laravel.laravel_boost_ai_root');

        return is_string($override) && $override !== ''
            ? $override
            : base_path('vendor/laravel/boost/.ai');
    }
}
