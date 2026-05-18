<?php

namespace Zowesoft\LaravelPrisma\Services;

class SchemaManager
{
    public function __construct(
        private DatabaseUrlBuilder $urlBuilder,
        private EnvManager         $envManager,
    ) {}

    /**
     * Ensure the prisma/ directory, schema.prisma and prisma.config.ts exist.
     * Creates them with the correct datasource block if missing.
     */
    public function ensureSchema(): void
    {
        $schemaPath = config('laravel-prisma.schema_path');
        $configPath = config('laravel-prisma.config_path');
        $dir        = dirname($schemaPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($schemaPath)) {
            file_put_contents($schemaPath, $this->defaultSchema());
        }

        if (! file_exists($configPath)) {
            file_put_contents($configPath, $this->defaultConfig());
        }
    }

    /**
     * Inject or update the datasource block in schema.prisma and prisma.config.ts
     * so that the provider and DATABASE_URL always match Laravel's .env.
     */
    public function syncDatasource(): void
    {
        $schemaPath = config('laravel-prisma.schema_path');
        $configPath = config('laravel-prisma.config_path');

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

        // Ensure SQLite file exists if using SQLite
        if ($provider === 'sqlite') {
            $sqlitePath = str_replace('file:', '', $databaseUrl);
            if (! file_exists($sqlitePath) && $sqlitePath !== ':memory:') {
                $dir = dirname($sqlitePath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                touch($sqlitePath);
            }
        }

        // Update schema.prisma datasource block (remove url property for Prisma 7+)
        $content = file_get_contents($schemaPath);

        $datasourceBlock = <<<PRISMA
            datasource db {
            provider = "{$provider}"
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

        // Update prisma.config.ts
        $this->syncConfig($configPath, $urlKey);
    }

    /**
     * Sync the prisma.config.ts file with the current environment variables.
     */
    private function syncConfig(string $configPath, string $urlKey): void
    {
        $schemaPath     = str_replace(base_path() . DIRECTORY_SEPARATOR, '', config('laravel-prisma.schema_path'));
        $migrationsPath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', config('laravel-prisma.migrations_path'));

        // Normalize separators to forward slashes for cross-platform TS config
        $schemaPath     = str_replace('\\', '/', $schemaPath);
        $migrationsPath = str_replace('\\', '/', $migrationsPath);

        $configContent = <<<TYPESCRIPT
import "dotenv/config";
import { defineConfig, env } from "prisma/config";

export default defineConfig({
  schema: "{$schemaPath}",
  migrations: {
    path: "{$migrationsPath}",
    seed: "tsx prisma/seed.ts",
  },
  datasource: {
    url: env("{$urlKey}"),
  },
});
TYPESCRIPT;

        file_put_contents($configPath, $configContent);
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

    /**
     * Rename snake_case plural models (from db pull) to PascalCase singular.
     * e.g. model users { ... } -> model User { ... @@map("users") }
     */
    public function prettify(): void
    {
        $schemaPath = config('laravel-prisma.schema_path');
        if (! file_exists($schemaPath)) {
            return;
        }

        $content = file_get_contents($schemaPath);

        // 1. Find all models and determine their new names
        preg_match_all('/model\s+(\w+)\s*\{([^}]+)\}/s', $content, $matches, PREG_SET_ORDER);

        $renames = [];
        foreach ($matches as $match) {
            $oldName = $match[1];
            // PascalCase + Singular (e.g. agent_conversation_messages -> AgentConversationMessage)
            $newName = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($oldName));

            if ($oldName !== $newName) {
                $renames[$oldName] = $newName;
            }
        }

        if (empty($renames)) {
            return;
        }

        // 2. Perform renames and add @@map
        foreach ($renames as $oldName => $newName) {
            $content = preg_replace_callback(
                '/model\s+' . $oldName . '\s*\{([^}]+)\}/s',
                function ($m) use ($oldName, $newName) {
                    $body = $m[1];
                    // Add @@map if not already present
                    if (! str_contains($body, '@@map')) {
                        $body = rtrim($body) . "\n\n  @@map(\"{$oldName}\")\n";
                    }
                    return "model {$newName} {{$body}}";
                },
                $content
            );
        }

        // 3. Update field types (relations)
        foreach ($renames as $oldName => $newName) {
            // Match oldName as a type, possibly followed by ? or []
            // Use a lookbehind to ensure we aren't matching part of another word
            $content = preg_replace('/(?<=\s)' . $oldName . '(?=\?|\[\]|\s|$)/m', $newName, $content);
        }

        file_put_contents($schemaPath, $content);
    }

    // -------------------------------------------------------------------------

    private function defaultSchema(): string
    {
        $provider = $this->urlBuilder->provider();

        return <<<PRISMA
// ──────────────────────────────────────────────────────────────────────────
//  Laravel Prisma Schema
//  This file is managed by the laravel-prisma package.
//  Run: php artisan prisma:generate  to apply changes.
//  Docs: https://www.prisma.io/docs/orm/prisma-schema
// ──────────────────────────────────────────────────────────────────────────

datasource db {
  provider = "{$provider}"
}

generator client {
  provider = "prisma-client"
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

    private function defaultConfig(): string
    {
        $urlKey         = config('laravel-prisma.database_url_key', 'DATABASE_URL');
        $schemaPath     = str_replace(base_path() . DIRECTORY_SEPARATOR, '', config('laravel-prisma.schema_path'));
        $migrationsPath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', config('laravel-prisma.migrations_path'));

        // Normalize separators to forward slashes
        $schemaPath     = str_replace('\\', '/', $schemaPath);
        $migrationsPath = str_replace('\\', '/', $migrationsPath);

        return <<<TYPESCRIPT
import "dotenv/config";
import { defineConfig, env } from "prisma/config";

export default defineConfig({
  schema: "{$schemaPath}",
  migrations: {
    path: "{$migrationsPath}",
    seed: "tsx prisma/seed.ts",
  },
  datasource: {
    url: env("{$urlKey}"),
  },
});
TYPESCRIPT;
    }
}
