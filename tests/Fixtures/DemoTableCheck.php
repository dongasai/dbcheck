<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableCheck;
use Dongasai\Dbcheck\Support\ColumnType;

/**
 * 表结构检查夹具（demo_table）。
 */
class DemoTableCheck extends AbstractTableCheck
{
    public static function table(): string
    {
        return 'demo_table';
    }

    public static function columns(): array
    {
        return [
            'id' => ['type' => ColumnType::ID],
            'name' => ['type' => ColumnType::STRING, 'length' => 50, 'default' => ''],
            'status' => ['type' => ColumnType::UNSIGNED_TINY_INTEGER, 'default' => 1],
            'created_at' => ['type' => ColumnType::TIMESTAMP, 'nullable' => true],
        ];
    }

    public static function indexes(): array
    {
        return [
            'demo_table_status_index' => ['columns' => ['status']],
        ];
    }
}
