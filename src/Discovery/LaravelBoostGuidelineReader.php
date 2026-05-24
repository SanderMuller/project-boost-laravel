<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Reads guidelines laravel/boost ships in `.ai/<pkg>/core.blade.php` and the
 * per-major variants under `.ai/<pkg>/<major>/*.blade.php`.
 *
 * Tag layering rules mirror the skill reader's sidecar manifest, but for
 * guidelines the sidecar key is the resolved guideline name (`pkg-core`,
 * `pkg-<major>-<filename>`). laravel/boost guidelines have NO YAML
 * frontmatter (the Blade engine renders `---` literally — see boost-core
 * `tag-skill-filtering.md` for the why), so the sidecar is the ONLY tag
 * source for these.
 *
 * Render failures accumulate in `renderErrors()` (out-param style); the
 * offending guideline is skipped, matching boost-core's lenient default.
 */
final class LaravelBoostGuidelineReader
{
    /** @var list<string> */
    private array $renderErrors = [];

    public function __construct(
        private readonly string $laravelBoostAiRoot,
        private readonly LaravelBoostTagManifest $tagManifest,
        private readonly ?SkillRenderer $bladeRenderer = null,
    ) {}

    /**
     * @return list<Guideline>
     */
    public function readGuidelines(): array
    {
        $this->renderErrors = [];

        if (! is_dir($this->laravelBoostAiRoot)) {
            return [];
        }

        $guidelines = [];

        // Match `.ai/<pkg>/core.blade.php` and `.ai/<pkg>/<major>/*.blade.php`
        // — but EXCLUDE the `.ai/<pkg>/skill/...` subtree (those are skills,
        // not guidelines).
        $finder = (new Finder())
            ->in($this->laravelBoostAiRoot)
            ->notPath('/\/skill\//')
            ->name('*.blade.php')
            ->files();

        foreach ($finder as $file) {
            $guideline = $this->buildGuideline($file);
            if ($guideline instanceof Guideline) {
                $guidelines[] = $guideline;
            }
        }

        return $guidelines;
    }

    /**
     * @return list<string>
     */
    public function renderErrors(): array
    {
        return $this->renderErrors;
    }

    private function buildGuideline(SplFileInfo $file): ?Guideline
    {
        if (! $this->bladeRenderer instanceof SkillRenderer) {
            return null;
        }

        $raw = (string) file_get_contents($file->getPathname());

        try {
            $rendered = $this->bladeRenderer->render($raw, new RenderContext(
                sourcePath: $file->getPathname(),
                sourceVendor: 'laravel/boost',
                frontmatter: [],
            ));
        } catch (Throwable $throwable) {
            $this->renderErrors[] = sprintf(
                'laravel/boost guideline render failed (`%s`): %s',
                $file->getPathname(),
                $throwable->getMessage(),
            );

            return null;
        }

        $name = $this->guidelineNameFromPath($file);
        $sidecarTags = $this->tagManifest->tagsFor($name);

        return new Guideline(
            name: $name,
            description: null,
            frontmatter: [],
            body: $rendered,
            sourcePath: $file->getPathname(),
            sourceVendor: 'laravel/boost',
            tags: $sidecarTags,
            tagsValid: true,
        );
    }

    /**
     * Naming rules:
     *  - `.ai/<pkg>/core.blade.php`              → `<pkg>-core`
     *  - `.ai/<pkg>/<major>/<file>.blade.php`    → `<pkg>-<major>-<file>`
     * Underscores stay; `-` is the separator. Lowercased.
     */
    private function guidelineNameFromPath(SplFileInfo $file): string
    {
        $relative = ltrim(
            substr($file->getPathname(), strlen($this->laravelBoostAiRoot)),
            DIRECTORY_SEPARATOR,
        );
        $relative = preg_replace('/\.blade\.php$/', '', $relative) ?? $relative;

        $parts = explode(DIRECTORY_SEPARATOR, $relative);

        return strtolower(implode('-', $parts));
    }
}
