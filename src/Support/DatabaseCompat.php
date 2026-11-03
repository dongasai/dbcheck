<?php

namespace Dongasai\Dbcheck\Support;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Illuminate\Support\Facades\DB;

/**
 * 数据库兼容性工具：驱动类型判断 + 跨库兜底写法。
 *
 * 与 Adapters\DbDriver 的分工：
 *  - DbDriver 面向「按连接选适配器」（程序内部用）；
 *  - 本类面向业务/诊断代码，回答「当前是什么库、要不要走兼容写法」。
 *
 * 类型口径取连接的**实际驱动名**（DB::connection()->getDriverName()），
 * 不读 config('database.connections.*.driver')——运行期切换默认连接或自定义连接时以实际连接为准。
 */
class DatabaseCompat
{
    /**
     * 当前连接的驱动名（小写，如 mysql / pgsql / sqlite / sqlsrv / dm）。
     *
     * @param string|null $connection 连接名（null = 默认连接）
     */
    public static function driver(?string $connection = null): string
    {
        return strtolower((string) DB::connection($connection)->getDriverName());
    }

    /**
     * 是否为指定驱动（如 is('pgsql')）。
     */
    public static function is(string $driver, ?string $connection = null): bool
    {
        return static::driver($connection) === strtolower($driver);
    }

    /**
     * 是否 MySQL 系（mysql / mariadb）。
     */
    public static function isMySQL(?string $connection = null): bool
    {
        return in_array(static::driver($connection), ['mysql', 'mariadb'], true);
    }

    /**
     * 是否 PostgreSQL 系（pgsql / postgres / postgresql，兼容 openGauss / Kingbase 等 PG 兼容库）。
     */
    public static function isPostgreSQL(?string $connection = null): bool
    {
        return in_array(static::driver($connection), ['pgsql', 'postgres', 'postgresql'], true);
    }

    /**
     * 是否 SQLite。
     */
    public static function isSqlite(?string $connection = null): bool
    {
        return static::is('sqlite', $connection);
    }

    /**
     * 是否达梦（驱动名 dm）。
     */
    public static function isDM(?string $connection = null): bool
    {
        return static::is('dm', $connection);
    }

    /**
     * 是否 SQL Server（sqlsrv / mssql）。
     */
    public static function isSqlServer(?string $connection = null): bool
    {
        return in_array(static::driver($connection), ['sqlsrv', 'mssql'], true);
    }

    /**
     * 当前连接将使用的驱动适配器类名；无可用适配器时返回 null。
     *
     * @param string|null $connection 连接名（null = 默认连接）
     * @return class-string|null
     */
    public static function adapterClass(?string $connection = null): ?string
    {
        try {
            return DbDriver::adapterClass($connection);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 行是否存在（判重读）。
     *
     * 预览上下文中命中 PreviewContext 缓存：preview() 在 pretend 之前预热判重结果，
     * 「已存在的行不再预览出 INSERT」，与真实修复同口径；非预览上下文直接查库、不缓存。
     *
     * @param array<string, mixed> $where 判重条件
     */
    public static function rowExists(string $table, array $where, ?string $connection = null): bool
    {
        return PreviewContext::remember(
            'exists.' . ($connection ?? 'default') . '.' . $table . '.' . json_encode($where),
            function () use ($table, $where, $connection) {
                return DB::connection($connection)->table($table)->where($where)->exists();
            }
        );
    }

    /**
     * 兜底复位 pretend 状态。
     *
     * Laravel 8 的 `Connection::pretend()` 没有 try/finally：回调抛异常时 pretending 会保持在 true，
     * 同一进程内后续 SELECT 恒返回空、写操作全部静默跳过（预览抛错 → fix 不写 → 假「修复成功」）。
     * 各 preview() 在 finally 里调用本方法复位；正常路径 pretend() 已自行复位，这里是 no-op。
     * 属性名按连接实际声明探测（不写死版本差异），找不到属性时不做任何事。
     */
    public static function stopPretending(?string $connection = null): void
    {
        $conn = DB::connection($connection);
        if (!method_exists($conn, 'pretending') || !$conn->pretending()) {
            return;
        }

        $reflection = new \ReflectionClass($conn);
        if (!$reflection->hasProperty('pretending')) {
            return;
        }

        $property = $reflection->getProperty('pretending');
        $property->setAccessible(true);
        $property->setValue($conn, false);
    }

    /**
     * 补插一行（已存在则跳过）。
     *
     * 不用 insertOrIgnore：PG 系（GaussDB/openGauss）会编译成不带冲突目标的
     * 「on conflict do nothing」，部分库不支持；各表唯一键也不统一，无法统一指定冲突目标，
     * 因此统一改为「先判重、再插入」（判重读经 rowExists()，预览上下文内可预热）。
     *
     * @param array<string, mixed> $where 判重条件（业务主键/唯一键）
     * @param array<string, mixed> $data 插入数据（数组值需调用方自行 json_encode）
     * @return bool 是否实际插入
     */
    public static function insertIfMissing(string $table, array $where, array $data, ?string $connection = null): bool
    {
        if (static::rowExists($table, $where, $connection)) {
            return false;
        }

        DB::connection($connection)->table($table)->insert($data);

        return true;
    }
}
