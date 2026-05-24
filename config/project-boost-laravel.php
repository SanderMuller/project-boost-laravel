<?php declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Suppress laravel/boost's own guideline/skill writers
    |--------------------------------------------------------------------------
    |
    | When true, no-op-rebinds `Laravel\Boost\Install\GuidelineWriter` and
    | `SkillWriter` in the container so even an interactive `boost:install`
    | (without `--mcp`) skips writing `CLAUDE.md`, `.cursor/rules/*`, etc.
    | This package then owns those writes via `project-boost:sync`. Belt-
    | and-suspenders; users who only ever run `project-boost:install` (which
    | already passes `--mcp`) can leave this false.
    |
    | Prototype: rebind logic not yet implemented. Flag currently inert.
    */
    'suppress_upstream_writers' => env('PROJECT_BOOST_SUPPRESS_UPSTREAM', false),

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
