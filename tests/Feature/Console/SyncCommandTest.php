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

    // The reader resolves `vendor/laravel/boost/.ai` relative to the CWD, which is
    // the testbench base path here — so without an override there is no laravel/boost
    // payload at all, and the sync deliberately keeps `boost.json` when it injected
    // nothing. A hermetic one-guideline tree stands in for the real thing.
    File::ensureDirectoryExists(base_path('tests-fixture-boost-ai'));
    file_put_contents(base_path('tests-fixture-boost-ai/foundation.blade.php'), 'Vendor foundation guideline body.');
    config(['project-boost-laravel.laravel_boost_ai_root' => base_path('tests-fixture-boost-ai')]);
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
        base_path('boost.json'),
        base_path('.boost/boost.json.retired'),
        base_path('.config/boost/boost.json.retired'),
    ] as $file) {
        if (file_exists($file)) {
            File::delete($file);
        }
    }

    if (is_dir(base_path('.config'))) {
        File::deleteDirectory(base_path('.config'));
    }

    foreach ([
        base_path('.ai'),
        base_path('.claude'),
        base_path('.codex'),
        base_path('.boost'),
        base_path('tests-fixture-boost-ai'),
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

/**
 * A project whose boost config declares the given agents.
 *
 * @param  list<string>  $agentCases
 */
function writeSyncBoostPhp(array $agentCases = ['Agent::CLAUDE_CODE']): void
{
    $agents = implode(', ', $agentCases);

    file_put_contents(base_path('boost.php'), <<<PHP
        <?php declare(strict_types=1);

        use SanderMuller\\BoostCore\\Config\\BoostConfig;
        use SanderMuller\\BoostCore\\Enums\\Agent;

        return BoostConfig::configure()->withAgents([{$agents}]);
        PHP);
}

/**
 * @param  list<string>  $agents  laravel/boost agent names, its own spelling
 */
function writeLaravelBoostJson(array $agents = ['claude_code']): void
{
    file_put_contents(base_path('boost.json'), json_encode([
        'agents' => $agents,
        'guidelines' => true,
        'skills' => ['laravel-best-practices'],
    ], JSON_THROW_ON_ERROR));
}

it('archives boost.json after a successful sync so `herd link` stops re-seeding', function (): void {
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync')
        ->expectsOutputToContain('archived')
        ->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeFalse()
        // Archived, not destroyed: the operator can read their old install state back.
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeTrue()
        ->and(file_get_contents(base_path('.boost/boost.json.retired')))->toContain('laravel-best-practices');
});

it('keeps boost.json while it records an agent the boost config does not declare', function (): void {
    // Adopt-before-remove: the agent list is the only record of what the operator
    // picked in laravel/boost's installer, and nothing imports it automatically.
    writeSyncBoostPhp(['Agent::CLAUDE_CODE']);
    writeLaravelBoostJson(['claude_code', 'copilot']);

    $this->artisan('project-boost:sync')
        ->expectsOutputToContain('copilot')
        ->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
});

it('does not let an agent boost-core has no case for block the archive', function (): void {
    // `zed` can never be adopted into boost.php, so blocking on it would mean the
    // file is never retired.
    writeSyncBoostPhp(['Agent::CLAUDE_CODE']);
    writeLaravelBoostJson(['claude_code', 'zed']);

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeFalse()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeTrue();
});

it('keeps boost.json under --keep-boost-json', function (): void {
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync', ['--keep-boost-json' => true])->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
});

it('previews the archive under --dry-run without moving anything', function (): void {
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync', ['--dry-run' => true])
        ->expectsOutputToContain('would-archive')
        ->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
});

it('leaves a boost.json that carries none of laravel/boost keys alone', function (): void {
    writeSyncBoostPhp();

    // Some other tool's boost.json — not ours to touch.
    file_put_contents(base_path('boost.json'), json_encode(['rockets' => 3], JSON_THROW_ON_ERROR));

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue();
});

it('leaves a boost.json that records no agent list alone', function (): void {
    writeSyncBoostPhp();

    // Another tool's boost.json can carry the generic keys laravel/boost also writes
    // (`skills`, `packages`, `cloud`, …). Only a recorded agent list marks the file as
    // laravel/boost's live install state — and it is what makes `boost:update` act.
    file_put_contents(base_path('boost.json'), json_encode(['skills' => ['some-skill'], 'guidelines' => true], JSON_THROW_ON_ERROR));

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
});

it('keeps boost.json when the sync injected no laravel/boost skills or guidelines', function (): void {
    // laravel/boost export-ignores its `.ai` payload, so a prefer-dist install has
    // none. Retiring the file there would stop `boost:update` from re-seeding content
    // this sync cannot emit either — laravel/boost's own path must stay the fallback.
    config(['project-boost-laravel.laravel_boost_ai_root' => base_path('tests-fixture-absent-ai')]);

    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync')
        ->expectsOutputToContain('injected no laravel/boost skills or guidelines')
        ->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
});

it('archives into .config/boost when the project uses that layout', function (): void {
    // Both state dirs are gitignored by boost-core and skipped by its stale-file
    // sweep; the archive must follow whichever layout the project is on.
    File::ensureDirectoryExists(base_path('.config'));
    file_put_contents(base_path('.config/boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);
        PHP);
    File::ensureDirectoryExists(base_path('.config/boost'));
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('.config/boost/boost.json.retired')))->toBeTrue()
        ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
});

it('keeps boost.json when there is no state directory to archive into', function (): void {
    // Gitignore management off: boost-core writes no `.boost/`, so creating one here
    // would leave an untracked directory in the operator's tree.
    file_put_contents(base_path('boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()
            ->withAgents([Agent::CLAUDE_CODE])
            ->withGitignoreManagement(false);
        PHP);
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeTrue()
        ->and(is_dir(base_path('.boost')))->toBeFalse();
});

it('ignores a stale opposite-layout state directory', function (): void {
    // A leftover `.config/boost/` in a root-`boost.php` project is not boost-core's
    // state dir there, so archiving into it could leave an untracked file behind.
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);
    File::ensureDirectoryExists(base_path('.config/boost'));

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('.boost/boost.json.retired')))->toBeTrue()
        ->and(file_exists(base_path('.config/boost/boost.json.retired')))->toBeFalse();
});

it('keeps boost.json when the state directory is a symlink', function (): void {
    // rename() follows the link, which would park the operator's file outside the
    // project while the command reported a project-relative path.
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);

    $target = sys_get_temp_dir() . '/pbl-state-' . bin2hex(random_bytes(6));
    mkdir($target, 0o755, recursive: true);
    symlink($target, base_path('.boost'));

    try {
        $this->artisan('project-boost:sync')->assertSuccessful();

        expect(file_exists(base_path('boost.json')))->toBeTrue()
            ->and(file_exists($target . '/boost.json.retired'))->toBeFalse();
    } finally {
        @unlink(base_path('.boost'));
        File::deleteDirectory($target);
    }
});

it('keeps boost.json when a guidance file was skipped as a symlink', function (): void {
    // boost-core skips a live symlink rather than writing through it, and that is not
    // an error — the sync still exits 0. That agent's guidance was never written, so
    // laravel/boost's own path is still the only thing emitting it.
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);

    $target = sys_get_temp_dir() . '/pbl-guidance-' . bin2hex(random_bytes(6)) . '.md';
    file_put_contents($target, "# Stale\n");
    symlink($target, base_path('CLAUDE.md'));

    try {
        $this->artisan('project-boost:sync')
            ->expectsOutputToContain('skipped as symlinks')
            ->assertSuccessful();

        expect(file_exists(base_path('boost.json')))->toBeTrue()
            ->and(file_exists(base_path('.boost/boost.json.retired')))->toBeFalse();
    } finally {
        @unlink(base_path('CLAUDE.md'));
        @unlink($target);
    }
});

it('never overwrites an existing archive that holds different content', function (): void {
    writeSyncBoostPhp();
    File::ensureDirectoryExists(base_path('.boost'));
    file_put_contents(base_path('.boost/boost.json.retired'), '{"agents":["claude_code"],"skills":["older-run"]}');
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_get_contents(base_path('.boost/boost.json.retired')))->toContain('older-run');

    $globbed = glob(base_path('.boost/boost.json.retired-*'));
    $siblings = $globbed === false ? [] : $globbed;
    expect($siblings)->toHaveCount(1)
        ->and(file_get_contents($siblings[0]))->toContain('laravel-best-practices')
        ->and(file_exists(base_path('boost.json')))->toBeFalse();

    foreach ($siblings as $sibling) {
        @unlink($sibling);
    }
});

it('drops the source when the existing archive already holds identical content', function (): void {
    writeSyncBoostPhp();
    File::ensureDirectoryExists(base_path('.boost'));
    writeLaravelBoostJson(['claude_code']);
    copy(base_path('boost.json'), base_path('.boost/boost.json.retired'));

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_exists(base_path('boost.json')))->toBeFalse()
        ->and(glob(base_path('.boost/boost.json.retired-*')))
        ->toBeEmpty();
});

it('leaves a symlinked boost.json untouched', function (): void {
    writeSyncBoostPhp();

    $target = sys_get_temp_dir() . '/pbl-boostjson-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($target, json_encode(['agents' => ['claude_code']], JSON_THROW_ON_ERROR));
    symlink($target, base_path('boost.json'));

    try {
        $this->artisan('project-boost:sync')
            ->expectsOutputToContain('symlink')
            ->assertSuccessful();

        expect(is_link(base_path('boost.json')))->toBeTrue()
            ->and(file_exists($target))->toBeTrue();
    } finally {
        @unlink(base_path('boost.json'));
        @unlink($target);
    }
});

it('keeps boost.json when the sync itself fails', function (): void {
    // laravel/boost's own path stays the fallback when this command cannot complete.
    file_put_contents(base_path('boost.php'), <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;
        use SanderMuller\BoostCore\Enums\Tag;

        return BoostConfig::configure()
            ->withAgents([Agent::CLAUDE_CODE])
            ->withTags(Tag::Laravel, Tag::Php);
        PHP);
    writeLaravelBoostJson(['claude_code']);

    $this->artisan('project-boost:sync')->assertExitCode(1);

    expect(file_exists(base_path('boost.json')))->toBeTrue();
});

it('keeps boost.json when the archive path itself is a symlink', function (): void {
    // Following it would leave the "recovery copy" outside the project, where it can
    // vanish independently of the repo.
    writeSyncBoostPhp();
    writeLaravelBoostJson(['claude_code']);
    File::ensureDirectoryExists(base_path('.boost'));

    $target = sys_get_temp_dir() . '/pbl-archive-' . bin2hex(random_bytes(6)) . '.json';
    copy(base_path('boost.json'), $target);
    symlink($target, base_path('.boost/boost.json.retired'));

    try {
        $this->artisan('project-boost:sync')->assertSuccessful();

        expect(file_exists(base_path('boost.json')))->toBeTrue();
    } finally {
        @unlink(base_path('.boost/boost.json.retired'));
        @unlink($target);
    }
});

it('keeps boost.json when the content-addressed archive name is taken by other content', function (): void {
    writeSyncBoostPhp();
    File::ensureDirectoryExists(base_path('.boost'));
    file_put_contents(base_path('.boost/boost.json.retired'), '{"agents":["claude_code"],"skills":["older-run"]}');
    writeLaravelBoostJson(['claude_code']);

    $digest = hash('sha256', (string) file_get_contents(base_path('boost.json')));
    file_put_contents(base_path('.boost/boost.json.retired-' . $digest), 'someone else was here');

    try {
        $this->artisan('project-boost:sync')->assertSuccessful();

        expect(file_exists(base_path('boost.json')))->toBeTrue()
            ->and(file_get_contents(base_path('.boost/boost.json.retired-' . $digest)))->toBe('someone else was here');
    } finally {
        @unlink(base_path('.boost/boost.json.retired-' . $digest));
    }
});

it('drops the source when the content-addressed archive already holds identical content', function (): void {
    writeSyncBoostPhp();
    File::ensureDirectoryExists(base_path('.boost'));
    file_put_contents(base_path('.boost/boost.json.retired'), '{"agents":["claude_code"],"skills":["older-run"]}');
    writeLaravelBoostJson(['claude_code']);

    $digest = hash('sha256', (string) file_get_contents(base_path('boost.json')));
    copy(base_path('boost.json'), base_path('.boost/boost.json.retired-' . $digest));

    try {
        $this->artisan('project-boost:sync')->assertSuccessful();

        expect(file_exists(base_path('boost.json')))->toBeFalse()
            ->and(file_get_contents(base_path('.boost/boost.json.retired')))->toContain('older-run');
    } finally {
        @unlink(base_path('.boost/boost.json.retired-' . $digest));
    }
});

it("carries laravel/boost's own core guideline into the assembled guidance", function (): void {
    // This is the fragment that makes laravel/boost's standing instructions reach
    // EVERY agent, not just the one talking to its MCP server — including, on
    // laravel/boost >= 2.5, the directive to read `.ai/rules/index.md` before
    // editing. boost-core has no `.ai/rules` pipeline of its own, so if this
    // injection ever breaks, agents silently stop being pointed at those rules.
    // Asserted on text stable across the supported `^2.5` range rather than on the
    // rules block itself.
    //
    // The reader resolves `vendor/laravel/boost/.ai` relative to the CWD, which
    // these tests point at the testbench base path — so it needs the absolute
    // path to THIS package's vendor dir via the documented override. laravel/boost
    // export-ignores `.ai`, so on a prefer-dist install (CI) the directory is
    // absent and there is no real guideline to carry; the assertion is skipped
    // rather than asserted against content that cannot be there.
    config(['project-boost-laravel.laravel_boost_ai_root' => laravelBoostAiRoot()]);

    writeSyncBoostPhp();

    $this->artisan('project-boost:sync')->assertSuccessful();

    expect(file_get_contents(base_path('CLAUDE.md')))
        ->toContain('# Laravel Boost')
        ->toContain('php artisan route:list');
})->skip(fn (): bool => ! is_dir(laravelBoostAiRoot()), 'laravel/boost ships no .ai payload on a prefer-dist install.');

/**
 * Absolute path to laravel/boost's `.ai` payload in THIS package's vendor dir.
 */
function laravelBoostAiRoot(): string
{
    return dirname(__DIR__, 3) . '/vendor/laravel/boost/.ai';
}
