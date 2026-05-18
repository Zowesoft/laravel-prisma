<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

class SqlIndex
{
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly array  $columns,   // string[]
        public readonly bool   $isUnique,
        public readonly ?string $type,     // BTREE | HASH | null
    ) {}
}
