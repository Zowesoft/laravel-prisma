<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaBaselineCommand extends Command
{
    protected $signature   = 'prisma:baseline {--name=0_init : The name of the baseline migration}';
    protected $description = 'Baseline an existing database by creating an initial migration and marking it as applied';

    public function handle(PrismaRunner $runner, SchemaManager $schema): int
    {
        $this->line('');
        $this->info('  Baselining existing database...');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $schemaPath = config('laravel-prisma.schema_path');
        $migrationsDir = config('laravel-prisma.migrations_path');
        $migrationName = $this->option('name');
        
        // 1. Generate the SQL diff
        $this->line('  <comment>1/3</comment> Generating baseline SQL from current schema...');
        try {
            $sql = $runner->migrateDiffFromEmpty($schemaPath);
        } catch (\Exception $e) {
            $this->error('  ' . $e->getMessage());
            return self::FAILURE;
        }

        // 2. Create the migration directory and file
        $this->line("  <comment>2/3</comment> Creating migration: {$migrationName}...");
        $targetDir = $migrationsDir . DIRECTORY_SEPARATOR . $migrationName;
        
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'migration.sql', $sql);

        // 3. Resolve the migration as applied
        $this->line('  <comment>3/3</comment> Marking migration as applied in the database...');
        $success = $runner->migrateResolve(function (string $type, string $line) {
            $this->line("  {$line}");
        }, $migrationName, 'applied');

        $this->line('');

        if ($success) {
            $this->info('  ✅ Database baselined successfully!');
            $this->line('  You can now run <comment>php artisan prisma:generate</comment> for future changes.');
        } else {
            $this->error('  ❌ Failed to resolve the migration history.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
