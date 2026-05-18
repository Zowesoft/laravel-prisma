<?php

use Zowesoft\LaravelPrisma\Services\SchemaManager;
use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Services\EnvManager;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel_prisma_schema_tests_' . uniqid();
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }

    $this->schemaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'schema.prisma';
    $this->configPath = $this->tempDir . DIRECTORY_SEPARATOR . 'prisma.config.ts';

    $this->app['config']->set('laravel-prisma.schema_path', $this->schemaPath);
    $this->app['config']->set('laravel-prisma.config_path', $this->configPath);
    $this->app['config']->set('laravel-prisma.migrations_path', $this->tempDir . DIRECTORY_SEPARATOR . 'migrations');

    $urlBuilder = $this->createMock(DatabaseUrlBuilder::class);
    $urlBuilder->method('provider')->willReturn('mysql');
    $urlBuilder->method('build')->willReturn('mysql://root:secret@127.0.0.1:3306/db');

    $envManager = $this->createMock(EnvManager::class);

    $this->schemaManager = new SchemaManager($urlBuilder, $envManager);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }
});

it('ensures schema and config files are created if missing', function () {
    expect($this->schemaPath)->not->toBeFile();
    expect($this->configPath)->not->toBeFile();

    $this->schemaManager->ensureSchema();

    expect($this->schemaPath)->toBeFile();
    expect($this->configPath)->toBeFile();

    $schemaContent = file_get_contents($this->schemaPath);
    expect($schemaContent)->toContain('provider = "mysql"')
                          ->toContain('generator client {');

    $configContent = file_get_contents($this->configPath);
    expect($configContent)->toContain('export default defineConfig');
});

it('syncs datasource correctly', function () {
    file_put_contents($this->schemaPath, 'model User { id Int @id }');

    $this->schemaManager->syncDatasource();

    $schemaContent = file_get_contents($this->schemaPath);
    expect($schemaContent)->toContain('datasource db {')
                          ->toContain('provider = "mysql"')
                          ->toContain('model User { id Int @id }');

    expect($this->configPath)->toBeFile();
    $configContent = file_get_contents($this->configPath);
    expect($configContent)->toContain('url: env("DATABASE_URL")');
});

it('prettifies snake_case plural models to singular pascalcase', function () {
    $initialSchema = '
        model user_profiles {
            id Int @id
            user_id Int
            user_profiles user_profiles @relation(fields: [user_id], references: [id])
        }
    ';

    file_put_contents($this->schemaPath, $initialSchema);

    $this->schemaManager->prettify();

    $prettified = file_get_contents($this->schemaPath);

    expect($prettified)->toContain('model UserProfile {')
                       ->toContain('@@map("user_profiles")');
});
