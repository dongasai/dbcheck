<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;

/**
 * 固定数据检查夹具（demo_data），带 problem 文案。
 */
class DemoDataCheck extends AbstractTableDataCheck
{
    public static function table(): string
    {
        return 'demo_data';
    }

    protected static function rows(): array
    {
        return [
            'demo_row' => [
                'where' => ['key' => 'row1'],
                'insert' => ['title' => '默认标题'],
                'item' => 'demo_data（key=row1）',
                'problem' => '菜单缺失，前端无入口',
                'fix' => '插入 demo_data 行',
            ],
        ];
    }
}
