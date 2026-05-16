<?php

namespace Zowesoft\LaravelPrisma\Schema;

class PrismaField
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool   $isRequired,
        public readonly bool   $isUnique,
        public readonly bool   $isId,
        public readonly bool   $isAutoIncrement,
        public readonly bool   $hasDefault,
        public readonly mixed  $default,
        public readonly bool   $isUpdatedAt,
        public readonly ?string $relation,
        public readonly bool   $isList,
        public readonly ?int   $length,       // e.g. @db.VarChar(255)
        public readonly ?int   $precision,    // e.g. Decimal(10, 2)
        public readonly ?int   $scale,
    ) {}
}
