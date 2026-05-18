<?php

use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatement;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlColumn;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlForeignKey;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlToSchemaBuilder;

beforeEach(function () {
    $this->builder = new SqlToSchemaBuilder();
});

it('builds create table with columns and autoincrement', function () {
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

    expect($blocks)->toHaveCount(1);
    expect($blocks[0])->toContain("Schema::create('users', function (Blueprint \$table) {")
                      ->toContain("\$table->increments('id');")
                      ->toContain("\$table->string('email')->nullable()->unique();");
});

it('simplifies timestamps and softdeletes in create table', function () {
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

    expect($blocks)->toHaveCount(1);
    expect($blocks[0])->toContain("\$table->timestamps();")
                      ->toContain("\$table->softDeletes();")
                      ->not->toContain("\$table->dateTime('created_at')");
});

it('builds rename table statement', function () {
    $stmt = new SqlStatement(
        type: SqlStatement::TYPE_RENAME_TABLE,
        table: 'old_table',
        raw: 'new_table'
    );

    $blocks = $this->builder->build([$stmt]);

    expect($blocks)->toHaveCount(1);
    expect($blocks[0])->toBe("Schema::rename('old_table', 'new_table');");
});

it('suppresses empty no op schema table blocks', function () {
    $stmt = new SqlStatement(
        type: SqlStatement::TYPE_ALTER_TABLE,
        table: 'users',
        alterations: []
    );

    $blocks = $this->builder->build([$stmt]);

    expect($blocks)->toBeEmpty();
});

it('degrades gracefully using unprepared db fallback for unknown statements', function () {
    $stmt = new SqlStatement(
        type: SqlStatement::TYPE_UNKNOWN,
        table: '',
        raw: 'CREATE EXTENSION IF NOT EXISTS "uuid-ossp";'
    );

    $blocks = $this->builder->build([$stmt]);

    expect($blocks)->toHaveCount(1);
    expect($blocks[0])->toContain('// TODO: review this raw SQL')
                      ->toContain("DB::unprepared('CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";');");
});

it('builds foreign key constraints', function () {
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

    expect($blocks)->toHaveCount(1);
    expect($blocks[0])->toContain("\$table->foreign('user_id')->references('id')->on('users')->cascade()->restrictOnUpdate();");
});
