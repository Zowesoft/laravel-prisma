<?php

namespace Zowesoft\LaravelPrisma\Tests\Feature\LaravelMode;

use Zowesoft\LaravelPrisma\Services\LaravelMode\MigrationFileWriter;
use Zowesoft\LaravelPrisma\Tests\TestCase;

class MigrationFileWriterTest extends TestCase
{
    private MigrationFileWriter $writer;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new MigrationFileWriter();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel_prisma_tests_' . uniqid();
        
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temp dir files
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
    public function it_writes_a_valid_laravel_migration_stub_correctly()
    {
        $name = 'create_users_table';
        $blocks = [
            "Schema::create('users', function (Blueprint \$table) {\n    \$table->id();\n});"
        ];

        $filePath = $this->writer->write($name, $blocks, $this->tempDir);

        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        $this->assertStringContainsString('class extends Migration', $content);
        $this->assertStringContainsString("Schema::create('users'", $content);
        $this->assertStringContainsString('use Illuminate\Database\Migrations\Migration;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Schema\Blueprint;', $content);
        $this->assertStringContainsString('use Illuminate\Support\Facades\Schema;', $content);
        // Should NOT import DB if not used
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\DB;', $content);
    }

    /** @test */
    public function it_adds_db_facade_import_if_db_unprepared_is_used()
    {
        $name = 'add_uuid_extension';
        $blocks = [
            "DB::unprepared('CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";');"
        ];

        $filePath = $this->writer->write($name, $blocks, $this->tempDir);
        $content = file_get_contents($filePath);

        $this->assertStringContainsString('use Illuminate\Support\Facades\DB;', $content);
    }

    /** @test */
    public function it_throws_an_exception_if_a_migration_with_the_same_name_already_exists()
    {
        $name = 'add_bio_to_users_table';
        $blocks = ["Schema::table('users', function (Blueprint \$table) {});"];

        // 1st write: should succeed
        $firstPath = $this->writer->write($name, $blocks, $this->tempDir);
        $this->assertFileExists($firstPath);

        // 2nd write with same name: should throw exception to prevent duplicates
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("A migration named 'add_bio_to_users_table' already exists");

        $this->writer->write($name, $blocks, $this->tempDir);
    }

    /** @test */
    public function it_correctly_finds_existing_migration_regardless_of_timestamp()
    {
        $name = 'add_bio_to_users_table';
        
        // Touch a mock file with a past timestamp
        $pastTimestamp = '2026_01_01_120000';
        $mockFile = $this->tempDir . DIRECTORY_SEPARATOR . "{$pastTimestamp}_{$name}.php";
        touch($mockFile);

        $existing = $this->writer->findExisting($name, $this->tempDir);
        $this->assertNotNull($existing);
        $this->assertEquals($mockFile, $existing);
    }
}
