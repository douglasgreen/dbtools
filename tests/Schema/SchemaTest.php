<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Schema;

use DouglasGreen\DbTools\Schema\ColumnInfo;
use DouglasGreen\DbTools\Schema\ForeignKey;
use DouglasGreen\DbTools\Schema\TableInfo;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testDetectsIntegerAndUnsignedColumns(): void
    {
        $unsigned = new ColumnInfo('id', 'int', 'int(10) unsigned', false);
        $signed = new ColumnInfo('id', 'int', 'int(11)', false);
        $text = new ColumnInfo('name', 'varchar', 'varchar(255)', true);

        self::assertTrue($unsigned->isInteger());
        self::assertTrue($unsigned->isUnsigned());
        self::assertTrue($signed->isInteger());
        self::assertFalse($signed->isUnsigned());
        self::assertFalse($text->isInteger());
    }

    public function testIntegerTypeMatchIgnoresDisplayWidthButNotSizeOrSign(): void
    {
        $a = new ColumnInfo('customer_id', 'int', 'int(10) unsigned', false);
        $sameWithOtherWidth = new ColumnInfo('customer_id', 'int', 'int(8) unsigned', false);
        $signed = new ColumnInfo('customer_id', 'int', 'int(11)', false);
        $bigger = new ColumnInfo('customer_id', 'bigint', 'bigint(20) unsigned', false);
        $text = new ColumnInfo('customer_id', 'varchar', 'varchar(20)', false);

        self::assertTrue($a->matchesIntegerType($sameWithOtherWidth));
        self::assertFalse($a->matchesIntegerType($signed));
        self::assertFalse($a->matchesIntegerType($bigger));
        self::assertFalse($a->matchesIntegerType($text));
    }

    public function testSingleIntegerPrimaryKeyRequiresOneIntegerColumn(): void
    {
        self::assertSame('id', $this->table(['id'])->singleIntegerPrimaryKey()?->name);
        self::assertNull($this->table(['id', 'created_at'])->singleIntegerPrimaryKey());
        self::assertNull($this->table(['code'])->singleIntegerPrimaryKey());
        self::assertNull($this->table([])->singleIntegerPrimaryKey());
    }

    public function testSelfReferenceIsDetected(): void
    {
        $self = new ForeignKey('categories', 'parent_id', 'categories', 'category_id', true);
        $cross = new ForeignKey('orders', 'customer_id', 'customers', 'customer_id', true);

        self::assertTrue($self->isSelfReference());
        self::assertFalse($cross->isSelfReference());
    }

    /**
     * @param list<string> $primaryKey
     */
    private function table(array $primaryKey): TableInfo
    {
        return new TableInfo('shop', 'widgets', 1024, 10, [
            'id' => new ColumnInfo('id', 'int', 'int(10) unsigned', false),
            'code' => new ColumnInfo('code', 'varchar', 'varchar(20)', false),
            'created_at' => new ColumnInfo('created_at', 'datetime', 'datetime', true),
        ], $primaryKey);
    }
}
