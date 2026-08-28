<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use SanderMuller\ProjectBoostLaravel\BoostWrapper;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The asset/entry partition for a laravel/boost skill directory, and the
 * source-path → emit-path rewrite that goes with it.
 *
 * Two call sites need the same answer from different runtimes:
 * {@see LaravelBoostAssetReader} builds the assets during a booted sync, and
 * {@see BoostWrapper} claims their emit paths
 * in boost-core's bare-CLI cleanup pass, with no app. Keeping the rule in one
 * place stops the two drifting — they already had different case-sensitivity on
 * backup filenames when the rule lived in both.
 *
 * It mirrors boost-core's `SkillSourceScope` / `SkillAssetCollector` rather than
 * calling them: both are `@internal` to boost-core, and the collector copies
 * bytes, which is the behaviour the Blade assets must avoid.
 *
 * @internal
 */
final class SkillAssetScope
{
    /**
     * Whether `$file` under a skill directory is an asset. A top-level `SKILL.*`
     * file is an entry candidate (so a stray `SKILL.md.license` never ships as
     * an asset); a deeper one is an asset; backup / editor-temp names are
     * neither.
     */
    public static function isAsset(SplFileInfo $file): bool
    {
        if (self::isBackupOrTempFile($file->getFilename())) {
            return false;
        }

        if ($file->getRelativePath() !== '') {
            return true;
        }

        return ! str_starts_with($file->getFilename(), 'SKILL.');
    }

    public static function isBackupOrTempFile(string $filename): bool
    {
        return str_ends_with($filename, '~')
            || preg_match('/\.(?:bak|orig|tmp|swp|swo)$/i', $filename) === 1;
    }

    /**
     * Where an asset is emitted, relative to the skill directory. Blade renders
     * to `.md` because the entry body links `rules/foo.md`, never the Blade
     * source name — the retired `boost:update` did the same rewrite.
     */
    public static function emitRelativePath(SplFileInfo $file): string
    {
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());

        if (! str_ends_with($relativePath, '.blade.php')) {
            return $relativePath;
        }

        return substr($relativePath, 0, -strlen('.blade.php')) . '.md';
    }
}
