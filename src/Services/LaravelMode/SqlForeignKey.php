<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

class SqlForeignKey
{
    public function __construct(
        public readonly string  $name,            // constraint name
        public readonly string  $localColumn,
        public readonly string  $referencedTable,
        public readonly string  $referencedColumn,
        public readonly ?string $onDelete,        // CASCADE | SET NULL | RESTRICT | NO ACTION
        public readonly ?string $onUpdate,
    ) {}
}
