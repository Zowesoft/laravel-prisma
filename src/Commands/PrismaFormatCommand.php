<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;

class PrismaFormatCommand extends Command
{
    protected $signature   = 'prisma:format';
    protected $description = 'Format schema.prisma using the Prisma formatter';

    public function handle(PrismaRunner $runner): int
    {
        $this->line('');

        if (! $runner->prismaInstalled()) {
            $this->error('  Prisma is not installed. Run: php artisan prisma:install');
            return self::FAILURE;
        }

        $this->line('  <comment>Running: npx prisma format</comment>');
        $this->line('');

        $success = $runner->format(function (string $type, string $line) {
            $this->line("  {$line}");
        });

        $this->line('');

        if ($success) {
            $this->info('  ✅ schema.prisma formatted.');
        } else {
            $this->error('  ❌ Formatting failed.');
        }

        $this->line('');

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
