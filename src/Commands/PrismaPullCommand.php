<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaPullCommand extends Command
{
    protected $signature   = 'prisma:pull {--force : Force overwrite of existing schema.prisma}';
    protected $description = 'Pull the current database schema into schema.prisma (runs prisma db pull)';

    public function handle(PrismaRunner $runner, SchemaManager $schema): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $this->line('  <info>Syncing database config...</info>');
        $schema->syncDatasource();

        $this->line('  <comment>Running: npx prisma db pull</comment>');
        $this->line('');

        $success = $runner->dbPull(function (string $type, string $line) {
            $this->line("  {$line}");
        }, $this->option('force'));

        $this->line('');

        if ($success) {
            $this->info('  ✓ Database pulled successfully.');
            $this->comment('  Tip: Run `php artisan prisma:prettify` to rename models to PascalCase singular.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
