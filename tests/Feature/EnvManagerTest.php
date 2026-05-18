<?php

use Zowesoft\LaravelPrisma\Services\EnvManager;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->envPath = base_path('.env');
    if (File::exists($this->envPath)) {
        File::delete($this->envPath);
    }
    File::put($this->envPath, "APP_NAME=Laravel\n");
});

afterEach(function () {
    if (File::exists($this->envPath)) {
        File::delete($this->envPath);
    }
});

it('can set an env value', function () {
    $env = new EnvManager();
    $env->set('PRISMA_TEST', 'foo');

    $content = File::get($this->envPath);
    expect($content)->toContain('PRISMA_TEST=foo');
    expect($content)->toContain('# Added by Laravel Prisma');
});

it('can update an existing env value', function () {
    $env = new EnvManager();
    $env->set('PRISMA_TEST', 'foo');
    $env->set('PRISMA_TEST', 'bar');

    $content = File::get($this->envPath);
    expect($content)->toContain('PRISMA_TEST=bar');
    expect($content)->not->toContain('PRISMA_TEST=foo');
});

it('can remove an env value', function () {
    $env = new EnvManager();
    $env->set('PRISMA_TEST', 'foo');
    
    expect(File::get($this->envPath))->toContain('PRISMA_TEST=foo');
    
    $env->remove('PRISMA_TEST');
    
    $content = File::get($this->envPath);
    expect($content)->not->toContain('PRISMA_TEST=foo');
    expect($content)->not->toContain('# Added by Laravel Prisma');
});

it('can remove an env value that was not added by the package', function () {
    File::append($this->envPath, "MANUAL_KEY=value\n");
    
    $env = new EnvManager();
    $env->remove('MANUAL_KEY');
    
    $content = File::get($this->envPath);
    expect($content)->not->toContain('MANUAL_KEY=value');
});
