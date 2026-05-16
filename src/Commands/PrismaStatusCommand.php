<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;

class PrismaStatusCommand extends Command
{
    protected $signature   = 'prisma:status';
    protected $description = 'Show the status of Prisma migrations (runs prisma migrate status)';

    public function handle(PrismaRunner $runner): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $this->line('  <comment>Running: npx prisma migrate status</comment>');
        $this->line('');

        $success = $runner->migrateStatus(function (string $type, string $line) {
            $this->line("  {$line}");
        });

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
