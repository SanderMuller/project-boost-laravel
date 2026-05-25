<?php declare(strict_types=1);

use Laravel\Roster\Enums\Packages;
use Laravel\Roster\Package;
use Laravel\Roster\Roster;
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

function makeRosterWithPackage(Packages $package, string $version): Roster
{
    $roster = new Roster();
    $roster->add(new Package($package, $package->value, $version));

    return $roster;
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
        $roster = makeRosterWithPackage(Packages::PEST, '3.5.2');
        $resolver = new VersionResolver($roster);

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
        $roster = new Roster();
        $resolver = new VersionResolver($roster);

        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/4/');
    });

    it('falls back when no variant matches the host-reported major', function (): void {
        // Host has Pest 2 — older than any variant laravel/boost ships.
        $roster = makeRosterWithPackage(Packages::PEST, '2.0.0');
        $resolver = new VersionResolver($roster);

        $skills = [
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/3/skill/pest-testing/SKILL.blade.php'),
            makeSkill('pest-testing', '/vendor/laravel/boost/.ai/pest/4/skill/pest-testing/SKILL.blade.php'),
        ];

        $resolved = $resolver->resolve($skills);

        expect($resolved)->toHaveCount(1)
            ->and($resolved[0]->sourcePath)
            ->toContain('/pest/4/');
    });

    it('handles mixed single + multi-variant input', function (): void {
        $roster = makeRosterWithPackage(Packages::PEST, '4.1.0');
        $resolver = new VersionResolver($roster);

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
