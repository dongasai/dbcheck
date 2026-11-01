<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableFieldCheck;

/**
 * 字段明细检查夹具（list + 每项带 key 写法）。
 */
class DemoFieldCheck extends AbstractTableFieldCheck
{
    public static function table(): string
    {
        return 'demo_field';
    }

    protected static function rows(): array
    {
        return [
            'demo_btn' => [
                'where' => ['key' => 'menu1'],
                'columns' => [
                    'title' => '锁定标题',
                    'btn_list' => [
                        ['key' => 'a', 'name' => 'A', 'checked' => false],
                        ['key' => 'b', 'name' => 'B', 'checked' => false],
                    ],
                ],
            ],
        ];
    }
}
