<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Override;
use SanderMuller\ProjectBoostLaravel\Console\InstallCommand;
use SanderMuller\ProjectBoostLaravel\Console\SyncCommand;
use SanderMuller\ProjectBoostLaravel\Console\WhereCommand;
use SanderMuller\ProjectBoostLaravel\Listeners\EnforceMcpFlagOnBoostInstall;

/**
 * @internal Not a consumer API — Laravel instantiates it from the
 * `extra.laravel.providers` entry. Its FQCN is a discovery contract (don't
 * rename; see PUBLIC_API.md), but it is not meant to be referenced or extended.
 */
final class ProjectBoostLaravelServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/project-boost-laravel.php', 'project-boost-laravel');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCommand::class,
                InstallCommand::class,
                WhereCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/project-boost-laravel.php' => config_path('project-boost-laravel.php'),
            ], 'project-boost-laravel-config');

            $events = $this->app->make(Dispatcher::class);
            $events->listen(CommandStarting::class, EnforceMcpFlagOnBoostInstall::class);
        }
    }
}
