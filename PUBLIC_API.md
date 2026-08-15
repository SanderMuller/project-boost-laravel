# Public API

The semver-protected surface of `sandermuller/project-boost-laravel`. Everything under **Stable surface** and **Frozen formats & conventions** is covered by [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — it will not break in a MINOR or PATCH of the same MAJOR. Everything marked `@internal`, and every implementation detail, may change in any release.

This package is a behavior wrapper, not a library: most of its value is the sync *behavior* and the CLI, not a PHP class surface. It ships **no skills of its own** — it reads laravel/boost's bundled skills/guidelines and fans them out across the agents you declare.

## Versioning

Semantic Versioning 2.0.0. From `1.0.0`, the surface below is locked for the `1.x` line — it will not break in a MINOR or PATCH. Pre-`1.0` history (where MINOR bumps could break it) is in `CHANGELOG.md` / `UPGRADING.md`.

## Stable surface

**No `@api` PHP classes.** This package exposes no class a consumer constructs or extends by name — its value is the CLI, config, and behavior below. (Its `SkillRenderer` implementation, `Rendering\BladeRenderer`, is `@internal`: it is auto-registered on the `project-boost:sync` path and never named by consumers.)

## Frozen formats & conventions

A class-only freeze misses most of this package's contract. These are frozen too:

### CLI

The command names, their documented options, and the exit-code contract (`0` ok, `1` failure):

- `project-boost:install` — `--no-sync`, `--no-interaction`
- `project-boost:sync` — `--dry-run`, `--show-untagged`, `--keep-boost-json` (added in 1.2)
- `project-boost:where`
- `project-boost:reconcile` — `--dry-run`, `--force`, `--no-sync` (added in 1.1)

Human-readable output text is NOT a contract.

### Config (`config/project-boost-laravel.php`)

- `suppress_upstream_writers` (env `PROJECT_BOOST_SUPPRESS_UPSTREAM`) — opt-in `CommandStarting` guard that injects `--mcp` into ad-hoc `boost:install` calls. Default `false`.
- `laravel_boost_ai_root` — override for laravel/boost's `.ai` root. Default `null` (auto-detect under `vendor/laravel/boost`).

### Discovery contracts

- The service provider FQCN `SanderMuller\ProjectBoostLaravel\ProjectBoostLaravelServiceProvider` (registered via `extra.laravel.providers`) — pinned for package discovery; not consumer-instantiated.
- The wrapper class name/namespace `SanderMuller\ProjectBoostLaravel\BoostWrapper` implementing boost-core's `@api` `BoostWrapperContract` — boost-core discovers it by name (guarded by a reflection test). `@internal`, but its identity is pinned.

### Tag sidecar manifest

- `resources/boost/laravel-boost-tags.yaml` — maps `<skill-name>: <space-delimited tags>`, supplying `boost-tags` for laravel/boost's bundled skills whose upstream frontmatter declares none. Its location and `name → tags` shape are part of the surface.

### Behavior + mechanism

- `project-boost:sync` reads laravel/boost's skills/guidelines from `vendor/laravel/boost/.ai/<pkg>/[<major>/]{skill,guideline}/…`, Blade-renders `.blade.php`, applies your `withTags()` filter, and injects them into boost-core via the **wrapper-injection** path (`BoostSync::sync(injectedVendorSkills:, injectedVendorGuidelines:)`) under the vendor key `laravel/boost`. It does **not** declare `extra.boost.*` skill/guideline paths and ships no skill set of its own.

## Internal (not covered by semver)

Every class is `@internal` and may change in any release — do not import or extend: the three console command classes, `BoostWrapper`, the service provider, `Rendering\BladeRenderer`, `Discovery\*` (`LaravelBoostAssetReader`, `LaravelBoostGuidelineReader`, `LaravelBoostGuidelineGate`, `LaravelBoostTagManifest`, `VersionResolver`), `Listeners\EnforceMcpFlagOnBoostInstall`, and `Console\Concerns\LoadsBoostConfig`. A pest-arch test asserts every `src/` class is marked `@api` or `@internal` so the boundary can't erode.

## Stability policy

Deprecations are announced in a MINOR (kept working, documented in `CHANGELOG.md` / `UPGRADING.md`) and removed only in a MAJOR. New optional parameters / config keys are additive and not breaking.
