<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;

/**
 * 分组检查夹具（group = testdata，默认不执行）。
 */
class TestdataDataCheck extends AbstractTableDataCheck
{
    public static function table(): string
    {
        return 'demo_data';
    }

    public static function group(): ?string
    {
        return 'testdata';
    }

    protected static function rows(): array
    {
        return [
            'test_row' => [
                'where' => ['key' => 'test'],
                'insert' => ['title' => '测试数据'],
                'item' => 'demo_data（key=test）',
                'problem' => '测试数据缺失',
                'fix' => '插入测试数据',
            ],
        ];
    }
}
