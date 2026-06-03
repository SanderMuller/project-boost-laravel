# Upgrading

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

### Fixed (no migration needed)

- `project-boost:sync`, `project-boost:where`, and the non-interactive
  `project-boost:install` now resolve the boost config from **both** the legacy
  root `boost.php` and the canonical `.config/boost.php` layout (boost-core
  `>= 0.17`). If you kept `boost.php` at the project root only to satisfy these
  commands, you may move it to `.config/boost.php` — but no action is required
  either way.
