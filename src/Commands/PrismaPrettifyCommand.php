<?php

namespace Zowesoft\LaravelPrisma\Commands;

use Illuminate\Console\Command;
use Zowesoft\LaravelPrisma\Services\SchemaManager;

class PrismaPrettifyCommand extends Command
{
    protected $signature   = 'prisma:prettify';
    protected $description = 'Rename snake_case plural models to PascalCase singular (best used after prisma:pull)';

    public function handle(SchemaManager $schema): int
    {
        $this->line('');
        $this->info('  Prettifying schema.prisma...');
        
        $schema->prettify();
        
        $this->info('  ✓ Models renamed to PascalCase singular.');
        $this->info('  ✓ @@map directives added to preserve table names.');
        $this->line('');
        
        return self::SUCCESS;
    }
}
