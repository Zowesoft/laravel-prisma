<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaPushCommand extends Command
{
    protected $signature   = 'prisma:push {--force-reset : Reset the database before pushing} {--accept-data-loss : Accept potential data loss during push}';
    protected $description = 'Push the current schema.prisma to the database (runs prisma db push)';

    public function handle(PrismaRunner $runner, SchemaManager $schema): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $this->line('  <info>Syncing database config...</info>');
        $schema->syncDatasource();

        $this->line('  <comment>Running: npx prisma db push</comment>');
        $this->line('');

        $success = $runner->dbPush(
            function (string $type, string $line) {
                $this->line("  {$line}");
            },
            $this->option('force-reset'),
            $this->option('accept-data-loss')
        );

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
