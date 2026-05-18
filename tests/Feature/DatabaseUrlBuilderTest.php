<?php

use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;

beforeEach(function () {
    $this->builder = new DatabaseUrlBuilder();
});

it('builds sqlite url', function () {
    $this->app['config']->set('database.default', 'sqlite');
    $this->app['config']->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => 'database/database.sqlite',
    ]);

    $url = $this->builder->build();
    expect($url)->toStartWith('file:')
                ->toContain('database/database.sqlite');
});

it('builds mysql url', function () {
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
    expect($url)->toBe('mysql://root:secret%40123@127.0.0.1:3306/laravel_db?charset=utf8mb4');
});

it('builds postgresql url', function () {
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
    expect($url)->toBe('postgresql://postgres:secret@localhost:5432/mydb?schema=custom_schema');
});

it('builds sqlserver url', function () {
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
    expect($url)->toBe('sqlserver://sqlhost:1433;database=mssql;user=sa;password=Pass123');
});

it('ignores stale managed database url when switching connections', function () {
    $this->app['config']->set('database.default', 'mysql');
    
    $staleUrl = 'file:database/database.sqlite';
    $_ENV['DATABASE_URL'] = $staleUrl;

    $this->app['config']->set('database.connections.mysql', [
        'driver' => 'mysql',
        'url' => $staleUrl,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'laravel_db',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $url = $this->builder->build();
    expect($url)->toBe('mysql://root:secret@127.0.0.1:3306/laravel_db');

    unset($_ENV['DATABASE_URL']);
});

it('returns correct provider names', function () {
    $this->app['config']->set('database.default', 'mysql');
    expect($this->builder->provider())->toBe('mysql');

    $this->app['config']->set('database.default', 'pgsql');
    expect($this->builder->provider())->toBe('postgresql');

    $this->app['config']->set('database.default', 'sqlite');
    expect($this->builder->provider())->toBe('sqlite');

    $this->app['config']->set('database.default', 'sqlsrv');
    expect($this->builder->provider())->toBe('sqlserver');
});
