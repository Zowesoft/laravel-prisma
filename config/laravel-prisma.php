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
    | Prisma Migrations Path
    |--------------------------------------------------------------------------
    | Where Prisma stores its generated SQL migration files.
    | Prisma manages this folder — do not manually edit files inside it.
    |
    */
    'migrations_path' => base_path('prisma/migrations'),

    /*
    |--------------------------------------------------------------------------
    | Node / npx Binary
    |--------------------------------------------------------------------------
    | Path to the npx executable. Usually auto-detected, but you can
    | hardcode it here if your server uses a non-standard install path.
    | e.g. '/usr/local/bin/npx' or '/home/user/.nvm/versions/node/v20/bin/npx'
    |
    */
    'npx_path' => env('PRISMA_NPX_PATH', 'npx'),

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
