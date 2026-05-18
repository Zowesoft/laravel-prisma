<?php

namespace Zowesoft\LaravelPrisma\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Zowesoft\LaravelPrisma\LaravelPrismaServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelPrismaServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set up default configs for tests
        $app['config']->set('laravel-prisma.schema_path', __DIR__ . '/fixtures/schema.prisma');
        $app['config']->set('laravel-prisma.config_path', __DIR__ . '/fixtures/prisma.config.ts');
        $app['config']->set('laravel-prisma.migrations_path', __DIR__ . '/fixtures/migrations');
        $app['config']->set('laravel-prisma.package_manager', 'npm');
        $app['config']->set('laravel-prisma.timeout', 300);
        $app['config']->set('laravel-prisma.mode', 'prisma');

        // Setup default mock database config
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
