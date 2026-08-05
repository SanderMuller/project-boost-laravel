# Upgrading

## From 1.1 to 1.2

### Changed

- **Requires `laravel/boost ^2.5`** (was `^2.4`). `1.2.0` restores compatibility
  with `laravel/roster 1.0.0`, whose API rewrite removed the `Packages` enum and
  the `Roster` class this package was built against — on `1.1.0` any consumer
  running `composer update`/`install` hard-crashes during the
  `project-boost:sync` post-hook with `Class "Laravel\Roster\Enums\Packages" not
  found`. Only `laravel/boost 2.5.0` adapted to Roster `1.0`; `2.4.x` still
  requires `laravel/roster ^0.5`, so there is no version of this fix that works
  on the `2.4` line.

  ```bash
  composer require --dev "sandermuller/project-boost-laravel:^1.2" -W
  ```

  `-W` matters here: `laravel/boost` is usually a sibling top-level require in
  the consuming app, so it needs to move to `^2.5` in the same resolve.

- **`laravel/roster ^1.0` is now an explicit requirement.** It was previously
  pulled in only transitively through `laravel/boost`, despite this package
  type-hinting its classes directly — which is how the upstream rewrite reached
  consumers with no constraint to stop it. No action needed unless you pin
  `laravel/roster` yourself, in which case move it to `^1.0`.

### No migration

- The `PUBLIC_API.md` surface is unchanged: same CLI commands, options, exit
  codes, and config keys. The rewritten classes (`LaravelBoostGuidelineGate`,
  `VersionResolver`) are `@internal`.
- Generated output is unchanged for a project whose packages Roster already
  resolved correctly. Guideline gating for **npm** packages (`inertia-react`,
  `inertia-svelte`, `inertia-vue`, `tailwindcss`) is now driven by the js side
  of the scan, matching `laravel/boost`'s own discovery — if you were receiving
  one of those guidelines without the package installed, it now correctly drops.

## From 0.10 to 1.0

`1.0.0` is the API-stability commitment: the surface in `PUBLIC_API.md` (CLI
command names + options + exit codes, config keys, discovery contracts, frozen
formats) is locked for the `1.x` line.

### Changed

- **Requires `sandermuller/boost-core ^1.0`** (was `^0.23.0||^1.0`) — the `0.23`
  range is dropped. boost-core `1.0` is a drop-in over `0.23.3` (no API break),
  so if you are already on boost-core `^0.23` the bump is mechanical:

  ```bash
  composer require --dev "sandermuller/project-boost-laravel:^1.0"
  ```

  If you require `sandermuller/boost-core` or `sandermuller/boost-skills`
  directly, move them to the 1.0 family in the same command (boost-skills `2.2.0`
  already admits boost-core `^1.0`):

  ```bash
  composer require --dev \
      "sandermuller/project-boost-laravel:^1.0" \
      "sandermuller/boost-core:^1.0" -W
  ```

### No behaviour change

- Nothing runtime changed at `1.0.0` — it is the `0.10.2` surface, re-validated
  against the boost-core `1.x` family and frozen. The `project-boost:install` /
  `:sync` / `:where` commands, config keys, and generated output are identical.

## From 0.9 to 0.10

### Changed

- **Requires `sandermuller/boost-core ^0.23`** (was `^0.22`), with
  `sandermuller/package-boost-laravel ^0.15` and `sandermuller/boost-skills
  ^2.1`. This is a **dev** package, so keep `--dev` — a bare `composer require`
  silently MOVES it into production `require`:

  ```bash
  composer require --dev "sandermuller/project-boost-laravel:^0.10"
  ```

  **If you require `sandermuller/boost-skills` directly** (most app consumers
  do), bump it in the SAME command. A directly-required `boost-skills ^2.0.6`
  transitively pins `boost-core ^0.22`, which collides with `0.10`'s `^0.23`
  floor — and `-W` alone can't resolve it, because `boost-skills` is a sibling
  top-level require, not a dependency of this package. Bump both:

  ```bash
  composer require --dev \
      "sandermuller/project-boost-laravel:^0.10" \
      "sandermuller/boost-skills:^2.1" -W
  ```

  Pin each boost package to the floor you actually validated against, not the
  wrapper's transitive floor. If you auto-run `project-boost:sync` from a
  composer post-install/post-update script, a later lock regeneration or a
  `--prefer-lowest` resolve can otherwise pull an OLDER `boost-skills` than the
  one you tested — and since `boost-skills` decides which skills/guidelines
  ship, that silently shifts generated guidance + the managed `.gitignore`
  across developers and CI.

### Fixed (behaviour change, no migration for valid configs)

- A malformed (non-string) `metadata.boost-tags` on a laravel/boost skill now
  **fails closed** (ships to no agent) instead of being treated as untagged and
  shipping everywhere — matching boost-core's own tag semantics. If you somehow
  relied on the old behaviour, fix the skill's frontmatter to a space-delimited
  string. Valid and absent `boost-tags` are unaffected.

## From 0.8 to 0.9

### Changed

- **Requires `sandermuller/boost-core ^0.22`** (was `^0.16`). boost-core's
  `0.21` line changed the `FileEmitter::emit()` contract to return `iterable`,
  and `0.9` re-points this package onto boost-core's frozen `@api` surface — so
  the whole boost stack moves to the `0.22` line together.

  Most apps only require this package and get boost-core transitively. Bumping
  this package is enough:

  ```bash
  composer require "sandermuller/project-boost-laravel:^0.9"
  ```

  If you require any boost package directly (you pin `boost-core`, or carry the
  `sandermuller/package-boost-laravel` umbrella as a dev dependency), move them
  to the `0.22` line in the same command:

  ```bash
  composer require \
      "sandermuller/boost-core:^0.22" \
      "sandermuller/package-boost-laravel:^0.14" \
      "sandermuller/boost-skills:^2.0.6"
  ```

  Running this package against `boost-core < 0.22` no longer resolves.

- **Hand-edit `withTags(...)` to array form BEFORE you bump.** boost-core `0.20`
  changed `withTags(Tag::Php, Tag::Jira)` to `withTags([Tag::Php, Tag::Jira])`.
  The `project-boost:sync` hook `require`s your boost config during
  `composer update`, so an un-migrated variadic call throws a `TypeError` at
  that point — before boost-core's own AST auto-migration can run. Fix the call
  first:

  ```php
  // before (pre-0.20)
  ->withTags(Tag::Laravel, Tag::Php)
  // after
  ->withTags([Tag::Laravel, Tag::Php])
  ```

  If you forget, `project-boost:sync` now prints this exact migration hint
  instead of aborting `composer update` with a raw stack trace.

### Fixed (no migration needed)

- `project-boost:sync`, `project-boost:where`, and the non-interactive
  `project-boost:install` now resolve the boost config from **both** the legacy
  root `boost.php` and the canonical `.config/boost.php` layout (boost-core
  `>= 0.17`). If you kept `boost.php` at the project root only to satisfy these
  commands, you may move it to `.config/boost.php` — but no action is required
  either way.
