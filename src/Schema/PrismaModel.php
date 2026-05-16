<?php

namespace Zowesoft\LaravelPrisma\Schema;

class PrismaModel
{
    /** @param PrismaField[] $fields */
    public function __construct(
        public readonly string $name,
        public readonly array  $fields,
        public readonly string $tableName,   // resolved from @@map or snake_case plural
        public readonly array  $uniqueComposites, // from @@unique([a, b])
        public readonly array  $indexComposites,  // from @@index([a, b])
    ) {}
}
