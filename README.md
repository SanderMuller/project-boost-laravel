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

|                                             | `laravel/boost` alone                   | + `project-boost-laravel`                                              |
|---------------------------------------------|-----------------------------------------|------------------------------------------------------------------------|
| MCP server (`boost:mcp`)                    | ✅                                       | ✅ unchanged                                                            |
| Laravel docs API + semantic search          | ✅                                       | ✅ unchanged                                                            |
| Bundled Laravel skills + guidelines         | ✅                                       | ✅ re-rendered through this package's pipeline                          |
| Agents                                      | 4 (Claude Code, Cursor, Codex, Copilot) | **9** (+ Gemini, Junie, Kiro, OpenCode, Amp)                           |
| Tag filtering                               | —                                       | ✅ `withTags()`. Ship `inertia-vue-development` only on Inertia projects |
| Remote skill sources                        | —                                       | ✅ `withRemoteSkills()`. Pull GitHub-published `.skill` bundles          |
| Vendor allowlist                            | auto via `composer.json`                | ✅ explicit `withAllowedVendors()` for collision control                 |
| `boost where` origin tracing                | —                                       | ✅ host / vendor / remote / shadow attribution                          |
| `project-boost:where` injection-set tracing | —                                       | ✅ symmetric, for the `laravel/boost` skill set this package injects    |
| User-scope sync                             | —                                       | ✅ `boost sync --scope=user` for globally-installed CLI tools           |
| Doctor + path-repo audit                    | —                                       | ✅ `boost doctor --check-versions`                                      |

Under the hood, `project-boost:install` calls `boost:install --mcp` so laravel/boost writes its MCP client config the same way it always does, then `project-boost:sync` takes over for the nine-agent fan-out.

## Install

```bash
composer require --dev sandermuller/project-boost-laravel
```

`laravel/boost` and `sandermuller/boost-core` come in transitively.

## Where do the skills come from?

Anywhere you want. Skill sources stack:

- **Your own `.ai/skills/` folder**, hand-authored next to the rest of the project. Same convention `laravel/boost` uses, and `boost-core` picks them up automatically.
- **A Composer-installed catalog package**. Any package that ships `resources/boost/skills/` works. I publish my personal catalog at [`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) — adopt it if your preferences align with mine, or treat it as a template for your own. Private repo, public package, monorepo subfolder; Composer resolves all of them.
- **External, non-Composer sources** via `withRemoteSkills()`. Pull GitHub-published `.skill` bundles or single-skill repos straight from the URL.
- **`laravel/boost`'s bundled Laravel skills** plus whatever Laravel-aware third-party packages it knows about. Picked up through the injection seam this package adds.

Mix and match freely. The vendor allowlist (`withAllowedVendors()`) gates which Composer packages may publish skills into your project; the tag filter (`withTags()`) gates which skills make it through. Both apply uniformly regardless of where a skill came from.

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

| Project | Tags |
|---|---|
| Laravel + Livewire | `Tag::Laravel, Tag::Php, 'livewire'` |
| Laravel + Inertia React | `Tag::Laravel, Tag::Php, 'frontend', 'inertia'` |
| Laravel API only | `Tag::Laravel, Tag::Php` |
| + Pest 4 + browser tests | add `'pest'` |

See the [`boost-core` README](https://github.com/sandermuller/boost-core) for the full `BoostConfig` surface.

## Commands

| Command | Does |
|---|---|
| `project-boost:install` | Wraps `boost:install --mcp` (boost owns MCP) and runs `project-boost:sync`. Auto-detects non-TTY for CI / Docker. Recommended entry point. |
| `project-boost:install --no-sync` | MCP only; skip the sync. |
| `project-boost:sync` | Discover, render, tag-filter, fan out to nine agents. Run after `composer install` or after editing `boost.php`. |
| `project-boost:sync --dry-run` | Preview the full SyncEngine pipeline (laravel/boost + host + scanned vendors + remote skills) in check mode. Requires `boost.php`. |
| `project-boost:where` | List the laravel/boost-bundled skills and guidelines this package injects, with per-skill ship / tag-filter / shadow status. The companion to `vendor/bin/boost where`, which covers the host, scanned-vendor, and remote origins. |

## Coexistence with `laravel/boost`

| Concern | Owner |
|---|---|
| MCP server (`boost:mcp` artisan command, `boost:install` MCP config writes) | **`laravel/boost`** |
| MCP config files (`.mcp.json`, `.amp/settings.json`, agent-specific) | **`laravel/boost`** |
| Laravel docs API + semantic search | **`laravel/boost`** |
| `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` content | **this package** (via `boost-core`) |
| `.{agent}/skills/<name>/SKILL.md` files | **this package** (via `boost-core`) |
| Skill content discovery + Blade rendering | **this package** (`LaravelBoostAssetReader` + `BladeRenderer`) |
| Versioned-variant resolution (e.g. `pest/3` vs `pest/4`) | **this package**. `Laravel\Roster\Roster::scan()` matches the host's installed major |
| Tag filtering + collision resolution | **`boost-core`** |
| Remote skill fetching (`withRemoteSkills`) | **`boost-core`** |

Things to avoid:

- `php artisan boost:install` without `--mcp`. The interactive default re-engages boost's writers and races this package.
- `php artisan boost:update`. Boost's bundled-asset refresh. Harmless but pointless; `project-boost:sync` re-renders on every run anyway.

## Auto-sync on `composer install`

```jsonc
{
  "scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"]
  }
}
```

`BoostAutoSync` fires `vendor/bin/boost sync` only (host + scanned vendors + remote skills), because Laravel's artisan kernel isn't available at composer-script time. For laravel/boost-bundled skill changes, run `php artisan project-boost:sync` manually or wire it into your deploy hook.

## Defensive flag: `suppress_upstream_writers`

Set `PROJECT_BOOST_SUPPRESS_UPSTREAM=true` in `.env` to enable a `CommandStarting` listener that intercepts ad-hoc `php artisan boost:install` calls and injects `--mcp` before they run. Any truthy value works (`=true`, `=1`, `=yes`). Off by default — `project-boost:install` already does the right thing in both TTY and non-TTY modes, so the flag is belt-and-suspenders for teams worried about muscle-memory mistakes.

Note: this does not suppress boost's integrations writers (cloud / sail / nightwatch). `--mcp` short-circuits feature selection only; the integrations multiselect still runs in TTY mode, and selecting one triggers its writer.

## Remote skills

Declared in `boost.php` via `withRemoteSkills([RemoteSkillSource::githubBundle(...), RemoteSkillSource::githubPath(...)])`. The mechanism, cache behavior, `BOOST_GITHUB_TOKEN`, and `BOOST_REMOTE_STRICT` all live in [boost-core's README](https://github.com/sandermuller/boost-core#remote-skill-sources) — same surface in this package.

## Architecture

`LaravelBoostAssetReader` and `LaravelBoostGuidelineReader` walk `vendor/laravel/boost/.ai/`, render any `.blade.php` files through the package's `BladeRenderer` (which uses `laravel/boost`'s own `RendersBladeGuidelines` trait so `$assist` binds correctly), and hand the resulting `Skill[]` and `Guideline[]` to boost-core via `SyncEngine::sync(injectedVendorSkills, injectedVendorGuidelines)`. From there it's boost-core's normal pipeline — tag filter, collision resolution, per-agent fan-out. See [boost-core's README](https://github.com/sandermuller/boost-core) for the engine internals.

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
