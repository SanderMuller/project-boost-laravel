<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Coexistence;

use JsonException;
use Laravel\Boost\Support\Config;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * Retires laravel/boost's `boost.json` once this package has taken over the
 * emission it describes — by ARCHIVING it, and only after its agent list has been
 * adopted into the project's own config.
 *
 * **Why retiring it is safe.** `boost.json` is read by exactly two things —
 * laravel/boost's `boost:install` and `boost:update` (`Laravel\Boost\Support\Config`
 * is referenced nowhere else). The MCP server does not read it: `boost:mcp` is
 * `Artisan::call('mcp:start laravel-boost')` against a server its ServiceProvider
 * registers unconditionally. This package does not read it either — it re-derives
 * laravel/boost's guidelines and skills from `vendor/laravel/boost/.ai/` on every
 * sync. (`config/boost.php`, laravel/boost's Laravel config file, is a different
 * file and is untouched.)
 *
 * **Why retiring it is the point.** `boost:update` bails out before doing anything
 * when the file is missing (`! $config->isValid() || empty($config->getAgents())`).
 * Herd runs `php artisan boost:update` on its own after `herd link` whenever
 * `vendor/laravel/boost` is present, which re-seeds laravel/boost's guidelines and
 * skills behind the operator's back and puts the guidance files back into a
 * takeover state on the next sync. With no `boost.json`, that trigger is inert.
 *
 * **Why it is archived rather than deleted, and gated on adoption.** The file's
 * `agents` list is the only record of what the operator picked in laravel/boost's
 * installer, and nothing imports it automatically. So: while it names an agent the
 * project's own config does not declare, it stays put and the operator is pointed at
 * `vendor/bin/boost install` (which pre-selects exactly those agents). Once the
 * config covers them, the file moves into boost's state directory instead of being
 * unlinked — gitignored, skipped by boost-core's stale-file sweep, and recoverable.
 *
 * Two further guards: a `boost.json` that records no agent list is not laravel/boost's
 * live install state — another tool's file, or one `boost:update` already refuses to
 * act on — and is left alone; and a symlinked path is never moved.
 *
 * @internal
 */
final class BoostJsonRemover
{
    public const string FILE = 'boost.json';

    public const string ARCHIVE_NAME = 'boost.json.retired';

    /**
     * The only key that identifies the file as laravel/boost's live install state.
     *
     * The other keys it writes ({@see Config}: `skills`, `packages`, `cloud`, …) are
     * generic enough that another tool's `boost.json` could carry them, and archiving
     * a foreign config is worse than leaving an inert one alone. A non-empty `agents`
     * list is also exactly what makes the file operative: `boost:update` bails out on
     * `empty($config->getAgents())`, so a file without one is already no trigger.
     */
    private const string LARAVEL_BOOST_STATE_KEY = 'agents';

    /**
     * boost-core's state directory per config layout: `.config/boost` belongs to a
     * `.config/boost.php` project, `.boost` to a root `boost.php` one. Both are
     * gitignored by boost-core's managed block AND skipped by its stale-file sweep,
     * so an archive there neither dirties the working tree nor gets reaped — but only
     * for the layout actually in use, which is why the config file decides, not
     * whichever directory happens to exist.
     */
    private const string CONFIG_DIR_LAYOUT_CONFIG = '.config/boost.php';

    private const string CONFIG_DIR_LAYOUT_STATE = '.config/boost';

    private const string ROOT_LAYOUT_STATE = '.boost';

    public function retire(string $projectRoot, BoostConfig $config, bool $dryRun): BoostJsonOutcome
    {
        $root = rtrim($projectRoot, '/');
        $path = $root . '/' . self::FILE;

        if (is_link($path)) {
            return new BoostJsonOutcome(BoostJsonRemoval::SYMLINK);
        }

        if (! is_file($path)) {
            return new BoostJsonOutcome(BoostJsonRemoval::ABSENT);
        }

        $state = $this->decode($path);
        if ($state === null) {
            return new BoostJsonOutcome(BoostJsonRemoval::FOREIGN);
        }

        $unadopted = $this->unadoptedAgents($state, $config);
        if ($unadopted !== []) {
            return new BoostJsonOutcome(BoostJsonRemoval::AGENTS_NOT_ADOPTED, unadoptedAgents: $unadopted);
        }

        $archiveDirRelative = $this->archiveDirectory($root, $config);
        if ($archiveDirRelative === null) {
            return new BoostJsonOutcome(BoostJsonRemoval::NO_ARCHIVE_LOCATION);
        }

        $target = $this->resolveArchiveTarget($root, $archiveDirRelative, $path);
        if ($target === null) {
            return new BoostJsonOutcome(BoostJsonRemoval::NO_ARCHIVE_LOCATION);
        }

        if ($dryRun) {
            return new BoostJsonOutcome(BoostJsonRemoval::WOULD_ARCHIVE, archivePath: $target['path']);
        }

        // An archive already holding this exact content makes the source redundant:
        // the recovery copy is there, so removing the original loses nothing.
        if (! $target['move']) {
            return @unlink($path)
                ? new BoostJsonOutcome(BoostJsonRemoval::ARCHIVED, archivePath: $target['path'])
                : new BoostJsonOutcome(BoostJsonRemoval::FAILED);
        }

        $archivePath = $root . '/' . $target['path'];
        $archiveDir = dirname($archivePath);

        if (! is_dir($archiveDir) && ! @mkdir($archiveDir, 0o755, recursive: true) && ! is_dir($archiveDir)) {
            return new BoostJsonOutcome(BoostJsonRemoval::FAILED);
        }

        // Rename, not copy-then-delete: the original is never gone unless the archive
        // exists.
        if (! @rename($path, $archivePath)) {
            return new BoostJsonOutcome(BoostJsonRemoval::FAILED);
        }

        return new BoostJsonOutcome(BoostJsonRemoval::ARCHIVED, archivePath: $target['path']);
    }

    /**
     * laravel/boost agent names (its own spelling) that the project's boost config
     * does not declare. Its `boost.json` stores snake_case `Agent::name()` values
     * and boost-core's enum is kebab-case; `claude_code` → `claude-code` is the only
     * spelling that differs, so a `_` → `-` swap covers the whole set.
     *
     * An agent boost-core has no case for (`antigravity`, `factory`, `grok_build`,
     * `pi`, `zed`) can never be adopted, so it must NOT block retirement — it is
     * reported by the sync command instead.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function unadoptedAgents(array $state, BoostConfig $config): array
    {
        $declared = [];
        foreach ($config->agents as $agent) {
            $declared[$agent->value] = true;
        }

        $unadopted = [];
        foreach ($this->agentNames($state) as $name) {
            $agent = Agent::tryFrom(str_replace('_', '-', $name));
            if (! $agent instanceof Agent) {
                continue; // no boost-core equivalent — nothing to adopt
            }

            if (! isset($declared[$agent->value])) {
                $unadopted[$name] = true;
            }
        }

        return array_keys($unadopted);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function agentNames(array $state): array
    {
        $agents = $state[self::LARAVEL_BOOST_STATE_KEY] ?? null;
        if (! is_array($agents)) {
            return [];
        }

        $names = [];
        foreach ($agents as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The state directory to archive into, project-relative — or null when there
     * isn't a safe one, in which case the file is kept.
     *
     * The LAYOUT decides, not directory existence: a leftover `.config/boost/` in a
     * root-`boost.php` project is not boost-core's state dir there, and writing into
     * it could leave an untracked file behind. Gitignore management must also be on;
     * with it off boost-core writes and ignores nothing, so no destination can be
     * assumed safe. A symlinked state dir is rejected too — `rename()` would follow
     * it and park the operator's file outside the project while the command reported
     * a project-relative path.
     */
    private function archiveDirectory(string $projectRoot, BoostConfig $config): ?string
    {
        if (! $config->manageGitignore) {
            return null;
        }

        $relative = is_file($projectRoot . '/' . self::CONFIG_DIR_LAYOUT_CONFIG)
            ? self::CONFIG_DIR_LAYOUT_STATE
            : self::ROOT_LAYOUT_STATE;

        return $this->hasSymlinkedSegment($projectRoot, $relative) ? null : $relative;
    }

    /**
     * Where this retirement lands, project-relative — and whether the source has to
     * MOVE there or is already represented by an identical archive (in which case it
     * is simply dropped). Null means no safe target: keep the file.
     *
     * `boost.json.retired` is used while it is free or already holds this exact
     * content. Otherwise the archive is content-addressed, so a `boost:install` that
     * regenerated `boost.json` with DIFFERENT content never overwrites the earlier
     * recovery copy. Every candidate must be a regular file — a symlinked archive
     * would put the "recovery copy" outside the project, where it can vanish
     * independently, and an occupied content-addressed name that does not match is
     * refused rather than clobbered.
     *
     * @return array{path: string, move: bool}|null
     */
    private function resolveArchiveTarget(string $projectRoot, string $archiveDirRelative, string $sourcePath): ?array
    {
        $default = $archiveDirRelative . '/' . self::ARCHIVE_NAME;
        $defaultAbsolute = $projectRoot . '/' . $default;

        if (is_link($defaultAbsolute)) {
            return null;
        }

        if (! file_exists($defaultAbsolute)) {
            return ['path' => $default, 'move' => true];
        }

        if ($this->isSameFile($defaultAbsolute, $sourcePath)) {
            return ['path' => $default, 'move' => false];
        }

        $raw = @file_get_contents($sourcePath);
        if ($raw === false) {
            return null;
        }

        $sibling = $default . '-' . hash('sha256', $raw);
        $siblingAbsolute = $projectRoot . '/' . $sibling;

        if (is_link($siblingAbsolute)) {
            return null;
        }

        if (! file_exists($siblingAbsolute)) {
            return ['path' => $sibling, 'move' => true];
        }

        return $this->isSameFile($siblingAbsolute, $sourcePath)
            ? ['path' => $sibling, 'move' => false]
            : null;
    }

    private function isSameFile(string $left, string $right): bool
    {
        $a = @file_get_contents($left);
        $b = @file_get_contents($right);

        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * Does any segment of the project-relative path resolve through a symlink?
     */
    private function hasSymlinkedSegment(string $projectRoot, string $relative): bool
    {
        $accumulated = $projectRoot;
        foreach (explode('/', $relative) as $segment) {
            $accumulated .= '/' . $segment;
            if (is_link($accumulated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The decoded state file, or null when it is not laravel/boost's live install
     * state: unreadable, malformed JSON, not an object, or carrying no agent list.
     * Both malformed JSON and a missing agent list are deliberately kept —
     * `boost:update` refuses to run on either, so the trigger is already inert and
     * touching a file that may belong to another tool buys nothing.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $this->agentNames($decoded) === [] ? null : $decoded;
    }
}
