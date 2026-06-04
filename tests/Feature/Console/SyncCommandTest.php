<?php declare(strict_types=1);

/**
 * Feature test for `project-boost:sync` host-guideline rendering.
 *
 * boost-core's GuidelineLoader silently skips any host guideline whose
 * extension no registered renderer claims, and boost-core ships only the
 * `.md` PassthroughRenderer. SyncCommand auto-registers this package's
 * BladeRenderer via `SyncEngine::sync(extraSkillRenderers: ...)` so a host's
 * `.ai/guidelines/*.blade.php` renders into the agent guidance instead of
 * vanishing with no warning. Without that registration this test fails
 * (marker absent). Surfaced by the mijntp proving consumer.
 */

use Illuminate\Support\Facades\File;

$syncCwdRef = null;

beforeEach(function () use (&$syncCwdRef): void {
    $cwd = getcwd();
    $syncCwdRef = $cwd === false ? null : $cwd;
    chdir(base_path());
    cleanSyncFixtures();
});

afterEach(function () use (&$syncCwdRef): void {
    cleanSyncFixtures();
    if (is_string($syncCwdRef)) {
        chdir($syncCwdRef);
        $syncCwdRef = null;
    }
});

function cleanSyncFixtures(): void
{
    foreach ([
        base_path('boost.php'),
        base_path('.config/boost.php'),
        base_path('CLAUDE.md'),
        base_path('AGENTS.md'),
        base_path('.mcp.json'),
    ] as $file) {
        if (file_exists($file)) {
            File::delete($file);
        }
    }

    foreach ([
        base_path('.ai'),
        base_path('.claude'),
        base_path('.codex'),
        base_path('.boost'),
    ] as $dir) {
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
}

it('renders a host .ai/guidelines/*.blade.php instead of silently skipping it', function (): void {
    file_put_contents(base_path('boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);
        PHP);

    File::ensureDirectoryExists(base_path('.ai/guidelines'));
    file_put_contents(
        base_path('.ai/guidelines/host-style.blade.php'),
        "## Host Blade Style Guide\n\nHOST_BLADE_GUIDELINE_MARKER\n",
    );

    $this->artisan('project-boost:sync')->assertSuccessful();

    // CLAUDE_CODE → CLAUDE.md. The host `.blade.php` guideline must render INTO
    // it. Without the auto-registered BladeRenderer, boost-core's GuidelineLoader
    // skips the unrenderable `.blade.php` and the marker never appears.
    expect(file_get_contents(base_path('CLAUDE.md')))
        ->toContain('HOST_BLADE_GUIDELINE_MARKER');
});

it('resolves config from .config/boost.php (canonical layout), not just root boost.php', function (): void {
    // Regression: the command guard hard-coded base_path('boost.php'), so after
    // the .config/ migration (boost-core >= 0.17 canonical) project-boost:sync
    // falsely aborted with "No boost config found" despite a valid config. A
    // config at .config/boost.php must drive the sync just like a root boost.php.
    File::ensureDirectoryExists(base_path('.config'));
    file_put_contents(base_path('.config/boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);
        PHP);

    File::ensureDirectoryExists(base_path('.ai/guidelines'));
    file_put_contents(
        base_path('.ai/guidelines/host-style.blade.php'),
        "## Host Style\n\nCONFIG_LAYOUT_MARKER\n",
    );

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_get_contents(base_path('CLAUDE.md')))
        ->toContain('CONFIG_LAYOUT_MARKER');
});

it('turns an un-migrated variadic withTags() into a migration hint, not a composer-aborting fatal', function (): void {
    // Regression (reported by dogfooding consumers): a boost.php still using the
    // pre-0.20 variadic withTags() throws a TypeError when project-boost:sync
    // require()s it from composer's post-update hook — which would abort the
    // whole `composer update` with a raw stack trace. The command must catch it
    // and surface an actionable migration hint with a clean non-zero exit.
    file_put_contents(base_path('boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;
        use SanderMuller\BoostCore\Enums\Tag;

        return BoostConfig::configure()
            ->withAgents([Agent::CLAUDE_CODE])
            ->withTags(Tag::Laravel, Tag::Php);
        PHP);

    $this->artisan('project-boost:sync')
        ->expectsOutputToContain('withTags')
        ->assertExitCode(1);
});
