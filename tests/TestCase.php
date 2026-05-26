<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel\Tests;

use Illuminate\Foundation\Application;
use Laravel\Boost\BoostServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use SanderMuller\ProjectBoostLaravel\ProjectBoostLaravelServiceProvider;

/**
 * Base Testbench TestCase for Feature tests.
 *
 * Registers laravel/boost's ServiceProvider (so `AgentsDetector`,
 * `McpWriter`, and the agent classes resolve from the container)
 * and this package's ServiceProvider (so the `project-boost:*`
 * artisan commands + the `EnforceMcpFlagOnBoostInstall` listener
 * are registered).
 *
 * Pest binds this class to all tests under `tests/Feature/` via
 * the `pest()->extend(...)->in('Feature')` call in `tests/Pest.php`.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BoostServiceProvider::class,
            ProjectBoostLaravelServiceProvider::class,
        ];
    }
}
