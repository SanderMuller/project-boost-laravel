<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;
use SanderMuller\BoostCore\Sync\BoostSync;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\ProjectBoostLaravel\BoostWrapper;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;

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

test('never returns guideline files (AGENTS.md / GEMINI.md)', function (): void {
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

/**
 * Write a root `boost.php` into a wrapper fixture root.
 */
function writeWrapperBoostPhp(string $root, string $chain): void
{
    file_put_contents("{$root}/boost.php", <<<PHP
        <?php declare(strict_types=1);

        use SanderMuller\\BoostCore\\Config\\BoostConfig;
        use SanderMuller\\BoostCore\\Enums\\Agent;

        return BoostConfig::configure(){$chain};
        PHP);
}

test('does not claim a skill the boost config excludes', function (): void {
    // A claim exempts a path from stale cleanup on every sync. Claiming an
    // excluded skill keeps its old files, and its manifest entries, forever.
    $root = wrapperProjectRoot([
        'pest/pest-testing' => 'blade.php',
        'folio/folio-routing' => 'md',
    ]);
    writeWrapperBoostPhp($root, "->withAgents([Agent::CLAUDE_CODE])->withExcludedSkills(['laravel/boost:folio-routing'])");

    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))
        ->toBe(['.claude/skills/pest-testing/SKILL.md']);
});

test('does not claim a skill the tag filter drops', function (): void {
    // folio-routing carries `laravel folio` from the sidecar manifest, so a
    // project that declares only `laravel` does not ship it.
    $root = wrapperProjectRoot([
        'pest/pest-testing' => 'blade.php',
        'folio/folio-routing' => 'md',
    ]);
    writeWrapperBoostPhp($root, "->withAgents([Agent::CLAUDE_CODE])->withTags(['laravel'])");

    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))
        ->toBe(['.claude/skills/pest-testing/SKILL.md']);
});

test('claims a Laravel Cloud skill only when the project declares the cloud tag', function (): void {
    $root = wrapperProjectRoot(['deployments/cloud-deploy' => 'md']);

    writeWrapperBoostPhp($root, '->withAgents([Agent::CLAUDE_CODE])');
    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))->toBeEmpty();

    writeWrapperBoostPhp($root, "->withAgents([Agent::CLAUDE_CODE])->withTags(['cloud'])");
    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))
        ->toBe(['.claude/skills/cloud-deploy/SKILL.md']);
});

test('claims every skill when the boost config cannot be loaded', function (): void {
    // Under-claiming lets a bare sync reap real files, so any doubt about the
    // config falls back to the full set.
    $root = wrapperProjectRoot(['folio/folio-routing' => 'md']);
    file_put_contents("{$root}/boost.php", '<?php throw new RuntimeException("broken");');

    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))
        ->toBe(['.claude/skills/folio-routing/SKILL.md']);
});

test('claims every skill when the loaded config is not the one the engine used', function (): void {
    // The engine can run with an explicit config file the wrapper cannot see.
    // An agent list that differs from the one the engine passed means the
    // wrapper read a different config, so it cannot trust that config's filters.
    $root = wrapperProjectRoot(['folio/folio-routing' => 'md']);
    writeWrapperBoostPhp($root, "->withAgents([Agent::CURSOR])->withExcludedSkills(['laravel/boost:folio-routing'])");

    expect(BoostWrapper::injectedEmitPaths($root, ['claude-code']))
        ->toBe(['.claude/skills/folio-routing/SKILL.md']);
});

test('a sync removes the files and manifest entries of a skill that stops shipping', function (string $chainAfter): void {
    // End to end through boost-core: the engine discovers this wrapper, and its
    // claim must match what the sync ships. A claimed path is exempt from stale
    // cleanup, and boost-core's tag-filter pruner refuses a skill directory with
    // a companion file, so an over-claim kept these files forever.
    $root = wrapperProjectRoot([]);
    $aiRoot = "{$root}/vendor/laravel/boost/.ai";
    foreach (['laravel/skill/kept-skill', 'deployments/skill/dropped-skill'] as $relative) {
        mkdir("{$aiRoot}/{$relative}/references", 0o755, true);
        file_put_contents("{$aiRoot}/{$relative}/SKILL.md", "---\nname: " . basename($relative) . "\ndescription: A fixture skill.\n---\nBody.");
        file_put_contents("{$aiRoot}/{$relative}/references/notes.md", 'Companion notes.');
    }

    $sync = static function () use ($root, $aiRoot): void {
        $skills = (new LaravelBoostAssetReader($aiRoot, new LaravelBoostTagManifest()))->readSkills();
        $packages = new InstalledPackages([
            'sandermuller/project-boost-laravel' => new PackageInfo(
                name: 'sandermuller/project-boost-laravel',
                version: 'dev-main',
                installPath: dirname(__DIR__, 2),
            ),
        ]);

        $result = BoostSync::make($packages)->sync($root, injectedVendorSkills: ['laravel/boost' => $skills]);

        expect($result->hasErrors())->toBeFalse();
    };

    writeWrapperBoostPhp($root, "->withAgents([Agent::CLAUDE_CODE])->withTags(['cloud'])");
    $sync();

    expect(file_exists("{$root}/.claude/skills/dropped-skill/references/notes.md"))->toBeTrue()
        ->and(file_get_contents("{$root}/.boost/manifest.json"))->toContain('dropped-skill');

    writeWrapperBoostPhp($root, $chainAfter);
    $sync();

    expect(file_exists("{$root}/.claude/skills/dropped-skill/SKILL.md"))->toBeFalse()
        ->and(file_exists("{$root}/.claude/skills/dropped-skill/references/notes.md"))->toBeFalse()
        ->and(file_exists("{$root}/.claude/skills/kept-skill/references/notes.md"))->toBeTrue()
        ->and(file_get_contents("{$root}/.boost/manifest.json"))->not->toContain('dropped-skill');
})->with([
    'excluded' => ["->withAgents([Agent::CLAUDE_CODE])->withTags(['cloud'])->withExcludedSkills(['laravel/boost:dropped-skill'])"],
    'tag-filtered' => ['->withAgents([Agent::CLAUDE_CODE])'],
]);
