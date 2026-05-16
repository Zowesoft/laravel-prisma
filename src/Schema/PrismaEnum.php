<?php

namespace Zowesoft\LaravelPrisma\Schema;

class PrismaEnum
{
    /** @param string[] $values */
    public function __construct(
        public readonly string $name,
        public readonly array  $values,
    ) {}
}
