<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableFieldCheck;

/**
 * 字段明细夹具：标量锁定为 null（验证 null 声明可检查、可预览、可修复）。
 */
class DemoNullFieldCheck extends AbstractTableFieldCheck
{
    public static function table(): string
    {
        return 'demo_field';
    }

    protected static function rows(): array
    {
        return [
            'demo_null' => [
                'where' => ['key' => 'menu1'],
                'columns' => [
                    'title' => null,
                ],
            ],
        ];
    }
}
