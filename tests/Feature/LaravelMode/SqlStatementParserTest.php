<?php

namespace Zowesoft\LaravelPrisma\Tests\Feature\LaravelMode;

use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatementParser;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatement;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlAlteration;
use Zowesoft\LaravelPrisma\Tests\TestCase;

class SqlStatementParserTest extends TestCase
{
    private SqlStatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SqlStatementParser();
    }

    /** @test */
    public function it_parses_create_table_statement()
    {
        $sql = '
            -- CreateTable
            CREATE TABLE "users" (
                "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                "name" TEXT NOT NULL,
                "email" VARCHAR(255) NULL,
                "role" ENUM(\'admin\',\'user\') DEFAULT \'user\',
                "created_at" DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ';

        $statements = $this->parser->parse($sql);

        $this->assertCount(1, $statements);
        $stmt = $statements[0];

        $this->assertEquals(SqlStatement::TYPE_CREATE_TABLE, $stmt->type);
        $this->assertEquals('users', $stmt->table);
        $this->assertCount(5, $stmt->columns);

        // Verify id column
        $idCol = $stmt->columns[0];
        $this->assertEquals('id', $idCol->name);
        $this->assertEquals('INTEGER', $idCol->baseType);
        $this->assertTrue($idCol->isPrimary);
        $this->assertTrue($idCol->isAutoIncrement);
        $this->assertFalse($idCol->nullable);

        // Verify email column
        $emailCol = $stmt->columns[2];
        $this->assertEquals('email', $emailCol->name);
        $this->assertEquals('VARCHAR', $emailCol->baseType);
        $this->assertEquals(255, $emailCol->length);
        $this->assertTrue($emailCol->nullable);

        // Verify role enum column
        $roleCol = $stmt->columns[3];
        $this->assertEquals('role', $roleCol->name);
        $this->assertEquals('ENUM', $roleCol->baseType);
        $this->assertEquals("'admin','user'", $roleCol->enumValues);
        $this->assertEquals('user', $roleCol->default);
        $this->assertTrue($roleCol->hasDefault);

        // Verify created_at column
        $createdAtCol = $stmt->columns[4];
        $this->assertEquals('created_at', $createdAtCol->name);
        $this->assertEquals('DATETIME', $createdAtCol->baseType);
        $this->assertEquals('CURRENT_TIMESTAMP', $createdAtCol->default);
    }

    /** @test */
    public function it_parses_alter_table_statement_with_column_additions_and_drops()
    {
        $sql = '
            ALTER TABLE "users" ADD COLUMN "bio" TEXT NULL;
            ALTER TABLE "users" DROP COLUMN "remember_token";
        ';

        $statements = $this->parser->parse($sql);

        $this->assertCount(2, $statements);

        // 1st ALTER statement: Add column
        $stmt1 = $statements[0];
        $this->assertEquals(SqlStatement::TYPE_ALTER_TABLE, $stmt1->type);
        $this->assertEquals('users', $stmt1->table);
        $this->assertCount(1, $stmt1->alterations);
        $alt1 = $stmt1->alterations[0];
        $this->assertEquals(SqlAlteration::OP_ADD_COLUMN, $alt1->operation);
        $this->assertEquals('bio', $alt1->column->name);
        $this->assertEquals('TEXT', $alt1->column->baseType);

        // 2nd ALTER statement: Drop column
        $stmt2 = $statements[1];
        $this->assertEquals(SqlStatement::TYPE_ALTER_TABLE, $stmt2->type);
        $this->assertEquals('users', $stmt2->table);
        $this->assertCount(1, $stmt2->alterations);
        $alt2 = $stmt2->alterations[0];
        $this->assertEquals(SqlAlteration::OP_DROP_COLUMN, $alt2->operation);
        $this->assertEquals('remember_token', $alt2->dropName);
    }

    /** @test */
    public function it_parses_foreign_key_constraints()
    {
        $sql = '
            CREATE TABLE "posts" (
                "id" INT NOT NULL PRIMARY KEY,
                "user_id" INT NOT NULL,
                CONSTRAINT "posts_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE CASCADE
            );
        ';

        $statements = $this->parser->parse($sql);
        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $this->assertCount(1, $stmt->foreignKeys);

        $fk = $stmt->foreignKeys[0];
        $this->assertEquals('posts_user_id_fkey', $fk->name);
        $this->assertEquals('user_id', $fk->localColumn);
        $this->assertEquals('users', $fk->referencedTable);
        $this->assertEquals('id', $fk->referencedColumn);
        $this->assertEquals('CASCADE', $fk->onDelete);
        $this->assertEquals('CASCADE', $fk->onUpdate);
    }

    /** @test */
    public function it_parses_sqlite_table_rename_statements()
    {
        $sql = 'ALTER TABLE "new_users" RENAME TO "users";';

        $statements = $this->parser->parse($sql);
        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $this->assertEquals(SqlStatement::TYPE_RENAME_TABLE, $stmt->type);
        $this->assertEquals('new_users', $stmt->table);
        $this->assertEquals('users', $stmt->raw); // Stores new table name here
    }

    /** @test */
    public function it_parses_standalone_create_index_statements()
    {
        $sql = 'CREATE UNIQUE INDEX "users_email_key" ON "users"("email");';

        $statements = $this->parser->parse($sql);
        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $this->assertEquals(SqlStatement::TYPE_CREATE_INDEX, $stmt->type);
        $this->assertEquals('users', $stmt->table);
        $this->assertCount(1, $stmt->indexes);

        $idx = $stmt->indexes[0];
        $this->assertEquals('users_email_key', $idx->name);
        $this->assertEquals('users', $idx->table);
        $this->assertTrue($idx->isUnique);
        $this->assertEquals(['email'], $idx->columns);
    }

    /** @test */
    public function it_parses_drop_table_statements()
    {
        $sql = 'DROP TABLE "users";';

        $statements = $this->parser->parse($sql);
        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $this->assertEquals(SqlStatement::TYPE_DROP_TABLE, $stmt->type);
        $this->assertEquals('users', $stmt->table);
    }
}
