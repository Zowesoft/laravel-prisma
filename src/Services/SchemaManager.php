<?php

namespace Zowesoft\LaravelPrisma\Services;

class SchemaManager
{
    public function __construct(
        private DatabaseUrlBuilder $urlBuilder,
        private EnvManager         $envManager,
    ) {}

    /**
     * Ensure the prisma/ directory and schema.prisma exist.
     * Creates them with the correct datasource block if missing.
     */
    public function ensureSchema(): void
    {
        $schemaPath = config('laravel-prisma.schema_path');
        $dir        = dirname($schemaPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($schemaPath)) {
            file_put_contents($schemaPath, $this->defaultSchema());
        }
    }

    /**
     * Inject or update the datasource block in schema.prisma so that
     * the provider and DATABASE_URL always match Laravel's .env.
     */
    public function syncDatasource(): void
    {
        $schemaPath = config('laravel-prisma.schema_path');

        if (! file_exists($schemaPath)) {
            throw new \RuntimeException(
                "schema.prisma not found. Run: php artisan prisma:init"
            );
        }

        $provider   = $this->urlBuilder->provider();
        $urlKey     = config('laravel-prisma.database_url_key', 'DATABASE_URL');
        $databaseUrl = $this->urlBuilder->build();

        // Write DATABASE_URL into .env
        $this->envManager->set($urlKey, $databaseUrl);

        // Update schema.prisma datasource block
        $content = file_get_contents($schemaPath);

        $datasourceBlock = <<<PRISMA
datasource db {
  provider = "{$provider}"
  url      = env("{$urlKey}")
}
PRISMA;

        if (preg_match('/datasource\s+\w+\s*\{[^}]+\}/s', $content)) {
            // Replace existing datasource block
            $content = preg_replace(
                '/datasource\s+\w+\s*\{[^}]+\}/s',
                $datasourceBlock,
                $content
            );
        } else {
            // Prepend datasource block
            $content = $datasourceBlock . "\n\n" . $content;
        }

        file_put_contents($schemaPath, $content);
    }

    /**
     * Return the current contents of schema.prisma.
     */
    public function read(): string
    {
        $schemaPath = config('laravel-prisma.schema_path');

        if (! file_exists($schemaPath)) {
            throw new \RuntimeException(
                "schema.prisma not found. Run: php artisan prisma:init"
            );
        }

        return file_get_contents($schemaPath);
    }

    // -------------------------------------------------------------------------

    private function defaultSchema(): string
    {
        $provider = $this->urlBuilder->provider();
        $urlKey   = config('laravel-prisma.database_url_key', 'DATABASE_URL');

        return <<<PRISMA
// ──────────────────────────────────────────────────────────────────────────
//  Laravel Prisma Schema
//  This file is managed by the laravel-prisma package.
//  Run: php artisan prisma:generate  to apply changes.
//  Docs: https://www.prisma.io/docs/orm/prisma-schema
// ──────────────────────────────────────────────────────────────────────────

datasource db {
  provider = "{$provider}"
  url      = env("{$urlKey}")
}

generator client {
  provider = "prisma-client-js"
}

// Define your models below.
// Example:
//
// model User {
//   id        Int      @id @default(autoincrement())
//   name      String
//   email     String   @unique
//   createdAt DateTime @default(now())
//   updatedAt DateTime @updatedAt
// }

PRISMA;
    }
}
