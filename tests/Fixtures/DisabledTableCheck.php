<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Checks\AbstractTableCheck;
use Dongasai\Dbcheck\Support\ColumnType;

/**
 * 被 $enabled=false 关闭的检查器夹具（自动发现应跳过）。
 */
class DisabledTableCheck extends AbstractTableCheck
{
    public static bool $enabled = false;

    public static function table(): string
    {
        return 'disabled_table';
    }

    public static function columns(): array
    {
        return [
            'id' => ['type' => ColumnType::ID],
        ];
    }

    public static function indexes(): array
    {
        return [];
    }
}
