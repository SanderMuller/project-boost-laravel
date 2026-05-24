<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Skill;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
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
 * Callers can promote to strict by checking the out-param themselves.
 */
final class LaravelBoostAssetReader
{
    /** @var list<string> */
    private array $renderErrors = [];

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

        if (! is_dir($this->laravelBoostAiRoot)) {
            return [];
        }

        $skills = [];

        $finder = (new Finder())
            ->in($this->laravelBoostAiRoot)
            ->path('/\/skill\//')
            ->name(['SKILL.md', 'SKILL.blade.php'])
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

        $tagsFromFrontmatter = $this->extractTags($frontmatter);
        $sidecarTags = $this->tagManifest->tagsFor($name);
        $tags = $tagsFromFrontmatter !== [] ? $tagsFromFrontmatter : $sidecarTags;

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
            tagsValid: true,
        );
    }

    /**
     * `vendor/laravel/boost/.ai/folio/skill/folio-routing/SKILL.blade.php` → `folio-routing`.
     */
    private function skillNameFromPath(SplFileInfo $file): string
    {
        return basename(dirname($file->getPathname()));
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private function extractTags(array $frontmatter): array
    {
        $metadata = $frontmatter['metadata'] ?? null;
        if (! is_array($metadata)) {
            return [];
        }

        $raw = $metadata['boost-tags'] ?? null;
        if (! is_string($raw)) {
            return [];
        }

        $parts = preg_split('/\s+/', trim($raw));
        if ($parts === false) {
            return [];
        }

        $tags = array_values(array_filter($parts, static fn (string $t): bool => $t !== ''));

        return array_values(array_unique($tags));
    }
}
