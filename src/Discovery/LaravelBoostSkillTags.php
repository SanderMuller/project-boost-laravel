<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use SanderMuller\BoostCore\Skills\BoostTags;

/**
 * The tags a laravel/boost skill carries into boost-core's tag filter.
 *
 * Two sources, in this order:
 *  - the skill's own `metadata.boost-tags`, which wins when declared (a
 *    malformed value fails closed, matching the engine); otherwise the sidecar
 *    manifest entry for the skill name;
 *  - an integration tag for a skill that laravel/boost installs only when the
 *    operator picks the matching install integration. That tag is added on top
 *    of the first source, because laravel/boost gates the whole directory
 *    whatever the skill declares.
 *
 * `LaravelBoostAssetReader` (the sync) and `BoostWrapper` (the stale-cleanup
 * claim) both resolve tags here, so the claim set matches what the sync ships.
 *
 * @internal
 */
final class LaravelBoostSkillTags
{
    /**
     * Top-level `.ai/<segment>/skill/` directories laravel/boost ships only
     * behind an install integration, mapped to the tag a project declares in
     * `withTags()` to opt in. laravel/boost's `SkillComposer::getIntegrationSkills()`
     * reads `.ai/deployments/skill/` only when the "Laravel Cloud" integration
     * is selected (`GuidelineConfig::$usesCloud`, laravel/boost >= 2.8).
     *
     * @var array<string, string>
     */
    public const array INTEGRATION_TAGS = ['deployments' => self::CLOUD_TAG];

    public const string CLOUD_TAG = 'cloud';

    /**
     * @param  string  $entryPath  the skill's `SKILL.{md,blade.php}` path under `$aiRoot`
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: bool}  [tags, valid]
     */
    public static function resolve(
        string $entryPath,
        string $aiRoot,
        string $name,
        array $frontmatter,
        LaravelBoostTagManifest $manifest,
    ): array {
        if (BoostTags::declaresTags($frontmatter)) {
            [$tags, $valid] = BoostTags::parse($frontmatter);
        } else {
            $tags = $manifest->tagsFor($name);
            $valid = true;
        }

        $integrationTag = self::integrationTag($entryPath, $aiRoot);
        if ($integrationTag !== null && ! in_array($integrationTag, $tags, true)) {
            $tags[] = $integrationTag;
        }

        return [$tags, $valid];
    }

    /**
     * The opt-in tag for a skill under an integration-gated directory, or null.
     */
    public static function integrationTag(string $entryPath, string $aiRoot): ?string
    {
        // rtrim: a configured root with a trailing separator must not shift the
        // first segment of the relative path.
        $relative = ltrim(str_replace('\\', '/', substr($entryPath, strlen(rtrim($aiRoot, '/\\')))), '/');
        $segment = explode('/', $relative)[0];

        return self::INTEGRATION_TAGS[$segment] ?? null;
    }
}
