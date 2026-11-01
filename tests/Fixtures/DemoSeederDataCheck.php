<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;
use Dongasai\Dbcheck\Support\DbCheckGroup;

/**
 * 播种分组夹具（demo_seed）：声明 2 行种子数据，显式归入 DbCheckGroup::SEEDER。
 *
 * 声明制分组：不随 dbcheck:check 默认执行，由 dbcheck:seed 显式播种。
 */
class DemoSeederDataCheck extends AbstractTableDataCheck
{
    public static function table(): string
    {
        return 'demo_seed';
    }

    public static function group(): ?string
    {
        return DbCheckGroup::SEEDER;
    }

    protected static function rows(): array
    {
        return [
            'seed_row1' => [
                'where' => ['key' => 'seed1'],
                'insert' => ['title' => '种子行一'],
                'item' => 'demo_seed（key=seed1）',
            ],
            'seed_row2' => [
                'where' => ['key' => 'seed2'],
                'insert' => ['title' => '种子行二'],
                'item' => 'demo_seed（key=seed2）',
            ],
        ];
    }
}
