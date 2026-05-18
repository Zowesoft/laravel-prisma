<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatementParser;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlToSchemaBuilder;
use Zowesoft\LaravelPrisma\Services\LaravelMode\MigrationFileWriter;

class PrismaGenerateCommand extends Command
{
    protected $signature = 'prisma:generate
                            {--name= : Name for the migration (passed to Prisma --name flag)}
                            {--create-only : Create a migration without applying it}
                            {--skip-seed : Skip running the seed command}
                            {--skip-sync : Skip syncing DATABASE_URL from Laravel config}';

    protected $description = 'Sync DB config and generate migrations (Prisma native or Laravel mode)';

    public function handle(
        PrismaRunner       $runner,
        SchemaManager      $schema,
        DatabaseUrlBuilder $urlBuilder,
    ): int {
        $this->line('');

        // ── Guard: Prisma installed? ─────────────────────────────────────────
        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed.');
            $this->line('  Run: <comment>php artisan prisma:install</comment>');
            $this->line('');
            return self::FAILURE;
        }

        // ── Guard: schema.prisma exists? ─────────────────────────────────────
        $schemaPath = config('laravel-prisma.schema_path');
        if (! file_exists($schemaPath)) {
            $this->error("  schema.prisma not found at: {$schemaPath}");
            $this->line('  Run: <comment>php artisan prisma:init</comment>');
            $this->line('');
            return self::FAILURE;
        }

        // ── Step 1: Sync DATABASE_URL from Laravel .env ──────────────────────
        if (! $this->option('skip-sync')) {
            $this->line('  <comment>Syncing database config...</comment>');

            try {
                $schema->syncDatasource();
                $provider = $urlBuilder->provider();
                $this->line("  <info>✓</info> Provider : <comment>{$provider}</comment>");
                $this->line("  <info>✓</info> DATABASE_URL synced from Laravel config");
            } catch (\RuntimeException $e) {
                $this->error('  ' . $e->getMessage());
                return self::FAILURE;
            }

            $this->line('');
        }

        // ── Step 2: Route to the correct mode ────────────────────────────────
        $mode = config('laravel-prisma.mode', 'prisma');

        if ($mode === 'laravel') {
            return $this->handleLaravelMode($runner, $schemaPath);
        }

        return $this->handlePrismaMode($runner);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Prisma Mode (default)
    // ─────────────────────────────────────────────────────────────────────────

    private function handlePrismaMode(PrismaRunner $runner): int
    {
        $name       = $this->option('name');
        $createOnly = $this->option('create-only');
        $skipSeed   = $this->option('skip-seed');

        $label = $name ? " --name={$name}" : '';
        if ($createOnly) $label .= ' --create-only';
        if ($skipSeed)   $label .= ' --skip-seed';

        $this->line("  <comment>Running: npx prisma migrate dev{$label}</comment>");
        $this->line('  ' . str_repeat('─', 50));
        $this->line('');

        $success = $runner->migrateDev(
            output: function (string $type, string $line) {
                if ($type === 'err') {
                    if ($this->looksLikeError($line)) {
                        $this->line("  <fg=red>{$line}</fg=red>");
                    } else {
                        $this->line("  <fg=yellow>{$line}</fg=yellow>");
                    }
                } else {
                    $this->line("  {$line}");
                }
            },
            name:       $name,
            createOnly: $createOnly,
            skipSeed:   $skipSeed,
        );

        $this->line('');
        $this->line('  ' . str_repeat('─', 50));

        if ($success) {
            $this->line('');
            $this->line('  <info>✅ Done.</info> Prisma migration applied successfully.');
            $this->line('');
            $this->line('  Migration files saved to: <comment>' . config('laravel-prisma.migrations_path') . '</comment>');
        } else {
            $this->line('');
            $this->error('  ❌ Prisma migration failed. Review the output above.');
            $this->line('');
            $this->line('  Common causes:');
            $this->line('    • Database is unreachable — check DB_HOST, DB_PORT in .env');
            $this->line('    • schema.prisma has a syntax error — run: <comment>php artisan prisma:validate</comment>');
            $this->line('    • Prisma migration history is out of sync — run: <comment>php artisan prisma:status</comment>');
            $this->line('');
            $this->line('  <fg=cyan>Note: If drift is detected on an existing database, try:</fg=cyan>');
            $this->line('    <comment>php artisan prisma:baseline --pull</comment>');
            $this->line('');
            $this->line('  <fg=cyan>Or sync without migrations (data-safe for redefines):</fg=cyan>');
            $this->line('    <comment>php artisan prisma:push --accept-data-loss</comment>');
        }

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Laravel Mode
    // ─────────────────────────────────────────────────────────────────────────

    private function handleLaravelMode(PrismaRunner $runner, string $schemaPath): int
    {
        $name = $this->option('name');

        if (! $name) {
            $this->error('  A migration name is required in Laravel mode.');
            $this->line('  Run: <comment>php artisan prisma:generate --name=your_migration_name</comment>');
            $this->line('');
            return self::FAILURE;
        }

        // Step 1: Get raw SQL diff from Prisma (no DB changes made)
        $this->line('  <comment>1/3</comment> Generating schema diff with Prisma...');
        try {
            $rawSql = $runner->migrateDiffFromConfig($schemaPath);
        } catch (\RuntimeException $e) {
            $this->error('  ❌ ' . $e->getMessage());
            return self::FAILURE;
        }

        $rawSql = trim($rawSql);

        if (empty($rawSql) || $rawSql === '-- This is an empty migration.') {
            $this->line('');
            $this->line('  <info>✓</info> No schema changes detected. Nothing to generate.');
            $this->line('');
            return self::SUCCESS;
        }

        // Step 2: Parse the SQL into structured statements
        $this->line('  <comment>2/3</comment> Translating SQL to Laravel Schema Builder...');
        $parser     = new SqlStatementParser();
        $builder    = new SqlToSchemaBuilder();
        $statements = $parser->parse($rawSql);
        $blocks     = $builder->build($statements);

        // Check if any blocks fell back to DB::unprepared()
        $fallbacks = array_filter($blocks, fn($b) => str_contains($b, 'DB::unprepared'));
        if (! empty($fallbacks)) {
            $this->line('  <fg=yellow>⚠  Some statements could not be fully translated and use DB::unprepared() with a // TODO comment.</fg=yellow>');
        }

        // Step 3: Write the Laravel migration file
        $this->line('  <comment>3/3</comment> Writing Laravel migration file...');
        $writer    = new MigrationFileWriter();
        $targetDir = database_path('migrations');

        try {
            $filePath = $writer->write($name, $blocks, $targetDir);
        } catch (\RuntimeException $e) {
            $this->line('');
            $this->error('  ❌ ' . $e->getMessage());
            $this->line('');
            $this->line('  Use a different <comment>--name</comment> to create a new migration,');
            $this->line('  or delete the existing file and re-run this command.');
            $this->line('');
            return self::FAILURE;
        }

        $relativePath = 'database/migrations/' . basename($filePath);

        $this->line('');
        $this->line('  ' . str_repeat('─', 50));
        $this->line('');
        $this->line('  <info>✅ Done.</info> Laravel migration generated successfully.');
        $this->line('');
        $this->line("  File: <comment>{$relativePath}</comment>");
        $this->line('');
        $this->line('  Run <comment>php artisan migrate</comment> to apply the migration.');
        $this->line('');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function looksLikeError(string $line): bool
    {
        $lower = strtolower($line);
        return str_contains($lower, 'error')
            || str_contains($lower, 'failed')
            || str_contains($lower, 'cannot')
            || str_contains($lower, 'invalid');
    }
}

