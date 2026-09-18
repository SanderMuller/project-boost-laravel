<?php declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;
use SanderMuller\ProjectBoostLaravel\Console\SyncCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  list<WrittenFile>  $writes
 */
function renderSyncWriteList(array $writes): string
{
    $buffer = new BufferedOutput();
    $command = new SyncCommand();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    (new ReflectionMethod($command, 'renderWrites'))->invoke($command, $writes);

    return $buffer->fetch();
}

function syncWritten(string $relativePath, WriteAction $action): WrittenFile
{
    return new WrittenFile(
        relativePath: $relativePath,
        absolutePath: "/project/{$relativePath}",
        action: $action,
    );
}

test('unchanged files collapse into one counted line per directory', function (): void {
    $output = renderSyncWriteList([
        syncWritten('.claude/skills/a/SKILL.md', WriteAction::UNCHANGED),
        syncWritten('.claude/skills/b/SKILL.md', WriteAction::UNCHANGED),
        syncWritten('.claude/agents/boost/x.md', WriteAction::UNCHANGED),
    ]);

    expect($output)->toContain('unchanged 2 file(s) in .claude/skills')
        ->toContain('unchanged 1 file(s) in .claude/agents')
        ->and(str_contains($output, 'SKILL.md'))
        ->toBeFalse();
});

test('every other action keeps its own line', function (): void {
    $output = renderSyncWriteList([
        syncWritten('.claude/skills/a/SKILL.md', WriteAction::UNCHANGED),
        syncWritten('.claude/skills/b/SKILL.md', WriteAction::WROTE),
    ]);

    expect($output)->toContain('unchanged 1 file(s) in .claude/skills')
        ->toContain('wrote .claude/skills/b/SKILL.md');
});

test('one directory gets one line and repeated paths are counted once', function (): void {
    $output = renderSyncWriteList([
        syncWritten('.agents/skills/a/SKILL.md', WriteAction::UNCHANGED),
        syncWritten('.agents/skills/b/SKILL.md', WriteAction::UNCHANGED),
        syncWritten('.agents/skills/a/SKILL.md', WriteAction::UNCHANGED),
    ]);

    expect(substr_count($output, 'unchanged 2 file(s) in .agents/skills'))->toBe(1);
});

test('a file at the project root is named, not read as a directory', function (): void {
    $output = renderSyncWriteList([
        syncWritten('CLAUDE.md', WriteAction::UNCHANGED),
    ]);

    expect($output)->toContain('unchanged 1 file(s) in the project root');
});

test('a path listed under another action is not also counted as unchanged', function (): void {
    $output = renderSyncWriteList([
        syncWritten('.agents/skills/a/SKILL.md', WriteAction::WROTE),
        syncWritten('.agents/skills/b/SKILL.md', WriteAction::UNCHANGED),
        syncWritten('.agents/skills/a/SKILL.md', WriteAction::UNCHANGED),
    ]);

    expect($output)->toContain('wrote .agents/skills/a/SKILL.md')
        ->toContain('unchanged 1 file(s) in .agents/skills');
});

test('a group whose every path was listed prints no unchanged line', function (): void {
    $output = renderSyncWriteList([
        syncWritten('.agents/skills/a/SKILL.md', WriteAction::WROTE),
        syncWritten('.agents/skills/a/SKILL.md', WriteAction::UNCHANGED),
    ]);

    expect(str_contains($output, 'unchanged'))->toBeFalse();
});
