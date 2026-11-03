<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

/**
 * 旧 list + name 形态的 indexes() 声明（迁移漏改形态的典型错误）。
 *
 * 期望：check() 显式抛 LogicException，而不是把整数下标当索引名建出名为 "0" 的索引。
 */
class BadIndexTableCheck extends DemoTableCheck
{
    public static function indexes(): array
    {
        return [
            ['name' => 'demo_table_status_index', 'columns' => ['status']],
        ];
    }
}
