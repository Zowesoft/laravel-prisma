<?php

namespace Zowesoft\LaravelPrisma;

use Illuminate\Support\ServiceProvider;
use Zowesoft\LaravelPrisma\Commands\PrismaFormatCommand;
use Zowesoft\LaravelPrisma\Commands\PrismaGenerateCommand;
use Zowesoft\LaravelPrisma\Commands\PrismaInitCommand;
use Zowesoft\LaravelPrisma\Commands\PrismaInstallCommand;
use Zowesoft\LaravelPrisma\Commands\PrismaResetCommand;
use Zowesoft\LaravelPrisma\Commands\PrismaStatusCommand;
use Zowesoft\LaravelPrisma\Commands\PrismaValidateCommand;
use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Services\EnvManager;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class LaravelPrismaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publishable config
        $this->publishes([
            __DIR__ . '/../config/laravel-prisma.php' => config_path('laravel-prisma.php'),
        ], 'laravel-prisma-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PrismaInstallCommand::class,
                PrismaInitCommand::class,
                PrismaGenerateCommand::class,
                PrismaStatusCommand::class,
                PrismaResetCommand::class,
                PrismaValidateCommand::class,
                PrismaFormatCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-prisma.php',
            'laravel-prisma'
        );

        $this->app->singleton(DatabaseUrlBuilder::class);
        $this->app->singleton(EnvManager::class);

        $this->app->singleton(SchemaManager::class, fn($app) => new SchemaManager(
            urlBuilder: $app->make(DatabaseUrlBuilder::class),
            envManager: $app->make(EnvManager::class),
        ));

        $this->app->singleton(PrismaRunner::class, fn() => new PrismaRunner(
            packageManager: config('laravel-prisma.package_manager', 'npm'),
            executorPath: config('laravel-prisma.executor_path') ?? '',
            timeout: (int) config('laravel-prisma.timeout', 300),
        ));
    }
}
