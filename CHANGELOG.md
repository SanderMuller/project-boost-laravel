# Changelog

All notable changes to `sandermuller/project-boost-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 - 2026-05-25

Initial public release. AI agent skills + guidelines sync for Laravel apps — a companion to [`laravel/boost`](https://github.com/laravel/boost) that keeps laravel/boost as the MCP server but takes over the per-agent fan-out via [`sandermuller/boost-core`](https://github.com/sandermuller/boost-core).

### Why this package

`laravel/boost` 2.x ships an MCP server **and** writes `CLAUDE.md` / `.cursor/rules/` / `.github/copilot-instructions.md` directly during `boost:install`. That works for the simple case, but it leaves three gaps for projects that already use boost-core:

- **Tag filtering** — laravel/boost writes every bundled skill to every agent. No way to exclude `inertia-vue-development` from a Livewire project.
- **Per-agent fan-out** — laravel/boost targets 4 agents (Claude Code, Cursor, Codex, Amp); boost-core targets 9.
- **Remote skills** — laravel/boost has no `withRemoteSkills` equivalent for GitHub-published `.skill` bundles.

This package closes those gaps. laravel/boost still owns the MCP server (its core value); this package takes over the agent-file fan-out via boost-core's `SyncEngine`.

### What ships in 0.1.0

#### Commands

- **`project-boost:install`** — wraps `boost:install --mcp` so laravel/boost writes the MCP client config but its `GuidelineWriter` / `SkillWriter` stay dormant, then runs `project-boost:sync`. Recommended entry point.
- **`project-boost:install --no-sync`** — MCP-only; skip the sync. Useful to inspect MCP config before fan-out.
- **`project-boost:sync`** — discover + render Blade + tag-filter + boost-core per-agent fan-out. Run after `composer install` or after editing `boost.php`.
- **`project-boost:sync --dry-run`** — preview the full SyncEngine pipeline (laravel/boost + host + scanned vendors + remote skills) in check mode. Add `--show-untagged` to also print the injection-set discovery tables.

#### Discovery + rendering

- **`LaravelBoostAssetReader`** walks `vendor/laravel/boost/.ai/<pkg>/skill/<name>/`, attaches sidecar tags, returns `Skill[]` stamped with `sourceVendor=laravel/boost`. Both `.md` and `.blade.php` skill files are loaded.
- **`LaravelBoostGuidelineReader`** does the same for `vendor/laravel/boost/.ai/<pkg>/core.blade.php` + per-major variants.
- **`BladeRenderer`** uses laravel/boost's own `RendersBladeGuidelines` trait so the `$assist = GuidelineAssist` runtime context binds correctly; `@boostsnippet` directives survive rendering.
- **Versioned variants** — when laravel/boost ships per-major directories (e.g. `pest/3/` + `pest/4/`), the highest version wins. Currently a lex-sort proxy; Roster-aware selection is on the roadmap.
- **Sidecar tag manifest** at `resources/boost/laravel-boost-tags.yaml` layers tags onto laravel/boost skills (which ship without `metadata.boost-tags`). Frontmatter wins when both are present.

#### Output

- Per-line action + path for every write, emitter event, and skipped-symlink (host-placed symlinks under `.{agent}/skills/<name>` are detected and respected — boost-core 0.7.0's symlink-clobber fix).
- rsync-style summary split per action (`wrote=N · deleted=N · unchanged=N · skipped-symlink=N`) plus optional emitter suffix (`emitters(wrote=N, unchanged=N, skipped=N)`).
- Delete attribution — boost-core's canonical `[WARNING] Deleted N file(s) ... no longer eligible (tag-filter / removed withRemoteSkills / stale prune)` warning is emitted via `SyncResult::renderDeleteAttribution()`, so wrapper and `vendor/bin/boost sync` produce identical attribution text.
- Dry-run output is a faithful preview of the full pipeline, not just the laravel/boost injection set.

### Coexistence with `laravel/boost`

| Concern | Owner |
|---|---|
| MCP server (`boost:mcp` artisan command, `boost:install` MCP config writes) | **laravel/boost** |
| MCP config files (`.mcp.json`, `.amp/settings.json`, agent-specific) | **laravel/boost** |
| `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` content | **this package** (via boost-core) |
| `.{agent}/skills/<name>/SKILL.md` files | **this package** (via boost-core) |
| Skill content discovery + Blade rendering | **this package** |
| Tag filtering + collision resolution | **boost-core** |
| Remote skill fetching (`withRemoteSkills`) | **boost-core** |

### Install

```bash
composer require --dev sandermuller/project-boost-laravel

```
`laravel/boost` and `sandermuller/boost-core` are hard requirements — one install gives you both.

### Compatibility

- PHP `^8.3`
- Laravel `^12.0 || ^13.0`
- `laravel/boost` `^2.4`
- `sandermuller/boost-core` `^0.7.0` (stable)
- `orchestra/testbench` `^11.1` (dev)

CI matrix: PHP 8.3 + 8.4 × Laravel 12.* + 13.* × testbench 10.* + 11.* with both `prefer-lowest` and `prefer-stable` stability legs.

### Known limitations

- **`project-boost:install` requires a TTY** — laravel/boost's installer runs interactive `multiselect` prompts for integrations (cloud / sail / nightwatch) and agents even with `--mcp`. The `--mcp` flag only short-circuits feature selection, not the downstream pickers. CI / non-TTY use needs to write `.mcp.json` directly or pipe answers.
- **`composer sync-ai` skips the laravel/boost injection seam** — it wraps `vendor/bin/boost sync` (boost-core's host + scanned-vendor + remote pipeline). For laravel/boost-bundled skill changes, re-run `php artisan project-boost:sync` manually. README covers this asymmetry.
- **Versioned-variant selection is lex-sort, not Roster-aware** — for laravel/boost packages shipping multiple majors (e.g. `pest/3` + `pest/4`), the highest-named directory wins. Roster-aware selection (resolving to the consumer's actually-installed major) is on the roadmap.

### Credits

- [Sander Muller](https://github.com/sandermuller)
- [`laravel/boost`](https://github.com/laravel/boost) — the MCP server this package leans on.
- [`sandermuller/boost-core`](https://github.com/sandermuller/boost-core) — the sync engine this package extends.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/commits/0.1.0

## [Unreleased]
