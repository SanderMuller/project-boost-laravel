# Changelog

All notable changes to `sandermuller/project-boost-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.1 - 2026-05-27

### What's in

#### Testbench feature tests for `project-boost:install` branching

The TTY-detection regression in 0.3.0 — `$input->isInteractive()` defaulting to true even when STDIN isn't a TTY, sending CI / Docker invocations down the multiselect-crashes path — was only caught at the ci-smoke step of the release gate, after local Pest had passed clean. 0.3.1 closes that surface gap with a Testbench-backed feature suite for `InstallCommand`'s TTY vs non-TTY branching:

- `tests/TestCase.php` — Testbench base that registers laravel/boost + companion ServiceProviders.
- `tests/Feature/Console/InstallCommandTest.php` — four cases covering the non-interactive branch matrix:
  1. `boost.php` missing → FAILURE with create-one hint.
  2. Empty `withAgents([])` → SUCCESS with warning.
  3. `Agent::CLAUDE_CODE` → `.mcp.json` written + `--no-sync` skips the sync step.
  4. Rsync-style summary line `MCP config · wrote=1 · skipped=0 · failed=0`.
  

Pest count: 20 → 24 tests / 31 → 43 assertions. The regression class that snuck through last release would now fail in local Pest before reaching CI.

The chdir dance in `beforeEach` is load-bearing: laravel/boost's `Install\Mcp\FileWriter` resolves `.mcp.json` against PHP's cwd, not against `base_path()`. In a Pest run the cwd defaults to the package root, so a straight artisan call would write into tracked package files. Each test chdir's to `base_path()` (Testbench's workbench, gitignored) on entry and restores on exit.

#### PHPStan ignores narrowed for Feature tests

The initial Feature-test commit added blanket `identifier: method.notFound` + `method.nonObject` suppressions under `tests/Feature/*` to handle Pest's `$this`-binding limitation (phpstan sees the closure's `$this` as `Pest\PendingCalls\TestCall`, not the bound TestCase). A typo like `$this->artisanX(...)` would have silently passed analysis under that rule.

Replaced with two narrow message-regex ignores:

1. Closed allowlist of Laravel TestCase methods Pest's `TestCall` shim doesn't declare (`artisan`, `withoutMockingConsoleOutput`, `expectException`, `expectExceptionMessage`). A typo against the same receiver but a different method name fails loudly — the allowlist gets widened deliberately when a new test method is introduced.
2. Cannot-call-method-on-mixed cascade limited to Laravel test assertion method-name prefixes (`expects…`, `assert…`, `dontExpect…`, `doesntExpect…`, `withoutMockingConsoleOutput`).

Verified empirically: introducing `$this->artisanXTypo(...)` in a feature test now produces 4 `method.notFound` errors. Restoring `artisan(...)` clears them.

#### boost-core floor → `^0.7.6`

Tightens the constraint from `^0.7.0` to `^0.7.6`. Picks up boost-core's:

- Phase 3 of agent-commands-sync: argument-placeholder transpilation for `.ai/commands/<name>.md` — canonical `$ARGUMENTS` + `$N` one-indexed + `$name` + `\$` escape, per-agent transpilers for Claude's zero-indexed `$N`, Copilot's `${input:...}`, Junie's all-required-named contract, Kiro's brace form, Cursor + Amp's verbatim-with-warn. Gemini + Codex stay doctor-only with manual-path notes.
- `boost doctor --check-versions` (from 0.7.2): opt-in Packagist lookup that flags path-repo installs shadowing a newer published version.
- Precise `boost where` origin labels (`vendor` / `remote` / `vendor+remote`).

Companion uses `SyncEngine::sync()` exclusively, never `AgentTarget::planCommands()` directly, so 0.7.6's internal `planCommands()` return-shape change from `list<PendingWrite>` to `array{writes, warnings}` doesn't intersect.

No new direct dependencies.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.0...0.3.1

## 0.3.0 - 2026-05-26

### Highlights

#### Non-interactive `project-boost:install`

The wrapper now branches on the terminal environment:

- **TTY mode** (unchanged): delegate to `boost:install --mcp` so laravel/boost wires the MCP client config. Inherits its `multiselect` prompts for integrations + agents.
- **Non-TTY mode** (CI / Docker / explicit `--no-interaction` / any environment where STDIN isn't a TTY): skip `boost:install` entirely, read agents from `boost.php` via `BoostConfigLoader::load()`, invoke laravel/boost's `McpWriter` per `SupportsMcp` agent directly. Same MCP config files land on disk; zero interactive prompts; safe in CI.

Detection probes `stream_isatty(STDIN)` after the explicit `--no-interaction` flag check, so the non-interactive branch fires automatically on CI runners that don't pass the flag — no muscle memory required.

Reuses laravel/boost's own `McpWriter` per-agent rather than re-implementing per-agent MCP config writers, so every agent that implements `SupportsMcp` ships supported. No fork risk on the MCP config shape; future laravel/boost agents land for free.

Behavior in non-TTY mode:

- No `boost.php` → FAILURE with create-one hint.
- Empty `withAgents([])` → SUCCESS with warning.
- Agent declared in `boost.php` but unrecognized by laravel/boost's `AgentsDetector` → skip with log.
- Agent doesn't implement `SupportsMcp` → skip with log.
- `McpWriter::write()` throws → log failure, continue with remaining agents.
- Any failure → exit FAILURE before cascading to `project-boost:sync`.

Summary line: `MCP config · wrote=N · skipped=N · failed=N`.

#### Defensive flag: `suppress_upstream_writers`

For teams who want to harden against muscle-memory `php artisan boost:install` calls (without `--mcp`) that would otherwise re-engage laravel/boost's `GuidelineWriter` + `SkillWriter` and race this package over `CLAUDE.md` / the per-agent skill dirs:

```bash
# .env
PROJECT_BOOST_SUPPRESS_UPSTREAM=true


```
A `CommandStarting` event listener intercepts the `boost:install` command and force-injects `--mcp` if it wasn't already passed. laravel/boost short-circuits its feature-selection step (the gate for its guideline + skill writers) when `--mcp` is set, so the user-visible outcome matches what `--mcp` would have produced.

Accepts any truthy env value (`=true`, `=1`, `=yes`, etc.) — matches Laravel's project-wide convention.

Off by default: the canonical entry point `project-boost:install` already passes `--mcp` in TTY mode and bypasses `boost:install` entirely in non-TTY mode, so the flag is genuinely belt-and-suspenders.

Limitation noted in docs: this does not suppress laravel/boost's integrations writers (cloud / sail / nightwatch). `--mcp` only short-circuits feature selection; the integrations multiselect still runs in TTY mode. Selecting an integration like `cloud` still triggers its writer.

#### boost-core floor → `^0.7.4`

Tightens the constraint from `^0.7.0` to `^0.7.4` to pick up:

- **`boost doctor --check-versions`** — opt-in Packagist lookup that flags boost-* family path-repo installs shadowing a newer published version. Surfaced from a real consumer's failure mode (path-repo from an rc cycle outliving the upgrade window, locking the dev SHA, partial-write state on disk mid-sync). Consumers running `vendor/bin/boost doctor --check-versions` post-install get the new audit for free.
- **`boost where` precise origin labels** — `vendor` / `remote` / `vendor+remote` instead of the ambiguous `vendor or remote`. No companion code change required (we don't consume the internal helper directly).

Companion's `project-boost:where` symmetry with `boost where` continues to work end-to-end, exposing the laravel/boost-injected skill set with per-skill `ship` / `shadowed by <vendor>` / `filtered (declare: <tags>)` / `excluded` status.

#### CI smoke now exercises the install path end-to-end

`.github/workflows/ci-smoke.yml` runs `php artisan project-boost:install --no-sync --no-interaction` against a fresh `laravel/laravel` skeleton (in addition to the pre-existing `project-boost:sync` step), then asserts `.mcp.json` lands on disk with a `laravel-boost` server entry. Catches AgentsDetector / McpWriter contract drift across laravel/boost releases.

The non-interactive install bug found mid-release — `$input->isInteractive()` defaulting to true even when STDIN isn't a TTY — was caught by exactly this workflow before tagging.

### Upgrade notes

No breaking changes. `composer update sandermuller/project-boost-laravel` picks it up.

If you've been passing `--no-interaction` explicitly to `project-boost:install` for CI: still works, identical behavior. If you weren't passing it and previously hit `multiselect` crashes in CI: drop the workaround and the wrapper auto-detects now.

If you want the defensive `suppress_upstream_writers` guardrail active, add `PROJECT_BOOST_SUPPRESS_UPSTREAM=true` to your `.env`. Optional — happy-path users don't need it.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.2.0...0.3.0

## 0.2.0 - 2026-05-25

### Highlights

#### `project-boost:where` — inspect the laravel/boost injection set

```bash
php artisan project-boost:where



```
Lists every laravel/boost-bundled skill + guideline this package injects into boost-core's `SyncEngine`, with per-skill status for the current `boost.php`:

- `ship` — survived the pipeline; lands in agent dirs
- `shadowed by <vendor>` — lost to a host or scanned-vendor skill of the same name (read from `SyncResult::hostShadows`)
- `filtered (declare: <tags>)` — tag-filtered out; the table shows exactly which tags to add
- `excluded` — untagged-but-not-shipping (uncommon; usually `withExcludedSkills` or a renderer-extension issue — tag advice wouldn't help)

Symmetric with boost-core 0.7.0's `vendor/bin/boost where`, which enumerates host + scanned-vendor + remote-skill origins but explicitly defers the caller-injection seam (the runtime-only `injectedVendorSkills` argument this package uses). The two commands together cover the full origin matrix.

#### Roster-aware versioned-skill resolution

When `laravel/boost` ships per-major skill variants (e.g. `vendor/laravel/boost/.ai/pest/3/skill/pest-testing/` AND `vendor/laravel/boost/.ai/pest/4/skill/pest-testing/`), this package now resolves the variant matching the host's installed major via `Laravel\Roster\Roster::scan(base_path())`. Replaces the prior lex-last `sourcePath` proxy, which picked `pest/4` over `pest/3` correctly today but would flip to the wrong variant once upstream ships double-digit majors (`pest/10` sorts before `pest/3` lexically).

Resolution order:

1. Roster scans the host project; if the package maps to a `Laravel\Roster\Enums\Packages` case and the host has it installed, the variant whose major matches the host's `majorVersion()` wins.
2. Otherwise (Roster missing, package not in the Roster enum, host doesn't have the package, no variant matches) — fall back to the previous lex-last `sourcePath` proxy.

Path matching normalizes Windows backslash separators before regex, so the Roster branch fires on Windows hosts where `SplFileInfo` emits native separators.

7 unit tests cover the resolution matrix (single-variant, Roster-match, no-Roster fallback, no-host-entry fallback, no-variant-match fallback, mixed input, Windows-style paths).

#### Fresh-install CI smoke

New `.github/workflows/ci-smoke.yml` exercises the end-to-end consumer install path on every push and PR that touches PHP source, `composer.json`, or `resources/boost/`:

1. Check out the companion repo into a sibling dir.
2. `composer create-project laravel/laravel skeleton` — empty L13 app.
3. Add the companion as a `path` Composer repository (no symlink — tests the dist-style install path) and `composer require --dev` it.
4. Write a minimal `boost.php`.
5. Run `php artisan project-boost:sync`.
6. Grep generated `CLAUDE.md` + `.claude/skills/` for unrendered Blade leakage (`^@php$`, `^@boostsnippet`).

Catches install-boundary issues and Blade-render regressions that the in-repo Pest suite can't reach.

#### Stable-stability adoption

`minimum-stability` tightened from `dev` to `stable`. Consumers no longer need to relax their own stability to install this package — the entire boost-skills + boost-core + package-boost-php cluster is now on tagged stable releases (boost-core 0.7.0, boost-skills 1.4, package-boost-php 0.9).

#### `package-boost-php` ^0.9 adopted as dev dep

`sandermuller/package-boost-php` added to `require-dev` (^0.9.0). The package-author skill cluster (`readme`, `release-notes`, `upgrading`, `lean-dist`) now syncs into this repo's `.claude/skills/`. Companion's own dev workflow benefits from the same skills it asks downstream consumers to use.

### Documentation

- `README.md`: new `project-boost:where` row in the Commands table; "Versioned variants" section updated to describe the actual Roster-aware resolution (was: "currently lex-sort proxy on the roadmap"); "Testing" section names the `ci-smoke.yml` workflow explicitly.
- Roadmap trimmed to two remaining items: non-interactive `project-boost:install` (CI/Docker support) and the `suppress_upstream_writers` config flag.

### Upgrade notes

No breaking changes from 0.1.0. `composer update sandermuller/project-boost-laravel` picks it up.

If you've manually pinned `boost.php` to handle versioned skills (you probably haven't — laravel/boost's per-major skill dirs are uncommon outside `pest/` and `livewire/`), the new Roster-aware resolution may pick a different variant than before. Run `php artisan project-boost:where` to inspect the resolved set.

If your consumer composer.json sets `minimum-stability: dev` solely because of this package, you can drop it — `^0.2.0` resolves cleanly under default `stable`.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.1.0...0.2.0

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
