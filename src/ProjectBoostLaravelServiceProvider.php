<?php declare(strict_types=1);

namespace SanderMuller\ProjectBoostLaravel;

use Illuminate\Support\ServiceProvider;
use Override;
use SanderMuller\ProjectBoostLaravel\Console\InstallCommand;
use SanderMuller\ProjectBoostLaravel\Console\SyncCommand;

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
            ]);

            $this->publishes([
                __DIR__ . '/../config/project-boost-laravel.php' => config_path('project-boost-laravel.php'),
            ], 'project-boost-laravel-config');
        }
    }
}
