<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prisma Schema Path
    |--------------------------------------------------------------------------
    | Path to your schema.prisma file relative to the Laravel base path.
    | This is where you define your data models.
    |
    */
    'schema_path' => base_path('prisma/schema.prisma'),

    /*
    |--------------------------------------------------------------------------
    | Prisma Config Path
    |--------------------------------------------------------------------------
    | Path to your prisma.config.ts file relative to the Laravel base path.
    | Prisma 7+ uses this file for datasource configuration.
    |
    */
    'config_path' => base_path('prisma.config.ts'),

    /*
    |--------------------------------------------------------------------------
    | Prisma Migrations Path
    |--------------------------------------------------------------------------
    | Where Prisma stores its generated SQL migration files.
    | Prisma manages this folder — do not manually edit files inside it.
    |
    */
    'migrations_path' => base_path('prisma/migrations'),

    /*
    |--------------------------------------------------------------------------
    | Package Manager
    |--------------------------------------------------------------------------
    | The package manager you use for your project.
    | Supported: "npm", "pnpm", "yarn", "bun"
    |
    */
    'package_manager' => env('PRISMA_PACKAGE_MANAGER', 'pnpm'),

    /*
    |--------------------------------------------------------------------------
    | Node / Package Manager Executor Binary
    |--------------------------------------------------------------------------
    | Path to the executor binary (npx, pnpm, yarn, bunx).
    | Usually auto-detected, but you can hardcode it here.
    |
    */
    'executor_path' => env('PRISMA_EXECUTOR_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Node Modules Path
    |--------------------------------------------------------------------------
    | Where npm packages are installed. Defaults to your Laravel root.
    |
    */
    'node_modules_path' => base_path('node_modules'),

    /*
    |--------------------------------------------------------------------------
    | Database URL Env Key
    |--------------------------------------------------------------------------
    | The .env key that Prisma reads for the database connection string.
    | This package auto-builds this value from your Laravel DB config.
    | You generally don't need to change this.
    |
    */
    'database_url_key' => 'DATABASE_URL',

    /*
    |--------------------------------------------------------------------------
    | Process Timeout
    |--------------------------------------------------------------------------
    | Maximum seconds to wait for Prisma CLI commands to complete.
    | Set to null for no timeout.
    |
    */
    'timeout' => 300,

];
