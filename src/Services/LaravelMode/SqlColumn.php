<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

class SqlColumn
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $rawType,      // e.g. "VARCHAR(255)", "INT", "DECIMAL(10,2)"
        public readonly string  $baseType,     // e.g. "VARCHAR", "INT", "DECIMAL"
        public readonly ?int    $length,       // VARCHAR(255) → 255
        public readonly ?int    $precision,    // DECIMAL(10,2) → 10
        public readonly ?int    $scale,        // DECIMAL(10,2) → 2
        public readonly bool    $nullable,
        public readonly bool    $isPrimary,
        public readonly bool    $isAutoIncrement,
        public readonly bool    $isUnsigned,
        public readonly bool    $isUnique,
        public readonly mixed   $default,      // raw default value string or null
        public readonly bool    $hasDefault,
        public readonly ?string $onUpdate,     // e.g. "CURRENT_TIMESTAMP"
        public readonly ?string $enumValues,   // raw enum values string e.g. "'a','b','c'"
        public readonly ?string $characterSet,
        public readonly ?string $collation,
    ) {}
}
