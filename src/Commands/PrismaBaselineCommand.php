<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaBaselineCommand extends Command
{
    protected $signature   = 'prisma:baseline {--name=0_init : The name of the baseline migration} {--pull : Pull the schema from the database before baselining} {--force : Overwrite existing baseline migration files}';
    protected $description = 'Baseline an existing database by creating an initial migration and marking it as applied';

    public function handle(PrismaRunner $runner, SchemaManager $schema): int
    {
        $this->line('');
        $this->info('  Baselining existing database...');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        // 0. Optional Pull
        if ($this->option('pull')) {
            $this->line('  <comment>0/3</comment> Pulling current database schema...');
            $schema->syncDatasource();
            $pullSuccess = $runner->dbPull(function (string $type, string $line) {
                // Silent pull unless error
            });

            if (! $pullSuccess) {
                $this->error('  ❌ Failed to pull database schema.');
                return self::FAILURE;
            }
            $this->line('  <info>✓</info> Schema pulled successfully.');
        }

        $schemaPath = config('laravel-prisma.schema_path');
        $migrationsDir = config('laravel-prisma.migrations_path');
        $migrationName = $this->option('name');
        
        // 1. Generate the SQL diff
        $this->line('  <comment>1/3</comment> Generating baseline SQL from current schema...');
        try {
            $sql = $runner->migrateDiffFromEmpty($schemaPath);
            // Normalize line endings to LF to prevent Prisma checksum drift on Windows
            $sql = str_replace("\r\n", "\n", $sql);
        } catch (\Exception $e) {
            $this->error('  ' . $e->getMessage());
            return self::FAILURE;
        }

        // 2. Create the migration directory and file
        $this->line("  <comment>2/3</comment> Creating migration: {$migrationName}...");
        
        if (! is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }
        
        $gitattributesFile = $migrationsDir . DIRECTORY_SEPARATOR . '.gitattributes';
        if (! file_exists($gitattributesFile)) {
            file_put_contents($gitattributesFile, "* text eol=lf\n");
        }

        $targetDir = $migrationsDir . DIRECTORY_SEPARATOR . $migrationName;
        
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'migration.sql';
        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->line("  <info>✓</info> Migration file already exists — skipping (use --force to overwrite).");
        } else {
            file_put_contents($targetFile, $sql);
        }

        // 3. Resolve the migration as applied
        $this->line('  <comment>3/3</comment> Marking migration as applied in the database...');
        
        $alreadyApplied = false;
        $success = $runner->migrateResolve(function (string $type, string $line) use (&$alreadyApplied) {
            if (str_contains($line, 'P3008')) {
                $alreadyApplied = true;
            }
            $this->line("  {$line}");
        }, $migrationName, 'applied');

        $this->line('');

        if ($success || $alreadyApplied) {
            $this->info($alreadyApplied ? '  ✅ Database is already baselined.' : '  ✅ Database baselined successfully!');
            $this->line('  You can now run <comment>php artisan prisma:generate</comment> for future changes.');
            return self::SUCCESS;
        } else {
            $this->error('  ❌ Failed to resolve the migration history.');
            return self::FAILURE;
        }
    }
}
