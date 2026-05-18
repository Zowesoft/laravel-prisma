<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaInstallCommand extends Command
{
    protected $signature   = 'prisma:install';
    protected $description = 'Install the Prisma CLI via the configured package manager and scaffold prisma/schema.prisma';

    public function handle(PrismaRunner $runner, SchemaManager $schema): int
    {
        $pm = config('laravel-prisma.package_manager');

        $this->line('');
        $this->line('  <info>Laravel Prisma — Installer</info>');
        $this->line('  ─────────────────────────────────────');
        $this->line('');

        // ── 1. Check Runtime (Node/Bun) ──────────────────────────────────────
        $runtime = $pm === 'bun' ? 'Bun' : 'Node.js';
        $this->line("  <comment>[1/3]</comment> Checking {$runtime}...");

        if (! $runner->nodeAvailable()) {
            $this->line('');
            $this->error("  {$runtime} is not installed or not in PATH.");
            $this->line('');
            if ($pm === 'bun') {
                $this->line('  Install Bun from: <href=https://bun.sh>https://bun.sh</href>');
            } else {
                $this->line('  Install Node.js from: <href=https://nodejs.org>https://nodejs.org</href>');
                $this->line('  Recommended version : Node.js 18 LTS or higher');
            }
            $this->line('');
            return self::FAILURE;
        }

        $this->line("  <info>✓</info> {$runtime} found.");
        $this->line('');

        // ── 2. Check Executor ────────────────────────────────────────────────
        $this->line('  <comment>[2/3]</comment> Checking package manager executor...');

        if (! $runner->executorAvailable()) {
            $this->error("  The executor for {$pm} is not available.");
            return self::FAILURE;
        }

        $this->line('  <info>✓</info> Executor found.');
        $this->line('');

        // ── 3. Install Prisma ─────────────────────────────────────────────────
        if ($runner->prismaInstalled()) {
            $this->line('  <comment>[3/3]</comment> Prisma is already installed.');
        } else {
            $this->line("  <comment>[3/3]</comment> Installing Prisma via {$pm}...");
            $this->line('');

            $success = $runner->install(function (string $type, string $line) {
                // Stream output live
                if ($type === 'err' && ! $this->looksLikeWarning($line)) {
                    $this->line("  <fg=red>{$line}</fg=red>");
                } else {
                    $this->line("  {$line}");
                }
            });

            $this->line('');

            if (! $success) {
                $this->error('  Prisma installation failed. Check the output above.');
                return self::FAILURE;
            }

            $this->line('  <info>✓</info> Prisma installed successfully.');
        }

        $this->line('');

        // ── 4. Scaffold schema.prisma ─────────────────────────────────────────
        $schemaPath = config('laravel-prisma.schema_path');

        if (file_exists($schemaPath)) {
            $this->line('  <info>✓</info> schema.prisma already exists — skipping scaffold.');
        } else {
            $schema->ensureSchema();
            $schema->syncDatasource();
            $this->line("  <info>✓</info> schema.prisma created at: <comment>{$schemaPath}</comment>");
        }

        $this->line('');
        $this->line('  ─────────────────────────────────────');
        $this->line('  <info>✅ Setup complete!</info>');
        $this->line('');
        $this->line('  Next steps:');
        $this->line('    1. Edit <comment>prisma/schema.prisma</comment> and define your models');
        $this->line('    2. Run <comment>php artisan prisma:generate</comment> to create and apply migrations');
        $this->line('    3. Run <comment>php artisan prisma:status</comment> to check migration state');
        $this->line('');

        return self::SUCCESS;
    }

    private function looksLikeWarning(string $line): bool
    {
        // npm prints progress and warnings to stderr — don't show them as errors
        return str_contains($line, 'npm warn')
            || str_contains($line, 'WARN')
            || str_contains($line, 'added ')
            || str_contains($line, 'packages');
    }
}
