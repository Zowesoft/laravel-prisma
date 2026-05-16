<?php

namespace Zowesoft\LaravelPrisma\Generator;

use Zowesoft\LaravelPrisma\Schema\PrismaEnum;
use Zowesoft\LaravelPrisma\Schema\PrismaField;
use Zowesoft\LaravelPrisma\Schema\PrismaModel;
use Zowesoft\LaravelPrisma\Schema\PrismaSchema;

class MigrationGenerator
{
    private string $provider;

    // Tracks models already in the state file for diff generation
    private array $existingState = [];

    public function __construct(private string $stateFile) {}

    // -------------------------------------------------------------------------
    //  Public entry point
    // -------------------------------------------------------------------------

    /**
     * Generate migration files for the given schema.
     * Returns an array of [ 'path' => ..., 'action' => 'create'|'alter' ]
     */
    public function generate(PrismaSchema $schema, string $outputPath): array
    {
        $this->provider      = $schema->datasourceProvider;
        $this->existingState = $this->loadState();
        $generated           = [];

        foreach ($schema->models as $model) {
            $existing = $this->existingState[$model->tableName] ?? null;

            if ($existing === null) {
                // Brand new table
                $file = $this->generateCreateMigration($model, $schema->enums, $outputPath);
                $generated[] = ['path' => $file, 'action' => 'create', 'table' => $model->tableName];
            } else {
                // Table exists — diff and generate ALTER if needed
                $file = $this->generateAlterMigration($model, $existing, $schema->enums, $outputPath);
                if ($file) {
                    $generated[] = ['path' => $file, 'action' => 'alter', 'table' => $model->tableName];
                }
            }

            // Save current state
            $this->existingState[$model->tableName] = $this->modelToState($model);
        }

        $this->saveState($this->existingState);

        return $generated;
    }

    // -------------------------------------------------------------------------
    //  CREATE migration
    // -------------------------------------------------------------------------

    private function generateCreateMigration(PrismaModel $model, array $enums, string $outputPath): string
    {
        $className  = 'Create' . $this->studly($model->tableName) . 'Table';
        $tableName  = $model->tableName;
        $columns    = $this->buildColumnLines($model->fields, $enums, indent: 12);
        $indexes    = $this->buildIndexLines($model, indent: 12);

        $stub = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
{$columns}{$indexes}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;

        return $this->writeFile($className, 'create_' . $tableName . '_table', $stub, $outputPath);
    }

    // -------------------------------------------------------------------------
    //  ALTER migration (diff)
    // -------------------------------------------------------------------------

    private function generateAlterMigration(
        PrismaModel $model,
        array $existingFields,
        array $enums,
        string $outputPath
    ): ?string {
        $addLines    = [];
        $modifyLines = [];
        $dropLines   = [];

        $currentFieldNames  = array_column($existingFields, 'name');
        $newFieldNames      = array_map(fn($f) => $f->name, $model->fields);

        // Fields to add
        foreach ($model->fields as $field) {
            if (! in_array($field->name, $currentFieldNames)) {
                $line = $this->fieldToColumn($field, $enums);
                if ($line) $addLines[] = str_repeat(' ', 12) . "\$table->{$line};";
            }
        }

        // Fields to drop
        foreach ($existingFields as $existing) {
            if (! in_array($existing['name'], $newFieldNames)) {
                $dropLines[] = str_repeat(' ', 12) . "\$table->dropColumn('{$existing['name']}');";
            }
        }

        // Fields to modify (type or nullable changed)
        foreach ($model->fields as $field) {
            $prev = collect($existingFields)->firstWhere('name', $field->name);
            if ($prev && ($prev['type'] !== $field->type || $prev['isRequired'] !== $field->isRequired)) {
                $line = $this->fieldToColumn($field, $enums, modify: true);
                if ($line) $modifyLines[] = str_repeat(' ', 12) . "\$table->{$line};";
            }
        }

        if (empty($addLines) && empty($modifyLines) && empty($dropLines)) {
            return null; // No changes
        }

        $tableName = $model->tableName;
        $className = 'Update' . $this->studly($tableName) . 'Table' . date('His');

        $upLines   = implode("\n", array_merge($addLines, $modifyLines, $dropLines));
        $downLines = '            // Reverse the migration manually if needed';

        $stub = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$upLines}
        });
    }

    public function down(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$downLines}
        });
    }
};
PHP;

        return $this->writeFile($className, 'update_' . $tableName . '_table', $stub, $outputPath);
    }

    // -------------------------------------------------------------------------
    //  Column builder
    // -------------------------------------------------------------------------

    private function buildColumnLines(array $fields, array $enums, int $indent): string
    {
        $lines     = [];
        $hasTimes  = false;
        $pad       = str_repeat(' ', $indent);

        foreach ($fields as $field) {
            // Detect if timestamps() can replace createdAt + updatedAt
            if (in_array($field->name, ['createdAt', 'updatedAt', 'created_at', 'updated_at'])) {
                $hasTimes = true;
                continue;
            }

            $col = $this->fieldToColumn($field, $enums);
            if ($col) {
                $lines[] = "{$pad}\$table->{$col};";
            }
        }

        if ($hasTimes) {
            $lines[] = "{$pad}\$table->timestamps();";
        }

        // Soft deletes
        $fieldNames = array_map(fn($f) => $f->name, $fields);
        if (in_array('deletedAt', $fieldNames) || in_array('deleted_at', $fieldNames)) {
            $lines[] = "{$pad}\$table->softDeletes();";
        }

        return implode("\n", $lines);
    }

    private function buildIndexLines(PrismaModel $model, int $indent): string
    {
        $lines = [];
        $pad   = str_repeat(' ', $indent);

        foreach ($model->uniqueComposites as $cols) {
            $colList = "['" . implode("', '", $cols) . "']";
            $lines[] = "{$pad}\$table->unique({$colList});";
        }

        foreach ($model->indexComposites as $cols) {
            $colList = "['" . implode("', '", $cols) . "']";
            $lines[] = "{$pad}\$table->index({$colList});";
        }

        return $lines ? "\n" . implode("\n", $lines) : '';
    }

    /**
     * Convert a PrismaField into a Laravel Blueprint method call string.
     * e.g. "string('email', 255)->unique()->nullable()"
     */
    private function fieldToColumn(PrismaField $field, array $enums, bool $modify = false): ?string
    {
        // Skip relation-only fields (no column in DB)
        if ($field->isList) return null;

        $col = $this->mapType($field, $enums);
        if ($col === null) return null;

        // nullable
        if (! $field->isRequired) {
            $col .= '->nullable()';
        }

        // unique
        if ($field->isUnique && ! $field->isId) {
            $col .= '->unique()';
        }

        // default
        if ($field->hasDefault && ! $field->isAutoIncrement) {
            $col .= $this->buildDefault($field->default);
        }

        // ->change() for ALTER
        if ($modify) {
            $col .= '->change()';
        }

        return $col;
    }

    private function mapType(PrismaField $field, array $enums): ?string
    {
        $name = $field->name;

        // Primary key
        if ($field->isId && $field->isAutoIncrement) {
            return match ($field->type) {
                'BigInt' => "bigIncrements('{$name}')",
                default  => "increments('{$name}')",
            };
        }

        if ($field->isId && ! $field->isAutoIncrement) {
            // UUID primary key
            return "uuid('{$name}')->primary()";
        }

        // Foreign key fields — detect by convention (ends with Id/ID)
        // Prisma uses @relation — we look for fields ending in Id that are Int/BigInt/String
        if (
            preg_match('/Id$/', $name)
            && in_array($field->type, ['Int', 'BigInt', 'String'])
            && ! $field->isId
        ) {
            $referenced = $this->toSnakePlural(
                lcfirst(preg_replace('/Id$/', '', $name))
            );
            $colType = $field->type === 'BigInt'
                ? "unsignedBigInteger('{$name}')"
                : ($field->type === 'String' ? "string('{$name}')" : "unsignedInteger('{$name}')");

            // We'll add the foreign key constraint as a separate line via a comment
            // The column itself is returned; FK line appended separately
            return $colType;
        }

        return match ($field->type) {
            // Strings
            'String'   => $field->length
                            ? "string('{$name}', {$field->length})"
                            : "string('{$name}')",

            // Integers
            'Int'      => "integer('{$name}')",
            'BigInt'   => "bigInteger('{$name}')",

            // Floats / Decimals
            'Float'    => "float('{$name}')",
            'Decimal'  => ($field->precision && $field->scale)
                            ? "decimal('{$name}', {$field->precision}, {$field->scale})"
                            : "decimal('{$name}')",

            // Boolean
            'Boolean'  => "boolean('{$name}')",

            // Dates
            'DateTime' => $field->isUpdatedAt
                            ? null  // handled by timestamps()
                            : "timestamp('{$name}')",

            // JSON
            'Json'     => "json('{$name}')",

            // Binary
            'Bytes'    => "binary('{$name}')",

            // Enum — check if type matches a parsed enum
            default    => $this->isEnum($field->type, $enums)
                            ? $this->buildEnumColumn($name, $field->type, $enums)
                            : null,
        };
    }

    private function buildDefault(mixed $default): string
    {
        if ($default === 'now()') return "->useCurrent()";
        if ($default === 'uuid()') return "->default(\\Illuminate\\Support\\Str::uuid())";
        if ($default === 'cuid()') return "";
        if (is_bool($default)) return '->default(' . ($default ? 'true' : 'false') . ')';
        if (is_string($default)) return "->default('{$default}')";
        return "->default({$default})";
    }

    private function isEnum(string $type, array $enums): bool
    {
        foreach ($enums as $enum) {
            if ($enum->name === $type) return true;
        }
        return false;
    }

    private function buildEnumColumn(string $fieldName, string $enumType, array $enums): ?string
    {
        foreach ($enums as $enum) {
            if ($enum->name === $enumType) {
                $values = "'" . implode("', '", $enum->values) . "'";
                return "enum('{$fieldName}', [{$values}])";
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    //  State management (tracks what has been migrated for diffing)
    // -------------------------------------------------------------------------

    private function loadState(): array
    {
        if (! file_exists($this->stateFile)) return [];
        return json_decode(file_get_contents($this->stateFile), true) ?? [];
    }

    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function modelToState(PrismaModel $model): array
    {
        return array_map(fn(PrismaField $f) => [
            'name'       => $f->name,
            'type'       => $f->type,
            'isRequired' => $f->isRequired,
        ], $model->fields);
    }

    // -------------------------------------------------------------------------
    //  File writing
    // -------------------------------------------------------------------------

    private function writeFile(string $className, string $slug, string $content, string $outputPath): string
    {
        $timestamp = date(config('laravel-prisma.timestamp_format', 'Y_m_d_His'));
        $filename  = "{$timestamp}_{$slug}.php";
        $fullPath  = rtrim($outputPath, '/') . '/' . $filename;

        if (! is_dir($outputPath)) mkdir($outputPath, 0755, true);

        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    // -------------------------------------------------------------------------
    //  String helpers
    // -------------------------------------------------------------------------

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function toSnakePlural(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        if (str_ends_with($snake, 'y')) return substr($snake, 0, -1) . 'ies';
        if (str_ends_with($snake, 's')) return $snake . 'es';
        return $snake . 's';
    }
}
