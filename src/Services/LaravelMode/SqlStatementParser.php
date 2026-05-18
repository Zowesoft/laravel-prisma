<?php

namespace Zowesoft\LaravelPrisma\Services\LaravelMode;

/**
 * SqlStatementParser
 *
 * Splits raw SQL output from `prisma migrate diff --script` into individual
 * SqlStatement objects, each fully tokenised into columns, indexes, foreign
 * keys and alterations.
 *
 * Supported statement types:
 *   CREATE TABLE ...
 *   ALTER TABLE  ... ADD COLUMN / DROP COLUMN / MODIFY COLUMN
 *   CREATE [UNIQUE] INDEX ...
 *   DROP TABLE ...
 *   DROP INDEX ...
 */
class SqlStatementParser
{
    /**
     * Parse a full SQL diff string into an array of SqlStatement objects.
     *
     * @return SqlStatement[]
     */
    public function parse(string $sql): array
    {
        $statements = $this->splitStatements($sql);
        $parsed     = [];

        foreach ($statements as $raw) {
            $raw = trim($raw);
            if (empty($raw)) continue;

            $statement = $this->parseStatement($raw);
            if ($statement) {
                $parsed[] = $statement;
            }
        }

        return $parsed;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Statement splitter
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Split raw SQL into individual statements on semicolons,
     * being careful not to split inside parentheses or string literals.
     *
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        // Strip Prisma comment headers like "-- CreateTable", "-- AddColumn" etc. (allowing leading whitespace)
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        $statements = [];
        $current    = '';
        $depth      = 0;
        $inString   = false;
        $stringChar = '';
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $current .= $char;
                if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString   = true;
                $stringChar = $char;
                $current   .= $char;
                continue;
            }

            if ($char === '(') { $depth++; $current .= $char; continue; }
            if ($char === ')') { $depth--; $current .= $char; continue; }

            if ($char === ';' && $depth === 0) {
                $stmt = trim($current);
                if (! empty($stmt)) {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $stmt = trim($current);
        if (! empty($stmt)) {
            $statements[] = $stmt;
        }

        return $statements;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Statement router
    // ─────────────────────────────────────────────────────────────────────────

    private function parseStatement(string $raw): ?SqlStatement
    {
        $upper = strtoupper(ltrim($raw));

        if (str_starts_with($upper, 'CREATE TABLE')) {
            return $this->parseCreateTable($raw);
        }

        if (str_starts_with($upper, 'ALTER TABLE')) {
            return $this->parseAlterTable($raw);
        }

        if (str_starts_with($upper, 'CREATE UNIQUE INDEX') ||
            str_starts_with($upper, 'CREATE INDEX')) {
            return $this->parseCreateIndex($raw);
        }

        if (str_starts_with($upper, 'DROP TABLE')) {
            return $this->parseDropTable($raw);
        }

        if (str_starts_with($upper, 'DROP INDEX')) {
            return $this->parseDropIndex($raw);
        }

        return new SqlStatement(
            type:  SqlStatement::TYPE_UNKNOWN,
            table: '',
            raw:   $raw,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CREATE TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function parseCreateTable(string $raw): SqlStatement
    {
        preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?(\w+)[`"\]]?/i', $raw, $m);
        $table = $m[1] ?? '';

        $body = $this->extractParenBody($raw);
        if ($body === null) {
            return new SqlStatement(SqlStatement::TYPE_CREATE_TABLE, $table, raw: $raw);
        }

        $lines       = $this->splitColumnLines($body);
        $columns     = [];
        $indexes     = [];
        $foreignKeys = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $upper = strtoupper($line);

            if (str_starts_with($upper, 'PRIMARY KEY')) {
                continue;
            }

            if (str_starts_with($upper, 'UNIQUE INDEX') ||
                str_starts_with($upper, 'UNIQUE KEY') ||
                str_starts_with($upper, 'KEY ') ||
                str_starts_with($upper, 'INDEX ')) {
                $idx = $this->parseInlineIndex($line, $table);
                if ($idx) $indexes[] = $idx;
                continue;
            }

            if (str_starts_with($upper, 'CONSTRAINT') ||
                str_starts_with($upper, 'FOREIGN KEY')) {
                $fk = $this->parseForeignKey($line);
                if ($fk) $foreignKeys[] = $fk;
                continue;
            }

            $col = $this->parseColumnDefinition($line);
            if ($col) $columns[] = $col;
        }

        return new SqlStatement(
            type:        SqlStatement::TYPE_CREATE_TABLE,
            table:       $table,
            columns:     $columns,
            indexes:     $indexes,
            foreignKeys: $foreignKeys,
            raw:         $raw,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ALTER TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function parseAlterTable(string $raw): SqlStatement
    {
        preg_match('/ALTER\s+TABLE\s+[`"\[]?(\w+)[`"\]]?/i', $raw, $m);
        $table = $m[1] ?? '';

        // Handle RENAME TO early — this is a table rename, not a column alteration
        // e.g. ALTER TABLE "new_users" RENAME TO "users"
        if (preg_match('/ALTER\s+TABLE\s+[`"\[]?(\w+)[`"\]]?\s+RENAME\s+TO\s+[`"\[]?(\w+)[`"\]]?/i', $raw, $rm)) {
            return new SqlStatement(
                type:  SqlStatement::TYPE_RENAME_TABLE,
                table: $rm[1],   // old name
                raw:   $rm[2],   // new name stored in raw for convenience
            );
        }

        $body = preg_replace('/ALTER\s+TABLE\s+[`"\[]?\w+[`"\]]?\s*/i', '', $raw, 1);

        $clauses     = $this->splitAtDepthZero($body ?? '');
        $alterations = [];

        foreach ($clauses as $clause) {
            $clause = trim($clause);

            if (preg_match('/^ADD\s+COLUMN\s+/i', $clause)) {
                $colDef = preg_replace('/^ADD\s+COLUMN\s+/i', '', $clause);
                $col    = $this->parseColumnDefinition($colDef);
                if ($col) {
                    $alterations[] = new SqlAlteration(SqlAlteration::OP_ADD_COLUMN, column: $col);
                }
                continue;
            }

            if (preg_match('/^DROP\s+COLUMN\s+[`"\[]?(\w+)[`"\]]?/i', $clause, $dm)) {
                $alterations[] = new SqlAlteration(SqlAlteration::OP_DROP_COLUMN, dropName: $dm[1]);
                continue;
            }

            if (preg_match('/^(MODIFY|ALTER)\s+COLUMN\s+/i', $clause)) {
                $colDef = preg_replace('/^(MODIFY|ALTER)\s+COLUMN\s+/i', '', $clause);
                $col    = $this->parseColumnDefinition($colDef);
                if ($col) {
                    $alterations[] = new SqlAlteration(SqlAlteration::OP_MODIFY_COLUMN, column: $col);
                }
                continue;
            }

            if (preg_match('/^ADD\s+CONSTRAINT\s+/i', $clause) ||
                preg_match('/^ADD\s+FOREIGN\s+KEY/i', $clause)) {
                $fk = $this->parseForeignKey($clause);
                if ($fk) {
                    $alterations[] = new SqlAlteration(SqlAlteration::OP_ADD_FK, foreignKey: $fk);
                }
                continue;
            }

            if (preg_match('/^DROP\s+FOREIGN\s+KEY\s+[`"\[]?(\w+)[`"\]]?/i', $clause, $dm)) {
                $alterations[] = new SqlAlteration(SqlAlteration::OP_DROP_FK, dropName: $dm[1]);
                continue;
            }

            if (preg_match('/^ADD\s+(?:UNIQUE\s+)?INDEX\s+/i', $clause)) {
                $idx = $this->parseInlineIndex($clause, $table);
                if ($idx) {
                    $alterations[] = new SqlAlteration(SqlAlteration::OP_ADD_INDEX, index: $idx);
                }
                continue;
            }

            if (preg_match('/^DROP\s+INDEX\s+[`"\[]?(\w+)[`"\]]?/i', $clause, $dm)) {
                $alterations[] = new SqlAlteration(SqlAlteration::OP_DROP_INDEX, dropName: $dm[1]);
                continue;
            }
        }

        return new SqlStatement(
            type:        SqlStatement::TYPE_ALTER_TABLE,
            table:       $table,
            alterations: $alterations,
            raw:         $raw,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CREATE INDEX
    // ─────────────────────────────────────────────────────────────────────────

    private function parseCreateIndex(string $raw): SqlStatement
    {
        $isUnique = (bool) preg_match('/CREATE\s+UNIQUE\s+INDEX/i', $raw);

        preg_match(
            '/CREATE\s+(?:UNIQUE\s+)?INDEX\s+[`"\[]?(\w+)[`"\]]?\s+ON\s+[`"\[]?(\w+)[`"\]]?\s*\(([^)]+)\)/i',
            $raw,
            $m
        );

        $indexName = $m[1] ?? '';
        $table     = $m[2] ?? '';
        $cols      = isset($m[3])
            ? array_map(fn($c) => trim($c, " `\"[]"), explode(',', $m[3]))
            : [];

        $type = null;
        if (preg_match('/USING\s+(BTREE|HASH|GIST|GIN)/i', $raw, $tm)) {
            $type = strtoupper($tm[1]);
        }

        $index = new SqlIndex(
            name:     $indexName,
            table:    $table,
            columns:  $cols,
            isUnique: $isUnique,
            type:     $type,
        );

        return new SqlStatement(
            type:    SqlStatement::TYPE_CREATE_INDEX,
            table:   $table,
            indexes: [$index],
            raw:     $raw,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DROP TABLE / DROP INDEX
    // ─────────────────────────────────────────────────────────────────────────

    private function parseDropTable(string $raw): SqlStatement
    {
        preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"\[]?(\w+)[`"\]]?/i', $raw, $m);
        return new SqlStatement(SqlStatement::TYPE_DROP_TABLE, table: $m[1] ?? '', raw: $raw);
    }

    private function parseDropIndex(string $raw): SqlStatement
    {
        preg_match('/DROP\s+INDEX\s+[`"\[]?(\w+)[`"\]]?\s+ON\s+[`"\[]?(\w+)[`"\]]?/i', $raw, $m);
        $index = new SqlIndex(name: $m[1] ?? '', table: $m[2] ?? '', columns: [], isUnique: false, type: null);
        return new SqlStatement(SqlStatement::TYPE_DROP_INDEX, table: $m[2] ?? '', indexes: [$index], raw: $raw);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Column definition parser
    // ─────────────────────────────────────────────────────────────────────────

    private function parseColumnDefinition(string $def): ?SqlColumn
    {
        $def = trim($def);

        if (! preg_match('/^[`"\[]?(\w+)[`"\]]?\s+(.+)$/i', $def, $m)) {
            return null;
        }

        $name  = $m[1];
        $rest  = trim($m[2]);
        $upper = strtoupper($rest);

        preg_match('/^(\w+)(?:\(([^)]+)\))?/i', $rest, $tm);
        $rawType  = $tm[0] ?? $rest;
        $baseType = strtoupper($tm[1] ?? '');
        $params   = isset($tm[2]) ? trim($tm[2]) : null;

        $length    = null;
        $precision = null;
        $scale     = null;

        if ($params !== null) {
            $parts = array_map('trim', explode(',', $params));
            if (count($parts) === 1 && is_numeric($parts[0])) {
                $length = (int) $parts[0];
            } elseif (count($parts) === 2) {
                $precision = (int) $parts[0];
                $scale     = (int) $parts[1];
            }
        }

        $enumValues = null;
        if ($baseType === 'ENUM' && $params) {
            $enumValues = $params;
        }

        $nullable        = ! str_contains($upper, 'NOT NULL');
        $isPrimary       = str_contains($upper, 'PRIMARY KEY');
        $isAutoIncrement = str_contains($upper, 'AUTO_INCREMENT') || str_contains($upper, 'AUTOINCREMENT');
        $isUnsigned      = str_contains($upper, 'UNSIGNED');
        $isUnique        = str_contains($upper, 'UNIQUE');

        $hasDefault = false;
        $default    = null;
        if (preg_match('/DEFAULT\s+(.+?)(?:\s+(?:ON|AUTO|PRIMARY|UNIQUE|COMMENT|CHARACTER|COLLATE)|$)/i', $rest, $dm)) {
            $hasDefault = true;
            $default    = trim($dm[1], " \t\n\r\0\x0B'\"");
        }

        $onUpdate = null;
        if (preg_match('/ON\s+UPDATE\s+(\S+)/i', $rest, $om)) {
            $onUpdate = strtoupper($om[1]);
        }

        $characterSet = null;
        $collation    = null;
        if (preg_match('/CHARACTER\s+SET\s+(\S+)/i', $rest, $cm)) $characterSet = $cm[1];
        if (preg_match('/COLLATE\s+(\S+)/i', $rest, $cl)) $collation = $cl[1];

        return new SqlColumn(
            name:            $name,
            rawType:         $rawType,
            baseType:        $baseType,
            length:          $length,
            precision:       $precision,
            scale:           $scale,
            nullable:        $nullable,
            isPrimary:       $isPrimary,
            isAutoIncrement: $isAutoIncrement,
            isUnsigned:      $isUnsigned,
            isUnique:        $isUnique,
            default:         $default,
            hasDefault:      $hasDefault,
            onUpdate:        $onUpdate,
            enumValues:      $enumValues,
            characterSet:    $characterSet,
            collation:       $collation,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Inline index parser (inside CREATE TABLE body)
    // ─────────────────────────────────────────────────────────────────────────

    private function parseInlineIndex(string $line, string $table): ?SqlIndex
    {
        $isUnique = (bool) preg_match('/UNIQUE/i', $line);

        if (preg_match('/(?:KEY|INDEX)\s+[`"\[]?(\w+)[`"\]]?\s*\(([^)]+)\)/i', $line, $m)) {
            $cols = array_map(
                fn($c) => trim(preg_replace('/\(\d+\)/', '', $c), " `\"[]"),
                explode(',', $m[2])
            );
            return new SqlIndex(name: $m[1], table: $table, columns: $cols, isUnique: $isUnique, type: null);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Foreign key parser
    // ─────────────────────────────────────────────────────────────────────────

    private function parseForeignKey(string $line): ?SqlForeignKey
    {
        preg_match(
            '/CONSTRAINT\s+[`"\[]?(\w+)[`"\]]?\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+[`"\[]?(\w+)[`"\]]?\s*\(([^)]+)\)(?:\s+ON\s+DELETE\s+(\w+(?:\s+(?!ON\b)\w+)?))?(?:\s+ON\s+UPDATE\s+(\w+(?:\s+(?!ON\b)\w+)?))?/i',
            $line,
            $m
        );

        if (empty($m)) {
            preg_match(
                '/FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+[`"\[]?(\w+)[`"\]]?\s*\(([^)]+)\)(?:\s+ON\s+DELETE\s+(\w+(?:\s+(?!ON\b)\w+)?))?(?:\s+ON\s+UPDATE\s+(\w+(?:\s+(?!ON\b)\w+)?))?/i',
                $line,
                $m2
            );
            if (empty($m2)) return null;

            return new SqlForeignKey(
                name:             'fk_' . uniqid(),
                localColumn:      trim($m2[1], " `\"[]"),
                referencedTable:  $m2[2],
                referencedColumn: trim($m2[3], " `\"[]"),
                onDelete:         isset($m2[4]) ? strtoupper(trim($m2[4])) : null,
                onUpdate:         isset($m2[5]) ? strtoupper(trim($m2[5])) : null,
            );
        }

        return new SqlForeignKey(
            name:             $m[1],
            localColumn:      trim($m[2], " `\"[]"),
            referencedTable:  $m[3],
            referencedColumn: trim($m[4], " `\"[]"),
            onDelete:         isset($m[5]) ? strtoupper(trim($m[5])) : null,
            onUpdate:         isset($m[6]) ? strtoupper(trim($m[6])) : null,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function extractParenBody(string $sql): ?string
    {
        $start = strpos($sql, '(');
        if ($start === false) return null;

        $depth  = 0;
        $end    = $start;
        $length = strlen($sql);

        for ($i = $start; $i < $length; $i++) {
            if ($sql[$i] === '(') $depth++;
            if ($sql[$i] === ')') $depth--;
            if ($depth === 0) { $end = $i; break; }
        }

        return substr($sql, $start + 1, $end - $start - 1);
    }

    private function splitColumnLines(string $body): array
    {
        return $this->splitAtDepthZero($body);
    }

    private function splitAtDepthZero(string $str): array
    {
        $parts   = [];
        $current = '';
        $depth   = 0;
        $length  = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            $char = $str[$i];
            if ($char === '(') $depth++;
            if ($char === ')') $depth--;
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
