<?php

namespace Zowesoft\LaravelPrisma\Services;

class DatabaseUrlBuilder
{
    /**
     * Build a Prisma-compatible DATABASE_URL from Laravel's DB config.
     * Reads the default connection defined in config/database.php.
     */
    public function build(): string
    {
        $connection = config('database.default');
        $db         = config("database.connections.{$connection}");

        if (empty($db)) {
            throw new \RuntimeException(
                "No database connection found for [{$connection}]. " .
                "Check your config/database.php and .env DB_CONNECTION."
            );
        }

        // If an explicit URL is provided in the config, use it.
        // However, we ignore it if it matches the env key we manage (usually DATABASE_URL).
        // This prevents a stale DATABASE_URL from a previous run (e.g. SQLite)
        // from overriding the fresh connection parameters when the user switches to MySQL.
        if (! empty($db['url'])) {
            $managedUrl = env(config('laravel-prisma.database_url_key', 'DATABASE_URL'));
            
            if ($db['url'] !== $managedUrl) {
                $url = $db['url'];
                // For SQLite, ensure it has the file: prefix if it's just a path
                if ($connection === 'sqlite' && ! str_starts_with($url, 'file:')) {
                    return "file:{$url}";
                }
                return $url;
            }
        }

        return match ($connection) {
            'mysql', 'mariadb' => $this->buildMysql($db),
            'pgsql'            => $this->buildPostgres($db),
            'sqlite'           => $this->buildSqlite($db),
            'sqlsrv'           => $this->buildSqlServer($db),
            default            => throw new \RuntimeException(
                "Unsupported database connection type: [{$connection}]. " .
                    "Prisma supports: mysql, pgsql, sqlite, sqlsrv."
            ),
        };
    }

    /**
     * Return the Prisma provider name for the current connection.
     */
    public function provider(): string
    {
        return match (config('database.default')) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql'            => 'postgresql',
            'sqlite'           => 'sqlite',
            'sqlsrv'           => 'sqlserver',
            default            => 'mysql',
        };
    }

    // -------------------------------------------------------------------------

    private function buildMysql(array $db): string
    {
        $host     = $db['host']     ?? '127.0.0.1';
        $port     = $db['port']     ?? 3306;
        $database = $db['database'] ?? '';
        $username = $db['username'] ?? '';
        $password = $db['password'] ?? '';

        $password = $this->encodePassword($password);

        $url = "mysql://{$username}:{$password}@{$host}:{$port}/{$database}";

        // Append common options
        $params = [];

        if (! empty($db['charset'])) {
            $params[] = 'charset=' . $db['charset'];
        }

        if (! empty($db['options'][\PDO::MYSQL_ATTR_SSL_CA] ?? null)) {
            $params[] = 'sslaccept=strict';
        }

        return $params ? $url . '?' . implode('&', $params) : $url;
    }

    private function buildPostgres(array $db): string
    {
        $host     = $db['host']     ?? '127.0.0.1';
        $port     = $db['port']     ?? 5432;
        $database = $db['database'] ?? '';
        $username = $db['username'] ?? '';
        $password = $db['password'] ?? '';
        $schema   = $db['schema']   ?? 'public';

        $password = $this->encodePassword($password);

        return "postgresql://{$username}:{$password}@{$host}:{$port}/{$database}?schema={$schema}";
    }

    private function buildSqlite(array $db): string
    {
        $database = $db['database'] ?? '';

        // Detect absolute paths (Unix / or Windows C:\)
        $isAbsolute = str_starts_with($database, '/') ||
            str_starts_with($database, '\\') ||
            (strlen($database) > 1 && $database[1] === ':');

        if (! $isAbsolute && $database !== ':memory:') {
            $database = base_path($database);
        }

        return "file:{$database}";
    }

    private function buildSqlServer(array $db): string
    {
        $host     = $db['host']     ?? 'localhost';
        $port     = $db['port']     ?? 1433;
        $database = $db['database'] ?? '';
        $username = $db['username'] ?? '';
        $password = $db['password'] ?? '';

        return "sqlserver://{$host}:{$port};database={$database};user={$username};password={$password}";
    }

    private function encodePassword(string $password): string
    {
        return rawurlencode($password);
    }
}
