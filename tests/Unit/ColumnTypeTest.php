<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Support\ColumnType;
use PHPUnit\Framework\TestCase;

/**
 * ColumnType 常量表测试。
 */
class ColumnTypeTest extends TestCase
{
    public function testAllContainsEveryConstantInOrder(): void
    {
        $expected = [
            'id',
            'bigInteger',
            'unsignedBigInteger',
            'integer',
            'unsignedInteger',
            'smallInteger',
            'unsignedSmallInteger',
            'tinyInteger',
            'unsignedTinyInteger',
            'boolean',
            'string',
            'char',
            'text',
            'mediumText',
            'longText',
            'json',
            'date',
            'time',
            'timestamp',
            'datetime',
            'decimal',
            'enum',
        ];

        $this->assertSame($expected, ColumnType::all());
    }

    public function testDefaultIsString(): void
    {
        $this->assertSame(ColumnType::STRING, ColumnType::DEFAULT);
    }

    public function testAllValuesAreUnique(): void
    {
        $all = ColumnType::all();

        $this->assertSame(count($all), count(array_unique($all)));
    }
}
