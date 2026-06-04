# Upgrading

## From 0.9 to 0.10

### Changed

- **Requires `sandermuller/boost-core ^0.23`** (was `^0.22`), with
  `sandermuller/package-boost-laravel ^0.15` and `sandermuller/boost-skills
  ^2.1`. Most apps only bump this package; boost-core resolves transitively:

  ```bash
  composer require "sandermuller/project-boost-laravel:^0.10"
  ```

  If you pin any boost package directly, move them to the `0.23` line together.

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
