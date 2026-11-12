<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\ProjectBoostLaravel\Coexistence\LaravelBoostExcludes;

test('maps a laravel/boost guideline key to the name this package gives it', function (string $key, ?string $name): void {
    expect(LaravelBoostExcludes::guidelineName($key))->toBe($name);
})->with([
    'core file' => ['foundation', 'foundation'],
    'core package' => ['boost', 'boost-core'],
    'conditional' => ['herd', 'herd-core'],
    'enforce tests' => ['tests', 'enforce-tests'],
    'package core' => ['livewire/core', 'livewire-core'],
    'package major' => ['laravel/v12', 'laravel-12-core'],
    'package major file' => ['livewire/v3/forms', 'livewire-3-forms'],
    'laravel conditional' => ['laravel/style', 'laravel-style'],
    'third-party' => ['acme/widgets/core', null],
    'host guideline' => ['.ai/team-rules', null],
]);

test('advises only the entries the boost config does not cover', function (): void {
    $root = sys_get_temp_dir() . '/project-boost-laravel-excludes-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, true);
    file_put_contents("{$root}/boost.php", <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;
        
        return BoostConfig::configure()
            ->withAgents([Agent::CLAUDE_CODE])
            ->withExcludedSkills(['laravel/boost:covered-skill', 'acme/widgets:vendor-skill'])
            ->withExcludedGuidelines(['laravel/boost:herd-core']);
        PHP);

    try {
        $config = BoostConfig::load($root);
    } finally {
        @unlink("{$root}/boost.php");
        @rmdir($root);
    }

    $advice = LaravelBoostExcludes::fromConfig(
        ['covered-skill', 'open-skill', 'vendor-skill', 42],
        ['herd', 'livewire/core', 'inertia-laravel/core', 'acme/widgets/core'],
    )->advice($config, ['covered-skill', 'open-skill'], ['herd-core', 'livewire-core']);

    expect($advice)->toHaveCount(3)
        ->and($advice[0])->toContain('`laravel/boost:open-skill`')
        ->and($advice[1])->toContain('`laravel/boost:livewire-core`')
        // inertia-laravel is not injected, so nothing ships and nothing is said.
        ->and($advice[2])->toContain('`acme/widgets/core` is not a laravel/boost guideline');
});
