<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Coexistence;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * laravel/boost's own exclude lists — `boost.skills.exclude` and
 * `boost.guidelines.exclude` in its `config/boost.php` — and where each entry
 * belongs in the project's boost config instead.
 *
 * `project-boost:sync` does not honour those lists. The boost config is the one
 * selection config this package documents, and boost-core applies its
 * `withExcludedSkills()` / `withExcludedGuidelines()` lists to every source.
 * laravel/boost's lists use bare names (`fluxui-development`, `livewire/core`),
 * so they cannot reach a skill boost-core picks up from another vendor without
 * a guess at that vendor. Merging only the laravel/boost half would leave the
 * other half silently ignored. So the sync warns instead, and names the
 * boost-config key for each entry it can map.
 *
 * An entry is left out of the advice when the boost config already covers it,
 * or when it names a laravel/boost guideline this sync does not inject anyway.
 * The warning goes away when each entry is covered or removed, which matters
 * because the sync runs from composer hooks.
 *
 * @internal
 */
final readonly class LaravelBoostExcludes
{
    private const string VENDOR = 'laravel/boost';

    /**
     * @param  list<string>  $skillExcludes  `boost.skills.exclude`
     * @param  list<string>  $guidelineExcludes  `boost.guidelines.exclude`
     */
    public function __construct(
        private array $skillExcludes,
        private array $guidelineExcludes,
    ) {}

    /**
     * Read both lists from laravel/boost's config, keeping only string entries.
     */
    public static function fromConfig(mixed $skillExcludes, mixed $guidelineExcludes): self
    {
        return new self(self::strings($skillExcludes), self::strings($guidelineExcludes));
    }

    /**
     * One advice line per entry the boost config does not cover yet.
     *
     * @param  list<string>  $injectedSkillNames  laravel/boost skill names this sync injects
     * @param  list<string>  $injectedGuidelineNames  laravel/boost guideline names this sync injects
     * @return list<string>
     */
    public function advice(BoostConfig $config, array $injectedSkillNames, array $injectedGuidelineNames): array
    {
        $lines = [];

        foreach ($this->skillExcludes as $entry) {
            $line = $this->skillAdvice($entry, $config, $injectedSkillNames);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        foreach ($this->guidelineExcludes as $entry) {
            $line = $this->guidelineAdvice($entry, $config, $injectedGuidelineNames);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The name this package gives the laravel/boost guideline that
     * laravel/boost keys as `$key`, or null for a key that is not a
     * laravel/boost guideline (a third-party `vendor/package/…` key, or a host
     * `.ai/…` guideline).
     *
     * laravel/boost keys its guidelines `foundation`, `boost`, `php`,
     * `deployments`, `herd`, `sail`, `tests`, `<pkg>/core`, `<pkg>/v<major>`,
     * `<pkg>/v<major>/<file>` and `laravel/<file>`. This package names them by
     * their path under `.ai/`: `boost-core`, `livewire-core`, `laravel-12-core`,
     * `enforce-tests`, and so on.
     */
    public static function guidelineName(string $key): ?string
    {
        $key = strtolower(trim($key, " \t/"));
        if ($key === '' || str_starts_with($key, '.ai/')) {
            return null;
        }

        $parts = explode('/', $key);

        return match (count($parts)) {
            1 => match ($key) {
                'foundation' => 'foundation',
                'tests' => 'enforce-tests',
                default => $key . '-core',
            },
            2 => preg_match('/^v(\d+)$/', $parts[1], $major) === 1
                ? sprintf('%s-%s-core', $parts[0], $major[1])
                : $parts[0] . '-' . $parts[1],
            3 => preg_match('/^v(\d+)$/', $parts[1], $major) === 1
                ? sprintf('%s-%s-%s', $parts[0], $major[1], $parts[2])
                : null,
            default => null,
        };
    }

    /**
     * @param  list<string>  $injectedSkillNames
     */
    private function skillAdvice(string $entry, BoostConfig $config, array $injectedSkillNames): ?string
    {
        if (in_array($entry, $injectedSkillNames, true)) {
            $key = self::VENDOR . ':' . $entry;

            return $config->excludesSkill($key)
                ? null
                : sprintf('  • boost.skills.exclude `%s` → add `%s` to withExcludedSkills()', $entry, $key);
        }

        foreach ($config->excludedSkills as $excluded) {
            if (str_ends_with($excluded, ':' . $entry)) {
                return null;
            }
        }

        return sprintf(
            '  • boost.skills.exclude `%s` is not a laravel/boost skill this package injects. If another package ships it, add `vendor/package:%s` to withExcludedSkills()',
            $entry,
            $entry,
        );
    }

    /**
     * @param  list<string>  $injectedGuidelineNames
     */
    private function guidelineAdvice(string $entry, BoostConfig $config, array $injectedGuidelineNames): ?string
    {
        $name = self::guidelineName($entry);

        if ($name === null) {
            return sprintf(
                '  • boost.guidelines.exclude `%s` is not a laravel/boost guideline. Exclude a vendor guideline by its `vendor/package:name` key in withExcludedGuidelines()',
                $entry,
            );
        }

        // Not injected (install-gated, or absent from this laravel/boost
        // release): nothing ships, so there is nothing to move.
        if (! in_array($name, $injectedGuidelineNames, true)) {
            return null;
        }

        $key = self::VENDOR . ':' . $name;

        return $config->excludesGuideline($key)
            ? null
            : sprintf('  • boost.guidelines.exclude `%s` → add `%s` to withExcludedGuidelines()', $entry, $key);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
