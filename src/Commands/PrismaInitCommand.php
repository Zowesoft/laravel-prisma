<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\SchemaManager;
use Zowesoft\LaravelPrisma\Services\DatabaseUrlBuilder;
use Zowesoft\LaravelPrisma\Services\EnvManager;

class PrismaInitCommand extends Command
{
    protected $signature   = 'prisma:init';
    protected $description = 'Scaffold prisma/schema.prisma using your Laravel DB config';

    public function handle(
        SchemaManager     $schema,
        DatabaseUrlBuilder $urlBuilder,
        EnvManager         $envManager,
    ): int {
        $schemaPath = config('laravel-prisma.schema_path');

        $this->line('');

        if (file_exists($schemaPath)) {
            if (! $this->confirm("  schema.prisma already exists. Overwrite?", false)) {
                $this->info('  Aborted.');
                return self::SUCCESS;
            }
        }

        // Build DATABASE_URL from Laravel config and write to .env
        try {
            $url      = $urlBuilder->build();
            $provider = $urlBuilder->provider();
            $urlKey   = config('laravel-prisma.database_url_key', 'DATABASE_URL');

            $envManager->set($urlKey, $url);

            $this->line("  <info>✓</info> DATABASE_URL written to .env");
            $this->line("  <info>✓</info> Provider: <comment>{$provider}</comment>");
        } catch (\RuntimeException $e) {
            $this->error('  ' . $e->getMessage());
            return self::FAILURE;
        }

        // Scaffold schema.prisma
        $schema->ensureSchema();
        $schema->syncDatasource();

        $this->line("  <info>✓</info> schema.prisma created at: <comment>{$schemaPath}</comment>");
        $this->line('');
        $this->line('  Edit your schema, then run:');
        $this->line('  <comment>php artisan prisma:generate</comment>');
        $this->line('');

        return self::SUCCESS;
    }
}
