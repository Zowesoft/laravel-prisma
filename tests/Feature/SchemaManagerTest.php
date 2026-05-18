<?php

namespace Zowesoft\LaravelPrisma\Tests\Feature;

use Zowesoft\LaravelPrisma\Services\SchemaManager;
use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Services\EnvManager;
use Zowesoft\LaravelPrisma\Tests\TestCase;

class SchemaManagerTest extends TestCase
{
    private string $tempDir;
    private string $schemaPath;
    private string $configPath;
    private SchemaManager $schemaManager;

    protected function setUp(): void
    {
        parent::setUp();
        
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
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_ensures_schema_and_config_files_are_created_if_missing()
    {
        $this->assertFileDoesNotExist($this->schemaPath);
        $this->assertFileDoesNotExist($this->configPath);

        $this->schemaManager->ensureSchema();

        $this->assertFileExists($this->schemaPath);
        $this->assertFileExists($this->configPath);

        $schemaContent = file_get_contents($this->schemaPath);
        $this->assertStringContainsString('provider = "mysql"', $schemaContent);
        $this->assertStringContainsString('generator client {', $schemaContent);

        $configContent = file_get_contents($this->configPath);
        $this->assertStringContainsString('export default defineConfig', $configContent);
    }

    /** @test */
    public function it_syncs_datasource_correctly()
    {
        // Setup initial schema file
        file_put_contents($this->schemaPath, 'model User { id Int @id }');

        $this->schemaManager->syncDatasource();

        $schemaContent = file_get_contents($this->schemaPath);
        
        // Assert that the mysql datasource block was injected
        $this->assertStringContainsString('datasource db {', $schemaContent);
        $this->assertStringContainsString('provider = "mysql"', $schemaContent);
        $this->assertStringContainsString('model User { id Int @id }', $schemaContent);

        // Assert config was updated
        $this->assertFileExists($this->configPath);
        $configContent = file_get_contents($this->configPath);
        $this->assertStringContainsString('url: env("DATABASE_URL")', $configContent);
    }

    /** @test */
    public function it_prettifies_snake_case_plural_models_to_singular_pascalcase()
    {
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

        // user_profiles model should become UserProfile
        $this->assertStringContainsString('model UserProfile {', $prettified);
        // It must append the @@map("user_profiles") definition to save original table mapping
        $this->assertStringContainsString('@@map("user_profiles")', $prettified);
    }
}
