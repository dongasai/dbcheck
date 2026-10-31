<?php

namespace Dongasai\Dbcheck\Adapters;

/**
 * 适配器工厂：按连接返回驱动适配器的稳定入口。
 *
 * 数据库类型判断与选择逻辑（显式参数 > 运行期覆盖 > 自动探测）由 DbDriver 实现，
 * 本类仅保持检查器调用面稳定，可传 $override 显式指定适配器（类名或驱动别名）。
 */
class AdapterFactory
{
    /**
     * 按连接选择驱动适配器。
     *
     * @param string|null $connection 连接名（null = 默认连接）
     * @param string|null $override 适配器类名或驱动别名，非空时直接采用（显式参数覆盖）
     * @return DatabaseAdapter
     */
    public static function make(?string $connection, ?string $override = null): DatabaseAdapter
    {
        return DbDriver::adapter($connection, $override);
    }
}
