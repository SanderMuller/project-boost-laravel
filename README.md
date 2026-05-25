# project-boost-laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/project-boost-laravel.svg?style=flat-square)](https://packagist.org/packages/sandermuller/project-boost-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/project-boost-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/project-boost-laravel/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/project-boost-laravel.svg?style=flat-square)](https://packagist.org/packages/sandermuller/project-boost-laravel)
[![License](https://img.shields.io/packagist/l/sandermuller/project-boost-laravel.svg?style=flat-square)](LICENSE)

> AI agent skills + guidelines sync for Laravel apps. Companion to [`laravel/boost`](https://github.com/laravel/boost): boost stays the MCP server, this package owns the agent-file fan-out (skills, guidelines, remote skills, tag filtering) via [`sandermuller/boost-core`](https://github.com/sandermuller/boost-core).

## Why a companion?

`laravel/boost` 2.x ships an MCP server **and** writes `CLAUDE.md` / `.cursor/rules/` / `.github/copilot-instructions.md` directly during `boost:install`. That works, but it leaves three gaps for a project that already uses boost-core for AI sync across nine agents:

| Gap | What's missing |
|---|---|
| **Tag filtering** | laravel/boost writes every bundled skill to every agent. No way to keep `inertia-vue-development` out of a project that uses Livewire. |
| **Per-agent fan-out** | laravel/boost targets 4 agents (Claude Code, Cursor, Codex, Amp). boost-core targets 9. |
| **Remote skills** | laravel/boost has no `withRemoteSkills` equivalent — no way to pull GitHub-published `.skill` bundles or single-skill repos. |

This package closes the gaps. laravel/boost still runs the MCP server (its core value); this package takes over the agent-file fan-out via boost-core's `SyncEngine`.

## Install

```bash
composer require --dev sandermuller/project-boost-laravel
```

`laravel/boost` and `sandermuller/boost-core` are hard requirements — one install gives you both.

## First run

```bash
php artisan project-boost:install
```

The wrapper:
1. Runs `php artisan boost:install --mcp` — wires the laravel-boost MCP server config into your selected agents (`.mcp.json`, `.amp/settings.json`, `~/.codex/config.toml`, etc). `laravel/boost` owns this; the wrapper just passes `--mcp` so its `GuidelineWriter` and `SkillWriter` stay dormant.
2. Runs `php artisan project-boost:sync` — discovers laravel/boost-bundled skills + guidelines, renders Blade templates, applies your `withTags()` filter, hands the result to boost-core's `SyncEngine` for the per-agent fan-out.

> [!WARNING]
> If you run `php artisan boost:install` **without** `--mcp` (its interactive default), laravel/boost's `GuidelineWriter` and `SkillWriter` will run alongside this package — both will write to `CLAUDE.md` and `.{agent}/skills/`, racing each other. Always go through `project-boost:install` or pass `--mcp` explicitly.

> [!NOTE]
> The wrapper still inherits laravel/boost's interactive `multiselect` prompts for integrations (cloud / sail / nightwatch) and agents — `--mcp` only short-circuits the feature picker, not these. Requires a TTY. Selecting an integration like `cloud` re-engages laravel/boost's per-integration writer (the parallel-writer warning above applies to those too). For CI / non-TTY, write `.mcp.json` directly or pipe the prompt answers.

## `boost.php`

Minimal:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR, Agent::CODEX])
    ->withTags(Tag::Laravel, Tag::Php);
```

Skills tagged with anything outside `{laravel, php}` (the project's declared tags) are dropped from the fan-out. Untagged skills always ship.

### Common tag combinations

| Project shape | Tags |
|---|---|
| Laravel monolith, Livewire | `Tag::Laravel, Tag::Php, 'livewire'` |
| Laravel + Inertia React | `Tag::Laravel, Tag::Php, 'frontend', 'inertia'` |
| Laravel API only | `Tag::Laravel, Tag::Php` |
| Laravel + Pest 4 + browser tests | add `'pest'` |

The full vocabulary is open — `vendor/bin/boost tags` lists every tag the installed skills/guidelines declare. `boost doctor` carries the same report as one of its sections.

## What gets synced

**Skills** — `vendor/laravel/boost/.ai/<pkg>/skill/<name>/SKILL.{md,blade.php}` → `.{agent}/skills/<name>/SKILL.md`. Blade templates render via laravel/boost's own `RendersBladeGuidelines` trait (so the `$assist = GuidelineAssist` runtime context binds correctly; `@boostsnippet` directives survive).

**Guidelines** — `vendor/laravel/boost/.ai/<pkg>/core.blade.php` + per-major variants → concatenated into `CLAUDE.md` / `AGENTS.md` / `GEMINI.md`.

**Versioned variants** — when laravel/boost ships per-major directories (e.g. `pest/3/` + `pest/4/`), the variant matching your host's installed major wins. Resolution uses `Laravel\Roster\Roster::scan(base_path())` to detect the package's major; falls back to lex-last `sourcePath` when Roster can't resolve (package not in the Roster enum, host doesn't have the package installed, etc.).

**Tag filter** — applied via boost-core's subset rule: a skill ships iff every tag in its `metadata.boost-tags` is among `withTags()`. laravel/boost skills have no `metadata.boost-tags` upstream; this package ships a [sidecar tag manifest](resources/boost/laravel-boost-tags.yaml) (`<skill-name>: <space-delimited tags>`) that layers tags in. Frontmatter wins when both are present.

## Commands

| Command | Does |
|---|---|
| `project-boost:install` | Wraps `boost:install --mcp` (laravel/boost owns MCP) + runs `project-boost:sync`. Recommended entry point. |
| `project-boost:install --no-sync` | MCP only; skip the sync. Useful when you want to inspect the MCP config before fan-out. |
| `project-boost:sync` | Discover + render + tag-filter + boost-core fan-out. Run after `composer install` or when you edit `boost.php`. |
| `project-boost:sync --dry-run` | Preview the full SyncEngine pipeline (laravel/boost + host + scanned vendors + remote skills) in check mode. Requires `boost.php`. Add `--show-untagged` to also print the injection-set discovery tables. |
| `project-boost:where` | List laravel/boost-bundled skills + guidelines this package injects, with tag-filter eligibility for the current `boost.php`. Symmetric with boost-core's `vendor/bin/boost where` (which covers host / scanned-vendor / remote origins but not the injection seam). |

## Auto-sync on `composer install`

Wire boost-core's `BoostAutoSync` callback to re-sync after every `composer install`:

```jsonc
{
  "scripts": {
    "post-install-cmd": [
      "SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"
    ],
    "post-update-cmd": [
      "SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"
    ]
  }
}
```

This only fires `vendor/bin/boost sync` (host + scanned vendors + remote skills). It does **not** invoke `project-boost:sync` — Laravel's artisan kernel doesn't exist at composer-script time. The same asymmetry applies to the `composer sync-ai` script (which also wraps `vendor/bin/boost sync`): for laravel/boost-bundled skill changes, re-run `php artisan project-boost:sync` manually (or wire it into your deploy hook).

## Coexistence with `laravel/boost`

| Concern | Owner |
|---|---|
| MCP server (`boost:mcp` artisan command, `boost:install` MCP config writes) | **laravel/boost** |
| MCP config files (`.mcp.json`, `.amp/settings.json`, agent-specific) | **laravel/boost** |
| `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` content | **this package** (via boost-core) |
| `.{agent}/skills/<name>/SKILL.md` files | **this package** (via boost-core) |
| Skill content discovery + Blade rendering | **this package** (`LaravelBoostAssetReader` + `BladeRenderer`) |
| Tag filtering + collision resolution | **boost-core** |
| Remote skill fetching (`withRemoteSkills`) | **boost-core** |

### What you should NOT run

- `php artisan boost:install` (the interactive default, without `--mcp`) — re-introduces the parallel-writer collision.
- `php artisan boost:update` — laravel/boost's bundled-asset refresh. Harmless but pointless; you re-render on every `project-boost:sync` anyway.

## Remote skills

Same shape as any boost-core project. Declare in `boost.php`:

```php
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withTags(Tag::Laravel, Tag::Php)
    ->withRemoteSkills([
        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [
            'composer-upgrade',
            'phpstan-developer',
        ]),
        RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
            'grill-with-docs' => 'skills/engineering/grill-with-docs',
        ]),
    ]);
```

Cache + offline behavior, `BOOST_GITHUB_TOKEN` for rate limits, `BOOST_REMOTE_STRICT` for CI: see [boost-core's README](https://github.com/sandermuller/boost-core#remote-skill-sources).

## Architecture (one-paragraph mental model)

`project-boost:sync` calls `LaravelBoostAssetReader::readSkills()` and `LaravelBoostGuidelineReader::readGuidelines()` — both walk `vendor/laravel/boost/.ai/` and render `.blade.php` via this package's `BladeRenderer` (which `use`s laravel/boost's `RendersBladeGuidelines` trait so the `$assist` runtime context binds correctly). The resulting `Skill[]` and `Guideline[]` are handed to boost-core via `SyncEngine::sync(injectedVendorSkills, injectedVendorGuidelines)` — a public injection seam that bypasses boost-core's `VendorScanner` (which expects `resources/boost/skills/`, a layout laravel/boost does not use). boost-core then tag-filters, resolves collisions, and fans out per agent — same pipeline as host or scanned-vendor skills.

## Troubleshooting

**`No boost.php found at <root>/boost.php`** — create one (see `boost.php` section above) or run `vendor/bin/boost install` to generate via the interactive picker.

**`Errors during sync: ... listed more than once`** — `boost.php` declared a `withRemoteSkills` source overlapping a skill name with another source, or the laravel/boost asset reader produced two versioned variants of the same skill that didn't dedupe (a bug — please report).

**`Errors during sync: ... also published by a scanned vendor`** — a Composer package you allowlisted via `withAllowedVendors` publishes a skill whose name collides with one this package injects from laravel/boost. Rename the vendor's skill or exclude it via `withExcludedSkills(['vendor/pkg:skill-name'])`.

**Blade-templated skill output contains literal `@php` / `{{ ... }}`** — the `BladeRenderer` didn't fire. Confirm `laravel/boost` is at `vendor/laravel/boost/` (run `composer show laravel/boost`). If the file is your own (not laravel/boost-shipped), register a `BladeRenderer` in `boost.php`:

```php
->withSkillRenderers([new \SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer])
```

(Skills under `.ai/skills/<name>/SKILL.blade.php` go through boost-core's loader path; the asset reader only handles laravel/boost-bundled files.)

**`unchanged` reported for every file on second sync** — expected. boost-core's `FileWriter` is content-aware; re-runs only write when bytes change.

## Testing

```bash
composer test
```

Runs the Pest suite (unit only).

A dedicated `.github/workflows/ci-smoke.yml` covers the end-to-end install-and-sync path: it spins up a fresh `laravel/laravel` app, installs this package from the checkout, runs `project-boost:sync` against a minimal `boost.php`, and asserts no Blade directives (`@php`, `{{ $var }}`, `@boostsnippet`) leak into the generated `CLAUDE.md` / `.claude/skills/`. Triggered on any push or PR touching PHP source, `composer.json`, or `resources/boost/`.

## Roadmap

- **`suppress_upstream_writers` config flag** — service-provider rebind of `Laravel\Boost\Install\{GuidelineWriter, SkillWriter}` for users who accidentally run interactive `boost:install`. Inert today; the `--mcp` wrapper covers the happy path.
- **Non-interactive `project-boost:install`** — write `.mcp.json` directly (or pipe selections) so the wrapper can run in CI / Docker without inheriting laravel/boost's TTY-bound `multiselect` prompts.

## License

MIT — see [LICENSE](LICENSE).

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [`laravel/boost`](https://github.com/laravel/boost) — the MCP server this package leans on.
- [`sandermuller/boost-core`](https://github.com/sandermuller/boost-core) — the sync engine this package extends.
