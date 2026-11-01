<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;

/**
 * 固定数据检查夹具（demo_data），声明 timestamps=false。
 */
class DemoNoTimestampsDataCheck extends AbstractTableDataCheck
{
    public static function table(): string
    {
        return 'demo_data';
    }

    protected static function rows(): array
    {
        return [
            'demo_row_no_ts' => [
                'where' => ['key' => 'row2'],
                'insert' => ['title' => '不带时间戳'],
                'item' => 'demo_data（key=row2）',
                'problem' => '缺行',
                'fix' => '插入行',
                'timestamps' => false,
            ],
        ];
    }
}
