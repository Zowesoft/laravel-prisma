<?php

namespace Zowesoft\LaravelPrisma\Tests\Feature\LaravelMode;

use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatement;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlColumn;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlForeignKey;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlIndex;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlToSchemaBuilder;
use Zowesoft\LaravelPrisma\Tests\TestCase;

class SqlToSchemaBuilderTest extends TestCase
{
    private SqlToSchemaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SqlToSchemaBuilder();
    }

    /** @test */
    public function it_builds_create_table_with_columns_and_autoincrement()
    {
        $stmt = new SqlStatement(
            type: SqlStatement::TYPE_CREATE_TABLE,
            table: 'users',
            columns: [
                new SqlColumn(
                    name: 'id', rawType: 'INT', baseType: 'INT', length: null,
                    precision: null, scale: null, nullable: false, isPrimary: true,
                    isAutoIncrement: true, isUnsigned: false, isUnique: false,
                    default: null, hasDefault: false, onUpdate: null, enumValues: null,
                    characterSet: null, collation: null
                ),
                new SqlColumn(
                    name: 'email', rawType: 'VARCHAR(255)', baseType: 'VARCHAR', length: 255,
                    precision: null, scale: null, nullable: true, isPrimary: false,
                    isAutoIncrement: false, isUnsigned: false, isUnique: true,
                    default: null, hasDefault: false, onUpdate: null, enumValues: null,
                    characterSet: null, collation: null
                ),
            ]
        );

        $blocks = $this->builder->build([$stmt]);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString("Schema::create('users', function (Blueprint \$table) {", $blocks[0]);
        $this->assertStringContainsString("\$table->increments('id');", $blocks[0]);
        $this->assertStringContainsString("\$table->string('email')->nullable()->unique();", $blocks[0]);
    }

    /** @test */
    public function it_simplifies_timestamps_and_softdeletes_in_create_table()
    {
        $stmt = new SqlStatement(
            type: SqlStatement::TYPE_CREATE_TABLE,
            table: 'users',
            columns: [
                new SqlColumn(
                    name: 'created_at', rawType: 'DATETIME', baseType: 'DATETIME', length: null,
                    precision: null, scale: null, nullable: false, isPrimary: false,
                    isAutoIncrement: false, isUnsigned: false, isUnique: false,
                    default: null, hasDefault: false, onUpdate: null, enumValues: null,
                    characterSet: null, collation: null
                ),
                new SqlColumn(
                    name: 'updated_at', rawType: 'DATETIME', baseType: 'DATETIME', length: null,
                    precision: null, scale: null, nullable: false, isPrimary: false,
                    isAutoIncrement: false, isUnsigned: false, isUnique: false,
                    default: null, hasDefault: false, onUpdate: null, enumValues: null,
                    characterSet: null, collation: null
                ),
                new SqlColumn(
                    name: 'deleted_at', rawType: 'DATETIME', baseType: 'DATETIME', length: null,
                    precision: null, scale: null, nullable: true, isPrimary: false,
                    isAutoIncrement: false, isUnsigned: false, isUnique: false,
                    default: null, hasDefault: false, onUpdate: null, enumValues: null,
                    characterSet: null, collation: null
                ),
            ]
        );

        $blocks = $this->builder->build([$stmt]);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString("\$table->timestamps();", $blocks[0]);
        $this->assertStringContainsString("\$table->softDeletes();", $blocks[0]);
        // Raw created_at/updated_at/deleted_at column definitions should NOT be generated again
        $this->assertStringNotContainsString("\$table->dateTime('created_at')", $blocks[0]);
    }

    /** @test */
    public function it_builds_rename_table_statement()
    {
        $stmt = new SqlStatement(
            type: SqlStatement::TYPE_RENAME_TABLE,
            table: 'old_table',
            raw: 'new_table'
        );

        $blocks = $this->builder->build([$stmt]);

        $this->assertCount(1, $blocks);
        $this->assertEquals("Schema::rename('old_table', 'new_table');", $blocks[0]);
    }

    /** @test */
    public function it_suppresses_empty_no_op_schema_table_blocks()
    {
        // An alter statement without any parsed alterations
        $stmt = new SqlStatement(
            type: SqlStatement::TYPE_ALTER_TABLE,
            table: 'users',
            alterations: []
        );

        $blocks = $this->builder->build([$stmt]);

        // It must filter out the empty block entirely
        $this->assertEmpty($blocks);
    }

    /** @test */
    public function it_degrades_gracefully_using_unprepared_db_fallback_for_unknown_statements()
    {
        $stmt = new SqlStatement(
            type: SqlStatement::TYPE_UNKNOWN,
            table: '',
            raw: 'CREATE EXTENSION IF NOT EXISTS "uuid-ossp";'
        );

        $blocks = $this->builder->build([$stmt]);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('// TODO: review this raw SQL', $blocks[0]);
        $this->assertStringContainsString("DB::unprepared('CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";');", $blocks[0]);
    }

    /** @test */
    public function it_builds_foreign_key_constraints()
    {
        $stmt = new SqlStatement(
            type: SqlStatement::TYPE_CREATE_TABLE,
            table: 'posts',
            foreignKeys: [
                new SqlForeignKey(
                    name: 'posts_user_id_fkey',
                    localColumn: 'user_id',
                    referencedTable: 'users',
                    referencedColumn: 'id',
                    onDelete: 'CASCADE',
                    onUpdate: 'RESTRICT'
                )
            ]
        );

        $blocks = $this->builder->build([$stmt]);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString(
            "\$table->foreign('user_id')->references('id')->on('users')->cascade()->restrictOnUpdate();",
            $blocks[0]
        );
    }
}
