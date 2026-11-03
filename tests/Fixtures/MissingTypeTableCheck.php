<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableCheck;

/**
 * 列声明缺 type 键的夹具（验证不会被静默按 string 建列，而是显式抛 LogicException）。
 */
class MissingTypeTableCheck extends AbstractTableCheck
{
    public static function table(): string
    {
        return 'demo_missing_type';
    }

    public static function columns(): array
    {
        return [
            'id' => [],
            'name' => ['length' => 10],
        ];
    }

    public static function indexes(): array
    {
        return [];
    }
}
