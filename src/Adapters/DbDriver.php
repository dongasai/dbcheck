<?php

namespace Dongasai\Dbcheck\Adapters;

use Illuminate\Support\Facades\DB;

/**
 * 数据库类型判断：检查连接的数据库类型，选择合适的驱动适配器。
 *
 * 选择优先级：
 *  1. 显式参数覆盖（adapter() 的 $override，适配器类名或驱动别名）；
 *  2. 运行期覆盖（override() 设置，作用于本次进程内全部后续解析；命令行为 --adapter=）；
 *  3. 按连接自动探测（检查判断）：mysql/mariadb → Mysql8Adapter（json 原生类型）；
 *     pgsql/postgres → PgsqlAdapter（仅存在性比对）；sqlite → SqliteAdapter（存在性比对）。
 *
 * 适配器映射不做配置化硬编码——类型由检查判断得出，例外由运行参数覆盖。
 * MySQL 5.7 等无原生 JSON 的旧版本，请用 mysql57 别名或 MysqlAdapter 类名显式覆盖（json 落 longText）。
 * 未知别名/类名显式抛 InvalidArgumentException，不静默回落。
 * adapterClass() 只解析类名不实例化，供诊断命令展示「当前连接会用哪个适配器」。
 */
class DbDriver
{
    /**
     * 运行期覆盖值（适配器类名或驱动别名；null = 未覆盖）。
     */
    protected static ?string $runtimeOverride = null;

    /**
     * 驱动别名 → 适配器类名（'mysql'/'mariadb' 为 Laravel 驱动名，自动探测默认 Mysql8Adapter；
     * 5.7 等旧版本用 'mysql57' 或 MysqlAdapter 类名显式指定）。
     *
     * @var array<string, class-string<DatabaseAdapter>>
     */
    protected static array $aliases = [
        'mysql' => Mysql8Adapter::class,
        'mysql8' => Mysql8Adapter::class,
        'mariadb' => Mysql8Adapter::class,
        'mysql57' => MysqlAdapter::class,
        'pgsql' => PgsqlAdapter::class,
        'postgres' => PgsqlAdapter::class,
        'sqlite' => SqliteAdapter::class,
    ];

    /**
     * 探测连接的数据库类型（Laravel 驱动名，小写）。
     */
    public static function detect(?string $connection): string
    {
        return strtolower((string) DB::connection($connection)->getDriverName());
    }

    /**
     * 设置运行期适配器覆盖（类名或驱动别名；传 null 清除）。
     *
     * 作用于本次进程内全部后续解析（命令行经 --adapter= 进入），直至清除。
     */
    public static function override(?string $aliasOrClass): void
    {
        static::$runtimeOverride = ($aliasOrClass === null || $aliasOrClass === '') ? null : $aliasOrClass;
    }

    /**
     * 别名/类名是否为可识别的适配器。
     */
    public static function supports(string $aliasOrClass): bool
    {
        $class = static::$aliases[strtolower($aliasOrClass)] ?? $aliasOrClass;

        return is_subclass_of($class, DatabaseAdapter::class);
    }

    /**
     * 按连接选择驱动适配器（选择优先级见类注释）。
     *
     * @param string|null $connection 连接名（null = 默认连接）
     * @param string|null $override 适配器类名或驱动别名，非空时直接采用（显式参数覆盖）
     */
    public static function adapter(?string $connection, ?string $override = null): DatabaseAdapter
    {
        if ($override !== null && $override !== '') {
            return static::makeByAlias($override);
        }

        if (static::$runtimeOverride !== null) {
            return static::makeByAlias(static::$runtimeOverride);
        }

        return static::makeByAlias(static::detect($connection));
    }

    /**
     * 按连接解析将使用的适配器类名（不实例化；选择优先级同 adapter()）。
     *
     * @param string|null $connection 连接名（null = 默认连接）
     * @param string|null $override 适配器类名或驱动别名，非空时直接采用（显式参数覆盖）
     * @return class-string<DatabaseAdapter>
     */
    public static function adapterClass(?string $connection, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return static::resolveClass($override);
        }

        if (static::$runtimeOverride !== null) {
            return static::resolveClass(static::$runtimeOverride);
        }

        return static::resolveClass(static::detect($connection));
    }

    /**
     * 由适配器类名或驱动别名实例化适配器；未知值显式抛 InvalidArgumentException。
     *
     * @param class-string<DatabaseAdapter>|string $aliasOrClass
     */
    protected static function makeByAlias(string $aliasOrClass): DatabaseAdapter
    {
        $class = static::resolveClass($aliasOrClass);

        return new $class();
    }

    /**
     * 别名/类名解析为适配器类名；未知值显式抛 InvalidArgumentException。
     *
     * @param string $aliasOrClass
     * @return class-string<DatabaseAdapter>
     */
    protected static function resolveClass(string $aliasOrClass): string
    {
        $class = static::$aliases[strtolower($aliasOrClass)] ?? $aliasOrClass;

        if (!is_subclass_of($class, DatabaseAdapter::class)) {
            throw new \InvalidArgumentException('未知的数据库驱动适配器: ' . $aliasOrClass);
        }

        return $class;
    }
}
