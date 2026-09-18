<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;
use SanderMuller\ProjectBoostLaravel\BoostWrapper;

/**
 * Build a fake project root with laravel/boost skills under
 * vendor/laravel/boost/.ai/<pkg>/[<major>/]skill/<name>/SKILL.{md,blade.php}.
 *
 * @param  array<string, string>  $skills  map of `<pkg>[/<major>]/<name>` → extension
 */
function wrapperProjectRoot(array $skills): string
{
    $root = sys_get_temp_dir() . '/project-boost-laravel-wrapper-' . bin2hex(random_bytes(8));
    $aiRoot = "{$root}/vendor/laravel/boost/.ai";

    foreach ($skills as $relative => $ext) {
        $dir = "{$aiRoot}/" . dirname($relative) . '/skill/' . basename($relative);
        mkdir($dir, 0o755, true);
        file_put_contents("{$dir}/SKILL.{$ext}", "---\nname: " . basename($relative) . "\n---\nbody");
    }

    return $root;
}

function removeWrapperFixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }

    @rmdir($path);
}

afterEach(function (): void {
    $dirs = glob(sys_get_temp_dir() . '/project-boost-laravel-wrapper-*');
    if ($dirs === false) {
        return;
    }

    foreach ($dirs as $dir) {
        removeWrapperFixture($dir);
    }
});

test('is named BoostWrapper at the package PSR-4 root implementing the contract', function (): void {
    // boost-core discovers the wrapper by the exact class name `BoostWrapper`
    // under a declared PSR-4 prefix, implementing BoostWrapperContract. Pin
    // all three via runtime reflection (a rename/move/contract-drop breaks it).
    $reflection = new ReflectionClass(BoostWrapper::class);

    expect($reflection->getShortName())->toBe('BoostWrapper')
        ->and($reflection->getNamespaceName())->toBe('SanderMuller\ProjectBoostLaravel')
        ->and($reflection->implementsInterface(BoostWrapperContract::class))->toBeTrue();
});

test('emits one path per skill per active agent, sharing the .agents pool', function (): void {
    $root = wrapperProjectRoot([
        'pest/pest-testing' => 'blade.php',
        'folio/folio-routing' => 'md',
    ]);

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code', 'copilot', 'codex']);
    sort($paths);

    // Claude Code is dedicated (.claude/skills); Copilot + Codex share the
    // .agents/skills pool, so each skill yields ONE .agents path, not two.
    expect($paths)->toBe([
        '.agents/skills/folio-routing/SKILL.md',
        '.agents/skills/pest-testing/SKILL.md',
        '.claude/skills/folio-routing/SKILL.md',
        '.claude/skills/pest-testing/SKILL.md',
    ]);
});

test('the shared .agents pool path is returned exactly once for copilot + codex', function (): void {
    // The dedup pin the boost-core maintainer asked for.
    $root = wrapperProjectRoot(['pest/pest-testing' => 'blade.php']);

    $paths = BoostWrapper::injectedEmitPaths($root, ['copilot', 'codex']);

    expect($paths)->toBe(['.agents/skills/pest-testing/SKILL.md']);
});

test('collapses version variants of the same skill to one name', function (): void {
    // pest/3 + pest/4 both ship pest-testing — the version-resolved sync emits
    // a single .../pest-testing/SKILL.md, so the claim set must too.
    $root = wrapperProjectRoot([
        'pest/3/pest-testing' => 'blade.php',
        'pest/4/pest-testing' => 'blade.php',
    ]);

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code']);

    expect($paths)->toBe(['.claude/skills/pest-testing/SKILL.md']);
});

test('returns an empty list when no laravel/boost skills are present', function (): void {
    $root = sys_get_temp_dir() . '/project-boost-laravel-wrapper-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, true);

    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))
        ->toBeEmpty();
});

test('returns an empty list when no agents are active', function (): void {
    $root = wrapperProjectRoot(['pest/pest-testing' => 'blade.php']);

    expect(BoostWrapper::injectedEmitPaths($root, []))
        ->toBeEmpty();
});

test('silently skips an agent identifier boost-core does not know', function (): void {
    // `activeTargets()` resolves via `Agent::tryFrom($a)?->target()`, so an
    // agent value boost-core's enum doesn't carry (a not-yet-supported or
    // host-registered custom agent) is dropped, not thrown — boost-core's
    // stale-cleanup pass must never crash on it. A regression to
    // `Agent::from()` would raise a ValueError here and fail this test.
    $root = wrapperProjectRoot(['pest/pest-testing' => 'blade.php']);

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code', 'totally-unknown-agent']);

    // Only the known agent contributes a path; the unknown one is skipped.
    expect($paths)->toBe(['.claude/skills/pest-testing/SKILL.md']);
});

test('never returns guideline files (CLAUDE.md / AGENTS.md / GEMINI.md)', function (): void {
    $root = wrapperProjectRoot([
        'pest/pest-testing' => 'blade.php',
        'folio/folio-routing' => 'md',
    ]);

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code', 'copilot', 'codex', 'gemini']);

    foreach ($paths as $path) {
        expect(basename($path))->toBe('SKILL.md');
    }
});

test('claims each skill asset, with Blade names rewritten to .md', function (): void {
    // boost-core emits an injected skill's assets under
    // `<skill-dir>/<name>/<asset-path>`. An unclaimed asset is reaped by the
    // same bare-CLI cleanup pass that would otherwise delete SKILL.md.
    $root = wrapperProjectRoot(['pest/pest-testing' => 'blade.php']);
    $skillDir = "{$root}/vendor/laravel/boost/.ai/pest/skill/pest-testing";
    mkdir("{$skillDir}/rules", 0o755, true);
    file_put_contents("{$skillDir}/rules/assertions.blade.php", 'blade');
    file_put_contents("{$skillDir}/rules/datasets.md", 'plain');
    file_put_contents("{$skillDir}/SKILL.md.license", 'MIT');
    file_put_contents("{$skillDir}/rules/assertions.md~", 'stale');

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code']);
    sort($paths);

    expect($paths)->toBe([
        '.claude/skills/pest-testing/SKILL.md',
        '.claude/skills/pest-testing/rules/assertions.md',
        '.claude/skills/pest-testing/rules/datasets.md',
    ]);
});

test('claims assets from the version-resolved variant only, not every variant', function (): void {
    $root = wrapperProjectRoot([
        'pest/3/pest-testing' => 'blade.php',
        'pest/4/pest-testing' => 'blade.php',
    ]);
    $aiRoot = "{$root}/vendor/laravel/boost/.ai";
    mkdir("{$aiRoot}/pest/3/skill/pest-testing/rules", 0o755, true);
    mkdir("{$aiRoot}/pest/4/skill/pest-testing/rules", 0o755, true);
    file_put_contents("{$aiRoot}/pest/3/skill/pest-testing/rules/legacy.md", 'v3');
    file_put_contents("{$aiRoot}/pest/4/skill/pest-testing/rules/modern.md", 'v4');

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code']);
    sort($paths);

    // A claim exempts a path from stale cleanup on EVERY sync. Claiming the
    // pest/3 asset too would strand rules/legacy.md on disk forever once the
    // host moves to Pest 4 — over-declaring is only safe for names, whose
    // unemitted paths have no file to preserve. No composer.json here, so the
    // resolver falls back to its lex-last proxy and picks pest/4.
    expect($paths)->toBe([
        '.claude/skills/pest-testing/SKILL.md',
        '.claude/skills/pest-testing/rules/modern.md',
    ]);
});

test('a host whose package scan throws still claims its skill paths', function (): void {
    // boost-core answers a throwing injectedEmitPaths() by dropping the whole
    // claim, and a bare `boost sync` then reaps every injected skill file. So a
    // host with an unreadable composer.json must degrade to the lex-last
    // variant proxy, never to an exception.
    $root = wrapperProjectRoot([
        'pest/3/pest-testing' => 'blade.php',
        'pest/4/pest-testing' => 'blade.php',
    ]);
    file_put_contents("{$root}/composer.json", '{ this is not valid json');

    $paths = BoostWrapper::injectedEmitPaths($root, ['claude-code']);

    expect($paths)->toBe(['.claude/skills/pest-testing/SKILL.md']);
});
