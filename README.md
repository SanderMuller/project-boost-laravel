# project-boost-laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/project-boost-laravel.svg?style=flat-square)](https://packagist.org/packages/sandermuller/project-boost-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/project-boost-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/project-boost-laravel/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/project-boost-laravel.svg?style=flat-square)](https://packagist.org/packages/sandermuller/project-boost-laravel)
[![License](https://img.shields.io/packagist/l/sandermuller/project-boost-laravel.svg?style=flat-square)](LICENSE)
[![Laravel Boost](https://badge.laravel.cloud/boost-badge.svg?style=flat-square)](https://github.com/laravel/boost)

> The Laravel-app member of the [sandermuller boost family](#which-package-fits-your-role). Sits next to [`laravel/boost`](https://github.com/laravel/boost) in the same project. Boost keeps doing what it already does: MCP server, Laravel docs API, bundled Laravel skills. This package picks up everything else — fanning the generated agent files out across nine AI coding agents instead of four, and adding the family's filtering controls (`withTags()`, `withAllowedVendors()`, `withRemoteSkills()`, `boost where`).

You run both packages together. Neither replaces the other; the design assumes they're installed side by side.

## Which package fits your role?

| You're building                          | Install                                                                                           | Ships                                                                                                |
|------------------------------------------|---------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| A PHP application (not a package)        | [`sandermuller/project-boost`](https://github.com/sandermuller/project-boost)                     | App-dev skills: DDD layering, repository pattern, DI, domain modeling, legacy coexistence            |
| **A Laravel application**                | **[`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)** | **`laravel/boost` MCP coexistence + nine-agent fanout + tag filter + remote skills  ← you are here** |
| A framework-agnostic Composer package    | [`sandermuller/package-boost-php`](https://github.com/sandermuller/package-boost-php)             | Package-author skills + `lean` / `gitattributes` commands                                            |
| A Laravel package                        | [`sandermuller/package-boost-laravel`](https://github.com/sandermuller/package-boost-laravel)     | Laravel-package skills + `McpJsonEmitter`                                                            |
| Your own skill bundle, or custom tooling | [`sandermuller/boost-core`](https://github.com/sandermuller/boost-core)                           | The sync engine. You supply the skills.                                                              |

## What this adds on top of `laravel/boost`

|                                             | `laravel/boost` alone                   | + `project-boost-laravel`                                               |
|---------------------------------------------|-----------------------------------------|-------------------------------------------------------------------------|
| MCP server (`boost:mcp`)                    | ✅                                       | ✅ unchanged                                                             |
| Laravel docs API + semantic search          | ✅                                       | ✅ unchanged                                                             |
| Bundled Laravel skills + guidelines         | ✅                                       | ✅ re-rendered through this package's pipeline                           |
| Tag filtering                               | —                                       | ✅ `withTags()`. Ship `inertia-vue-development` only on Inertia projects |
| Remote skill sources                        | —                                       | ✅ `withRemoteSkills()`. Pull GitHub-published `.skill` bundles          |
| Vendor allowlist                            | auto via `composer.json`                | ✅ explicit `withAllowedVendors()` for collision control                 |
| `boost where` origin tracing                | —                                       | ✅ host / vendor / remote / shadow attribution                           |
| `project-boost:where` injection-set tracing | —                                       | ✅ symmetric, for the `laravel/boost` skill set this package injects     |
| User-scope sync                             | —                                       | ✅ `boost sync --scope=user` for globally-installed CLI tools            |
| Doctor + path-repo audit                    | —                                       | ✅ `boost doctor --check-versions`                                       |

Under the hood, `project-boost:install` calls `boost:install --mcp` so laravel/boost writes its MCP client config the same way it always does, then `project-boost:sync` takes over for the nine-agent fan-out.

## Install

```bash
composer require --dev sandermuller/project-boost-laravel
```

`laravel/boost` and `sandermuller/boost-core` come in transitively — do **not** require `sandermuller/boost-core` separately, it resolves through this package.

## Where do the skills come from?

Anywhere you want. Skill sources stack:

- **Your own `.ai/skills/` folder**, hand-authored next to the rest of the project. Same convention `laravel/boost` uses, and `boost-core` picks them up automatically.
- **A Composer-installed catalog package**. Any package that ships `resources/boost/skills/` works. I publish my personal catalog at [`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) — adopt it if your preferences align with mine, or treat it as a template for your own. Private repo, public package, monorepo subfolder; Composer resolves all of them.
- **External, non-Composer sources** via `withRemoteSkills()`. Pull GitHub-published `.skill` bundles or single-skill repos straight from the URL.
- **`laravel/boost`'s bundled Laravel skills** plus whatever Laravel-aware third-party packages it knows about. Picked up through the injection seam this package adds.

Mix and match freely. `withAllowedVendors()` gates Composer-scanned vendors (source 2) only — host skills (source 1), remote skills (source 3), and the `laravel/boost` bundle (source 4) are not gated by it. `withTags()` filters sources 2, 3, and 4. Host skills (source 1) bypass both gates: your project authored them, so the engine treats them as canonical and applies neither filter.

## First run

```bash
php artisan project-boost:install
```

What the wrapper does:

1. Runs `php artisan boost:install --mcp`. Boost writes its MCP client config; the `--mcp` flag keeps its `GuidelineWriter` and `SkillWriter` dormant, so this package owns the agent-file fan-out from here on.
2. Runs `php artisan project-boost:sync`. Discovers laravel/boost-bundled skills and guidelines, renders Blade templates, applies your `withTags()` filter, fans out to every agent you declared in `boost.php`.

In CI / Docker / any non-TTY shell, the wrapper detects the absence of an interactive STDIN (`stream_isatty(STDIN)` plus the `--no-interaction` flag) and skips boost's install command entirely. It calls boost's `McpWriter` directly, once per agent in `boost.php`. No prompts, no integration multiselect, no crashes.

> [!WARNING]
> If you run `php artisan boost:install` **without** `--mcp`, boost's `GuidelineWriter` and `SkillWriter` fire and race this package over `CLAUDE.md` and the per-agent skill directories. Always go through `project-boost:install`, or pass `--mcp` explicitly. The `suppress_upstream_writers` flag below is the guardrail for muscle-memory mistakes.

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

Skills are tag-gated. A skill ships if every tag in its `metadata.boost-tags` is also in your `withTags()`. Untagged skills always ship. `vendor/bin/boost tags` lists every tag your installed packages declare. For a worked example of how someone organizes a tag vocabulary across a catalog, see [`sandermuller/boost-skills`'s tag registry](https://github.com/sandermuller/boost-skills#tags).

Common shapes:

| Project                  | Tags                                            |
|--------------------------|-------------------------------------------------|
| Laravel + Livewire       | `Tag::Laravel, Tag::Php, 'livewire'`            |
| Laravel + Inertia React  | `Tag::Laravel, Tag::Php, 'frontend', 'inertia'` |
| Laravel API only         | `Tag::Laravel, Tag::Php`                        |
| + Pest 4 + browser tests | add `'pest'`                                    |

See the [`boost-core` README](https://github.com/sandermuller/boost-core) for the full `BoostConfig` surface.

## Commands

| Command                           | Does                                                                                                                                                                                                                               |
|-----------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `project-boost:install`           | Wraps `boost:install --mcp` (boost owns MCP) and runs `project-boost:sync`. Auto-detects non-TTY for CI / Docker. Recommended entry point.                                                                                         |
| `project-boost:install --no-sync` | MCP only; skip the sync.                                                                                                                                                                                                           |
| `project-boost:sync`              | Discover, render, tag-filter, fan out to nine agents. Run after `composer install` or after editing `boost.php`.                                                                                                                   |
| `project-boost:sync --dry-run`    | Preview the full SyncEngine pipeline (laravel/boost + host + scanned vendors + remote skills) in check mode. Requires `boost.php`.                                                                                                 |
| `project-boost:where`             | List the laravel/boost-bundled skills and guidelines this package injects, with per-skill ship / tag-filter / shadow status. The companion to `vendor/bin/boost where`, which covers the host, scanned-vendor, and remote origins. |

## Coexistence with `laravel/boost`

| Concern                                                                     | Owner                                                                                |
|-----------------------------------------------------------------------------|--------------------------------------------------------------------------------------|
| MCP server (`boost:mcp` artisan command, `boost:install` MCP config writes) | **`laravel/boost`**                                                                  |
| MCP config files (`.mcp.json`, `.amp/settings.json`, agent-specific)        | **`laravel/boost`**                                                                  |
| Laravel docs API + semantic search                                          | **`laravel/boost`**                                                                  |
| `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` content                             | **this package** (via `boost-core`)                                                  |
| `.{agent}/skills/<name>/SKILL.md` files                                     | **this package** (via `boost-core`)                                                  |
| Skill content discovery + Blade rendering                                   | **this package** (`LaravelBoostAssetReader` + `BladeRenderer`)                       |
| Versioned-variant resolution (e.g. `pest/3` vs `pest/4`)                    | **this package**. `Laravel\Roster\Roster::scan()` matches the host's installed major |
| Tag filtering + collision resolution                                        | **`boost-core`**                                                                     |
| Remote skill fetching (`withRemoteSkills`)                                  | **`boost-core`**                                                                     |

Things to avoid:

- `php artisan boost:install` without `--mcp`. The interactive default re-engages boost's writers and races this package.
- `php artisan boost:update`. Boost's bundled-asset refresh. Harmless but pointless; `project-boost:sync` re-renders on every run anyway.

## Auto-sync on `composer install`

In Laravel projects using this package, wire `@php artisan project-boost:sync` into composer's post-install / post-update hooks:

```jsonc
{
  "scripts": {
    "post-install-cmd": ["@php artisan project-boost:sync"],
    "post-update-cmd": ["@php artisan project-boost:sync"]
  }
}
```

The `@php artisan project-boost:sync` hook routes through this package's wrapper, which walks `vendor/laravel/boost/.ai/`, pre-renders Blade templates with proper container context, and injects laravel/boost-bundled skills (`pest-testing`, `livewire-development`, `filament-development`, `inertia-development`, `eloquent-models`, and the rest) into the SyncEngine call. Without this hook, those bundled skills don't reach your agent directories — host skills + scanned vendors + remote skills still sync, but the laravel/boost set silently doesn't.

### Why not `BoostAutoSync::run`?

`boost-core` ships a `SanderMuller\BoostCore\Scripts\BoostAutoSync::run` composer-script helper that invokes `vendor/bin/boost sync` (bare CLI). In Laravel projects using this package, that helper is the wrong hook: bare CLI bypasses this wrapper's injection pipeline entirely, so the laravel/boost-bundled skill set never reaches your agent directories. Operators who wire `BoostAutoSync::run` into their composer scripts typically don't notice — the bare-CLI sync still reports success, just against a smaller skill set than the wrapper would have surfaced. Use `@php artisan project-boost:sync` instead.

A stray bare-CLI sync no longer *deletes* the wrapper's already-emitted skill files — the `BoostWrapper` contract (see [Architecture](#architecture)) declares them so the cleanup pass leaves them in place. But bare CLI still won't *(re)emit* the laravel/boost set, so `@php artisan project-boost:sync` remains the correct hook.

(For non-Laravel projects consuming `boost-core` directly without a wrapper, `BoostAutoSync::run` IS the correct hook. The guidance above is Laravel-app-specific.)

## Defensive flag: `suppress_upstream_writers`

Set `PROJECT_BOOST_SUPPRESS_UPSTREAM=true` in `.env` to enable a `CommandStarting` listener that intercepts ad-hoc `php artisan boost:install` calls and injects `--mcp` before they run. Any truthy value works (`=true`, `=1`, `=yes`). Off by default — `project-boost:install` already does the right thing in both TTY and non-TTY modes, so the flag is belt-and-suspenders for teams worried about muscle-memory mistakes.

Note: this does not suppress boost's integrations writers (cloud / sail / nightwatch). `--mcp` short-circuits feature selection only; the integrations multiselect still runs in TTY mode, and selecting one triggers its writer.

## Remote skills

Declared in `boost.php` via `withRemoteSkills([RemoteSkillSource::githubBundle(...), RemoteSkillSource::githubPath(...)])`. The mechanism, cache behavior, `BOOST_GITHUB_TOKEN`, and `BOOST_REMOTE_STRICT` all live in [boost-core's README](https://github.com/sandermuller/boost-core#remote-skill-sources) — same surface in this package.

## Architecture

`LaravelBoostAssetReader` and `LaravelBoostGuidelineReader` walk `vendor/laravel/boost/.ai/`, render any `.blade.php` files through the package's `BladeRenderer` (which uses `laravel/boost`'s own `RendersBladeGuidelines` trait so `$assist` binds correctly), and hand the resulting `Skill[]` and `Guideline[]` to boost-core via `SyncEngine::sync(injectedVendorSkills, injectedVendorGuidelines)`. From there it's boost-core's normal pipeline — tag filter, collision resolution, per-agent fan-out. See [boost-core's README](https://github.com/sandermuller/boost-core) for the engine internals.

Guidelines are install-gated. `LaravelBoostGuidelineReader` emits only the core guidelines plus guidelines for packages the host actually installed, mirroring `laravel/boost`'s own `GuidelineComposer` detection (PHPUnit-vs-Pest priority, Sail opt-in, direct-only MCP / Livewire). An app never receives guidelines for packages it doesn't use — a Livewire + Filament + PHPUnit app won't get Inertia, Pest, or Sail guidance. Version-major sub-fragments are version-scoped too, on two axes: package dirs by exact installed major (a Laravel 12 app gets `laravel/12`, not `laravel/11` — they're alternative complete sets), and `php/8.x` cumulative-downward to your declared `require.php` floor (`php/8.4` features are usable on 8.5, so a project supporting `^8.3` keeps `≤8.3` and won't be told to use 8.5-only syntax it can't rely on).

A `BoostWrapper` class implements boost-core's `BoostWrapperContract` (introduced in 0.11.0), declaring the per-agent skill-emit paths this package injects. A bare `vendor/bin/boost sync` (no wrapper injection) then preserves those files instead of flagging them stale-to-delete. Requires `boost-core ^0.14 || ^0.15`.

## Troubleshooting

**`No boost.php found at <root>/boost.php`** — create one (see `boost.php` above) or run `vendor/bin/boost install`.

**`Errors during sync: ... listed more than once`** — `boost.php` declared a `withRemoteSkills` source whose skill name overlaps another source, or the laravel/boost asset reader produced two versioned variants of the same skill that didn't dedupe. The second case is a bug; please report.

**`Errors during sync: ... also published by a scanned vendor`** — a package you allowlisted via `withAllowedVendors` publishes a skill colliding with one this package injects from laravel/boost. Either rename the vendor's skill or exclude it: `->withExcludedSkills(['vendor/pkg:skill-name'])`.

**Blade-templated skill output contains literal `@php` or `{{ ... }}`** — `BladeRenderer` didn't fire. Confirm `laravel/boost` is installed (`composer show laravel/boost`). For your own `.ai/skills/<name>/SKILL.blade.php`, register the renderer in `boost.php`:

```php
->withSkillRenderers([new \SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer])
```

**`unchanged` for every file on a second sync** — that's expected. boost-core's `FileWriter` is content-aware.

## Testing

```bash
composer test
```

Pest suite. Unit tests for discovery, version resolution, and the suppress-upstream listener, plus Testbench-backed feature tests for `project-boost:install`'s TTY-vs-non-TTY branching.

`.github/workflows/ci-smoke.yml` runs the end-to-end consumer install path on every push and PR. It spins up a fresh `laravel/laravel` app, installs this package from the checkout, runs `project-boost:install --no-sync --no-interaction` (asserts `.mcp.json` lands with the `laravel-boost` server entry), then `project-boost:sync` (asserts no Blade directives leak into rendered output).

## License

MIT. See [LICENSE](LICENSE).

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [`laravel/boost`](https://github.com/laravel/boost) for the MCP server, the bundled Laravel skills, and the per-agent `McpWriter` this package reuses.
- [`sandermuller/boost-core`](https://github.com/sandermuller/boost-core) for the sync engine this package extends.
