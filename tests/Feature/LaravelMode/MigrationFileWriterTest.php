<?php

use Zowesoft\LaravelPrisma\Services\LaravelMode\MigrationFileWriter;

beforeEach(function () {
    $this->writer = new MigrationFileWriter();
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel_prisma_tests_' . uniqid();
    
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
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

it('writes a valid laravel migration stub correctly', function () {
    $name = 'create_users_table';
    $blocks = [
        "Schema::create('users', function (Blueprint \$table) {\n    \$table->id();\n});"
    ];

    $filePath = $this->writer->write($name, $blocks, $this->tempDir);

    expect($filePath)->toBeFile();
    $content = file_get_contents($filePath);

    expect($content)->toContain('class extends Migration')
                    ->toContain("Schema::create('users'")
                    ->toContain('use Illuminate\Database\Migrations\Migration;')
                    ->toContain('use Illuminate\Database\Schema\Blueprint;')
                    ->toContain('use Illuminate\Support\Facades\Schema;')
                    ->not->toContain('use Illuminate\Support\Facades\DB;');
});

it('adds db facade import if db unprepared is used', function () {
    $name = 'add_uuid_extension';
    $blocks = [
        "DB::unprepared('CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";');"
    ];

    $filePath = $this->writer->write($name, $blocks, $this->tempDir);
    $content = file_get_contents($filePath);

    expect($content)->toContain('use Illuminate\Support\Facades\DB;');
});

it('throws an exception if a migration with the same name already exists', function () {
    $name = 'add_bio_to_users_table';
    $blocks = ["Schema::table('users', function (Blueprint \$table) {});"];

    $firstPath = $this->writer->write($name, $blocks, $this->tempDir);
    expect($firstPath)->toBeFile();

    // Check expectation of RuntimeException
    expect(fn() => $this->writer->write($name, $blocks, $this->tempDir))
        ->toThrow(\RuntimeException::class, "A migration named 'add_bio_to_users_table' already exists");
});

it('correctly finds existing migration regardless of timestamp', function () {
    $name = 'add_bio_to_users_table';
    
    $pastTimestamp = '2026_01_01_120000';
    $mockFile = $this->tempDir . DIRECTORY_SEPARATOR . "{$pastTimestamp}_{$name}.php";
    touch($mockFile);

    $existing = $this->writer->findExisting($name, $this->tempDir);
    expect($existing)->toBe($mockFile);
});
