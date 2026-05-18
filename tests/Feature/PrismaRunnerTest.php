<?php

namespace Zowesoft\LaravelPrisma\Tests\Feature;

use Zowesoft\LaravelPrisma\Services\PrismaRunner;
use Zowesoft\LaravelPrisma\Tests\TestCase;
use ReflectionMethod;

class PrismaRunnerTest extends TestCase
{
    /** @test */
    public function it_builds_correct_install_command_for_various_package_managers()
    {
        $managers = [
            'npm'  => ['npm', 'install', 'prisma@latest', '--save-dev'],
            'pnpm' => ['pnpm', 'add', '-D', 'prisma@latest'],
            'yarn' => ['yarn', 'add', '--dev', 'prisma@latest'],
            'bun'  => ['bun', 'add', '--dev', 'prisma@latest'],
        ];

        foreach ($managers as $manager => $expected) {
            $runner = new PrismaRunner(
                packageManager: $manager,
                executorPath:   '',
                timeout:        300
            );

            $method = new ReflectionMethod(PrismaRunner::class, 'getInstallCommand');
            $method->setAccessible(true);
            $actual = $method->invoke($runner);

            $this->assertEquals($expected, $actual);
        }
    }

    /** @test */
    public function it_builds_correct_executor_command_for_various_package_managers()
    {
        $executors = [
            'npm'  => ['npx'],
            'pnpm' => ['pnpm', 'dlx'],
            'yarn' => ['yarn', 'dlx'],
            'bun'  => ['bunx'],
        ];

        foreach ($executors as $manager => $expected) {
            $runner = new PrismaRunner(
                packageManager: $manager,
                executorPath:   '',
                timeout:        300
            );

            $method = new ReflectionMethod(PrismaRunner::class, 'getExecutorCommand');
            $method->setAccessible(true);
            $actual = $method->invoke($runner);

            $this->assertEquals($expected, $actual);
        }
    }

    /** @test */
    public function it_overrides_executor_command_when_custom_path_is_provided()
    {
        $runner = new PrismaRunner(
            packageManager: 'npm',
            executorPath:   '/usr/bin/custom-npx',
            timeout:        300
        );

        $method = new ReflectionMethod(PrismaRunner::class, 'getExecutorCommand');
        $method->setAccessible(true);
        $actual = $method->invoke($runner);

        $this->assertEquals(['/usr/bin/custom-npx'], $actual);
    }

    /** @test */
    public function it_correctly_builds_environment_paths()
    {
        $runner = new PrismaRunner('npm', '', 300);

        $method = new ReflectionMethod(PrismaRunner::class, 'buildEnv');
        $method->setAccessible(true);
        $env = $method->invoke($runner);

        $this->assertArrayHasKey('PATH', $env);
        $this->assertStringContainsString('/usr/local/bin', $env['PATH']);
    }
}
