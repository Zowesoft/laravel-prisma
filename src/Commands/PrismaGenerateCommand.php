<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaGenerateCommand extends Command
{
    protected $signature = 'prisma:generate
                            {--name= : Name for the migration (passed to Prisma --name flag)}
                            {--skip-sync : Skip syncing DATABASE_URL from Laravel config}';

    protected $description = 'Sync DB config and run prisma migrate dev to generate and apply migrations';

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

        // ── Step 2: Run prisma migrate dev ───────────────────────────────────
        $name = $this->option('name');
        $label = $name ? " --name={$name}" : '';

        $this->line("  <comment>Running: npx prisma migrate dev{$label}</comment>");
        $this->line('  ' . str_repeat('─', 50));
        $this->line('');

        $success = $runner->migrateDev(
            output: function (string $type, string $line) {
                // Print Prisma's output verbatim — it already has good formatting
                if ($type === 'err') {
                    // Prisma sends some info through stderr (progress indicators etc.)
                    // Only colour it red if it looks like a real error
                    if ($this->looksLikeError($line)) {
                        $this->line("  <fg=red>{$line}</fg=red>");
                    } else {
                        $this->line("  <fg=yellow>{$line}</fg=yellow>");
                    }
                } else {
                    $this->line("  {$line}");
                }
            },
            name: $name,
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
        }

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function looksLikeError(string $line): bool
    {
        $lower = strtolower($line);
        return str_contains($lower, 'error')
            || str_contains($lower, 'failed')
            || str_contains($lower, 'cannot')
            || str_contains($lower, 'invalid');
    }
}
