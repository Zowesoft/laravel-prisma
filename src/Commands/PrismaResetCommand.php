<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;

class PrismaResetCommand extends Command
{
    protected $signature   = 'prisma:reset {--force : Skip confirmation prompt}';
    protected $description = 'Reset the database using Prisma (drops all data and re-applies migrations)';

    public function handle(PrismaRunner $runner): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('  ⚠️  This will DROP all tables and re-apply all migrations from scratch.');
            $this->line('');

            if (! $this->confirm('  Are you sure you want to reset the database?', false)) {
                $this->info('  Aborted.');
                $this->line('');
                return self::SUCCESS;
            }
        }

        $this->line('');
        $this->line('  <comment>Running: npx prisma migrate reset</comment>');
        $this->line('');

        $success = $runner->migrateReset(
            output: function (string $type, string $line) {
                $this->line("  {$line}");
            },
            force: $this->option('force'),
        );

        $this->line('');

        if ($success) {
            $this->info('  ✅ Database reset complete.');
        } else {
            $this->error('  ❌ Database reset failed. Review the output above.');
        }

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
