<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Contracts\DbCheckInterface;

/**
 * 直接实现接口、未继承三基类的检查器夹具（grouped() 应抛 LogicException）。
 */
class DirectCheck implements DbCheckInterface
{
    public static function moduleName(): string
    {
        return 'direct';
    }

    public static function check(): array
    {
        return [];
    }

    public static function fix(string $key): void
    {
    }

    public static function preview(string $key): array
    {
        return [];
    }
}
