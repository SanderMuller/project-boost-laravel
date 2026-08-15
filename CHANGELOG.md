# Changelog

All notable changes to `sandermuller/project-boost-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0](https://github.com/sandermuller/project-boost-laravel/compare/1.2.0...1.3.0) - 2026-08-15

<!-- verified-sha: f7555f99e835d92ffdd4d4d2f1f97e42ade01278 -->
`project-boost:sync` now retires `laravel/boost`'s `boost.json` once it has taken over what that file describes — the step that stops `herd link` from silently re-seeding guidance behind this package. Additive; no migration.

**Action required:** this release requires `sandermuller/boost-core ^1.6` (up from `^1.0`).

```bash
composer require --dev "sandermuller/project-boost-laravel:^1.3" -W

```
### Added

- **`project-boost:sync` retires `laravel/boost`'s `boost.json` after a successful sync.** The file is laravel/boost's install state, read by `boost:install` and `boost:update` and nothing else — not the MCP server (`boost:mcp` starts a server the ServiceProvider registers unconditionally), not this package (it re-derives from `vendor/laravel/boost/.ai/` every run), not boost-core. Retiring it is what stops the automatic re-seed: `boost:update` bails out when the file is missing, and **`herd link` runs `php artisan boost:update` on its own** whenever `vendor/laravel/boost` is present (Herd's bundled valet CLI), which otherwise rewrites the guidance files inside laravel/boost's marker and reinstalls its skill directories behind this command's back.
  - **Adopt before retire.** The file's `agents` list is the only record of what was picked in laravel/boost's installer, and nothing imports it automatically. While it names an agent the project's own config does not declare, the file stays put and the sync says which agent and how to adopt it — `vendor/bin/boost install` pre-selects exactly that set. An agent boost-core has no case for (`antigravity`, `factory`, `grok_build`, `pi`, `zed`) can never be adopted, so it never blocks; the sync names it instead, because nothing this package emits reaches that agent and retiring the file ends laravel/boost's updates for it too.
  - **Archived, not deleted.** The file moves to `.boost/boost.json.retired` (or `.config/boost/boost.json.retired` when the project uses that config layout — the layout decides, not whichever directory happens to exist). Both directories are gitignored by boost-core and skipped by its stale-file sweep, so the archive survives later syncs and never dirties the working tree. An existing archive is never overwritten: identical content means the source is simply dropped, and different content is archived alongside under a content-addressed name. Restore from there, or run `php artisan boost:install` to regenerate.
  - **Only on a real takeover.** A sync that injected no laravel/boost skills or guidelines (laravel/boost export-ignores its `.ai` payload, so a prefer-dist install has none), or that skipped a guidance path because it is a live symlink, has taken over nothing — neither case is an error, so the sync still exits `0`, and in both the file is kept with the reason printed.
  - Further guards: with gitignore management off there is no state directory to archive into, so the file is kept rather than parked somewhere untracked; a symlink anywhere on the destination path is refused (`rename()` would follow it out of the project); an archive name already taken by different content is refused rather than overwritten; a `boost.json` recording no agent list is not laravel/boost's live install state — another tool's file, or one `boost:update` already refuses to act on — and is kept; a failed sync keeps the file, since laravel/boost's own path stays the fallback; a failed archive leaves the original in place.
  - `--dry-run` reports `would-archive` and moves nothing. `--keep-boost-json` opts out entirely.
  

### Changed

- **Requires `sandermuller/boost-core ^1.6`** (was `^1.0`). Two behaviours the retirement flow is documented against landed in `1.6.0`: the stale-file sweep is manifest-gated, so the `.boost/boost.json.retired` archive survives later syncs instead of being reaped as unowned; and `boost install` pre-selects the agents recorded in laravel/boost's `boost.json`, which is the adoption step this command waits for before retiring the file.

`--keep-boost-json` joins the frozen CLI surface in `PUBLIC_API.md` from this release. Validated across the CI matrix (PHP 8.3/8.4 × Laravel 12/13, `prefer-lowest` and `prefer-stable`).

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/1.2.0...1.3.0

## 1.2.0 - 2026-08-05

<!-- verified-sha: 5b2c79aa75f0cd17ba3ee48a45496f431ef3893d -->
Restores compatibility with `laravel/roster 1.0.0`, whose API rewrite made every `composer update` on a consumer app hard-crash during the `project-boost:sync` post-hook.

**Action required:** this release requires `laravel/boost ^2.5` (up from `^2.4`). See [UPGRADING.md](UPGRADING.md#from-11-to-12).

```bash
composer require --dev "sandermuller/project-boost-laravel:^1.2" -W


```
`-W` matters: `laravel/boost` is usually a sibling top-level require in the consuming app, so it has to move to `^2.5` in the same resolve.

### Fixed

- **`composer update` / `install` no longer fatals with `Class "Laravel\Roster\Enums\Packages" not found`.** `laravel/roster 1.0.0` (2026-07-18) removed the `Packages` enum and the `Roster` class this package was built against. The failure was unrecoverable rather than degrading to the intended permissive fallback: `LaravelBoostGuidelineGate::EXCLUDED_PACKAGES` referenced enum cases in a class-constant initializer, which PHP evaluates on class initialization — so `permissive()`, the graceful-fallback path itself, threw before any `class_exists()` guard could run. Package identity is now a plain composer/npm name string throughout, and `Roster::scan()` becomes `ProjectScan::scan()`.
  
- **Guideline dirs resolve through `laravel/boost`'s own name mapper.** Package name → guideline dir now delegates to `PackageRegistry::guidelineName()` instead of slugifying locally. Pre-1.0 Roster's `Package::name()` returned the enum *case name* (`FLUXUI_PRO`), which the old slugify handled correctly; Roster 1.0 returns the composer name (`livewire/flux-pro`), which it would not have. Left unfixed, this would have replaced the fatal with silence — every package guideline suppressed, no error.
  
- **npm-ecosystem packages are gated again.** Discovery scans both ecosystems (`php()` + `js()`), matching `laravel/boost`'s own `DiscoverPackagePaths::packages()`. The `inertia-react`, `inertia-svelte`, `inertia-vue` and `tailwindcss` guideline dirs are npm-driven and had no gate signal from a php-only scan.
  

### Changed

- **Requires `laravel/boost ^2.5`** (was `^2.4`). `Laravel\Boost\Support\PackageRegistry` — which this package now mirrors for package constants and the name → dir map — landed in `2.5.0` alongside boost's own Roster 1.0 adaptation. `2.4.x` still requires `laravel/roster ^0.5`, so there is no version of this fix that works on the `2.4` line.
  
- **`laravel/roster ^1.0` is now an explicit requirement.** It was previously pulled in only transitively through `laravel/boost`, despite this package type-hinting its classes directly — which is how a major upstream rewrite reached consumers with no constraint to stop it.
  
- **The known-package universe is derived from the dirs `laravel/boost` ships** under `.ai/`, rather than enumerated from a hardcoded list. Roster 1.0 removed the enum that supplied it, and boost's replacement keeps its name → dir map private. Scanning is also self-maintaining: a guideline dir boost adds in a future release is gated correctly without a release here. Verified equivalent against boost 2.5 — every package dir it ships was a `Packages` case, and the enum cases with no shipped dir were already no-ops.
  

### Internal

- `LaravelBoostGuidelineGate::fromRoster()` became `fromProjectScan()` and takes a `ProjectScan`; `VersionResolver` takes a `?ProjectScan`. Both are `@internal` — the `PUBLIC_API.md` surface (CLI commands, options, exit codes, config keys) is unchanged.
  
- Added a regression guard asserting the gate's exclusion and must-be-direct lists still match `laravel/boost`'s own. The gate is a 1:1 mirror of `DiscoverPackagePaths`, and unlike a removed class, a changed policy array drifts silently — no fatal, just guidelines quietly emitted or suppressed.
  
- Dropped the abandoned `rector/type-perfect` dev dependency, superseded by `tomasvotruba/type-coverage`, which now bundles it. Both installed made PHPStan abort during container compilation on a duplicate service registration, exiting non-zero with no output — so the quality gate looked green while analysing nothing.
  

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/1.1.0...1.2.0

## 1.1.0 - 2026-06-05

<!-- verified-sha: cd1316735c6f6e36ec3ad0df05bc35a0faac5ae7 -->
Adds `project-boost:reconcile` — a guided takeover that captures laravel/boost-seeded agent guidance before a sync would overwrite it. Additive; no migration.

### Added

- **`project-boost:reconcile`** — a diff-first guided takeover for the `laravel/boost` coexistence seam. `boost:install` seeds its guidelines directly into your agent files (`CLAUDE.md` / `AGENTS.md` / `GEMINI.md` / …) inside a `<laravel-boost-guidelines>` marker; a markerless boost-core sync regenerates those files wholesale, so hand-authored content outside the marker is lost. `reconcile` detects foreign-seeded files by that marker (the same signal `vendor/bin/boost doctor` uses), backs each up verbatim to `.boost-reconcile/`, captures the hand-authored residual into `.ai/guidelines/reconciled.md` (append-with-dedup — it never clobbers edits you have made there), then runs `project-boost:sync` so the captured content is re-composed into every agent file. Options: `--dry-run`, `--force`, `--no-sync`.
- **`project-boost:sync` now warns** when a sync would overwrite `laravel/boost`-seeded guidance, pointing at `project-boost:reconcile` first (warn-and-continue, matching boost-core's default).
- **A post-`boost:install` nudge** toward `project-boost:reconcile`, fired only after a bare `boost:install` that actually seeds guidance — silent after `boost:install --mcp` (which keeps laravel/boost's writers dormant, so nothing is seeded) and while `project-boost:install` is driving its own sequence.

### Docs

- New [`docs/laravel-coexistence.md`](../docs/laravel-coexistence.md): the canonical command sequence (`boost:install` once → `project-boost:reconcile` once → `project-boost:sync` ongoing), the division of labor with `laravel/boost`, and why a bare `vendor/bin/boost sync` on a wrapper project loses content. The README and `PUBLIC_API.md` (which adds `project-boost:reconcile` to the frozen CLI surface) link to it.

The new command is part of the `1.x` `@api`/CLI surface from this release. Requires `boost-core ^1.0`; validated across the CI matrix.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/1.0.0...1.1.0

## 1.0.0 - 2026-06-05

<!-- verified-sha: 6236f8674aa80d58944eee7695b9696d6ce01775 -->
The stability commitment. `project-boost-laravel` joins the boost family's `1.0` line — the last freeze in dependency order (boost-core → package-boost-php → package-boost-laravel → here). No runtime change: `1.0.0` is the `0.10.2` surface, re-validated against the boost-core `1.x` family and frozen.

### The 1.0 contract

This package is a behavior wrapper, not a class library — its contract is the CLI, config, and sync behavior, documented in `PUBLIC_API.md`. From `1.0.0` that surface is locked for the `1.x` line (no break in a MINOR or PATCH):

- **CLI** — `project-boost:install` / `:sync` / `:where`, their documented options, and the exit-code contract.
- **Config** — `config/project-boost-laravel.php` keys (`suppress_upstream_writers`, `laravel_boost_ai_root`).
- **Discovery & frozen formats** — the service-provider FQCN, the `BoostWrapper` contract, the laravel/boost tag manifest, and the install-gated guideline behavior.
- **No `@api` PHP classes** — nothing here is constructed or extended by name; the `SkillRenderer` implementation stays `@internal`.

### Changed

- **Requires `sandermuller/boost-core ^1.0`** (was `^0.23.0||^1.0`) — the `0.23` range is dropped. boost-core `1.0` is a drop-in over `0.23.3` (no API break), so a consumer already on `^0.23` bumps mechanically. If you require `sandermuller/boost-core` or `sandermuller/boost-skills` directly, move them to the `1.0` family in the same `composer update -W` (boost-skills `2.2.0` already admits boost-core `^1.0`). See `UPGRADING.md`.

Re-validated against the resolved boost-core `1.x` family across the CI matrix (prefer-lowest → `1.0.0`, prefer-stable → `1.1.0`): full suite green, the boost-core `@api`-closure guard intact, static analysis clean, sync no-drift.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.10.2...1.0.0

## 0.10.2 - 2026-06-05

<!-- verified-sha: 2f7225c3670d572eda83b3c74ef54df8c2a94251 -->
### Changed

- **Now allows `sandermuller/boost-core ^1.0`** (was `^0.23.0`). boost-core 1.0.0 is a drop-in over 0.23.3 (no API break), so the runtime requirement widens to `^0.23.0||^1.0`. This unblocks wrapper consumers from resolving boost-core 1.0 transitively — `project-boost-laravel` was the last consumer-facing cap in the cascade (`boost-skills` already admits `^1.0`). Validated against the 0.23.3 surface, which is byte-identical to 1.0.0: full suite green (65/65), the boost-core `@api`-closure guard intact, PHPStan clean.
  
  Consumers that require `sandermuller/boost-core` directly can bump it to `^1.0` in the same `composer update -W`. No action needed otherwise — a scoped update under an existing `^0.10` constraint picks this up.
  

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.10.1...0.10.2

## 0.10.1 - 2026-06-04

<!-- verified-sha: 9438a09d30c602762a400a33018a32944a1d3c2d -->
### Fixed

- **`project-boost:where` now install-gates guidelines exactly like `project-boost:sync`.** `where` built its guideline reader WITHOUT the install-gate `sync` applies, so it over-reported laravel/boost guidelines (inertia / livewire / pest / sail …) for packages the host hasn't installed — misleading for a command whose whole job is "what ships for this project shape." Both commands now share the gate through a single `GatesGuidelines` concern, so `where` reports the set `sync` actually emits.
- **The `laravel_boost_ai_root` config override is now honored.** The key shipped in `config/project-boost-laravel.php` — documented as a test / non-standard-vendor-layout override — was never read; both commands hard-coded `vendor/laravel/boost/.ai`. `project-boost:sync` and `project-boost:where` now resolve the asset root through it (falling back to the standard vendor path), making the documented override actually work.

### Docs

- **`UPGRADING.md`: corrected the 0.10 upgrade command**, sourced from consumer adoption feedback. The prior command was incomplete on three counts: it omitted `--dev` (a bare `composer require` silently promotes this dev package into production `require`); consumers that require `sandermuller/boost-skills` directly must co-bump it to `^2.1 -W` in the same command (a directly-required `boost-skills ^2.0.6` transitively pins `boost-core ^0.22`, colliding with 0.10's `^0.23` floor, and `-W` alone can't resolve a sibling top-level require); and each boost package should be pinned to the floor you validated against, not the wrapper's transitive floor, to avoid generated-guidance drift in repos that auto-run `project-boost:sync` from composer scripts.

### Internal

- The `project-boost:where` guideline-gate regression test is now hermetic: it points the `laravel_boost_ai_root` override at a small fixture `.ai` tree instead of the real `vendor/laravel/boost/.ai` payload — which laravel/boost export-ignores, so it is absent from a prefer-dist Composer install (and was failing the CI test matrix while passing locally).
- Removed the orphaned `VersionResolver::withHostRoster()` static factory, left dead by the `where` gate refactor.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.10.0...0.10.1

## 0.10.0 - 2026-06-04

<!-- verified-sha: 64d69008f742058ff99840c396c18548455bac11 -->
Adopts the boost-core `0.23` line and locks the package's `@api`/`@internal` surface ahead of `1.0`.

### Breaking

- **Requires `sandermuller/boost-core ^0.23`** (was `^0.22`), alongside `sandermuller/package-boost-laravel ^0.15` and `sandermuller/boost-skills ^2.1`. Most apps only bump this package — boost-core resolves transitively:
  
  ```bash
  composer require "sandermuller/project-boost-laravel:^0.10"
  
  
  
  
  
  
  
  ```
  If you require any boost package directly, move them to the `0.23` line together. Running against `boost-core < 0.23` no longer resolves.
  

### Fixed

- **A malformed `metadata.boost-tags` now fails closed.** laravel/boost skills are tag-filtered against your `withTags()`. Previously a malformed (non-string) `boost-tags` value was treated as "untagged" and shipped to every agent — the opposite of the engine's fail-closed contract. The package now tokenizes and validates tags through boost-core's canonical `BoostTags`, so a malformed value ships nowhere, matching `boost`'s own behaviour. (An explicitly-empty `boost-tags` is untagged on purpose — no sidecar-manifest fallback.)

### Internal

- **Locked the `@api`/`@internal` surface for `1.0`**, documented in the new [`PUBLIC_API.md`](https://github.com/sandermuller/project-boost-laravel/blob/main/PUBLIC_API.md). This package exposes **no `@api` PHP classes** — it's an artisan/CLI-driven wrapper, so its semver-protected contract is its CLI commands, config keys, and documented behaviour, not a class API. An architecture test fails if any `src/` class is added without an explicit `@api`/`@internal` mark.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.9.1...0.10.0

## 0.9.1 - 2026-06-04

<!-- verified-sha: 4404f5cde8efec10cce44e7487b5c2311459313c -->
A patch hardening the upgrade path, from real-world adoption feedback across several consumer apps.

### Fixed

- **A pre-0.20 variadic `withTags(...)` in your boost config no longer aborts `composer update`.** boost-core `0.20` made `withTags(Tag::Php, ...)` into `withTags([...])`, and the `project-boost:sync` composer hook `require`s your config — so an un-migrated variadic call threw a `TypeError` that aborted the whole update with a raw stack trace. `project-boost:sync`, `project-boost:where`, and `project-boost:install` now catch a config-load failure and print a clean, actionable migration hint with a non-zero exit instead. (A missing config likewise gets a friendly "create one" hint rather than an uncaught exception.)
- **The README minimal example used the pre-0.20 variadic `withTags(Tag::Laravel, Tag::Php)`** — which would `TypeError` if copy-pasted under `boost-core ^0.22`. Corrected to the array form.

### Internal

- Added an `@api`-closure conformance test: it scans every boost-core import under `src/` and asserts each symbol is part of boost-core's frozen `@api` surface, so the package can only depend on the 1.0-stable contract.

### Docs

- `UPGRADING.md`: hand-edit `withTags(...)` → `withTags([...])` before bumping (the post-update sync hook loads your config before any auto-migration can run).
- `README.md`: clarified that boost-core config resolves from `.config/boost.php` or a legacy root `boost.php`, while laravel/boost's `boost.json` stays at the project root (laravel/boost owns it).

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.9.0...0.9.1

## 0.9.0 - 2026-06-03

<!-- verified-sha: 1812278609c603f03d62aae2cd0e53595c4094d9 -->
Adopts the boost-core `0.22` line and moves every sync-driving and wrapper code path onto boost-core's frozen `@api` surface, ahead of boost-core's `1.0` freeze. Also fixes the console commands for the canonical `.config/boost.php` config layout.

### Breaking

- **Requires `boost-core ^0.22`** (was `^0.16`). The boost stack moves together — `sandermuller/package-boost-laravel ^0.14` (the Laravel umbrella that ships the `emit(): iterable` `FileEmitter` contract and floors `package-boost-php ^0.18.1`) and `sandermuller/boost-skills ^2.0.6`. Running against `boost-core < 0.22` no longer resolves.
  
  Most apps only need to bump this package — boost-core comes through transitively:
  
  ```bash
  composer require "sandermuller/project-boost-laravel:^0.9"
  
  
  
  
  
  
  
  
  
  ```
  If you pin any boost package directly, move them to the `0.22` line together: `sandermuller/boost-core ^0.22`, `sandermuller/package-boost-laravel ^0.14`, `sandermuller/boost-skills ^2.0.6`.
  

### Fixed

- `project-boost:sync`, `project-boost:sync --dry-run`, `project-boost:where`, and the non-interactive `project-boost:install` now resolve the boost config from **both** the legacy root `boost.php` **and** the canonical `.config/boost.php` layout (boost-core ≥ 0.17). They previously hard-coded a root `boost.php` check and aborted with `No boost.php found` for projects already on the `.config/` layout, before the sync ever ran.

### Internal

- Re-pointed the package onto boost-core's `@api` surface: sync now drives the `BoostSync` facade, agent targets resolve via `Agent::target()`, config reads via `BoostConfig::load()`, skill emit paths via `AgentTarget::skillRelativePathForName()`, and frontmatter via the now-`@api` `FrontmatterParser`. Every boost-core symbol the package imports is `@api`, so it stays stable under boost-core's `1.0` semver guarantee — no longer relying on engine internals.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.8.2...0.9.0

## 0.8.2 - 2026-05-31

<!-- verified-sha: a2dd0a2a4aecf6bc80a16df94e0c2ff1f05bed44 -->
### 0.8.2

Makes guideline and skill discovery order deterministic across operating systems, eliminating content-free sync churn between macOS and Linux. Patch release — no constraint or API changes.

#### Fixed

##### Deterministic guideline/skill ordering across OSes

`LaravelBoostGuidelineReader` and `LaravelBoostAssetReader` walked `vendor/laravel/boost/.ai/` with a Symfony `Finder` that had no `sortByName()`, so each yielded entries in **filesystem-iteration order** — APFS hash order on macOS, ext4 readdir order on Linux. The guideline reader's output is appended in that order and boost-core's `SyncEngine` concatenates guidelines into `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` in array order.

The result: the *same commit* regenerated those agent files with a different section order depending on which OS ran the sync. Downstream that showed up as a large, content-free reorder diff (~157 lines, zero content change) and — worse — a CI auto-fix loop, where CI on Linux kept rewriting the order a developer had committed on macOS and pushing the "fix" back.

Both finders now call `->sortByName()`, pinning a stable lexicographic order regardless of the underlying filesystem. A reader test asserts the native emission order is already lexicographic (it fails without the sort).

If you previously worked around this by gating sync off in CI, you can drop that guard once on 0.8.2 — with deterministic ordering, regenerating in CI is byte-stable against a local sync of the same commit.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.8.1...0.8.2

## 0.8.1 - 2026-05-31

<!-- verified-sha: 07bb8585227b163db93d59ea83ba57f684cff35d -->
Fixes a silent-skip footgun: a host project's own `.ai/guidelines/*.blade.php` (and `.ai/skills/*.blade.php`) now render on the `project-boost:sync` path instead of vanishing from the synced agent files with no warning. Patch release — no constraint or API changes.

### Fixed

#### Host `.blade.php` guidelines no longer silently dropped

boost-core's `GuidelineLoader` skips any host guideline whose file extension has no registered renderer, and boost-core ships only the `.md` `PassthroughRenderer`. So a host that wrote a Blade-templated guideline under `.ai/guidelines/` got nothing in `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` — no error, no warning, just absent content.

`SyncCommand` now passes `extraSkillRenderers: [new BladeRenderer()]` to `SyncEngine::sync`, registering this package's `BladeRenderer` for the engine's own loaders. Host `.ai/guidelines/*.blade.php` and `.ai/skills/<name>/SKILL.blade.php` render automatically — no `boost.php` change required.

The renderer **appends** after the host's own `boost.php` `withSkillRenderers()`, and the dispatcher is first-registered-wins, so a host that already registered its own `.blade.php` renderer keeps it (no collision). The registration runs on the artisan path, where the container is bootstrapped and `BladeRenderer`'s container guard is satisfied.

If you sync through bare `vendor/bin/boost sync` (no wrapper), nothing changes — register the renderer in `boost.php` yourself as before. The README troubleshooting entry is updated to reflect that manual registration is only needed off the wrapper path.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.8.0...0.8.1

## 0.8.0 - 2026-05-31

<!-- verified-sha: 9cbee480d2bddf17df6fbb0017637cbd1c2dbfd3 -->
Narrows the `boost-core` requirement to `^0.16` so the package lines up with boost-skills 2.0 (the conventions-inlining token release). This is a floor bump — consumers must move their boost-core constraint to `^0.16`.

### Breaking

#### boost-core narrowed to `^0.16`

`sandermuller/boost-core` is now required at `^0.16.0` (was `^0.14.0 || ^0.15.0`). The widened-OR closes — boost-core 0.14 and 0.15 are dropped from the supported range:

```bash
composer require sandermuller/boost-core:^0.16












```
**Why ^0.16 specifically.** boost-skills 2.0 migrated its skills to render-time conventions tokens. Its Jira skills inline a `mcp.jira` sub-key conventions token that only resolves on boost-core 0.16 — on 0.15 the resolver short-circuits the open-vocab schema leaf and emits the token raw (broken skill body). So a project on boost-skills 2.0 needs boost-core 0.16 at render time; aligning this package's floor to `^0.16` keeps the two in lockstep and avoids a resolution conflict (boost-skills 2.0 declares its own direct `boost-core ^0.16`).

boost-core 0.16's conventions-token leak detection is otherwise a no-op for this wrapper (it declares no conventions and `BoostWrapperContract` is unchanged), so the wrapper needs no code change.

#### Dev tooling moved to the v2 line

Not consumer-facing, but for contributors: `sandermuller/boost-skills` `^1.9 → ^2.0` and `sandermuller/package-boost-laravel` `^0.10 → ^0.11` (0.11 drops the umbrella's own boost-core ceiling and inherits package-boost-php's range, which is what unblocks resolving boost-core 0.16 in the dev tree).

### Upgrade notes

```bash
composer require sandermuller/boost-core:^0.16   # or just composer update if tracked transitively
composer update sandermuller/project-boost-laravel sandermuller/boost-core
php artisan project-boost:sync












```
If you're adopting boost-skills 2.0's token-bearing skills, boost-core 0.16 is required for them to render — this release is what lets your Laravel project resolve it through this package. If you were on boost-core 0.14 or 0.15 and not ready to move, stay on project-boost-laravel 0.7.x.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.7.1...0.8.0

## 0.7.1 - 2026-05-31

<!-- verified-sha: 1612ec3c7846eec4ae5f28ef1e529cf9690c9058 -->
Widens the `boost-core` constraint to accept `^0.15` so consumers can adopt the upcoming token-bearing skills. Non-breaking — existing `^0.14` setups are unaffected.

### Changed

#### `boost-core` constraint widened to `^0.14 || ^0.15`

`sandermuller/boost-core` now resolves against `^0.14.0 || ^0.15.0` (was `^0.14.0`). This is a widened-OR open beat, not a floor bump:

- **Existing consumers on boost-core 0.14** — no change, nothing to do.
- **Consumers adopting boost-skills' token-bearing skills** — can now resolve boost-core 0.15 through this package. boost-skills' upcoming `$.slot`→token migration emits raw tokens on any pre-0.15 engine, so those skills require boost-core 0.15's slot-token engine; project-boost-laravel is the resolution floor for Laravel consumers (boost-skills is a markdown catalog with no direct boost-core require), so this widen is what lets them resolve 0.15.

`BoostWrapperContract` is unchanged across 0.14 and 0.15, so the wrapper needs no code change. boost-core 0.15's conventions-inlining engine is non-load-bearing for this wrapper directly — it matters only as the engine the token skills need.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.7.0...0.7.1

## 0.7.0 - 2026-05-31

<!-- verified-sha: da9e945fa2ef5b0a6fd0afb95bfc5307dc964827 -->
Crosses the package to **`boost-core ^0.14`** (floor bump — adopters must move their boost-core constraint to `^0.14`). No wrapper code change; the crossing adopts boost-core 0.14's project-scope reconcile-on-sync.

### Breaking

#### boost-core floor raised to `^0.14`

`sandermuller/boost-core` is now required at `^0.14.0` (was `^0.13.0`). Consumers on boost-core 0.13 must bump to `^0.14`:

```bash
composer require sandermuller/boost-core:^0.14














```
(Consumers who track boost-core transitively through this package get it on a `composer update --with-all-dependencies` — no explicit require needed.)

`BoostWrapperContract` is unchanged, so the wrapper needs no code change — verified against the 0.14 tree (full gauntlet green; the live sync confirmed the behaviors below in this package's own consumer tree).

### What the crossing brings (boost-core 0.14)

- **Agent-deselection guidance-orphan reap.** Dropping an agent from `boost.php`'s `withAgents()` now reaps its now-stale guidance file (`CLAUDE.md` / `AGENTS.md` / `GEMINI.md`) — but only when boost owns it (the on-disk `sha256` matches boost-core's sync manifest). A file you hand-edited since the last sync has a diverged sha and is **preserved** (never-lossy). This closes the stale-orphan case where a de-selected agent's guidance file lingered indefinitely (boost-core previously pruned the agent's skill dir but left its guidance file).
- **gitignore dir/per-file dedup.** The boost-managed `.gitignore` block no longer lists per-skill `SKILL.md` entries already covered by the dir-level glob (`.agents/skills/`, `.claude/skills/`). The block collapses back to dir-level only — this release's `.gitignore` is that self-heal. (boost-core 0.13's wrapper-emit gitignore entries had double-listed dir + per-file; 0.14 coalesces by prefix.)
- **Emitter-dormancy reap.** A file emitter that goes dormant has its previously-emitted output reaped (sha-gated, same never-lossy guard). Not exercised by this package directly, but part of the same reconcile-on-sync pass.

The dev-only `sandermuller/package-boost-laravel` umbrella resolves to `0.9.1` (its boost-core constraint widened to `^0.13 || ^0.14`); no change to this package's own constraint on it.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.6.0...0.7.0

## 0.6.0 - 2026-05-31

<!-- verified-sha: d2552273e92e5876279a6f1044cfc4c02e4c2a2f -->
Crosses the package to **`boost-core ^0.13`** (floor bump — adopters must move their boost-core constraint to `^0.13`), and folds in the PHP-version guideline scoping fix from the 0.5.x line.

### Breaking

#### boost-core floor raised to `^0.13`

`sandermuller/boost-core` is now required at `^0.13.0` (was `^0.11.0`). Consumers tracking boost-core 0.11 or 0.12 must bump to `^0.13` to adopt this release:

```bash
composer require sandermuller/boost-core:^0.13















```
`BoostWrapperContract` is unchanged across 0.11–0.13 (same `injectedEmitPaths()` signature), so this wrapper needs no code change — verified against the 0.13 tree. The crossing brings two consumer-visible boost-core changes:

- **Markerless agent-guidance files (0.12).** `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` are wholesale-owned by boost-core (no managed-region markers), with an empty-over-non-empty preserve guard — boost never blanks a non-empty guidance file. On the first sync, content found outside boost's generated output is preserved below it with a warning; move durable hand-content into `.ai/guidelines/`.
- **Sync manifest (0.13).** boost-core writes `.boost/manifest.json` recording every emitted path with provenance — this package's injected skill files are tagged `wrapper:sandermuller/project-boost-laravel`, so a bare-CLI `boost sync` reliably preserves them. The boost-managed `.gitignore` block gains a `.boost/` entry.

The dev-only `sandermuller/package-boost-php` constraint moved to `^0.15.0` (it requires boost-core `^0.13`).

### Fixed

#### PHP-version guidelines scoped to the declared floor

(Folded from the 0.5.x line.) The guideline install-gate scoped version-major fragments to the host's major — correct for package dirs (`laravel/11` vs `laravel/12`), but it had dropped *every* `php/<version>` fragment, losing a project's own PHP-version guidance (a PHP 8.5 project lost `array_first`, the pipe operator `|>`, `clone`-with).

`php/8.x` fragments are cumulative-downward — each lists features new in that version, usable on any later PHP — so the gate now keeps `php/<v>` for every `v` at or below the project's declared `require.php` floor (the range the code must support). A `^8.5` project keeps `php/8.4` + `php/8.5`; a `^8.3` project keeps `≤8.3` and isn't told to use syntax it can't rely on. The reader also skips empty-rendering guidelines, mirroring `laravel/boost`'s `GuidelineComposer` (`filled()`), so boost's empty per-version PHP fragments don't emit as noise.

### Compatibility

- PHP `^8.3`
- Laravel `^12.0 || ^13.0`
- `laravel/boost` `^2.4`
- `sandermuller/boost-core` `^0.13.0` (raised from `^0.11.0`)
- `orchestra/testbench` `^11.1` (dev)

### Upgrade notes

```bash
composer require sandermuller/boost-core:^0.13
composer update sandermuller/project-boost-laravel sandermuller/boost-core
php artisan project-boost:sync















```
After the update:

- Your agent-guidance files become markerless boost-owned output. If you kept hand-written notes in `CLAUDE.md`/`AGENTS.md`, move them to `.ai/guidelines/` — boost preserves out-of-band content below its output on the migration sync and warns, but `.ai/guidelines/` is the durable home. Track `CLAUDE.md` so any overwrite is visible in git.
- A `.boost/manifest.json` appears (gitignored) and your injected skill files are recorded as wrapper-owned — a stray bare-CLI sync no longer risks deleting them.
- PHP-version guidance is scoped to your declared `require.php` floor; drop any `withExcludedGuidelines` entries you were carrying for off-floor `php-8.x` fragments.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.5.0...0.6.0

## 0.5.0 - 2026-05-31

<!-- verified-sha: 309e2c02a552c19f921f7d4b3c36aaeb693fec78 -->
Scopes version-major guideline fragments to the host's installed major — resolving the known limitation carried since 0.4.0. The guideline install-gate now mirrors `laravel/boost`'s `GuidelineComposer` faithfully on the version axis, not just package presence.

### Changed

#### Version-major guideline scoping

The install-gate (added in 0.4.0) keyed only on the top-level package segment, so every version-major sub-fragment emitted regardless of the host's actual major:

- a Laravel 12 app received BOTH `laravel/11` and `laravel/12` guidance;
- a host received every `php/8.x` fragment (`php/8.2` … `php/8.5`) even though `laravel/boost` composes only `php/core`, never the per-version dirs.

`laravel/boost`'s `GuidelineComposer` composes only `<dir>/{majorVersion}` for a package guideline dir (the host's installed major), and composes no version subdir for non-package dirs (it emits `php/core`, never `php/8.x`). This release matches that:

- **Package version dirs** — `<pkg>/<version>/…` emits only when `<pkg>` is an installed, gate-allowed package AND `<version>` is its host major (resolved via `Laravel\Roster\Roster`'s `majorVersion()`). A Laravel 12 app gets `laravel/12` guidance; `laravel/11` is dropped.
- **Non-package version dirs** — `php/8.x` and any other version subdir under a dir that isn't a Composer package are dropped entirely. Only the top-level `php/core` emits, exactly as `laravel/boost` composes it.

`LaravelBoostGuidelineReader` extracts the version sub-segment from each guideline path and passes it to the gate; the gate decides per the rule above. Package-presence gating, priority/exclusion rules, and the non-Composer-package pass-through (herd, enforce-tests) from 0.4.0/0.4.1 are unchanged.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` then `php artisan project-boost:sync`. After the update you'll see fewer guideline fragments — wrong-major package guidance and per-version PHP fragments stop emitting. If you were carrying `withExcludedGuidelines` entries for off-major or `php/8.x` fragments (e.g. `laravel-11-core` on a Laravel 12 app, `php-8.2-core`), you can drop them — the gate now handles those.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.4.1...0.5.0

## 0.4.1 - 2026-05-30

<!-- verified-sha: a0fe7d9ad5b2b410f36afc23fd5f6e8ccd1ab126 -->
Fixes a regression in 0.4.0's guideline install-gate: it dropped `herd-core` (and any other guideline whose source isn't a Composer package) for every consumer.

### Fixed

#### Install-gate no longer drops non-Composer-package guidelines

0.4.0's `LaravelBoostGuidelineGate` was an allow-list — a guideline segment was emitted only if it was a core guideline or mapped to an installed Composer package. But some guidelines laravel/boost ships aren't gated on package presence at all: `herd-core` is gated on runtime detection (a `.test` app URL + the Herd binary), and `enforce-tests` on install-time config. The gate had no signal for those, so it denied them — dropping `herd-core` for every consumer, including those serving via Herd. Unlike an over-emitted guideline, a dropped one has no `withExcludedGuidelines` lever to add it back.

The gate is now a deny-list: a segment is suppressed only when it maps to a known Composer package (any `laravel/roster` `Packages` case) the host hasn't installed, or that priority/exclusion filtering removed. Segments that aren't Composer packages — `herd`, `enforce-tests` — pass through.

0.4.0's package-gating fix is unchanged: `inertia`, `pest`, `sail`, `pennant`, and the rest are Composer packages, so they stay gated out when uninstalled. A Livewire + Filament + PHPUnit app still won't receive Inertia/Pest/Sail guidance, and `pest-core` still won't contradict `phpunit-core` — but a Herd user keeps `herd-core`.

#### Known limitation (unchanged from 0.4.0)

Version-major sub-fragments (`laravel/11` vs `laravel/12`, `php/8.x`) are not yet scoped to the host's installed major — they key on the top-level package segment only, so all majors still emit. Tracked for a later faithful-mirror pass. If you're on a single major and want only its fragment, keep the corresponding `withExcludedGuidelines` entries for now.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.4.0...0.4.1

## 0.4.0 - 2026-05-30

<!-- verified-sha: 37f9ca9c248c6260fab3c59f067ce919ad4bfbe5 -->
Two precision improvements to what this wrapper puts on disk, plus the floor bump to `boost-core` 0.11.0 that one of them requires.

- **Guidelines are now install-gated** — an app only receives guidelines for packages it actually installed.
- **The wrapper declares its emit surface** — a stray bare-CLI `boost sync` no longer flags this wrapper's injected skill files for deletion.

Floor bump: `sandermuller/boost-core` `^0.11.0` (was `^0.8.2 || ^0.9.0 || ^0.10.0`).

### Added

#### `BoostWrapper` — bare-CLI drift protection

This package injects laravel/boost-bundled skills into `boost-core`'s `SyncEngine` at sync time. The resulting `<agent-skill-dir>/<name>/SKILL.md` files live on disk afterward. A bare `vendor/bin/boost sync` (no wrapper injection args) used to see those files with no backing source and flag every one as stale-to-delete.

`boost-core` 0.11.0 introduced `BoostWrapperContract` to close that false-positive. This release ships `SanderMuller\ProjectBoostLaravel\BoostWrapper` implementing it: `injectedEmitPaths()` enumerates the laravel/boost-bundled skill names and maps each across the active agents' skill directories (resolved through `boost-core`'s own `AgentTarget` API, so the layout stays in lockstep across engine versions), declaring the set so the cleanup pass preserves them.

The shared `.agents/skills/` pool (Copilot, Codex, and the other shared-pool agents) is declared once, not per-agent. Scope is deletion-exclusion only — a bare CLI still won't *(re)emit* the laravel/boost set, so `php artisan project-boost:sync` remains the correct hook; `boost doctor`'s entry-point banner continues to point operators there.

### Fixed

#### Install-gated guideline emission

`LaravelBoostGuidelineReader` walked the entire `vendor/laravel/boost/.ai/` tree and emitted every package's guidelines unconditionally. A Livewire + Filament + PHPUnit app would receive Inertia, Pest, and Sail guidelines it never installed — and `pest-core` directly contradicts `phpunit-core`, so the noise was also a correctness problem.

The new `LaravelBoostGuidelineGate` mirrors laravel/boost's own `GuidelineComposer` / `DiscoverPackagePaths` detection:

- only core guidelines + guidelines for packages the host actually installed are emitted;
- PHPUnit-vs-Pest and Flux-free-vs-Flux-pro priority (the higher-priority package shadows the other);
- Boost and Sail excluded from package discovery (Boost is a core guideline already; Sail is opt-in);
- MCP and Livewire counted only as direct requirements.

The reader takes an optional gate; when `laravel/roster` can't resolve the host's packages the gate is permissive (emit-all), so there is no regression on detection-unavailable setups. `project-boost:sync` scans the host roster once and shares it with both the version resolver and the gate.

Surfaced by a real-world downstream consumer whose Livewire/Filament/PHPUnit stack received the contradicting test-framework guidance.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up, pulling `boost-core` 0.11.0. After the update:

- Apps will see fewer guidelines emitted — only those matching installed packages. This is the intended fix; a guideline that disappeared was for a package you don't have.
- Bare-CLI `boost sync` runs stop deleting this wrapper's injected skill files. Sync through `php artisan project-boost:sync` as before for the full injected set.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.8...0.4.0

## 0.3.8 - 2026-05-29

<!-- verified-sha: 8f8c12b986dbc4c320a0f19250e92b2d5ad8ee78 -->
Open beat for the `boost-core` 0.10.x line per the widened-OR lifecycle pattern. Widens the `sandermuller/boost-core` constraint from `^0.8.2 || ^0.9.0` to `^0.8.2 || ^0.9.0 || ^0.10.0` so consumers can adopt `boost-core` 0.10.x (the wrong-entry-point cycle's fixes) without waiting for a hard floor bump.

Safe drop-in upgrade from 0.3.7. No code change in this package's own surface.

### Why this release

`boost-core` 0.10.0 just shipped the wrong-entry-point cycle's coordinated improvements:

- **Laravel-aware composer-hook scaffold** — `boost install` now detects Laravel + `project-boost-laravel` context and emits `@php artisan project-boost:sync` instead of `BoostAutoSync::run` for fresh installs. Closes the foot-gun that mijntp inherited and was silently missing 10+ laravel/boost-bundled skills.
- **`boost doctor` Entry-point mismatch banner** — when bare CLI runs in a Laravel project where `project-boost-laravel` is present, doctor now surfaces a banner pointing operators at the artisan-flavored hook with explicit cross-agent-asymmetry context (Claude Code's MCP server may mask the absence while Cursor / Copilot / Codex silently miss).
- **Three-case diagnostic-copy split for "possible typo" tag warnings** — case 2 names the bare-CLI-without-wrapper-injection scenario explicitly, including the cross-agent dimension.

Consumers using this wrapper benefit from the engine-side improvements (banner, diagnostic copy, scaffold fix) the moment they bump `boost-core` to 0.10.x. The wrapper itself needs no behavior changes — the engine improvements operate at engine surfaces (CLI banner, diagnostic strings, scaffold output) that this wrapper doesn't override.

Caught by mijntp proving consumer ([`iqyla3z3`](https://github.com/sandermuller)) on PR #5057: their `composer require --dev sandermuller/boost-core:^0.10` plus `sandermuller/project-boost-laravel:^0.3.7` resolved to a constraint conflict because this wrapper's `^0.8.2 || ^0.9.0` doesn't accept 0.10.x. Same wrapper-pin-lag chain-blocker shape from the `boost-core 0.9.0` cycle that 0.3.4 unblocked via the original widened-OR move. Pattern repeats.

### What's in

#### Constraint widening (no code change)

| Dependency | Old | New |
|---|---|---|
| `sandermuller/boost-core` | `^0.8.2 \|\| ^0.9.0` | `^0.8.2 \|\| ^0.9.0 \|\| ^0.10.0` |

Consumers stay on whichever `boost-core` minor their root `composer.json` allows; this wrapper now accepts all three.

#### Open beat per widened-OR lifecycle

Per the family strategy doc's widened-OR lifecycle pattern (authored by `pszozhdu` (`package-boost-php`) and folded mid-cycle):

- **Open** — the OR widen ships as a patch on the wrapper; existing `^0.9.0` consumers continue to resolve, new `^0.10.0` consumers become possible. ← This release.
- **Absorb (one or more)** — `boost-core` 0.10.x stable-line evolution absorbs transparently via the OR; the wrapper ships patches whenever upstream produces visible-to-consumer state.
- **Close** — when the close-decision conditions hold (upstream higher-bound stabilized + no known active consumers in lower-bound sub-range + maintenance cost non-trivial), narrow the constraint as a minor bump.

Three-clause OR is at the compound-OR retention policy boundary (default N=3). Future widens (when `boost-core` 0.11.x ships) will require closing one of the existing clauses; the `^0.8.2` close beat is the natural candidate when its close conditions clearly hold.

#### Wrapper-side integration with 0.10.x

`project-boost:sync` calls `SyncEngine::default()->sync(injectedVendorSkills: ..., injectedVendorGuidelines: ...)` — the same call signature as in 0.8.x and 0.9.x. The engine's 0.10.0 internal changes (banner emission via `DoctorCommand`, diagnostic-copy split via the typo-check surface, scaffold-template detection via `InstallCommand`) are opaque to the wrapper. The engine maintainer (`6scam1ri`) confirmed wrapper-side integration ahead of 0.10.0 tagging; this wrapper inherits the engine improvements transitively with no code changes.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up. The wrapper's own resolved `boost-core` version doesn't change for consumers who weren't trying to bump the engine.

If you want to adopt `boost-core 0.10.x` after this release: bump your root `composer.json` to `sandermuller/boost-core: ^0.10.0` (or widen-OR yourself), then `composer update sandermuller/boost-core`. The engine-side improvements (banner, diagnostic copy, scaffold fix) become visible at sync time.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.7...0.3.8

## 0.3.7 - 2026-05-29

Docs-only release. Corrects the auto-sync section to lead with `@php artisan project-boost:sync` as THE composer hook for Laravel projects using this package, and adds a "Why not `BoostAutoSync::run`?" subsection explaining the wrong-entry-point foot-gun.

Safe drop-in upgrade from 0.3.6. No code change.

### What's in

#### `## Auto-sync on composer install` section rewrite

Previous section led with `SanderMuller\BoostCore\Scripts\BoostAutoSync::run` (boost-core's bare-CLI composer hook helper) as the recommended composer post-install hook, with a footnote noting that laravel/boost-bundled skill changes require a separate manual `php artisan project-boost:sync` invocation.

Caught via the mijntp proving consumer ([`iqyla3z3`](https://github.com/sandermuller)) during a setup audit: their `composer.json` carried `BoostAutoSync::run` as the post-install hook (inherited from `boost install` scaffold's Laravel-unaware default emission), and they were silently missing 12+ laravel/boost-bundled skills (`pest-testing`, `livewire-development`, `filament-development`, `inertia-development`, `eloquent-models`, and several more) across their entire `boost-skills` 1.7.0 adoption window. Sync still reported success on every run; nothing in the output surfaced the absence.

Corrected section structure:

- Headlines `@php artisan project-boost:sync` as THE composer hook for Laravel projects using this package. Explains that the artisan-flavored hook routes through this wrapper's injection pipeline and lists the bundled skills the hook surfaces.
- Adds a `### Why not BoostAutoSync::run?` subsection that explicitly describes the wrong-entry-point foot-gun, why operators typically don't notice (bare-CLI sync still reports success against the smaller skill set; nothing emits "you're missing N skills"), and clarifies that the bare-CLI helper IS correct for non-Laravel projects consuming `boost-core` directly without a wrapper.

The previous wording was technically defensible — it warned consumers to "run `php artisan project-boost:sync` manually" — but it framed manual invocation as a workaround rather than as the canonical composer-hook shape. Operators reading the section reasonably wired `BoostAutoSync::run` and never manually-invoked the artisan command, because the README presented `BoostAutoSync::run` first as if it were the primary recommendation.

#### Belt-and-suspenders with `boost-core` 0.10.0 scaffold-template fix

`boost-core` 0.10.0 (queued upstream as task #62) lands a Laravel-aware composer-hook emission in the `boost install` scaffold: when `composer.json` requires both `laravel/framework` and `sandermuller/project-boost-laravel`, the scaffold emits the artisan-flavored hook by default. Once that ships and fresh installs inherit the right hook, this README guidance becomes consumer-side belt-and-suspenders rather than primary defense.

The README guidance stays even after the scaffold fix, because many consumers won't re-run `boost install` for years after their initial setup — they'd remain on the legacy `BoostAutoSync::run` hook indefinitely unless their `composer.json` is hand-edited. The README is the only mechanism that reaches that population.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up. No behavior change in the package itself — the docs fix is consumer-facing only.

**Action item for consumers using `BoostAutoSync::run`:** check your `composer.json` `scripts.post-install-cmd` and `scripts.post-update-cmd`. If either uses `SanderMuller\BoostCore\Scripts\BoostAutoSync::run` in a Laravel project where you're consuming `project-boost-laravel`, replace with `@php artisan project-boost:sync`. After the change, your next `composer install` or `composer update` will fan out the laravel/boost-bundled skills (~12 skills typically) that the bare-CLI hook was silently bypassing.

You can verify by running `php artisan project-boost:sync` directly and checking the output for `wrote` lines mentioning paths like `.claude/skills/pest-testing/`, `.claude/skills/livewire-development/`, etc. If those skills appear under `unchanged` (because they were already written by an earlier artisan invocation), great. If they don't appear at all and only show up after this fix, your prior bare-CLI hook was hiding them from your agents.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.6...0.3.7

## 0.3.6 - 2026-05-28

Two related bare-CLI-in-Laravel-project fixes landing alongside `boost-core` 0.9.4's diagnostics-rendering improvements.

Safe drop-in upgrade from 0.3.5.

### What's in

#### `BladeRenderer` container-bootstrap guard

`RendersBladeGuidelines` (the laravel/boost trait this renderer composes from) internally calls `Container::path()` — a method on `Illuminate\Foundation\Application`, not on the base `Illuminate\Container`. When bare `vendor/bin/boost sync` runs in a Laravel project AND the consumer's `boost.php` declares `->withSkillRenderers(BladeRenderer::class)`, the renderer gets instantiated against the bare Container (the artisan kernel hasn't bootstrapped the Application), and the eventual `Container::path()` call fails with a cryptic "undefined method" error mid-render.

Before `boost-core` 0.9.3 (the render-fail-then-write safety gate), the error fired AFTER partial content was already in flight to managed regions — operators saw corrupted `CLAUDE.md` output without a clear explanation. With 0.9.3 the data-loss surface is bounded; with 0.9.4 the diagnostics surface around it improves; with this release the wrapper-side surfaces a clear "use artisan instead" message before the renderer even attempts the bootstrap-dependent work.

Implementation: top of `BladeRenderer::render()` checks `Container::getInstance() instanceof Application` and throws an actionable `RuntimeException` if not:

```
BladeRenderer requires a bootstrapped Laravel Application; the current
container is a bare Illuminate\Container. This typically happens when
running `vendor/bin/boost sync` in a Laravel project. Use `php artisan
project-boost:sync` instead — the artisan entry point bootstraps the
framework before invoking the renderer. To skip Blade rendering
entirely, remove BladeRenderer from your boost.php withSkillRenderers()
declaration.





















```
Combined with the engine's 0.9.3 safety gate (which converts the thrown exception into a `SyncResult::error` rather than letting it propagate mid-write), the worst-case path is now: operator sees a clear message, no partial writes happen, recovery is straightforward.

#### `SyncCommand` diagnostics section header rename

`boost-core` 0.9.4 renames the diagnostics section header from `Project Conventions` to `Diagnostics` because the `SyncResult::diagnostics` list now carries multiple kinds beyond conventions content (parseable-divergence warnings, schema parse failures, scaffold notes, etc.). Mirroring the rename here keeps visual language consistent across both entry points (`php artisan project-boost:sync` and `vendor/bin/boost sync`).

`SyncCommand::renderDiagnostics()` already orders the render BEFORE the `hasErrors` short-circuit (same shape `boost-core` 0.9.4 locked in engine-side), so no structural change beyond the header rename.

### Trace note: no engine-side auto-discovery layer

The `BladeRenderer` fix is purely wrapper-side. The engine maintainer's trace ([`boost-core` task #53](https://github.com/sandermuller/boost-core)) confirmed no auto-discovery layer exists engine-side — `PassthroughRenderer` is the only renderer `boost-core` ships, and every other renderer must be explicitly wired via `BoostConfig->withSkillRenderers([...])` in the consumer's `boost.php` OR passed as `extraSkillRenderers` to `SyncEngine::sync()`.

This means the bare-CLI Blade trigger path lives entirely in the consumer's `boost.php` declaration → BladeRenderer's `render()` method, with no engine-side eager-instantiation surface to fix. The earlier "engine-side prevention + wrapper-side self-defense" framing assumed an auto-discovery layer that doesn't exist; the actual fix is wrapper-side container-aware bail only.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up. Behavior change is additive:

- If your `boost.php` declares `BladeRenderer` in `withSkillRenderers()` AND you've been running bare `vendor/bin/boost sync` (not `php artisan project-boost:sync`) in a Laravel project: you'll now see a clear `RuntimeException` with guidance instead of a cryptic `Container::path()` stack trace. The actionable path is `php artisan project-boost:sync`.
- If you've been running `php artisan project-boost:sync` only: no behavior change.
- If your `boost.php` doesn't register `BladeRenderer`: no behavior change. The guard fires only when the renderer is actually invoked.

The diagnostics section header rename from `Project Conventions` to `Diagnostics` is also additive — it changes the label on a previously-named section, doesn't alter what gets rendered into it.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.5...0.3.6

## 0.3.5 - 2026-05-28

Bug-fix release. `project-boost:sync` was silently dropping the engine's `SyncResult::diagnostics` channel — operators saw re-renders happen (`wrote CLAUDE.md`) but got no explanation for why. Fix propagates the same diagnostics surface `boost-core`'s own `vendor/bin/boost sync` renders.

Safe drop-in upgrade from 0.3.4.

### What's in

#### `project-boost:sync` now surfaces engine diagnostics

`SyncResult::diagnostics` has been on the engine boundary since `boost-core` 0.8.0 — carrying lenient `error` / `warning` / `info` diagnostics from the conventions-schema layer (parseable-divergence warnings, schema parse failures, scaffold notes). This wrapper's `SyncCommand::renderResult()` never iterated the channel, so operators running `php artisan project-boost:sync` saw only the per-file write summary:

```
  unchanged AGENTS.md
  wrote CLAUDE.md
Sync complete · wrote=1 · deleted=0 · unchanged=118






















```
Same output between "no divergence" and "divergence resolved by re-render" runs. Operator sees the re-render happened but gets no signal explaining the WHY — even when the engine emitted a parseable-divergence warning to the diagnostics channel.

Fix mirrors `boost-core`'s own `SyncCommand::renderConventionsDiagnostics()` pattern: section header `Project Conventions`, ✗/⚠/ℹ glyph per `Diagnostic::level`, slot + vendor decoration when present. Same visual language across `php artisan project-boost:sync` and `vendor/bin/boost sync` invocations, so operators get a consistent diagnostic surface regardless of which entry point they used.

Example post-fix output when the engine emits a divergence warning:

```
  unchanged AGENTS.md
  wrote CLAUDE.md

Project Conventions
  ⚠ db-strategy: CLAUDE.md body diverged from boost.php's withConventions(); re-rendered from boost.php as canonical source.

Sync complete · wrote=1 · deleted=0 · unchanged=118






















```
### Why this surfaced now

`boost-core` 0.9.1 ships the parseable-divergence diagnostic that finally exercised the previously-empty diagnostics channel through this wrapper's call path. The wrapper-side gap had existed since 0.8.0 but went unnoticed because the diagnostics list was always empty in practice. The 0.9.1 verification cycle from a proving consumer (running the engine's adoption pass) caught it explicitly.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up. Behavior change is additive: when the engine emits diagnostics, they now appear in your sync output. When the engine emits nothing (most syncs that don't touch conventions schema), output is identical to 0.3.4.

If you're invoking `php artisan project-boost:sync` from CI / scripts and depend on parsing the stdout shape, the new `Project Conventions` section appears between the delete-attribution warning (if any) and the final `Sync complete` summary line. Empty diagnostics list = no section emitted.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.4...0.3.5

## 0.3.4 - 2026-05-28

Widens the `sandermuller/boost-core` constraint from `^0.8.2` to `^0.8.2 || ^0.9.0` so consumers can adopt the engine's 0.9.0 conventions-source-flip release without waiting for this wrapper to hard-bump. No code change in this package's own surface.

Safe drop-in upgrade from 0.3.3.

### Why this release

`sandermuller/boost-core` 0.9.0 moved the operator-edit surface for Project Conventions from a marker-bounded region inside `CLAUDE.md` to a `withConventions([...])` chained method on `BoostConfig` in `boost.php`. CLAUDE.md continues to stay tracked — operator-authored content outside the conventions markers (custom H1, intro prose) is preserved across sync via the same marker-bounded round-trip safety shipped in `boost-core` 0.8.2.

Proving consumers running the engine's 1.7.0 / 0.9.0 adoption cycle (notably hihaho on slot-domain vocabulary and mijntp on Filament/Livewire vocabulary) blocked on this wrapper's `^0.8.2` constraint — Composer refused resolution any time a root require pulled `boost-core ^0.9` while this wrapper transitively pinned `^0.8.2`. Widening the constraint to `^0.8.2 || ^0.9.0` unblocks adoption without forcing consumers still on 0.8.x to migrate.

### What's in

#### Constraint widening (no code change)

| Dependency | Old | New |
|---|---|---|
| `sandermuller/boost-core` | `^0.8.2` | `^0.8.2 || ^0.9.0` |

Consumers stay on whichever boost-core minor their root `composer.json` allows; this wrapper now accepts both.

#### Wrapper-side integration with 0.9.0

`project-boost:sync` calls `SyncEngine::default()->sync(injectedVendorSkills: ..., injectedVendorGuidelines: ...)` — the same call signature as in 0.8.x. The engine's 0.9.0 internal flip (`SyncEngine` reads `$config->conventions` from `BoostConfig` instead of extracting from CLAUDE.md) is opaque to the wrapper. The P1 wrapper-merger fix in 0.9.0 (`InjectedVendorMerger::mergeExtraRenderers` now preserves `$config->conventions` during rebuild) does not affect this wrapper's call paths, since `extraSkillRenderers` is not passed by this package per the `SyncCommand` docblock decision to pre-render Blade in the reader pipeline.

The cross-version integration was verified at the source level by the engine maintainer ahead of 0.9.0 tagging; this wrapper inherits the conventions-flip transitively with no code changes.

#### Migration for consumers using `withConventions()`

If you adopt `boost-core 0.9.0` and want to move existing `## Project Conventions` content from `CLAUDE.md` into `boost.php`'s `withConventions([...])`:

> Run `vendor/bin/boost convert-conventions` then commit `boost.php` and `CLAUDE.md` together as a single "Migrate Project Conventions to boost.php" change. CLAUDE.md stays tracked — operator-authored content outside the conventions markers (custom H1, intro prose) is preserved across sync.

The migration command lives at the engine level — invoke `vendor/bin/boost convert-conventions` directly, same pattern as `vendor/bin/boost where`. No artisan wrapper provided.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up. The wrapper's own resolved boost-core version doesn't change for consumers who weren't trying to bump the engine.

If you want to adopt `boost-core 0.9.0` after this release: bump your root `composer.json` to `sandermuller/boost-core: ^0.9.0` (or widen-OR yourself), then `composer update sandermuller/boost-core`. Run `vendor/bin/boost convert-conventions` if you had a `## Project Conventions` block in CLAUDE.md to migrate.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.3...0.3.4

## 0.3.3 - 2026-05-28

Constraint-floor patch. Bumps `sandermuller/boost-core` from `^0.8.0` to `^0.8.2` so `composer install` / `composer update` pulls the engine version that fixes a destructive `CLAUDE.md` regression.

Safe drop-in upgrade from 0.3.2. No API or behavior change in this package's own surface.

### Why this release

`sandermuller/boost-core` 0.8.0 and 0.8.1 wholesale-overwrote `CLAUDE.md` on every `vendor/bin/boost sync`, wiping the operator-filled `## Project Conventions` block. Root cause was engine-side — `formatGuidelinesContent()` returned concatenated guideline bodies and `FileWriter` wrote the whole file, destroying every region outside the guidelines content. Fix shipped as `boost-core` 0.8.2, converting the guidelines write to the marker-bounded `ManagedRegion` pattern already used by the `.gitignore` and conventions blocks.

This package's `project-boost:sync` wrapper inherits the fix transitively — no wrapper-side code change was needed. The constraint floor bump is the mechanism that forces absorption: a consumer on a locked `boost-core 0.8.0` / `0.8.1` would keep running the buggy engine until they ran `composer update`, so floor-bumping makes the fix mandatory on next install rather than discretionary.

### Upgrade notes

`composer update sandermuller/project-boost-laravel` picks it up. If a downstream project's `CLAUDE.md` was wiped during a run of `boost-core` 0.8.0 / 0.8.1, restoring the `## Project Conventions` block from git history is the only recovery path — there's no incremental migration this release can perform. Going forward (engine 0.8.2+), the block survives every `vendor/bin/boost sync`.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.2...0.3.3

## 0.3.2 - 2026-05-27

### What's in

#### README rewrite

The previous README sold the package as a bolt-on. The new one leads with three things a reader needs to make a decision:

1. **Family-routing table at the top.** "Which package fits your role?" — covers the seven packages in the sandermuller boost family, with `← you are here` annotation marking the Laravel-app row. Self-locating for anyone landing here directly from Packagist.
2. **`## What this adds on top of laravel/boost` side-by-side comparison.** Concrete feature delta in two columns: `laravel/boost` alone vs. + this package. Headline rows cover tag filtering, remote skill sources, vendor allowlist, `boost where` origin tracing, user-scope sync, and `boost doctor --check-versions`. The agent-count row was removed late in the cycle when `laravel/boost` shipped Antigravity upstream — the durable axes (framework-agnostic scope, explicit allowlist, remote skill sources, conventions schema) carry the comparison; agent count was never the durable axis.
3. **`## Where do the skills come from?` four-source menu.** Skills stack from four sources: hand-authored `.ai/skills/` folder, Composer-installed catalog package, external GitHub sources via `withRemoteSkills()`, and `laravel/boost`'s bundled set. Mix freely. `sandermuller/boost-skills` is framed as one example of the catalog-package pattern, not as a recommended dependency.

Architecture and remote-skills sections shortened to link out to [boost-core's README](https://github.com/sandermuller/boost-core) instead of duplicating engine internals.

Tone shift throughout: complements-not-competes with `laravel/boost`. First person introduced in the skill-source menu. Em-dash count down, defensive "not X; Y" framings replaced with direct statements.

#### Skill-source filter semantics corrected

The previous README claimed that `withAllowedVendors()` and `withTags()` "apply uniformly regardless of where a skill came from." That claim was false on both axes — `boost-core`'s `SyncEngine::resolveSkills` gates them asymmetrically per source:

- `withAllowedVendors()` gates Composer-scanned vendors (source 2) only. Host skills, remote-declared skills, and the `laravel/boost` wrapper bundle all bypass it.
- `withTags()` filters sources 2, 3, and 4 — but NOT host skills (source 1). Host skills bypass `SkillTagFilter` entirely (parallel comment in the engine documents "host guidelines are never filtered" for the same reason).

Corrected wording surfaces both axes explicitly. The bug was found by an external code-review pass on the family snippets file and verified by `boost-core`'s maintainer against current source. The corrected per-source matrix is now the canonical reference across the family.

#### `boost.php` config

- `Tag::Laravel` added to `withTags()`. The previous PHP/Github-only tag set was missing the Laravel context tag for the companion's own dev environment.
- `withTags()` call reflowed from one-line minified to multi-line with one tag per line, matching the family convention (the multi-line shape is hand-edited; `composer boost:install` emits the minified shape).
- `->withDisabledEmitters([])` no-op stripped from the dogfood config. Empty-array configuration calls are functional no-ops but propagate to published examples — `boost-core` 0.8.0 also fixed the scaffold template so future installs don't inherit the line.

### Upgrade notes

No breaking changes in this package's API or behavior. `composer update sandermuller/project-boost-laravel` picks it up, with the caveat that the new `boost-core ^0.8` floor will pull the engine forward if your composer.json allowed it.

The README rewrite + filter-semantics correction are the user-visible artefacts; runtime behavior is identical to 0.3.1.

If your project's `boost.php` was authored against the previous README's examples, it still works as-is — the `withTags()` set in the README continues to use `Tag::Laravel, Tag::Php` as the minimal recommendation; the four-source skill menu is documentation of how the existing API surfaces work, not a new mechanism.

**Full Changelog**: https://github.com/SanderMuller/project-boost-laravel/compare/0.3.1...0.3.2

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
