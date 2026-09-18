<?php declare(strict_types=1);

use SanderMuller\ProjectBoostLaravel\Discovery\SkillAssetScope;
use Symfony\Component\Finder\SplFileInfo;

function scopeFile(string $relativePathname): SplFileInfo
{
    $relativePath = dirname($relativePathname);

    return new SplFileInfo(
        '/skills/foo/' . $relativePathname,
        $relativePath === '.' ? '' : $relativePath,
        $relativePathname,
    );
}

test('a top-level SKILL.* file is an entry candidate, never an asset', function (string $filename): void {
    expect(SkillAssetScope::isAsset(scopeFile($filename)))->toBeFalse();
})->with(['SKILL.md', 'SKILL.blade.php', 'SKILL.md.license']);

test('a deeper file is an asset, including a nested SKILL.*', function (string $relativePathname): void {
    expect(SkillAssetScope::isAsset(scopeFile($relativePathname)))->toBeTrue();
})->with(['rules/a.md', 'references/checklist.blade.php', 'examples/SKILL.md']);

test('backup and editor-temp names are never assets, whatever their case', function (string $relativePathname): void {
    expect(SkillAssetScope::isAsset(scopeFile($relativePathname)))->toBeFalse();
})->with(['rules/a.md~', 'rules/a.md.bak', 'rules/a.md.BAK', 'rules/a.md.orig', 'rules/a.md.swp']);

test('emitRelativePath rewrites Blade to .md and leaves everything else alone', function (string $in, string $out): void {
    expect(SkillAssetScope::emitRelativePath(scopeFile($in)))->toBe($out);
})->with([
    ['rules/a.blade.php', 'rules/a.md'],
    ['rules/a.md', 'rules/a.md'],
    ['scripts/run.mjs', 'scripts/run.mjs'],
]);
