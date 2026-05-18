# 🔷 Laravel Prisma

A powerful Laravel integration for [Prisma ORM](https://www.prisma.io/) that bridges the gap between Prisma's schema-driven workflows and native Laravel database architectures.

This package allows you to use Prisma's elegant `schema.prisma` file to model your database, while giving you the freedom to choose how those changes are migrated:
1. **Prisma Mode (Default):** Use Prisma's native SQL-based migrations (`prisma/migrations`).
2. **Laravel Mode (Beta):** Generate standard Laravel PHP migration classes (`database/migrations`) directly from your Prisma schema changes!

---

## ⚠️ Laravel Mode: Under Development (Beta)

> [!WARNING]
> **Laravel Mode** is currently in **Beta**. While it is highly capable at translating standard column types, indexes, primary keys, and foreign keys, highly complex database features (e.g. specialized indexes, custom SQLite pragmas, and intricate raw data updates) will fall back to using `DB::unprepared()` raw SQL.
>
> Always review generated PHP migration files before running them in production.

---

## How it Works

```
Your .env (DB_HOST, DB_USER...) ─┐
                                  ├─→ DATABASE_URL built automatically
schema.prisma ────────────────────┘
        │
        ▼
php artisan prisma:generate
        │
        ├───► [Prisma Mode] ──► Runs npx prisma migrate dev (Live output)
        │                       Creates SQL files in prisma/migrations/
        │
        └───► [Laravel Mode] ─► Parses Prisma SQL diffs into Laravel PHP Builders
                                Creates PHP files in database/migrations/
```

---

## Requirements

- PHP 8.1+
- Laravel 10.x / 11.x / 12.x / 13.x
- **Node.js 18+** (Prisma is a Node.js CLI tool)
- **npm** (or `pnpm`, `yarn`, `bun`)

---

## Installation

### 1. Install the Laravel Package
```bash
composer require Zowesoft/laravel-prisma
```

### 2. Publish the Config (Optional)
```bash
php artisan vendor:publish --tag=laravel-prisma-config
```

### 3. Install Prisma & Scaffold Files
```bash
php artisan prisma:install
```
This command automatically:
- Checks your system's package manager (`npm`, `pnpm`, `yarn`, or `bun`).
- Installs the Prisma CLI as a dev dependency.
- Scaffolds a `prisma/schema.prisma` pre-configured for your Laravel database.
- Writes a synchronized `DATABASE_URL` to your `.env`.

---

## Modes & Configuration

You can customize the package behavior in `config/laravel-prisma.php`.

### Switch Migration Modes
Change the `mode` parameter in your published configuration file or set it in your `.env`:

```env
PRISMA_MODE=laravel  # Options: 'prisma' (default) or 'laravel'
```

### Reference Config
```php
// config/laravel-prisma.php
return [
    'mode'             => env('PRISMA_MODE', 'prisma'),
    'schema_path'      => base_path('prisma/schema.prisma'),
    'config_path'      => base_path('prisma.config.ts'),
    'migrations_path'  => base_path('prisma/migrations'),
    'package_manager'  => env('PRISMA_PACKAGE_MANAGER', 'npm'),
    'executor_path'    => env('PRISMA_EXECUTOR_PATH', null),
    'node_modules_path'=> base_path('node_modules'),
    'database_url_key' => 'DATABASE_URL',
    'timeout'          => 300,
];
```

---

## Migration Workflows

### 1. Prisma Mode (Default)
In Prisma Mode, Prisma is fully responsible for database migrations. It generates standard SQL files.

1. Make edits to `prisma/schema.prisma`.
2. Generate and apply your migration:
   ```bash
   php artisan prisma:generate --name=create_users_table
   ```
3. SQL migration files will be generated in `prisma/migrations/`, and your live database will be updated automatically.

---

### 2. Laravel Mode (Beta)
In Laravel Mode, Prisma handles the *schema diffing*, but we parse that raw SQL and compile it into a **native Laravel PHP migration**. 

Since Prisma is not managing its own migration history files here, it relies on your **live database** to compute what changes still need to be generated.

#### ⚠️ Critical Laravel Mode Workflow Rule:
You **must** run your Laravel migrations immediately after generating them. Otherwise, Prisma will still think your database lacks those changes, and your next generation will produce a duplicate, bloated migration!

**The Golden Loop:**
1. Edit `prisma/schema.prisma`.
2. Generate the Laravel migration:
   ```bash
   php artisan prisma:generate --name=create_posts_table
   ```
3. **Immediately run Laravel's migrator:**
   ```bash
   php artisan migrate
   ```
4. Repeat for your next database change!

*Note: The generator checks for duplicate migration names in `database/migrations` and will safely abort to avoid overwriting existing work.*

---

## Command Reference

| Command | Description |
|---------|-------------|
| `php artisan prisma:install` | Installs Prisma dev dependency and scaffolds project configuration |
| `php artisan prisma:init` | Regenerates `schema.prisma` using your Laravel configuration |
| `php artisan prisma:generate` | Generates a migration (Prisma native SQL or Laravel PHP based on `mode`) |
| `php artisan prisma:generate --name=foo` | Generates a migration with a specific, friendly name |
| `php artisan prisma:generate --create-only` | Creates a migration without applying it (Prisma Mode only) |
| `php artisan prisma:status` | Shows migration history status (Prisma Mode only) |
| `php artisan prisma:reset` | Drops all database tables and re-applies migrations (Data-destructive!) |
| `php artisan prisma:validate` | Validates `schema.prisma` syntax and schema definitions |
| `php artisan prisma:format` | Automatically formats the `schema.prisma` file |
| `php artisan prisma:pull` | Pulls database tables and schema and writes them to `schema.prisma` |
| `php artisan prisma:prettify` | Converts pulled plural snake_case models to clean singular PascalCase |
| `php artisan prisma:baseline` | Baselines an existing database to prevent drift errors |
| `php artisan prisma:baseline --pull` | Pulls schema and baselines in a single command |
| `php artisan prisma:push` | Directly updates your database schema bypassing migrations (Data-safe for redefines) |

---

## How DATABASE_URL is Synchronized

The package reads your Laravel `config/database.php` default connection and automatically constructs a valid Prisma connection string:

| Connection | Prisma `DATABASE_URL` Format |
|---|---|
| `mysql`, `mariadb` | `mysql://user:pass@host:3306/db?charset=utf8mb4` |
| `pgsql` | `postgresql://user:pass@host:5432/db?schema=public` |
| `sqlite` | `file:/path/to/database.sqlite` |
| `sqlsrv` | `sqlserver://host:1433;database=db;user=u;password=p` |

*Note: Stale `DATABASE_URL` values are automatically ignored and rebuilt during connection changes (e.g. switching between SQLite and MySQL).*

---

## Contributing Guide

We welcome contributions to help make `laravel-prisma` more robust, especially in refining the SQL translation parser for Laravel Mode.

### Development Setup

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/Zowesoft/laravel-prisma.git
   cd laravel-prisma
   ```

2. **Install Dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Running the Test Suite:**
   Ensure you have configured a local database (SQLite is recommended for quick test runs).
   ```bash
   vendor/bin/phpunit
   ```

### Code Standards & Design Goals

- **Maintain PHP Standards:** Adhere to PSR-12 coding style. Run `composer run lint` (or your preferred linter) before pushing code.
- **Fail-safe SQL Parsing:** If adding support for a new database column type or constraint translation in `SqlToSchemaBuilder.php`, make sure it degrades gracefully. Unrecognized syntax must fall back to the safe `DB::unprepared()` wrapper.
- **Maintain Comments and Docstrings:** Make sure your new functions are documented with concise PHP DocBlocks.

### Submitting Pull Requests

1. Fork the repository and create a new feature branch (`git checkout -b feature/amazing-feature`).
2. Implement your changes along with corresponding tests.
3. Make sure all PHPUnit tests pass.
4. Commit your changes with semantic, descriptive messages.
5. Submit a pull request to the `main` branch!

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
