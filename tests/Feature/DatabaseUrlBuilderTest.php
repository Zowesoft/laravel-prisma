<?php

namespace Zowesoft\LaravelPrisma\Tests\Feature;

use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Tests\TestCase;

class DatabaseUrlBuilderTest extends TestCase
{
    private DatabaseUrlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DatabaseUrlBuilder();
    }

    /** @test */
    public function it_builds_sqlite_url()
    {
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => 'database/database.sqlite',
        ]);

        $url = $this->builder->build();
        $this->assertStringStartsWith('file:', $url);
        $this->assertStringContainsString('database/database.sqlite', $url);
    }

    /** @test */
    public function it_builds_mysql_url()
    {
        $this->app['config']->set('database.default', 'mysql');
        $this->app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'laravel_db',
            'username' => 'root',
            'password' => 'secret@123',
            'charset' => 'utf8mb4',
        ]);

        $url = $this->builder->build();
        // Password should be URL-encoded (secret%40123)
        $this->assertEquals('mysql://root:secret%40123@127.0.0.1:3306/laravel_db?charset=utf8mb4', $url);
    }

    /** @test */
    public function it_builds_postgresql_url()
    {
        $this->app['config']->set('database.default', 'pgsql');
        $this->app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'mydb',
            'username' => 'postgres',
            'password' => 'secret',
            'schema' => 'custom_schema',
        ]);

        $url = $this->builder->build();
        $this->assertEquals('postgresql://postgres:secret@localhost:5432/mydb?schema=custom_schema', $url);
    }

    /** @test */
    public function it_builds_sqlserver_url()
    {
        $this->app['config']->set('database.default', 'sqlsrv');
        $this->app['config']->set('database.connections.sqlsrv', [
            'driver' => 'sqlsrv',
            'host' => 'sqlhost',
            'port' => '1433',
            'database' => 'mssql',
            'username' => 'sa',
            'password' => 'Pass123',
        ]);

        $url = $this->builder->build();
        $this->assertEquals('sqlserver://sqlhost:1433;database=mssql;user=sa;password=Pass123', $url);
    }

    /** @test */
    public function it_ignores_stale_managed_database_url_when_switching_connections()
    {
        $this->app['config']->set('database.default', 'mysql');
        
        // Assume DATABASE_URL is set to a stale SQLite path in .env
        $staleUrl = 'file:database/database.sqlite';
        $_ENV['DATABASE_URL'] = $staleUrl;

        $this->app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'url' => $staleUrl, // Usually matches env('DATABASE_URL') in standard Laravel config
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'laravel_db',
            'username' => 'root',
            'password' => 'secret',
        ]);

        $url = $this->builder->build();
        
        // It must ignore the stale SQLite URL and correctly build the MySQL URL
        $this->assertEquals('mysql://root:secret@127.0.0.1:3306/laravel_db', $url);

        unset($_ENV['DATABASE_URL']);
    }

    /** @test */
    public function it_returns_correct_provider_names()
    {
        $this->app['config']->set('database.default', 'mysql');
        $this->assertEquals('mysql', $this->builder->provider());

        $this->app['config']->set('database.default', 'pgsql');
        $this->assertEquals('postgresql', $this->builder->provider());

        $this->app['config']->set('database.default', 'sqlite');
        $this->assertEquals('sqlite', $this->builder->provider());

        $this->app['config']->set('database.default', 'sqlsrv');
        $this->assertEquals('sqlserver', $this->builder->provider());
    }
}
