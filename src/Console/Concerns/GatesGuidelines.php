<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Console\Concerns;

use Laravel\Roster\ProjectScan;
use SanderMuller\ProjectBoostLaravel\Discovery\LaravelBoostGuidelineGate;

/**
 * Builds the laravel/boost guideline install-gate shared by `project-boost:sync`
 * and `project-boost:where`, so `where` reports the SAME guideline set `sync`
 * actually emits — install-gated by which packages the host has installed, and
 * scoped to the project's declared PHP floor. Without the shared gate, `where`
 * over-reports guidelines (e.g. inertia/pest/sail) that `sync` suppresses for
 * the current project shape.
 *
 * @internal
 */
trait GatesGuidelines
{
    private function guidelineGate(?ProjectScan $scan, string $aiRoot, string $projectRoot): LaravelBoostGuidelineGate
    {
        if (! $scan instanceof ProjectScan) {
            return LaravelBoostGuidelineGate::permissive();
        }

        return LaravelBoostGuidelineGate::fromProjectScan(
            $scan,
            $aiRoot,
            $this->detectPhpFloor($projectRoot . '/composer.json'),
        );
    }

    /**
     * The project's declared PHP floor — the lowest `major.minor` in
     * composer.json `require.php` (e.g. `8.3` from `^8.3`). Null when
     * composer.json is unreadable or declares no `php` constraint, in which
     * case the install gate keeps every php-version guideline fragment.
     */
    private function detectPhpFloor(string $composerJsonPath): ?string
    {
        if (! is_file($composerJsonPath)) {
            return null;
        }

        $raw = file_get_contents($composerJsonPath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $require = $decoded['require'] ?? null;
        $php = is_array($require) ? ($require['php'] ?? null) : null;

        return is_string($php) ? LaravelBoostGuidelineGate::parsePhpFloor($php) : null;
    }
}
