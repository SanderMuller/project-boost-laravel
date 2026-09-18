<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\BoostTags;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillAsset;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo as FinderFileInfo;
use Throwable;

/**
 * Reads skills laravel/boost ships in vendor/laravel/boost/.ai/<pkg>/skill/<name>/SKILL.{md,blade.php}.
 *
 * Pipeline per file:
 *  - `.md` files pass through unchanged (no template rendering needed).
 *  - `.blade.php` files run through the optional `$bladeRenderer` (boost-core
 *    SkillRenderer contract). The companion package supplies `BladeRenderer`
 *    which delegates to laravel/boost's RendersBladeGuidelines trait so the
 *    `$assist = GuidelineAssist` runtime context exists.
 *  - Frontmatter is parsed from the rendered (or raw, for .md) content.
 *  - Companion files next to the entry file (`rules/*.md`,
 *    `references/*.blade.php`, …) become `SkillAsset`s through the same
 *    render dispatch, so laravel/boost's routing tables resolve. A rendered
 *    Blade asset is emitted with its extension rewritten to `.md`, because
 *    the entry body links `rules/foo.md`, not `rules/foo.blade.php`.
 *  - Tags are layered: frontmatter `metadata.boost-tags` wins; otherwise the
 *    sidecar manifest fills in.
 *
 * Skills feed into boost-core's SyncEngine via the `injectedVendorSkills`
 * seam — they bypass `SkillLoader`'s file walker entirely, which is why the
 * Blade rendering happens here (the dispatcher in `SkillLoader` never sees
 * these files).
 *
 * Render failures are recorded in `$renderErrors` (out-param) and the
 * offending skill is skipped, mirroring boost-core's lenient default.
 * A failing ASSET drops only that asset — the entry rendered fine, so the
 * skill still ships (minus the file that would have been half-rendered).
 * Callers can promote to strict by checking the out-param themselves.
 *
 * Blade files skipped for want of a renderer are counted in
 * `skippedBladeAssets()` so `project-boost:sync` can report them instead of
 * dropping them silently.
 *
 * @internal
 */
final class LaravelBoostAssetReader
{
    /** @var list<string> */
    private array $renderErrors = [];

    private int $skippedBladeAssets = 0;

    public function __construct(
        private readonly string $laravelBoostAiRoot,
        private readonly LaravelBoostTagManifest $tagManifest,
        private readonly ?SkillRenderer $bladeRenderer = null,
        private readonly FrontmatterParser $frontmatter = new FrontmatterParser(),
    ) {}

    /**
     * @return list<Skill>
     */
    public function readSkills(): array
    {
        $this->renderErrors = [];
        $this->skippedBladeAssets = 0;

        if (! is_dir($this->laravelBoostAiRoot)) {
            return [];
        }

        $skills = [];

        // sortByName() pins lexicographic order — Symfony Finder otherwise
        // yields in OS filesystem-iteration order (APFS vs ext4), so the
        // injected skill set would reach SyncEngine in a different order per OS.
        $finder = (new Finder())
            ->in($this->laravelBoostAiRoot)
            ->path('/\/skill\//')
            ->name(['SKILL.md', 'SKILL.blade.php'])
            ->sortByName()
            ->files();

        foreach ($finder as $file) {
            $skill = $this->buildSkill($file);
            if ($skill instanceof Skill) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * @return list<string>
     */
    public function renderErrors(): array
    {
        return $this->renderErrors;
    }

    /**
     * How many Blade companion files were dropped because no renderer is
     * wired. Non-zero means an emitted skill links files that will not exist.
     */
    public function skippedBladeAssets(): int
    {
        return $this->skippedBladeAssets;
    }

    private function buildSkill(SplFileInfo $file): ?Skill
    {
        $raw = (string) file_get_contents($file->getPathname());
        $isBlade = str_ends_with($file->getFilename(), '.blade.php');

        $content = $raw;
        if ($isBlade) {
            if (! $this->bladeRenderer instanceof SkillRenderer) {
                // No renderer wired — skip Blade skills silently. Matches
                // boost-core's pre-renderer behavior; the SyncCommand
                // surfaces this as a counter in the dry-run report.
                return null;
            }

            try {
                $preParsed = $this->frontmatter->parse($raw);
                $content = $this->bladeRenderer->render($raw, new RenderContext(
                    sourcePath: $file->getPathname(),
                    sourceVendor: 'laravel/boost',
                    frontmatter: $preParsed->frontmatter,
                ));
            } catch (Throwable $e) {
                $this->renderErrors[] = sprintf(
                    'laravel/boost skill render failed (`%s`): %s',
                    $file->getPathname(),
                    $e->getMessage(),
                );

                return null;
            }
        }

        $parsed = $this->frontmatter->parse($content);
        $name = $this->skillNameFromPath($file);
        $frontmatter = $parsed->frontmatter;

        // Author-declared `metadata.boost-tags` win — tokenized + validated via
        // boost-core's canonical BoostTags so a malformed value fails closed
        // (ships nowhere), matching the engine. Otherwise fall back to the
        // sidecar manifest (always-valid normalized strings).
        if (BoostTags::declaresTags($frontmatter)) {
            [$tags, $tagsValid] = BoostTags::parse($frontmatter);
        } else {
            $tags = $this->tagManifest->tagsFor($name);
            $tagsValid = true;
        }

        $description = isset($frontmatter['description']) && is_string($frontmatter['description'])
            ? $frontmatter['description']
            : null;

        return new Skill(
            name: $name,
            description: $description,
            frontmatter: $frontmatter,
            body: $parsed->body,
            sourcePath: $file->getPathname(),
            sourceVendor: 'laravel/boost',
            tags: $tags,
            tagsValid: $tagsValid,
            assets: $this->collectAssets($file),
        );
    }

    /**
     * Every non-entry file under the skill directory, rendered through the same
     * dispatch as the entry file.
     *
     * {@see SkillAssetScope} owns which files qualify and where each one is
     * emitted.
     *
     * @return list<SkillAsset>
     */
    private function collectAssets(SplFileInfo $entry): array
    {
        $skillDir = dirname($entry->getPathname());
        if (! is_dir($skillDir)) {
            return [];
        }

        // sortByName() for the same cross-OS determinism reason as the entry
        // walker above — asset order reaches SyncEngine as emit order.
        $finder = (new Finder())
            ->files()
            ->in($skillDir)
            ->ignoreDotFiles(true)
            ->filter(SkillAssetScope::isAsset(...))
            ->sortByName();

        $assets = [];
        $claimed = [];
        foreach ($finder as $file) {
            $asset = $this->buildAsset($file);
            if (! $asset instanceof SkillAsset) {
                continue;
            }

            // The `.blade.php` → `.md` rewrite can make two sources claim one
            // emit path (`rules/a.md` beside `rules/a.blade.php`). laravel/boost
            // ships no such pair, and a silent last-write-wins would be the
            // wrong answer if it ever did — so drop the second and say so.
            if (isset($claimed[$asset->relativePath])) {
                $this->renderErrors[] = sprintf(
                    'laravel/boost skill asset collision (`%s`): two sources claim `%s`.',
                    $file->getPathname(),
                    $asset->relativePath,
                );

                continue;
            }

            $claimed[$asset->relativePath] = true;
            $assets[] = $asset;
        }

        return $assets;
    }

    private function buildAsset(FinderFileInfo $file): ?SkillAsset
    {
        $relativePath = SkillAssetScope::emitRelativePath($file);

        $raw = @file_get_contents($file->getPathname());
        if ($raw === false) {
            // Vanished between enumeration and read, or unreadable. Emitting
            // `(string) false` would write an EMPTY asset over valid content
            // from a previous sync, so skip the file and say why.
            $this->renderErrors[] = sprintf(
                'laravel/boost skill asset unreadable (`%s`): skipped.',
                $file->getPathname(),
            );

            return null;
        }

        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            return new SkillAsset(relativePath: $relativePath, contents: $raw);
        }

        if (! $this->bladeRenderer instanceof SkillRenderer) {
            ++$this->skippedBladeAssets;

            return null;
        }

        try {
            // The whole rendered string is the asset — an asset is a plain
            // companion file, not a skill, so no frontmatter/body split. The
            // frontmatter is still pre-parsed for the RenderContext, matching
            // the entry path.
            $contents = $this->bladeRenderer->render($raw, new RenderContext(
                sourcePath: $file->getPathname(),
                sourceVendor: 'laravel/boost',
                frontmatter: $this->frontmatter->parse($raw)->frontmatter,
            ));
        } catch (Throwable $throwable) {
            $this->renderErrors[] = sprintf(
                'laravel/boost skill asset render failed (`%s`): %s',
                $file->getPathname(),
                $throwable->getMessage(),
            );

            return null;
        }

        return new SkillAsset(relativePath: $relativePath, contents: $contents);
    }

    /**
     * `vendor/laravel/boost/.ai/folio/skill/folio-routing/SKILL.blade.php` → `folio-routing`.
     */
    private function skillNameFromPath(SplFileInfo $file): string
    {
        return basename(dirname($file->getPathname()));
    }
}
