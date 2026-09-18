<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\EmitterResult;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;
use SanderMuller\ProjectBoostLaravel\Rendering\InjectedSkillStatus;

/**
 * @param  list<string>  $tags
 */
function statusSkill(string $name, array $tags = []): Skill
{
    return new Skill(
        name: $name,
        description: null,
        frontmatter: [],
        body: '',
        sourcePath: "/vendor/laravel/boost/.ai/laravel/skill/{$name}/SKILL.md",
        sourceVendor: 'laravel/boost',
        tags: $tags,
    );
}

function wouldWrite(string $skillName): WrittenFile
{
    return new WrittenFile(
        relativePath: ".claude/skills/{$skillName}/SKILL.md",
        absolutePath: "/project/.claude/skills/{$skillName}/SKILL.md",
        action: WriteAction::WOULD_WRITE,
    );
}

/**
 * @param  list<WrittenFile>  $writes
 * @param  list<string>  $errors
 * @param  list<EmitterResult>  $emitters
 * @param  list<array{skill: string, shadowedVendor: string}>  $hostShadows
 */
function statusResult(array $writes = [], array $errors = [], array $emitters = [], array $hostShadows = []): SyncResult
{
    return new SyncResult(
        writes: $writes,
        emitters: $emitters,
        errors: $errors,
        check: true,
        hostShadows: $hostShadows,
    );
}

test('a skill the sync would write reads as shipping', function (): void {
    $status = InjectedSkillStatus::from(statusResult(writes: [wouldWrite('foo-skill')]));

    expect($status->isShipped('foo-skill'))->toBeTrue()
        ->and($status->cellFor(statusSkill('foo-skill'), '<untagged>'))->toContain('ship');
});

test('a tagged skill that did not ship names the tags to declare', function (): void {
    $status = InjectedSkillStatus::from(statusResult());

    expect($status->cellFor(statusSkill('foo-skill', ['laravel', 'php']), 'laravel php'))
        ->toContain('filtered (declare: laravel php)');
});

test('an untagged skill that did not ship reads as excluded, since tag advice would not help', function (): void {
    $status = InjectedSkillStatus::from(statusResult());

    expect($status->cellFor(statusSkill('foo-skill'), '<untagged>'))->toContain('excluded');
});

test('a shadowed skill names every shadowing vendor, not the last one seen', function (): void {
    // Regression: `$map[$name] = $vendor` kept only the last, so a host copy
    // shadowing two vendors reported one while reading as a complete answer.
    $status = InjectedSkillStatus::from(statusResult(hostShadows: [
        ['skill' => 'foo-skill', 'shadowedVendor' => 'vendor/one'],
        ['skill' => 'foo-skill', 'shadowedVendor' => 'vendor/two'],
    ]));

    expect($status->cellFor(statusSkill('foo-skill'), '<untagged>'))
        ->toContain('shadowed by vendor/one, vendor/two');
});

test('a run carrying errors withholds the reason instead of blaming the tag filter', function (): void {
    // The bug this seam exists for: a source whose renderer throws is excluded
    // from the resolved set, so it never reaches `$result->writes` and the
    // cell fell through to `filtered (declare: …)` — sending an operator to
    // fix a `withTags()` that was never the cause.
    $status = InjectedSkillStatus::from(statusResult(errors: ['skill render failed (`foo/SKILL.blade.php`): boom']));

    $cell = $status->cellFor(statusSkill('foo-skill', ['laravel']), 'laravel');

    expect($status->isDegraded())->toBeTrue()
        ->and($cell)->toContain('reason unknown')
        ->and($cell)->not->toContain('filtered');
});

test('an ERRORED emitter degrades the listing even when the error list is empty', function (): void {
    // `hasErrors()` is true for an ERRORED emitter, and the errors list does
    // not contain one. Reading a single channel is how a failed run reports
    // as a clean one — the defect this package found in boost-core's own CLI.
    $status = InjectedSkillStatus::from(statusResult(emitters: [
        new EmitterResult(
            fqcn: 'Acme\\Emitters\\McpWriter',
            vendor: 'acme/emitters',
            action: EmitterAction::ERRORED,
            relativePath: '.mcp.json',
            reason: 'unwritable',
        ),
    ]));

    expect($status->isDegraded())->toBeTrue()
        ->and($status->cellFor(statusSkill('foo-skill', ['laravel']), 'laravel'))->toContain('reason unknown');
});

test('a degraded run still reports what did ship and what shadowed what', function (): void {
    // Those two remain true of the skills they describe; only the negative
    // attributions are unknowable, so only they are withheld.
    $status = InjectedSkillStatus::from(statusResult(
        writes: [wouldWrite('shipped-skill')],
        errors: ['boom'],
        hostShadows: [['skill' => 'shadowed-skill', 'shadowedVendor' => 'vendor/one']],
    ));

    expect($status->cellFor(statusSkill('shipped-skill'), '<untagged>'))->toContain('ship')
        ->and($status->cellFor(statusSkill('shadowed-skill'), '<untagged>'))->toContain('shadowed by vendor/one');
});
