<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

/**
 * SqlToSchemaBuilder
 *
 * Translates an array of SqlStatement objects (parsed from Prisma's raw SQL diff)
 * into Laravel Schema Builder PHP code strings.
 *
 * Each statement produces one string block of PHP code, e.g.:
 *
 *   Schema::create('users', function (Blueprint $table) {
 *       $table->id();
 *       $table->string('name');
 *       $table->timestamps();
 *   });
 *
 * Any statement or column that cannot be reliably mapped falls back to
 * DB::unprepared() with a // TODO comment so the developer is notified.
 */
class SqlToSchemaBuilder
{
    // ─────────────────────────────────────────────────────────────────────────
    //  Type map: SQL base type → Laravel Blueprint method name
    // ─────────────────────────────────────────────────────────────────────────

    private const TYPE_MAP = [
        // Integer types
        'TINYINT'   => 'tinyInteger',
        'SMALLINT'  => 'smallInteger',
        'MEDIUMINT' => 'mediumInteger',
        'INT'       => 'integer',
        'INTEGER'   => 'integer',
        'BIGINT'    => 'bigInteger',

        // String types
        'VARCHAR'   => 'string',
        'NVARCHAR'  => 'string',
        'CHAR'      => 'char',
        'NCHAR'     => 'char',

        // Text types
        'TINYTEXT'   => 'tinyText',
        'TEXT'       => 'text',
        'MEDIUMTEXT' => 'mediumText',
        'LONGTEXT'   => 'longText',
        'CLOB'       => 'text',

        // Numeric types
        'DECIMAL'  => 'decimal',
        'NUMERIC'  => 'decimal',
        'FLOAT'    => 'float',
        'DOUBLE'   => 'double',
        'REAL'     => 'float',

        // Boolean
        'BOOLEAN'  => 'boolean',
        'BOOL'     => 'boolean',
        'TINYINT(1)' => 'boolean',

        // Date / Time
        'DATE'      => 'date',
        'TIME'      => 'time',
        'YEAR'      => 'year',
        'DATETIME'  => 'dateTime',
        'TIMESTAMP' => 'timestamp',

        // Binary / Blob
        'BINARY'     => 'binary',
        'VARBINARY'  => 'binary',
        'TINYBLOB'   => 'binary',
        'BLOB'       => 'binary',
        'MEDIUMBLOB' => 'binary',
        'LONGBLOB'   => 'binary',

        // Special
        'JSON'  => 'json',
        'JSONB' => 'jsonb',
        'UUID'  => 'uuid',
        'ENUM'  => 'enum',
        'SET'   => 'set',

        // Geometric (Postgres)
        'GEOMETRY'  => 'geometry',
        'POINT'     => 'point',
        'LINESTRING' => 'lineString',
        'POLYGON'   => 'polygon',

        // SQLite
        'INTEGER PRIMARY KEY AUTOINCREMENT' => 'id',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    //  ON DELETE / ON UPDATE action map
    // ─────────────────────────────────────────────────────────────────────────

    private const FK_ACTION_MAP = [
        'CASCADE'     => 'cascade',
        'SET NULL'    => 'setNull',
        'SET DEFAULT' => 'setDefault',
        'RESTRICT'    => 'restrict',
        'NO ACTION'   => 'noActionOnDelete',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    //  Entry point
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build an array of PHP code blocks from parsed SQL statements.
     * Each element in the returned array is a self-contained block of code
     * ready to be embedded in the migration's up() method.
     *
     * @param  SqlStatement[] $statements
     * @return string[]
     */
    public function build(array $statements): array
    {
        $blocks = [];

        foreach ($statements as $stmt) {
            $block = match ($stmt->type) {
                SqlStatement::TYPE_CREATE_TABLE  => $this->buildCreateTable($stmt),
                SqlStatement::TYPE_ALTER_TABLE   => $this->buildAlterTable($stmt),
                SqlStatement::TYPE_RENAME_TABLE  => $this->buildRenameTable($stmt),
                SqlStatement::TYPE_CREATE_INDEX  => $this->buildCreateIndex($stmt),
                SqlStatement::TYPE_DROP_TABLE    => $this->buildDropTable($stmt),
                SqlStatement::TYPE_DROP_INDEX    => $this->buildDropIndex($stmt),
                default                          => $this->buildFallback($stmt->raw),
            };

            // Skip empty Schema::table() blocks (e.g. from unrecognised ALTER TABLE alterations)
            if (! $this->isEmptySchemaBlock($block)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CREATE TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function buildCreateTable(SqlStatement $stmt): string
    {
        $lines = [];

        // Detect if this is a timestamps-only pattern we can simplify
        $hasTimestamps     = $this->detectTimestamps($stmt->columns);
        $hasSoftDeletes    = $this->detectSoftDeletes($stmt->columns);
        $remainingColumns  = $this->filterTimestampColumns($stmt->columns);
        if ($hasSoftDeletes) {
            $remainingColumns = $this->filterSoftDeleteColumn($remainingColumns);
        }

        foreach ($remainingColumns as $col) {
            $lines[] = $this->buildColumn($col);
        }

        // Inline foreign keys
        foreach ($stmt->foreignKeys as $fk) {
            $lines[] = $this->buildForeignKey($fk);
        }

        // Inline indexes
        foreach ($stmt->indexes as $idx) {
            $lines[] = $this->buildIndex($idx);
        }

        if ($hasTimestamps) {
            $lines[] = '$table->timestamps();';
        }

        if ($hasSoftDeletes) {
            $lines[] = '$table->softDeletes();';
        }

        $body = implode("\n        ", array_filter($lines));

        return "Schema::create('{$stmt->table}', function (Blueprint \$table) {\n        {$body}\n    });";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ALTER TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function buildAlterTable(SqlStatement $stmt): string
    {
        $lines = [];

        foreach ($stmt->alterations as $alt) {
            $lines[] = match ($alt->operation) {
                SqlAlteration::OP_ADD_COLUMN    => $this->buildAddColumn($alt),
                SqlAlteration::OP_DROP_COLUMN   => "\$table->dropColumn('{$alt->dropName}');",
                SqlAlteration::OP_MODIFY_COLUMN => $this->buildModifyColumn($alt),
                SqlAlteration::OP_ADD_INDEX     => $this->buildIndex($alt->index),
                SqlAlteration::OP_DROP_INDEX    => "\$table->dropIndex('{$alt->dropName}');",
                SqlAlteration::OP_ADD_FK        => $this->buildForeignKey($alt->foreignKey),
                SqlAlteration::OP_DROP_FK       => "\$table->dropForeign('{$alt->dropName}');",
                default                         => '// TODO: unsupported alteration: ' . $alt->operation,
            };
        }

        $body = implode("\n        ", array_filter($lines));

        return "Schema::table('{$stmt->table}', function (Blueprint \$table) {\n        {$body}\n    });";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CREATE INDEX (standalone)
    // ─────────────────────────────────────────────────────────────────────────

    private function buildCreateIndex(SqlStatement $stmt): string
    {
        if (empty($stmt->indexes)) {
            return $this->buildFallback($stmt->raw);
        }

        $idx = $stmt->indexes[0];
        $line = $this->buildIndex($idx);

        return "Schema::table('{$stmt->table}', function (Blueprint \$table) {\n        {$line}\n    });";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  RENAME TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function buildRenameTable(SqlStatement $stmt): string
    {
        // $stmt->table = old table name, $stmt->raw = new table name (we stored it there)
        $from = $stmt->table;
        $to   = $stmt->raw;
        return "Schema::rename('{$from}', '{$to}');";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DROP TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function buildDropTable(SqlStatement $stmt): string
    {
        return "Schema::dropIfExists('{$stmt->table}');";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DROP INDEX (standalone)
    // ─────────────────────────────────────────────────────────────────────────

    private function buildDropIndex(SqlStatement $stmt): string
    {
        if (empty($stmt->indexes)) {
            return $this->buildFallback($stmt->raw);
        }

        $idx  = $stmt->indexes[0];
        $name = $idx->name;

        return "Schema::table('{$stmt->table}', function (Blueprint \$table) {\n        \$table->dropIndex('{$name}');\n    });";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Column builder
    // ─────────────────────────────────────────────────────────────────────────

    private function buildColumn(SqlColumn $col): string
    {
        // Primary auto-increment shorthand
        if ($col->isPrimary && $col->isAutoIncrement) {
            $method = match ($col->baseType) {
                'BIGINT'  => 'id',       // id() defaults to unsignedBigInteger
                'INT', 'INTEGER' => 'increments',
                'SMALLINT'       => 'smallIncrements',
                'TINYINT'        => 'tinyIncrements',
                'MEDIUMINT'      => 'mediumIncrements',
                default          => 'id',
            };
            return "\$table->{$method}('{$col->name}');";
        }

        // UUID primary key
        if ($col->isPrimary && $col->baseType === 'UUID') {
            return "\$table->uuid('{$col->name}')->primary();";
        }

        $call = $this->buildMethodCall($col);

        if ($call === null) {
            // Fallback for unsupported types
            $escaped = addslashes($col->rawType);
            return "// TODO: unsupported column type — \$table->addColumn('{$col->name}', '{$escaped}');";
        }

        // Chain modifiers
        $modifiers = $this->buildModifiers($col);

        return "\$table->{$call}{$modifiers};";
    }

    private function buildMethodCall(SqlColumn $col): ?string
    {
        $method = self::TYPE_MAP[$col->baseType] ?? null;

        if ($method === null) {
            return null;
        }

        return match ($method) {
            'string'       => "string('{$col->name}'" . ($col->length && $col->length !== 255 ? ", {$col->length}" : '') . ")",
            'char'         => "char('{$col->name}'" . ($col->length ? ", {$col->length}" : '') . ")",
            'integer'      => $col->isUnsigned ? "unsignedInteger('{$col->name}')" : "integer('{$col->name}')",
            'tinyInteger'  => $col->isUnsigned ? "unsignedTinyInteger('{$col->name}')" : "tinyInteger('{$col->name}')",
            'smallInteger' => $col->isUnsigned ? "unsignedSmallInteger('{$col->name}')" : "smallInteger('{$col->name}')",
            'mediumInteger'=> $col->isUnsigned ? "unsignedMediumInteger('{$col->name}')" : "mediumInteger('{$col->name}')",
            'bigInteger'   => $col->isUnsigned ? "unsignedBigInteger('{$col->name}')" : "bigInteger('{$col->name}')",
            'decimal'      => "decimal('{$col->name}'" . ($col->precision !== null ? ", {$col->precision}, {$col->scale}" : '') . ")",
            'float'        => "float('{$col->name}'" . ($col->precision !== null ? ", {$col->precision}, {$col->scale}" : '') . ")",
            'double'       => "double('{$col->name}'" . ($col->precision !== null ? ", {$col->precision}, {$col->scale}" : '') . ")",
            'dateTime'     => $col->precision ? "dateTime('{$col->name}', {$col->precision})" : "dateTime('{$col->name}')",
            'timestamp'    => $col->precision ? "timestamp('{$col->name}', {$col->precision})" : "timestamp('{$col->name}')",
            'enum'         => $this->buildEnumCall($col),
            'set'          => $this->buildSetCall($col),
            'boolean'      => "boolean('{$col->name}')",
            default        => "{$method}('{$col->name}')",
        };
    }

    private function buildModifiers(SqlColumn $col): string
    {
        $chain = '';

        if ($col->nullable) {
            $chain .= '->nullable()';
        }

        if ($col->isUnique) {
            $chain .= '->unique()';
        }

        if ($col->hasDefault && $col->default !== null) {
            $default = $col->default;
            // Numeric or boolean-like defaults don't need quotes
            if (is_numeric($default) || in_array(strtoupper($default), ['TRUE', 'FALSE', 'NULL', 'CURRENT_TIMESTAMP'])) {
                if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                    $chain .= '->useCurrent()';
                } elseif (strtoupper($default) === 'NULL') {
                    // nullable() already added above, skip
                } else {
                    $chain .= "->default({$default})";
                }
            } else {
                $escaped = addslashes($default);
                $chain .= "->default('{$escaped}')";
            }
        }

        if ($col->onUpdate === 'CURRENT_TIMESTAMP') {
            $chain .= '->useCurrentOnUpdate()';
        }


        return $chain;
    }

    private function buildEnumCall(SqlColumn $col): string
    {
        if ($col->enumValues) {
            $values = array_map(
                fn($v) => "'" . trim($v, "' ") . "'",
                explode(',', $col->enumValues)
            );
            return "enum('{$col->name}', [" . implode(', ', $values) . "])";
        }
        return "enum('{$col->name}', [])";
    }

    private function buildSetCall(SqlColumn $col): string
    {
        if ($col->enumValues) {
            $values = array_map(
                fn($v) => "'" . trim($v, "' ") . "'",
                explode(',', $col->enumValues)
            );
            return "set('{$col->name}', [" . implode(', ', $values) . "])";
        }
        return "set('{$col->name}', [])";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Add / Modify column for ALTER TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function buildAddColumn(SqlAlteration $alt): string
    {
        return $this->buildColumn($alt->column);
    }

    private function buildModifyColumn(SqlAlteration $alt): string
    {
        $col  = $alt->column;
        $call = $this->buildMethodCall($col);

        if ($call === null) {
            return "// TODO: unsupported column modify for '{$col->name}'";
        }

        $modifiers = $this->buildModifiers($col);

        return "\$table->{$call}{$modifiers}->change();";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Index builder
    // ─────────────────────────────────────────────────────────────────────────

    private function buildIndex(SqlIndex $idx): string
    {
        $cols = count($idx->columns) === 1
            ? "'{$idx->columns[0]}'"
            : "['" . implode("', '", $idx->columns) . "']";

        if ($idx->isUnique) {
            return "\$table->unique({$cols}, '{$idx->name}');";
        }

        return "\$table->index({$cols}, '{$idx->name}');";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Foreign key builder
    // ─────────────────────────────────────────────────────────────────────────

    private function buildForeignKey(SqlForeignKey $fk): string
    {
        $line = "\$table->foreign('{$fk->localColumn}')->references('{$fk->referencedColumn}')->on('{$fk->referencedTable}')";

        if ($fk->onDelete) {
            $action = self::FK_ACTION_MAP[strtoupper($fk->onDelete)] ?? null;
            if ($action) {
                $line .= "->{$action}()";
            }
        }

        if ($fk->onUpdate && $fk->onUpdate !== $fk->onDelete) {
            $action = self::FK_ACTION_MAP[strtoupper($fk->onUpdate)] ?? null;
            if ($action) {
                $line .= "->{$action}OnUpdate()";
            }
        }

        return $line . ";";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Timestamp / SoftDelete detection helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect if the columns include a created_at / updated_at pair that maps
     * cleanly to $table->timestamps().
     */
    private function detectTimestamps(array $columns): bool
    {
        $names = array_map(fn(SqlColumn $c) => $c->name, $columns);
        return in_array('created_at', $names) && in_array('updated_at', $names);
    }

    private function detectSoftDeletes(array $columns): bool
    {
        $names = array_map(fn(SqlColumn $c) => $c->name, $columns);
        return in_array('deleted_at', $names);
    }

    private function filterTimestampColumns(array $columns): array
    {
        return array_filter(
            $columns,
            fn(SqlColumn $c) => ! in_array($c->name, ['created_at', 'updated_at'])
        );
    }

    private function filterSoftDeleteColumn(array $columns): array
    {
        return array_filter($columns, fn(SqlColumn $c) => $c->name !== 'deleted_at');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Fallback
    // ─────────────────────────────────────────────────────────────────────────

    private function buildFallback(string $rawSql): string
    {
        $escaped = str_replace("'", "\'", trim($rawSql));
        return "// TODO: review this raw SQL — could not be translated automatically\n    DB::unprepared('{$escaped}');";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true if a generated Schema::table() block has an empty body —
     * i.e. no column, index, or FK operations were produced for it.
     * This suppresses no-op blocks that result from unrecognised ALTER TABLE
     * clauses (e.g. SQLite's intermediate RENAME TO steps).
     */
    private function isEmptySchemaBlock(string $block): bool
    {
        // Match Schema::table('...', function (Blueprint $table) { <body> });
        // and check if the body is blank / only whitespace
        if (preg_match('/Schema::table\([^)]+,\s*function\s*\([^)]+\)\s*\{([^}]*)\}\s*\);/s', $block, $m)) {
            return trim($m[1]) === '';
        }
        return false;
    }
}

