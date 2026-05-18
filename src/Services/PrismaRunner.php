<?php

namespace Zowesoft\LaravelPrisma\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PrismaRunner
{
    public function __construct(
        private string $packageManager,
        private string $executorPath,
        private int    $timeout,
    ) {}

    /**
     * Check whether Node.js or Bun is available on the system.
     */
    public function nodeAvailable(): bool
    {
        $binary = $this->packageManager === 'bun' ? 'bun' : 'node';
        $process = new Process([$binary, '--version']);
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Check whether the package manager executor is available.
     */
    public function executorAvailable(): bool
    {
        $command = $this->getExecutorCommand();
        $process = new Process([...$command, '--version']);
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
     * Install Prisma via the chosen package manager.
     */
    public function install(callable $output): bool
    {
        return $this->run(
            command: $this->getInstallCommand(),
            cwd:     base_path(),
            output:  $output,
        );
    }

    /**
     * Run `prisma migrate dev` with live output.
     */
    public function migrateDev(callable $output, ?string $name = null, bool $createOnly = false, bool $skipSeed = false): bool
    {
        $command = [
            ...$this->getExecutorCommand(),
            'prisma',
            'migrate',
            'dev',
            '--schema=' . config('laravel-prisma.schema_path'),
        ];

        if ($name) {
            $command[] = '--name';
            $command[] = $name;
        }

        if ($createOnly) {
            $command[] = '--create-only';
        }

        if ($skipSeed) {
            $command[] = '--skip-seed';
        }

        return $this->run(
            command: $command,
            cwd:     base_path(),
            output:  $output,
        );
    }

    /**
     * Run `prisma migrate status`.
     */
    public function migrateStatus(callable $output): bool
    {
        return $this->run(
            command: [
                ...$this->getExecutorCommand(),
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
     * Run `prisma migrate reset`.
     */
    public function migrateReset(callable $output, bool $force = false): bool
    {
        $command = [
            ...$this->getExecutorCommand(),
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
     * Run `prisma format`.
     */
    public function format(callable $output): bool
    {
        return $this->run(
            command: [
                ...$this->getExecutorCommand(),
                'prisma',
                'format',
                '--schema=' . config('laravel-prisma.schema_path'),
            ],
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma validate`.
     */
    public function validate(callable $output): bool
    {
        return $this->run(
            command: [
                ...$this->getExecutorCommand(),
                'prisma',
                'validate',
                '--schema=' . config('laravel-prisma.schema_path'),
            ],
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma db pull`.
     */
    public function dbPull(callable $output, bool $force = false): bool
    {
        $command = [
            ...$this->getExecutorCommand(),
            'prisma',
            'db',
            'pull',
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
     * Run `prisma db push`.
     */
    public function dbPush(callable $output, bool $forceReset = false, bool $acceptDataLoss = false): bool
    {
        $command = [
            ...$this->getExecutorCommand(),
            'prisma',
            'db',
            'push',
            '--schema=' . config('laravel-prisma.schema_path'),
        ];

        if ($forceReset) {
            $command[] = '--force-reset';
        }

        if ($acceptDataLoss) {
            $command[] = '--accept-data-loss';
        }

        return $this->run(
            command: $command,
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma migrate resolve`.
     */
    public function migrateResolve(callable $output, string $migrationName, string $status): bool
    {
        $command = [
            ...$this->getExecutorCommand(),
            'prisma',
            'migrate',
            'resolve',
            '--schema=' . config('laravel-prisma.schema_path'),
        ];

        if ($status === 'applied') {
            $command[] = '--applied';
        } else {
            $command[] = '--rolled-back';
        }

        $command[] = $migrationName;

        return $this->run(
            command: $command,
            cwd:    base_path(),
            output: $output,
        );
    }

    /**
     * Run `prisma migrate diff` and return the SQL script.
     */
    public function migrateDiffFromEmpty(string $schemaPath): string
    {
        $process = new Process([
            ...$this->getExecutorCommand(),
            'prisma',
            'migrate',
            'diff',
            '--from-empty',
            '--to-schema',
            $schemaPath,
            '--script',
        ], base_path(), $this->buildEnv());

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Prisma migrate diff failed: " . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * Run `prisma migrate diff` comparing the datasource in prisma.config.ts against the schema.
     * Uses --from-config-datasource (Prisma v7+) which reads the live database from the config file
     * rather than a raw URL. Used by Laravel Mode to detect what has changed without touching the DB.
     */
    public function migrateDiffFromConfig(string $schemaPath): string
    {
        $configPath = config('laravel-prisma.config_path');

        $command = [
            ...$this->getExecutorCommand(),
            'prisma',
            'migrate',
            'diff',
            '--from-config-datasource',
            '--to-schema',
            $schemaPath,
            '--script',
        ];

        // Pass --config if a custom prisma.config.ts path is configured
        if ($configPath && file_exists($configPath)) {
            $command[] = '--config';
            $command[] = $configPath;
        }

        $process = new Process($command, base_path(), $this->buildEnv());

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Prisma migrate diff failed: " . $process->getErrorOutput());
        }

        return $process->getOutput();
    }


    /**
     * Get the installation command for the package manager.
     */
    private function getInstallCommand(): array
    {
        return match ($this->packageManager) {
            'pnpm' => ['pnpm', 'add', '-D', 'prisma@latest'],
            'yarn' => ['yarn', 'add', '--dev', 'prisma@latest'],
            'bun'  => ['bun', 'add', '--dev', 'prisma@latest'],
            default => ['npm', 'install', 'prisma@latest', '--save-dev'],
        };
    }

    /**
     * Get the base executor command (npx, pnpm dlx, etc.)
     */
    private function getExecutorCommand(): array
    {
        if ($this->executorPath) {
            return [$this->executorPath];
        }

        return match ($this->packageManager) {
            'pnpm' => ['pnpm', 'dlx'],
            'yarn' => ['yarn', 'dlx'],
            'bun'  => ['bunx'],
            default => ['npx'],
        };
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
