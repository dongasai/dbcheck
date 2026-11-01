<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableFieldCheck;

/**
 * 字段明细检查夹具（map: itemKey => 定义 写法）。
 */
class DemoMapFieldCheck extends AbstractTableFieldCheck
{
    public static function table(): string
    {
        return 'demo_field';
    }

    protected static function rows(): array
    {
        return [
            'demo_map' => [
                'where' => ['key' => 'menu2'],
                'columns' => [
                    'btn_list' => [
                        'a' => ['key' => 'a', 'name' => 'A'],
                        'b' => ['key' => 'b', 'name' => 'B'],
                    ],
                ],
            ],
        ];
    }
}
