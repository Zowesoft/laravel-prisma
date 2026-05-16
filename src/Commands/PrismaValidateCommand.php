<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;

class PrismaValidateCommand extends Command
{
    protected $signature   = 'prisma:validate';
    protected $description = 'Validate your schema.prisma file for syntax errors';

    public function handle(PrismaRunner $runner): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $this->line('  <comment>Running: npx prisma validate</comment>');
        $this->line('');

        $success = $runner->validate(function (string $type, string $line) {
            if ($type === 'err') {
                $this->line("  <fg=red>{$line}</fg=red>");
            } else {
                $this->line("  {$line}");
            }
        });

        $this->line('');

        if ($success) {
            $this->info('  ✅ schema.prisma is valid.');
        } else {
            $this->error('  ❌ schema.prisma has errors. Fix them before running prisma:generate.');
        }

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
