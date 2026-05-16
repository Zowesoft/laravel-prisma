<?php

namespace Zowesoft\LaravelPrisma\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PrismaRunner
{
    public function __construct(
        private string $npxPath,
        private int    $timeout,
    ) {}

    /**
     * Check whether Node.js is available on the system.
     */
    public function nodeAvailable(): bool
    {
        $process = new Process(['node', '--version']);
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Check whether npx is available.
     */
    public function npxAvailable(): bool
    {
        $process = new Process([$this->npxPath, '--version']);
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Check whether prisma is installed in node_modules.
     */
    public function prismaInstalled(): bool
    {
        return file_exists(
            config('laravel-prisma.node_modules_path') . '/prisma/package.json'
        );
    }

    /**
     * Install Prisma via npm with live terminal output.
     * Streams every line of npm output back through the $output callback.
     *
     * @param callable $output  fn(string $type, string $line) — type is 'out' or 'err'
     */
    public function install(callable $output): bool
    {
        return $this->run(
            command: ['npm', 'install', 'prisma', '--save-dev'],
            cwd:     base_path(),
            output:  $output,
        );
    }

    /**
     * Run `prisma migrate dev` with live output.
     * This is the primary command — generates SQL files and applies them.
     *
     * @param callable $output  fn(string $type, string $line)
     * @param string|null $name Optional migration name (--name flag)
     */
    public function migrateDev(callable $output, ?string $name = null): bool
    {
        $command = [
            $this->npxPath,
            'prisma',
            'migrate',
            'dev',
            '--schema=' . config('laravel-prisma.schema_path'),
        ];

        if ($name) {
            $command[] = '--name';
            $command[] = $name;
        }

        return $this->run(
            command: $command,
            cwd:     base_path(),
            output:  $output,
        );
    }

    /**
     * Run `prisma migrate status` — shows pending migrations.
     */
    public function migrateStatus(callable $output): bool
    {
        return $this->run(
            command: [
                $this->npxPath,
                'prisma',
                'migrate',
                'status',
                '--schema=' . config('laravel-prisma.schema_path'),
            ],
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma migrate reset` — resets the database.
     */
    public function migrateReset(callable $output, bool $force = false): bool
    {
        $command = [
            $this->npxPath,
            'prisma',
            'migrate',
            'reset',
            '--schema=' . config('laravel-prisma.schema_path'),
        ];

        if ($force) {
            $command[] = '--force';
        }

        return $this->run(
            command: $command,
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma format` — formats the schema file.
     */
    public function format(callable $output): bool
    {
        return $this->run(
            command: [
                $this->npxPath,
                'prisma',
                'format',
                '--schema=' . config('laravel-prisma.schema_path'),
            ],
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma validate` — validates the schema.
     */
    public function validate(callable $output): bool
    {
        return $this->run(
            command: [
                $this->npxPath,
                'prisma',
                'validate',
                '--schema=' . config('laravel-prisma.schema_path'),
            ],
            cwd:    base_path(),
            output: $output,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Core process runner — streams output line by line as it arrives.
     */
    private function run(array $command, string $cwd, callable $output): bool
    {
        $process = new Process(
            command: $command,
            cwd:     $cwd,
            env:     $this->buildEnv(),
            timeout: $this->timeout,
        );

        // TTY mode lets Prisma output its coloured, interactive progress bars.
        // Falls back gracefully if TTY is not available (e.g. CI environments).
        if (Process::isTtySupported()) {
            $process->setTty(true);
        } else {
            $process->setPty(Process::isPtySupported());
        }

        $process->start();

        // Stream output in real time
        foreach ($process as $type => $data) {
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $outputType = ($type === Process::ERR) ? 'err' : 'out';
                $output($outputType, $line);
            }
        }

        $process->wait();

        return $process->isSuccessful();
    }

    /**
     * Build the environment for the subprocess.
     * Passes through the current environment and ensures PATH is set correctly
     * so npm/npx/prisma executables can be found.
     */
    private function buildEnv(): array
    {
        return array_merge($_ENV, [
            'PATH' => implode(':', array_filter([
                '/usr/local/bin',
                '/usr/bin',
                '/bin',
                getenv('HOME') ? getenv('HOME') . '/.npm-global/bin' : null,
                getenv('PATH') ?: '',
            ])),
            // Disable Prisma telemetry
            'PRISMA_TELEMETRY_INFORMATION' => '0',
            // Forward the DATABASE_URL that was injected into .env
            'DATABASE_URL' => env(config('laravel-prisma.database_url_key', 'DATABASE_URL'), ''),
        ]);
    }
}
