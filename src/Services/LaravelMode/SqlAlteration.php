<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

class SqlAlteration
{
    public const OP_ADD_COLUMN    = 'ADD_COLUMN';
    public const OP_DROP_COLUMN   = 'DROP_COLUMN';
    public const OP_MODIFY_COLUMN = 'MODIFY_COLUMN';
    public const OP_RENAME_COLUMN = 'RENAME_COLUMN';
    public const OP_ADD_INDEX     = 'ADD_INDEX';
    public const OP_DROP_INDEX    = 'DROP_INDEX';
    public const OP_ADD_FK        = 'ADD_FOREIGN_KEY';
    public const OP_DROP_FK       = 'DROP_FOREIGN_KEY';

    public function __construct(
        public readonly string         $operation,   // One of the OP_* constants
        public readonly ?SqlColumn     $column      = null,
        public readonly ?SqlIndex      $index       = null,
        public readonly ?SqlForeignKey $foreignKey  = null,
        public readonly ?string        $dropName    = null, // for DROP operations
    ) {}
}
