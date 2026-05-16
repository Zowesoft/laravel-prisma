<?php

namespace Zowesoft\LaravelPrisma\Services;

class EnvManager
{
    private string $envPath;

    public function __construct()
    {
        $this->envPath = base_path('.env');
    }

    /**
     * Write or update a key in the .env file.
     * If the key already exists it is updated in-place.
     * If it does not exist it is appended.
     */
    public function set(string $key, string $value): void
    {
        if (! file_exists($this->envPath)) {
            throw new \RuntimeException('.env file not found at: ' . $this->envPath);
        }

        $content = file_get_contents($this->envPath);

        // Value needs quoting if it contains spaces or special chars
        $formatted = $this->formatValue($value);
        $line      = "{$key}={$formatted}";

        if (preg_match("/^{$key}=.*/m", $content)) {
            // Update existing key
            $content = preg_replace("/^{$key}=.*/m", $line, $content);
        } else {
            // Append new key with a blank line separator
            $content = rtrim($content) . "\n\n# Added by Laravel Prisma\n{$line}\n";
        }

        file_put_contents($this->envPath, $content);
    }

    /**
     * Read a key from the .env file directly (not from cached config).
     */
    public function get(string $key): ?string
    {
        if (! file_exists($this->envPath)) {
            return null;
        }

        $content = file_get_contents($this->envPath);

        if (preg_match("/^{$key}=\"?([^\"\n]*)\"?/m", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Check whether a key exists in .env.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    // -------------------------------------------------------------------------

    private function formatValue(string $value): string
    {
        // Wrap in quotes if value contains spaces, #, $, or = signs
        if (preg_match('/[\s#$=]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}
