<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;

/**
 * 固定数据夹具：insert_merge 引用其它行的列值（验证预览能解析引用行，不误抛「引用行不存在」）。
 */
class DemoInsertMergeDataCheck extends AbstractTableDataCheck
{
    public static function table(): string
    {
        return 'demo_data';
    }

    protected static function rows(): array
    {
        return [
            'merged_row' => [
                'where' => ['key' => 'row_merge'],
                'insert' => ['title' => '合并默认标题'],
                'insert_merge' => [
                    'title' => ['where' => ['key' => 'row1'], 'value' => 'title'],
                ],
            ],
        ];
    }
}
