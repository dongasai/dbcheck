<?php

namespace Dongasai\Dbcheck\Adapters;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * 适配器工厂：按连接选择驱动适配器。
 *
 * 优先级：
 *  1. config('dbcheck.adapters.<连接名>') 显式覆盖（默认连接键为 'default'）；
 *  2. 按 Laravel 驱动自动选择：
 *     - mysql / mariadb → Mysql8Adapter（现代默认，json 原生类型）
 *     - pgsql / postgres → PgsqlAdapter
 */
class AdapterFactory
{
    /**
     * @return DatabaseAdapter
     */
    public static function make(?string $connection): DatabaseAdapter
    {
        $key = $connection ?? 'default';
        $override = Config::get('dbcheck.adapters.' . $key);

        if (is_string($override) && class_exists($override)) {
            return new $override();
        }

        $driver = DB::connection($connection)->getDriverName();

        switch ($driver) {
            case 'pgsql':
            case 'postgres':
                return new PgsqlAdapter();
            case 'mysql':
            case 'mariadb':
            default:
                return new Mysql8Adapter();
        }
    }
}
