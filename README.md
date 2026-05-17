# 🔷 Laravel Prisma

A Laravel package that wraps the real [Prisma](https://www.prisma.io/) CLI — giving you Prisma's powerful schema-based migrations with a familiar Laravel Artisan interface.

You write `schema.prisma`. Laravel Prisma reads your Laravel `.env`, builds the correct `DATABASE_URL`, and shells out to `prisma migrate dev` under the hood — with live terminal output.

**Prisma is not abstracted away.** It is installed as a real npm dependency and does all the actual migration work. This package is purely the Laravel integration layer.

---

## How it works

```
Your .env (DB_HOST, DB_USER...) ─┐
                                  ├─→ DATABASE_URL built automatically
schema.prisma ────────────────────┘
        │
        ▼
php artisan prisma:generate
        │
        ▼
npx prisma migrate dev   ← real Prisma CLI runs here, output streamed live
        │
        ▼
SQL migration files generated in prisma/migrations/
Database updated ✅
```

---

## Requirements

- PHP 8.1+
- Laravel 10.x / 11.x / 12.x / 13.x
- **Node.js 18+** (Prisma is a Node.js tool)
- **npm** (comes with Node.js)

---

## Installation

### 1. Install the Laravel package

```bash
composer require Zowesoft/laravel-prisma
```

### 2. Publish the config (optional)

```bash
php artisan vendor:publish --tag=laravel-prisma-config
```

### 3. Install Prisma and scaffold schema.prisma

```bash
php artisan prisma:install
```

This will:
- Check that your runtime (Node.js/Bun) and package manager are available
- Run the installation command (e.g., `npm install prisma@latest --save-dev`) with live progress output
- Create `prisma/schema.prisma` pre-configured with your Laravel DB provider
- Write `DATABASE_URL` to your `.env` built from your existing `DB_*` variables

---

## Configuration

You can customize the package behavior in `config/laravel-prisma.php`. Key options include:

- `package_manager`: Choose your preferred package manager (`npm`, `pnpm`, `yarn`, or `bun`). Defaults to `npm`.
- `executor_path`: Manually specify the path to your executor (e.g., `/usr/local/bin/pnpm`).
- `schema_path`: Where your `schema.prisma` file is located.
- `timeout`: Maximum seconds to wait for Prisma commands.

---

## Usage

### Define your schema

Edit `prisma/schema.prisma`:

```prisma
// datasource and generator are managed automatically by the package

datasource db {
  provider = "mysql"
  url      = env("DATABASE_URL")
}

generator client {
  provider = "prisma-client"
}

model User {
  id        Int      @id @default(autoincrement())
  name      String
  email     String   @unique
  posts     Post[]
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt
}

model Post {
  id        Int      @id @default(autoincrement())
  title     String
  body      String
  published Boolean  @default(false)
  authorId  Int
  author    User     @relation(fields: [authorId], references: [id])
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@index([authorId])
}
```

### Generate and apply migrations

```bash
php artisan prisma:generate
```

This will:
1. Read your Laravel `.env` DB credentials
2. Build and inject `DATABASE_URL` into `.env`
3. Update the `datasource` block in `schema.prisma`
4. Run `npx prisma migrate dev` — live output streamed to your terminal
5. Prisma generates SQL files in `prisma/migrations/` and applies them

**With a custom migration name:**

```bash
php artisan prisma:generate --name=add_posts_table
```

---

## Commands

| Command | Description |
|---------|-------------|
| `php artisan prisma:install` | Install Prisma via your package manager + scaffold schema.prisma |
| `php artisan prisma:init` | (Re)scaffold schema.prisma from your Laravel DB config |
| `php artisan prisma:generate` | Sync DB config → run `prisma migrate dev` |
| `php artisan prisma:generate --name=foo` | Same, with a named migration |
| `php artisan prisma:status` | Run `prisma migrate status` |
| `php artisan prisma:reset` | Run `prisma migrate reset` (drops all data!) |
| `php artisan prisma:validate` | Validate schema.prisma for syntax errors |
| `php artisan prisma:format` | Format schema.prisma with Prisma's formatter |

---

## How DATABASE_URL is built

The package reads your standard Laravel `.env` DB variables and builds the correct Prisma connection string automatically:

| Laravel `.env` | Prisma `DATABASE_URL` |
|---|---|
| `DB_CONNECTION=mysql` | `mysql://user:pass@host:3306/db` |
| `DB_CONNECTION=pgsql` | `postgresql://user:pass@host:5432/db?schema=public` |
| `DB_CONNECTION=sqlite` | `file:./database/database.sqlite` |
| `DB_CONNECTION=sqlsrv` | `sqlserver://host:1433;database=db;user=u;password=p` |

The built `DATABASE_URL` is written to your `.env` and passed to Prisma automatically — you never need to set it manually.

---

## Config

```php
// config/laravel-prisma.php
return [
    'schema_path'      => base_path('prisma/schema.prisma'),
    'migrations_path'  => base_path('prisma/migrations'),
    'npx_path'         => env('PRISMA_NPX_PATH', 'npx'),
    'node_modules_path'=> base_path('node_modules'),
    'database_url_key' => 'DATABASE_URL',
    'timeout'          => 300,
];
```

**If npx is not in PATH on your server** (common on shared hosting), find it and set:

```env
PRISMA_NPX_PATH=/usr/local/bin/npx
```

---

## .gitignore recommendations

Add to your project `.gitignore`:

```gitignore
# Prisma
node_modules/
prisma/.env
```

**Do commit** `prisma/migrations/` — Prisma uses this folder to track migration history.

---

## Publishing to Packagist

```bash
# 1. Push to GitHub
git init && git add . && git commit -m "v1.0.0"
git remote add origin https://github.com/Zowesoft/laravel-prisma.git
git push -u origin main
git tag v1.0.0 && git push origin v1.0.0

# 2. Go to packagist.org → Submit → paste your GitHub URL

# 3. Set up the GitHub webhook for auto-updates:
#    Settings → Webhooks → https://packagist.org/api/github?username=Zowesoft
```

---

## License

MIT
