<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

/**
 * 索引声明漏写 columns 键（裸列数组形态）的夹具。
 *
 * 期望：declaredIndexes() 显式抛 LogicException——否则 check() 永远报「索引缺失」，
 * fix() 会生成 `create index ... ()` 这种 SQL 语法错误，该表永远修不好。
 */
class BadColumnsIndexTableCheck extends DemoTableCheck
{
    public static function indexes(): array
    {
        return [
            'demo_table_status_index' => ['status'],
        ];
    }
}
