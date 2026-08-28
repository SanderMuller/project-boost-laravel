<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillAsset;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostAssetReader;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostTagManifest;

function makeFixtureRoot(): string
{
    $root = sys_get_temp_dir() . '/project-boost-laravel-fixture-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, true);

    return $root;
}

function rmFixtureRoot(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.') {
            continue;
        }

        if ($entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
        if (is_dir($full) && ! is_link($full)) {
            rmFixtureRoot($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

function writeSkill(string $root, string $package, string $skill, string $content, string $ext = 'md'): void
{
    $dir = "{$root}/{$package}/skill/{$skill}";
    mkdir($dir, 0o755, true);
    file_put_contents("{$dir}/SKILL.{$ext}", $content);
}

test('discovers SKILL.md and SKILL.blade.php under <pkg>/skill/<name>/ when a Blade renderer is wired', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\ndescription: F.\n---\nbody");
        writeSkill($root, 'folio', 'bar-skill', "---\nname: bar-skill\ndescription: B.\n---\nbody", 'blade.php');

        $fakeBlade = new class implements SkillRenderer {
            /** @return list<string> */
            public function extensions(): array
            {
                return ['blade.php'];
            }

            public function render(string $raw, RenderContext $ctx): string
            {
                return $raw;
            }
        };

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest(), bladeRenderer: $fakeBlade);
        $names = array_map(fn (Skill $s) => $s->name, $reader->readSkills());

        sort($names);
        expect($names)->toBe(['bar-skill', 'foo-skill']);
    } finally {
        rmFixtureRoot($root);
    }
});

test('silently skips Blade skills when no renderer is wired (matches boost-core pre-renderer default)', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");
        writeSkill($root, 'folio', 'bar-skill', "---\nname: bar-skill\n---\nbody", 'blade.php');

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());
        $names = array_map(fn (Skill $s) => $s->name, $reader->readSkills());

        expect($names)->toBe(['foo-skill']);
    } finally {
        rmFixtureRoot($root);
    }
});

test('records render failure in renderErrors() and skips the offending skill', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'folio', 'broken', "---\nname: broken\n---\nbody", 'blade.php');

        $throwingRenderer = new class implements SkillRenderer {
            /** @return list<string> */
            public function extensions(): array
            {
                return ['blade.php'];
            }

            public function render(string $raw, RenderContext $ctx): string
            {
                throw new RuntimeException('blade explode');
            }
        };

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest(), bladeRenderer: $throwingRenderer);
        $skills = $reader->readSkills();

        expect($skills)->toBeEmpty()
            ->and($reader->renderErrors())->toHaveCount(1)
            ->and($reader->renderErrors()[0])->toContain('blade explode');
    } finally {
        rmFixtureRoot($root);
    }
});

test('frontmatter metadata.boost-tags wins over sidecar manifest', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill(
            $root,
            'foo',
            'tagged-skill',
            "---\nname: tagged-skill\nmetadata:\n  boost-tags: 'php inline'\n---\nbody",
        );

        $manifest = new LaravelBoostTagManifest(['tagged-skill' => ['from', 'sidecar']]);
        $reader = new LaravelBoostAssetReader($root, $manifest);
        $skill = $reader->readSkills()[0];

        expect($skill->tags)->toBe(['php', 'inline']);
    } finally {
        rmFixtureRoot($root);
    }
});

test('sidecar manifest fills tags when frontmatter has none', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'foo', 'untagged-skill', "---\nname: untagged-skill\n---\nbody");

        $manifest = new LaravelBoostTagManifest(['untagged-skill' => ['laravel', 'folio']]);
        $reader = new LaravelBoostAssetReader($root, $manifest);
        $skill = $reader->readSkills()[0];

        expect($skill->tags)->toBe(['laravel', 'folio']);
    } finally {
        rmFixtureRoot($root);
    }
});

test('a malformed metadata.boost-tags fails closed (tagsValid false), not ships untagged', function (): void {
    // Regression: the reader used to treat a malformed (non-string) boost-tags
    // as "untagged" and ship it everywhere with tagsValid:true — the opposite of
    // boost-core's fail-closed contract. Adopting the @api BoostTags::parse makes
    // a malformed value fail closed (ships nowhere), matching the engine. The
    // author DECLARED boost-tags (just malformed), so it must NOT fall back to
    // the sidecar manifest either.
    $root = makeFixtureRoot();
    try {
        writeSkill(
            $root,
            'foo',
            'broken-tags-skill',
            "---\nname: broken-tags-skill\nmetadata:\n  boost-tags:\n    - php\n    - inline\n---\nbody",
        );

        $manifest = new LaravelBoostTagManifest(['broken-tags-skill' => ['from', 'sidecar']]);
        $reader = new LaravelBoostAssetReader($root, $manifest);
        $skill = $reader->readSkills()[0];

        expect($skill->tagsValid)->toBeFalse()
            ->and($skill->tags)
            ->toBeEmpty();
    } finally {
        rmFixtureRoot($root);
    }
});

test('an explicitly-empty metadata.boost-tags ships untagged, not via the sidecar', function (): void {
    // `declaresTags` distinguishes "author declared (even empty) tags" from "no
    // boost-tags key". An empty declared value is untagged-on-purpose, so the
    // sidecar fallback must NOT kick in (it only fills when the key is absent).
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'foo', 'empty-tags-skill', "---\nname: empty-tags-skill\nmetadata:\n  boost-tags: ''\n---\nbody");

        $manifest = new LaravelBoostTagManifest(['empty-tags-skill' => ['from', 'sidecar']]);
        $reader = new LaravelBoostAssetReader($root, $manifest);
        $skill = $reader->readSkills()[0];

        expect($skill->tags)
            ->toBeEmpty()
            ->and($skill->tagsValid)->toBeTrue();
    } finally {
        rmFixtureRoot($root);
    }
});

test('sourceVendor stamped as laravel/boost', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'foo', 'any-skill', "---\nname: any-skill\n---\nbody");

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());
        $skill = $reader->readSkills()[0];

        expect($skill->sourceVendor)->toBe('laravel/boost')
            ->and($skill->excludeKey())
            ->toBe('laravel/boost:any-skill');
    } finally {
        rmFixtureRoot($root);
    }
});

test('returns empty list when laravel/boost asset root does not exist', function (): void {
    $reader = new LaravelBoostAssetReader('/nonexistent/path/anywhere', new LaravelBoostTagManifest());

    expect($reader->readSkills())
        ->toBeEmpty();
});

function writeAsset(string $root, string $package, string $skill, string $relativePath, string $content): void
{
    $path = "{$root}/{$package}/skill/{$skill}/{$relativePath}";
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents($path, $content);
}

/**
 * A renderer that strips a leading `@php … @endphp` block, so a test can tell a
 * rendered asset from a verbatim one.
 */
function fakeBladeRenderer(): SkillRenderer
{
    return new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return trim((string) preg_replace('/@php.*?@endphp\s*/s', '', $raw));
        }
    };
}

test('an injected skill carries its plain-file asset siblings verbatim', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nsee rules/a.md");
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.md', 'rule A');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/b.md', 'rule B');

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());
        $skill = $reader->readSkills()[0];

        $paths = array_map(fn (SkillAsset $a): string => $a->relativePath, $skill->assets);

        expect($paths)->toBe(['rules/a.md', 'rules/b.md'])
            ->and($skill->assets[0]->contents)->toBe('rule A');
    } finally {
        rmFixtureRoot($root);
    }
});

test('a Blade asset renders and its emit path is rewritten to .md', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nsee rules/a.md");
        writeAsset(
            $root,
            'laravel',
            'foo-skill',
            'rules/a.blade.php',
            "@php\n\$pest = true;\n@endphp\nrendered rule A",
        );

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest(), bladeRenderer: fakeBladeRenderer());
        $skill = $reader->readSkills()[0];

        expect($skill->assets)->toHaveCount(1)
            ->and($skill->assets[0]->relativePath)->toBe('rules/a.md')
            ->and($skill->assets[0]->contents)->toBe('rendered rule A');
    } finally {
        rmFixtureRoot($root);
    }
});

test('a Blade asset is skipped and counted when no renderer is wired', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.blade.php', 'blade');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/b.md', 'plain');

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());
        $skill = $reader->readSkills()[0];

        $paths = array_map(fn (SkillAsset $a): string => $a->relativePath, $skill->assets);

        expect($paths)->toBe(['rules/b.md'])
            ->and($reader->skippedBladeAssets())->toBe(1);
    } finally {
        rmFixtureRoot($root);
    }
});

test('a failing Blade asset lands in renderErrors() and emits no partial file, keeping the skill', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.blade.php', 'blade');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/b.md', 'plain');

        $throwingRenderer = new class implements SkillRenderer {
            /** @return list<string> */
            public function extensions(): array
            {
                return ['blade.php'];
            }

            public function render(string $raw, RenderContext $ctx): string
            {
                throw new RuntimeException('asset blade explode');
            }
        };

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest(), bladeRenderer: $throwingRenderer);
        $skills = $reader->readSkills();

        expect($skills)->toHaveCount(1)
            ->and(array_map(fn (SkillAsset $a): string => $a->relativePath, $skills[0]->assets))->toBe(['rules/b.md'])
            ->and($reader->renderErrors())->toHaveCount(1)
            ->and($reader->renderErrors()[0])->toContain('asset blade explode');
    } finally {
        rmFixtureRoot($root);
    }
});

test('entry-file siblings and editor-temp files are never assets', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");
        writeAsset($root, 'laravel', 'foo-skill', 'SKILL.md.license', 'MIT');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.md~', 'stale');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.md.bak', 'stale');
        writeAsset($root, 'laravel', 'foo-skill', '.hidden', 'nope');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.md', 'rule A');

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());
        $skill = $reader->readSkills()[0];

        expect(array_map(fn (SkillAsset $a): string => $a->relativePath, $skill->assets))->toBe(['rules/a.md']);
    } finally {
        rmFixtureRoot($root);
    }
});

test('a skill directory with no siblings reports zero assets', function (): void {
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());

        expect($reader->readSkills()[0]->assets)->toBeEmpty();
    } finally {
        rmFixtureRoot($root);
    }
});

test('two sources claiming one emit path drop the second and report a collision', function (): void {
    // The `.blade.php` → `.md` rewrite is the only way this happens. Silent
    // last-write-wins would ship whichever file the walker saw last.
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.md', 'plain');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.blade.php', 'blade');

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest(), bladeRenderer: fakeBladeRenderer());
        $skill = $reader->readSkills()[0];

        expect($skill->assets)->toHaveCount(1)
            // sortByName() decides which wins: `a.blade.php` sorts before
            // `a.md`. Arbitrary but deterministic across OSes.
            ->and($skill->assets[0]->contents)->toBe('blade')
            ->and($reader->renderErrors())->toHaveCount(1)
            ->and($reader->renderErrors()[0])->toContain('collision');
    } finally {
        rmFixtureRoot($root);
    }
});

test('an unreadable asset is skipped rather than emitted empty over valid content', function (): void {
    // `(string) file_get_contents()` on a failed read yields '', which the sync
    // would write over the previous good copy. Skip and report instead.
    $root = makeFixtureRoot();
    try {
        writeSkill($root, 'laravel', 'foo-skill', "---\nname: foo-skill\n---\nbody");
        writeAsset($root, 'laravel', 'foo-skill', 'rules/a.md', 'rule A');
        writeAsset($root, 'laravel', 'foo-skill', 'rules/b.md', 'rule B');
        chmod("{$root}/laravel/skill/foo-skill/rules/a.md", 0o000);

        $reader = new LaravelBoostAssetReader($root, new LaravelBoostTagManifest());
        $skill = $reader->readSkills()[0];

        expect(array_map(fn (SkillAsset $a): string => $a->relativePath, $skill->assets))->toBe(['rules/b.md'])
            ->and($reader->renderErrors())->toHaveCount(1)
            ->and($reader->renderErrors()[0])->toContain('unreadable');
    } finally {
        @chmod("{$root}/laravel/skill/foo-skill/rules/a.md", 0o644);
        rmFixtureRoot($root);
    }
})->skip(fn (): bool => posix_geteuid() === 0, 'root bypasses file permissions');
