<?php

namespace Zowesoft\LaravelPrisma\Parser;

use Zowesoft\LaravelPrisma\Schema\PrismaEnum;
use Zowesoft\LaravelPrisma\Schema\PrismaField;
use Zowesoft\LaravelPrisma\Schema\PrismaModel;
use Zowesoft\LaravelPrisma\Schema\PrismaSchema;

class PrismaSchemaParser
{
    private string $content;
    private string $provider = 'mysql';

    public function parse(string $schemaPath): PrismaSchema
    {
        if (! file_exists($schemaPath)) {
            throw new \RuntimeException("schema.prisma not found at: {$schemaPath}");
        }

        $this->content = file_get_contents($schemaPath);

        // Strip comments
        $this->content = preg_replace('/\/\/.*$/m', '', $this->content);

        $this->provider = $this->parseDatasourceProvider();

        return new PrismaSchema(
            datasourceProvider: $this->provider,
            models: $this->parseModels(),
            enums:  $this->parseEnums(),
        );
    }

    // -------------------------------------------------------------------------
    //  Datasource
    // -------------------------------------------------------------------------

    private function parseDatasourceProvider(): string
    {
        if (preg_match('/datasource\s+\w+\s*\{([^}]+)\}/s', $this->content, $m)) {
            if (preg_match('/provider\s*=\s*"([^"]+)"/', $m[1], $p)) {
                return strtolower($p[1]); // mysql | postgresql | sqlite | sqlserver
            }
        }
        return 'mysql';
    }

    // -------------------------------------------------------------------------
    //  Models
    // -------------------------------------------------------------------------

    /** @return PrismaModel[] */
    private function parseModels(): array
    {
        $models = [];

        preg_match_all('/model\s+(\w+)\s*\{([^}]+)\}/s', $this->content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $modelName = $match[1];
            $body      = $match[2];
            $lines     = array_filter(array_map('trim', explode("\n", $body)));

            $fields           = [];
            $uniqueComposites = [];
            $indexComposites  = [];
            $mapName          = null;

            foreach ($lines as $line) {
                if (empty($line)) continue;

                // @@map("table_name")
                if (preg_match('/@@map\("([^"]+)"\)/', $line, $m)) {
                    $mapName = $m[1];
                    continue;
                }

                // @@unique([field1, field2])
                if (preg_match('/@@unique\(\[([^\]]+)\]\)/', $line, $m)) {
                    $uniqueComposites[] = array_map('trim', explode(',', $m[1]));
                    continue;
                }

                // @@index([field1, field2])
                if (preg_match('/@@index\(\[([^\]]+)\]\)/', $line, $m)) {
                    $indexComposites[] = array_map('trim', explode(',', $m[1]));
                    continue;
                }

                // Skip remaining @@ directives
                if (str_starts_with($line, '@@')) continue;

                $field = $this->parseField($line);
                if ($field) {
                    $fields[] = $field;
                }
            }

            $tableName = $mapName ?? $this->toSnakePlural($modelName);

            $models[] = new PrismaModel(
                name:             $modelName,
                fields:           $fields,
                tableName:        $tableName,
                uniqueComposites: $uniqueComposites,
                indexComposites:  $indexComposites,
            );
        }

        return $models;
    }

    private function parseField(string $line): ?PrismaField
    {
        // Match: fieldName  FieldType?  @directive ...
        // e.g.:  email      String      @unique @db.VarChar(255)
        //        price      Decimal?    @db.Decimal(10, 2)
        //        createdAt  DateTime    @default(now()) @updatedAt
        if (! preg_match('/^(\w+)\s+(\w+)(\?)?(\[\])?\s*(.*)$/', $line, $m)) {
            return null;
        }

        $name       = $m[1];
        $type       = $m[2];
        $isRequired = empty($m[3]);   // no ? = required
        $isList     = ! empty($m[4]); // [] = array/relation list
        $rest       = trim($m[5] ?? '');

        // Relation fields (other model types) — skip, handled as FK
        // We detect them by checking if the type starts uppercase and is not a scalar
        $scalars = ['String','Int','BigInt','Float','Decimal','Boolean','DateTime','Json','Bytes'];
        $isRelation = ! in_array($type, $scalars) && ctype_upper($type[0]);

        $isId            = str_contains($rest, '@id');
        $isUnique        = str_contains($rest, '@unique');
        $isUpdatedAt     = str_contains($rest, '@updatedAt');
        $isAutoIncrement = str_contains($rest, 'autoincrement()');

        // @default(value)
        $hasDefault = false;
        $default    = null;
        if (preg_match('/@default\(([^)]+)\)/', $rest, $dm)) {
            $hasDefault = true;
            $default    = $this->parseDefaultValue($dm[1], $type);
        }

        // @db.VarChar(255) → length
        $length = null;
        if (preg_match('/@db\.\w+\((\d+)(?:,\s*\d+)?\)/', $rest, $lm)) {
            $length = (int) $lm[1];
        }

        // Decimal precision & scale: @db.Decimal(10, 2)
        $precision = null;
        $scale     = null;
        if (preg_match('/@db\.Decimal\((\d+),\s*(\d+)\)/', $rest, $pm)) {
            $precision = (int) $pm[1];
            $scale     = (int) $pm[2];
        }

        // @relation(fields: [foreignKey], references: [id])
        $relation = null;
        if (preg_match('/@relation\(([^)]+)\)/', $rest, $rm)) {
            $relation = $rm[1];
        }

        return new PrismaField(
            name:            $name,
            type:            $type,
            isRequired:      $isRequired,
            isUnique:        $isUnique,
            isId:            $isId,
            isAutoIncrement: $isAutoIncrement,
            hasDefault:      $hasDefault,
            default:         $default,
            isUpdatedAt:     $isUpdatedAt,
            relation:        $relation,
            isList:          $isList,
            length:          $length,
            precision:       $precision,
            scale:           $scale,
        );
    }

    private function parseDefaultValue(string $raw, string $type): mixed
    {
        $raw = trim($raw);

        if ($raw === 'now()')          return 'now()';
        if ($raw === 'autoincrement()') return 'autoincrement()';
        if ($raw === 'cuid()')         return 'cuid()';
        if ($raw === 'uuid()')         return 'uuid()';
        if ($raw === 'true')           return true;
        if ($raw === 'false')          return false;

        // Quoted string
        if (preg_match('/^"(.+)"$/', $raw, $m)) return $m[1];

        // Numeric
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    // -------------------------------------------------------------------------
    //  Enums
    // -------------------------------------------------------------------------

    /** @return PrismaEnum[] */
    private function parseEnums(): array
    {
        $enums = [];

        preg_match_all('/enum\s+(\w+)\s*\{([^}]+)\}/s', $this->content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $enumName = $match[1];
            $values   = array_values(array_filter(
                array_map('trim', explode("\n", $match[2]))
            ));
            $enums[] = new PrismaEnum(name: $enumName, values: $values);
        }

        return $enums;
    }

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    private function toSnakePlural(string $name): string
    {
        // CamelCase → snake_case
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        // Simple pluralisation
        if (str_ends_with($snake, 'y')) {
            return substr($snake, 0, -1) . 'ies';
        }
        if (str_ends_with($snake, 's') || str_ends_with($snake, 'x') || str_ends_with($snake, 'z')) {
            return $snake . 'es';
        }
        return $snake . 's';
    }
}
