<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;

/**
 * 删除验证夹具（demo_cleanup）：声明 2 行必需数据 + 开启 deleteExtra。
 *
 * 未在声明中的行视为多余数据，修复 = 删除；分组默认归入 DbCheckGroup::DELETE。
 */
class DemoCleanupDataCheck extends AbstractTableDataCheck
{
    public static function table(): string
    {
        return 'demo_cleanup';
    }

    protected static function rows(): array
    {
        return [
            'cleanup_row1' => [
                'where' => ['code' => 'keep1'],
                'insert' => ['title' => '保留行一'],
            ],
            'cleanup_row2' => [
                'where' => ['code' => 'keep2'],
                'insert' => ['title' => '保留行二'],
            ],
        ];
    }

    protected static function deleteExtra(): bool
    {
        return true;
    }
}
