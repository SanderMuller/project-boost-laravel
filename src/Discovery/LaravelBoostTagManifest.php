<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Discovery;

use Symfony\Component\Yaml\Yaml;

/**
 * Sidecar tag manifest companion-package-owned, lets tag filtering bite
 * laravel/boost's bundled assets whose upstream frontmatter has no
 * metadata.boost-tags field. Maps skill name → space-delimited tag string.
 *
 * Format (resources/boost/laravel-boost-tags.yaml):
 *
 *     folio-routing: laravel folio
 *     fluxui-development: laravel frontend
 *     pennant-development: laravel feature-flags
 *
 * @internal
 */
final readonly class LaravelBoostTagManifest
{
    /**
     * @param  array<string, list<string>>  $skillTags  name → list of tags.
     */
    public function __construct(
        private array $skillTags = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return new self();
        }

        $contents = Yaml::parseFile($path);
        if (! is_array($contents)) {
            return new self();
        }

        $normalized = [];
        foreach ($contents as $skill => $raw) {
            if (! is_string($skill)) {
                continue;
            }

            if (! is_string($raw)) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($raw));
            if ($parts === false) {
                continue;
            }

            $tags = array_values(array_filter($parts, static fn (string $t): bool => $t !== ''));
            $normalized[$skill] = array_values(array_unique($tags));
        }

        return new self($normalized);
    }

    /**
     * @return list<string>
     */
    public function tagsFor(string $skill): array
    {
        return $this->skillTags[$skill] ?? [];
    }
}
