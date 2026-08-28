<?php declare(strict_types=1);

use Laravel\Boost\Support\PackageRegistry;
use Laravel\Roster\ProjectScan;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\ProjectBoostLaravel\Discovery\VersionResolver;

function makeSkill(string $name, string $sourcePath): Skill
{
    return new Skill(
        name: $name,
        description: null,
        frontmatter: [],
        body: '',
        sourcePath: $sourcePath,
        sourceVendor: 'laravel/boost',
    );
}

function scanWithPackage(string $package, string $version): ProjectScan
{
    return scanWithPackages([[$package, true, $version]]);
}

describe('VersionResolver', function (): void {
    it('returns single-variant skills unchanged', function (): void {
        $skills = [
            makeSkill('folio-routing', '/vendor/laravel/boost/.ai/folio/skill/folio-routing/SKILL.blade.php'),
            makeSkill('socialite-setup', '/vendor/laravel/boost/.ai/socialite/skill/socialite-setup/SKILL.blade.php'),
        ];

        $resolved = (new VersionResolver())->resolve($skills);

        expect($resolved)->toHaveCount(2)
            ->and(array_map(fn (Skill $s) => $s->name, $resolved))
            ->toEqualCanonicalizing(['folio-routing', 'socialite-setup']);
    });

    it('picks the variant matching the host major when Roster knows the package', function (): void {
        $scan = scanWithPackage(PackageRegistry::PEST, '3.5.2');
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/3/');
    });

    it('falls back to lex-last sourcePath when no Roster is provided', function (): void {
        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = (new VersionResolver())->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/4/');
    });

    it('falls back to lex-last when Roster has no entry for the package', function (): void {
        // Roster scanned a project that doesn't use Pest.
        $scan = scanWithPackages([]);
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/4/');
    });

    it('falls back to lex-last when the host package has no resolvable version', function (): void {
        // Package::major() is null for an empty version — no major to match on.
        $scan = scanWithPackage(PackageRegistry::PEST, '');
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/4/');
    });

    it('resolves a js-ecosystem package group through the npm side of the scan', function (): void {
        $scan = scanWithPackages([], [['@inertiajs/vue3', true, '2.1.0']]);
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('inertia-vue-development', '/vendor/laravel/boost/.ai/inertia-vue/1/skill/inertia-vue-development/SKILL.blade.php'),
            makeSkill('inertia-vue-development', '/vendor/laravel/boost/.ai/inertia-vue/2/skill/inertia-vue-development/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/inertia-vue/2/');
    });

    it('falls back when no variant matches the host-reported major', function (): void {
        // Host has Pest 2 — older than any variant laravel/boost ships.
        $scan = scanWithPackage(PackageRegistry::PEST, '2.0.0');
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/4/');
    });

    it('resolves Roster-aware on Windows-style backslash paths', function (): void {
        // Regression: SplFileInfo on Windows emits backslash separators;
        // the path regex must normalize before matching, otherwise the
        // Roster branch is silently bypassed and lex-sort takes over.
        $scan = scanWithPackage(PackageRegistry::PEST, '3.5.2');
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('pest-testing', 'C:\\app\\vendor\\laravel\\boost\\.ai\\pest\\3\\skill\\pest-testing\\SKILL.blade.php'),
            makeSkill('pest-testing', 'C:\\app\\vendor\\laravel\\boost\\.ai\\pest\\4\\skill\\pest-testing\\SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('\\pest\\3\\');
    });

    it('handles mixed single + multi-variant input', function (): void {
        $scan = scanWithPackage(PackageRegistry::PEST, '4.1.0');
        $resolver = new VersionResolver($scan);

        $skills = [
            makeSkill('folio-routing', '/vendor/laravel/boost/.ai/folio/skill/folio-routing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(2);

        $bySkillName = [];
        foreach ($resolved as $skill) {
            $bySkillName[$skill->name] = $skill;
        }

        expect($bySkillName['folio-routing']->sourcePath)->toContain('/folio/skill/')
            ->and($bySkillName['pest-testing']->sourcePath)->toContain('/pest/4/');
    });
});

test('pickSourcePath selects the same variant resolve() does', function (): void {
    // BoostWrapper claims emit paths through pickSourcePath while the sync
    // emits through resolve(). If the two ever disagree, the wrapper protects
    // one variant's assets while the sync writes another's, and the unclaimed
    // files get reaped on the next bare-CLI run.
    $variants = [
        '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php',
        '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php',
    ];

    $resolver = new VersionResolver();

    $resolved = $resolver->resolve(array_map(
        fn (string $path): Skill => makeSkill('pest-testing', $path),
        $variants,
    ));

    expect($resolver->pickSourcePath($variants))->toBe($resolved[0]->sourcePath);
});
