<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\EnvManager;
use Illuminate\Support\Facades\File;

class PrismaUninstallCommand extends Command
{
    protected $signature   = 'prisma:uninstall {--force : Do not ask for confirmation}';
    protected $description = 'Uninstall the Prisma CLI and remove all generated Prisma files';

    public function handle(PrismaRunner $runner, EnvManager $envManager): int
    {
        $this->line('');
        $this->line('  <info>Laravel Prisma — Uninstaller</info>');
        $this->line('  ─────────────────────────────────────');
        $this->line('');

        if (! $this->option('force')) {
            if (! $this->confirm('  This will remove your prisma/ directory, prisma.config.ts and uninstall the prisma package. Are you sure?', false)) {
                $this->info('  Aborted.');
                return self::SUCCESS;
            }
        }

        // ── 1. Uninstall Prisma Package ──────────────────────────────────────
        $pm = config('laravel-prisma.package_manager');
        $this->line("  <comment>[1/4]</comment> Uninstalling Prisma package via {$pm}...");

        if ($runner->prismaInstalled()) {
            $success = $runner->uninstall(function (string $type, string $line) {
                if ($type === 'err') {
                    $this->line("  <fg=red>{$line}</fg=red>");
                } else {
                    $this->line("  {$line}");
                }
            });

            if (! $success) {
                $this->error('  Prisma uninstallation failed. Check the output above.');
                if (! $this->confirm('  Do you want to continue removing files anyway?', true)) {
                    return self::FAILURE;
                }
            } else {
                $this->line('  <info>✓</info> Prisma package uninstalled.');
            }
        } else {
            $this->line('  <comment>Prisma package not found — skipping.</comment>');
        }

        $this->line('');

        // ── 2. Remove Prisma Directory ───────────────────────────────────────
        $this->line('  <comment>[2/4]</comment> Removing prisma directory...');
        $schemaPath = config('laravel-prisma.schema_path');
        $prismaDir  = dirname($schemaPath);

        if (File::isDirectory($prismaDir)) {
            File::deleteDirectory($prismaDir);
            $this->line("  <info>✓</info> Directory <comment>{$prismaDir}</comment> removed.");
        } else {
            $this->line('  <comment>Prisma directory not found — skipping.</comment>');
        }

        $this->line('');

        // ── 3. Remove Prisma Config ──────────────────────────────────────────
        $this->line('  <comment>[3/4]</comment> Removing prisma.config.ts...');
        $configPath = config('laravel-prisma.config_path');

        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->line("  <info>✓</info> Config <comment>{$configPath}</comment> removed.");
        } else {
            $this->line('  <comment>Prisma config not found — skipping.</comment>');
        }

        $this->line('');

        // ── 4. Clean up .env ─────────────────────────────────────────────────
        $this->line('  <comment>[4/4]</comment> Cleaning up .env...');
        $urlKey = config('laravel-prisma.database_url_key', 'DATABASE_URL');

        if ($envManager->has($urlKey)) {
            $envManager->remove($urlKey);
            $this->line("  <info>✓</info> <comment>{$urlKey}</comment> removed from .env.");
        } else {
            $this->line('  <comment>No DATABASE_URL found in .env — skipping.</comment>');
        }

        $this->line('');
        $this->line('  ─────────────────────────────────────');
        $this->line('  <info>✅ Uninstallation complete!</info>');
        $this->line('');

        return self::SUCCESS;
    }
}
