<?php

namespace Zowesoft\LaravelPrisma\Schema;

class PrismaSchema
{
    /**
     * @param PrismaModel[] $models
     * @param PrismaEnum[]  $enums
     */
    public function __construct(
        public readonly string $datasourceProvider, // mysql | postgresql | sqlite | sqlserver
        public readonly array  $models,
        public readonly array  $enums,
    ) {}
}
