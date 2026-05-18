<?php

use Zowesoft\LaravelPrisma\Services\PrismaRunner;

it('builds correct install command for various package managers', function () {
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

        expect($actual)->toBe($expected);
    }
});

it('builds correct executor command for various package managers', function () {
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

        expect($actual)->toBe($expected);
    }
});

it('overrides executor command when custom path is provided', function () {
    $runner = new PrismaRunner(
        packageManager: 'npm',
        executorPath:   '/usr/bin/custom-npx',
        timeout:        300
    );

    $method = new ReflectionMethod(PrismaRunner::class, 'getExecutorCommand');
    $method->setAccessible(true);
    $actual = $method->invoke($runner);

    expect($actual)->toBe(['/usr/bin/custom-npx']);
});

it('correctly builds environment paths', function () {
    $runner = new PrismaRunner('npm', '', 300);

    $method = new ReflectionMethod(PrismaRunner::class, 'buildEnv');
    $method->setAccessible(true);
    $env = $method->invoke($runner);

    expect($env)->toHaveKey('PATH');
    expect($env['PATH'])->toContain('/usr/local/bin');
});
