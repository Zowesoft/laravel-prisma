<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaResolveCommand extends Command
{
    protected $signature   = 'prisma:resolve {migration : The name of the migration to resolve} {--applied : Mark the migration as applied} {--rolled-back : Mark the migration as rolled back}';
    protected $description = 'Resolve a failed migration in the migration history (runs prisma migrate resolve)';

    public function handle(PrismaRunner $runner, SchemaManager $schema): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $migration = $this->argument('migration');
        $status = $this->option('applied') ? 'applied' : ($this->option('rolled-back') ? 'rolled-back' : null);

        if (! $status) {
            $this->error('  You must specify either --applied or --rolled-back.');
            return self::FAILURE;
        }

        $this->line('  <info>Syncing database config...</info>');
        $schema->syncDatasource();

        $this->line("  <comment>Running: npx prisma migrate resolve --{$status} {$migration}</comment>");
        $this->line('');

        $success = $runner->migrateResolve(
            function (string $type, string $line) {
                $this->line("  {$line}");
            },
            $migration,
            $status
        );

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
