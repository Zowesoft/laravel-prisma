<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

class SqlStatement
{
    public const TYPE_CREATE_TABLE = 'CREATE_TABLE';
    public const TYPE_ALTER_TABLE  = 'ALTER_TABLE';
    public const TYPE_RENAME_TABLE = 'RENAME_TABLE';
    public const TYPE_CREATE_INDEX = 'CREATE_INDEX';
    public const TYPE_DROP_TABLE   = 'DROP_TABLE';
    public const TYPE_DROP_INDEX   = 'DROP_INDEX';
    public const TYPE_UNKNOWN      = 'UNKNOWN';

    /**
     * @param string           $type        One of the TYPE_* constants above
     * @param string           $table       The table name this statement affects
     * @param SqlColumn[]      $columns     Columns defined or modified (CREATE/ALTER)
     * @param SqlIndex[]       $indexes     Indexes defined inline (CREATE TABLE) or standalone
     * @param SqlForeignKey[]  $foreignKeys Foreign key constraints
     * @param SqlAlteration[]  $alterations ALTER TABLE operations (add/drop/modify)
     * @param string           $raw         The original raw SQL (used for fallback)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $table,
        public readonly array  $columns      = [],   // SqlColumn[]
        public readonly array  $indexes      = [],   // SqlIndex[]
        public readonly array  $foreignKeys  = [],   // SqlForeignKey[]
        public readonly array  $alterations  = [],   // SqlAlteration[]
        public readonly string $raw          = '',
    ) {}
}
