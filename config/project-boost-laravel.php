<?php declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Suppress laravel/boost's own guideline/skill writers
    |--------------------------------------------------------------------------
    |
    | When true, intercepts the `boost:install` artisan command via a
    | CommandStarting listener and force-injects `--mcp` if it wasn't
    | already passed. laravel/boost short-circuits its feature-selection
    | step (which gates its guideline + skill writers) when `--mcp` is
    | set, so this is effectively "skip the writers that would collide
    | with project-boost:sync".
    |
    | Belt-and-suspenders: users who only ever run `project-boost:install`
    | (which always passes `--mcp`) don't need this — leave it false. The
    | flag is for defensive teams who want to harden against muscle-memory
    | `php artisan boost:install` calls that would otherwise re-engage
    | laravel/boost's writers and race this package over AGENTS.md / the
    | per-agent skill dirs.
    |
    | Note: this does NOT suppress laravel/boost's integrations writers
    | (cloud / sail / nightwatch). `--mcp` only short-circuits feature
    | selection; the integrations multiselect still runs after that.
    | Selecting an integration like `cloud` still triggers its writer.
    */
    'suppress_upstream_writers' => env('PROJECT_BOOST_SUPPRESS_UPSTREAM', false),

    /*
    |--------------------------------------------------------------------------
    | Render laravel/boost's MCP tool guidance
    |--------------------------------------------------------------------------
    |
    | laravel/boost's guidelines gate their Tools, search-docs and record-rule
    | sections on whether the laravel-boost MCP server is in use. This package
    | assumes it is (laravel/boost stays the MCP), so those sections render by
    | default. Set to false when the agents do not run the laravel-boost MCP
    | server, so the guidance does not point at tools they cannot call.
    |
    */
    'mcp' => env('PROJECT_BOOST_MCP', true),

    /*
    |--------------------------------------------------------------------------
    | Laravel/boost asset root
    |--------------------------------------------------------------------------
    |
    | Override only for testing or a non-standard vendor layout. Defaults to
    | `base_path('vendor/laravel/boost/.ai')` at command runtime.
    */
    'laravel_boost_ai_root' => null,
];
