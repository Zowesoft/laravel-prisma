<?php

use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatementParser;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlStatement;
use Zowesoft\LaravelPrisma\Services\LaravelMode\SqlAlteration;

beforeEach(function () {
    $this->parser = new SqlStatementParser();
});

it('parses create table statement', function () {
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

    expect($statements)->toHaveCount(1);
    $stmt = $statements[0];

    expect($stmt->type)->toBe(SqlStatement::TYPE_CREATE_TABLE)
                       ->and($stmt->table)->toBe('users')
                       ->and($stmt->columns)->toHaveCount(5);

    $idCol = $stmt->columns[0];
    expect($idCol->name)->toBe('id')
                        ->and($idCol->baseType)->toBe('INTEGER')
                        ->and($idCol->isPrimary)->toBeTrue()
                        ->and($idCol->isAutoIncrement)->toBeTrue()
                        ->and($idCol->nullable)->toBeFalse();

    $emailCol = $stmt->columns[2];
    expect($emailCol->name)->toBe('email')
                           ->and($emailCol->baseType)->toBe('VARCHAR')
                           ->and($emailCol->length)->toBe(255)
                           ->and($emailCol->nullable)->toBeTrue();

    $roleCol = $stmt->columns[3];
    expect($roleCol->name)->toBe('role')
                         ->and($roleCol->baseType)->toBe('ENUM')
                         ->and($roleCol->enumValues)->toBe("'admin','user'")
                         ->and($roleCol->default)->toBe('user')
                         ->and($roleCol->hasDefault)->toBeTrue();

    $createdAtCol = $stmt->columns[4];
    expect($createdAtCol->name)->toBe('created_at')
                               ->and($createdAtCol->baseType)->toBe('DATETIME')
                               ->and($createdAtCol->default)->toBe('CURRENT_TIMESTAMP');
});

it('parses alter table statement with column additions and drops', function () {
    $sql = '
        ALTER TABLE "users" ADD COLUMN "bio" TEXT NULL;
        ALTER TABLE "users" DROP COLUMN "remember_token";
    ';

    $statements = $this->parser->parse($sql);

    expect($statements)->toHaveCount(2);

    $stmt1 = $statements[0];
    expect($stmt1->type)->toBe(SqlStatement::TYPE_ALTER_TABLE)
                        ->and($stmt1->table)->toBe('users')
                        ->and($stmt1->alterations)->toHaveCount(1);
    $alt1 = $stmt1->alterations[0];
    expect($alt1->operation)->toBe(SqlAlteration::OP_ADD_COLUMN)
                            ->and($alt1->column->name)->toBe('bio')
                            ->and($alt1->column->baseType)->toBe('TEXT');

    $stmt2 = $statements[1];
    expect($stmt2->type)->toBe(SqlStatement::TYPE_ALTER_TABLE)
                        ->and($stmt2->table)->toBe('users')
                        ->and($stmt2->alterations)->toHaveCount(1);
    $alt2 = $stmt2->alterations[0];
    expect($alt2->operation)->toBe(SqlAlteration::OP_DROP_COLUMN)
                            ->and($alt2->dropName)->toBe('remember_token');
});

it('parses foreign key constraints', function () {
    $sql = '
        CREATE TABLE "posts" (
            "id" INT NOT NULL PRIMARY KEY,
            "user_id" INT NOT NULL,
            CONSTRAINT "posts_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE CASCADE
        );
    ';

    $statements = $this->parser->parse($sql);
    expect($statements)->toHaveCount(1);

    $stmt = $statements[0];
    expect($stmt->foreignKeys)->toHaveCount(1);

    $fk = $stmt->foreignKeys[0];
    expect($fk->name)->toBe('posts_user_id_fkey')
                     ->and($fk->localColumn)->toBe('user_id')
                     ->and($fk->referencedTable)->toBe('users')
                     ->and($fk->referencedColumn)->toBe('id')
                     ->and($fk->onDelete)->toBe('CASCADE')
                     ->and($fk->onUpdate)->toBe('CASCADE');
});

it('parses sqlite table rename statements', function () {
    $sql = 'ALTER TABLE "new_users" RENAME TO "users";';

    $statements = $this->parser->parse($sql);
    expect($statements)->toHaveCount(1);

    $stmt = $statements[0];
    expect($stmt->type)->toBe(SqlStatement::TYPE_RENAME_TABLE)
                       ->and($stmt->table)->toBe('new_users')
                       ->and($stmt->raw)->toBe('users');
});

it('parses standalone create index statements', function () {
    $sql = 'CREATE UNIQUE INDEX "users_email_key" ON "users"("email");';

    $statements = $this->parser->parse($sql);
    expect($statements)->toHaveCount(1);

    $stmt = $statements[0];
    expect($stmt->type)->toBe(SqlStatement::TYPE_CREATE_INDEX)
                       ->and($stmt->table)->toBe('users')
                       ->and($stmt->indexes)->toHaveCount(1);

    $idx = $stmt->indexes[0];
    expect($idx->name)->toBe('users_email_key')
                      ->and($idx->table)->toBe('users')
                      ->and($idx->isUnique)->toBeTrue()
                      ->and($idx->columns)->toBe(['email']);
});

it('parses drop table statements', function () {
    $sql = 'DROP TABLE "users";';

    $statements = $this->parser->parse($sql);
    expect($statements)->toHaveCount(1);

    $stmt = $statements[0];
    expect($stmt->type)->toBe(SqlStatement::TYPE_DROP_TABLE)
                       ->and($stmt->table)->toBe('users');
});
