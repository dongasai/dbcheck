<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * 字段明细检查基类（显式声明的字段：标量值是否正确、JSON 数组项是否齐全）。
 *
 * 只校验 rows() 里显式声明的字段并修复错误值，未声明的行与列一概不管——
 * 不存在「全字段锁定」的隐式行为。声明独立于 DataCheck（少量重复，换取两边改动互不影响）。
 *
 *  - 标量不一致报 field.<标识>.<列>，修复回写声明值；
 *  - 数组列缺项报 item.<标识>.<列>.<项key>，修复只补缺失项，已存项与运行期状态（如 checked）保留；
 *  - 行本身不存在时跳过，由 DataCheck 报缺行；fix() 拒绝未声明的行/列/项，抛 LogicException。
 */
abstract class AbstractTableFieldCheck implements DbCheckInterface
{
    /**
     * 是否启用该检查器。
     */
    public static bool $enabled = true;

    /**
     * 所属分组（分组名由业务自行定义，框架不预置）。
     *
     *  - 返回 null：常规检查，dbcheck:check 默认执行；
     *  - 返回任意字符串（如 'testdata'）：分组检查，默认不执行，
     *    需 dbcheck:check --group=<分组名> 显式触发。
     */
    public static function group(): ?string
    {
        return null;
    }

    /**
     * 表名。
     */
    abstract public static function table(): string;

    /**
     * 字段声明。
     *
     * 每项：
     *  - where：定位行
     *  - columns：列名 => 标量（锁定为此值）或 数组（JSON 列，校验声明项齐全）
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected static function rows(): array;

    /**
     * 使用的数据库连接名（null = 默认连接）。
     */
    protected static function connectionName(): ?string
    {
        return null;
    }

    /**
     * 展示名（默认取表名）。
     */
    public static function moduleName(): string
    {
        return static::table();
    }

    /**
     * 表是否存在（表缺失时字段检查返回空，由表结构检查统一报缺表，避免重复报错与查询异常）。
     */
    protected static function hasTable(): bool
    {
        return Schema::connection(static::connectionName())->hasTable(static::table());
    }

    /**
     * 字段明细检查。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(): array
    {
        // 表缺失时后置检查一律返回空：缺表由表结构检查报一项，避免这里 SELECT 抛异常
        if (!static::hasTable()) {
            return [];
        }

        $issues = [];

        foreach (static::rows() as $key => $def) {
            $where = (array) ($def['where'] ?? []);
            $row = static::findRow($where);
            if ($row === null) {
                continue;
            }

            foreach ((array) ($def['columns'] ?? []) as $col => $expected) {
                $actualVal = $row->{$col};

                if (is_array($expected)) {
                    $arr = is_string($actualVal) ? json_decode($actualVal, true) : (array) $actualVal;
                    $arr = is_array($arr) ? $arr : [];
                    foreach ($expected as $itemKey => $itemDef) {
                        if (!array_key_exists($itemKey, $arr)) {
                            $issues[] = [
                                'key' => "item.{$key}.{$col}.{$itemKey}",
                                'type' => 'item',
                                'message' => "字段 {$col} 缺项 {$itemKey}",
                                'optional' => false,
                                'repair' => "补项 {$itemKey}",
                            ];
                        }
                    }
                    continue;
                }

                if (!static::scalarEquals($actualVal, $expected)) {
                    $issues[] = [
                        'key' => "field.{$key}.{$col}",
                        'type' => 'field',
                        'message' => "字段 {$col} 值不一致：实际 " . var_export($actualVal, true)
                            . ' ≠ 期望 ' . var_export($expected, true),
                        'optional' => false,
                        'repair' => "回写 {$col} = " . var_export($expected, true),
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * 修复字段明细项。未知 key 抛异常。
     */
    public static function fix(string $key): void
    {
        $conn = static::connectionName();
        $table = static::table();

        if (substr($key, 0, 6) === 'field.') {
            [, $rowKey, $col] = explode('.', $key, 3);
            $def = static::rows()[$rowKey] ?? null;
            if ($def === null) {
                throw new LogicException('未知行: ' . $rowKey);
            }
            if (!isset($def['columns'][$col]) || is_array($def['columns'][$col])) {
                throw new LogicException('列 ' . $col . ' 非标量或已声明');
            }
            $expected = $def['columns'][$col];
            DB::connection($conn)->table($table)->where((array) ($def['where'] ?? []))->update([$col => $expected]);

            return;
        }

        if (substr($key, 0, 5) === 'item.') {
            [, $rowKey, $col, $itemKey] = explode('.', $key, 4);
            $def = static::rows()[$rowKey] ?? null;
            if ($def === null) {
                throw new LogicException('未知行: ' . $rowKey);
            }
            if (!isset($def['columns'][$col]) || !is_array($def['columns'][$col])) {
                throw new LogicException('列 ' . $col . ' 非数组');
            }
            if (!isset($def['columns'][$col][$itemKey])) {
                throw new LogicException('未声明项: ' . $itemKey);
            }
            $row = static::findRow((array) ($def['where'] ?? []));
            if ($row === null) {
                throw new LogicException('行不存在');
            }
            $arr = is_string($row->{$col}) ? json_decode($row->{$col}, true) : (array) $row->{$col};
            $arr = is_array($arr) ? $arr : [];
            $arr[$itemKey] = $def['columns'][$col][$itemKey];
            DB::connection($conn)->table($table)->where((array) ($def['where'] ?? []))
                ->update([$col => json_encode($arr, JSON_UNESCAPED_UNICODE)]);

            return;
        }

        throw new LogicException('未知修复项: ' . $key);
    }

    /**
     * 预览更新 SQL（pretend 模式，不落库）。
     *
     * @return array<int, string>
     */
    public static function preview(string $key): array
    {
        return DB::connection(static::connectionName())->pretend(function () use ($key) {
            static::fix($key);
        });
    }

    /**
     * 按 where 取首行。
     *
     * @param array<string, mixed> $where
     * @return object|null
     */
    protected static function findRow(array $where)
    {
        return DB::connection(static::connectionName())->table(static::table())->where($where)->first();
    }

    /**
     * 标量相等比较（兼容 DB 返回字符串 / 数字）。
     *
     * @param mixed $actual
     * @param mixed $expected
     */
    protected static function scalarEquals($actual, $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }
        if ($expected === null) {
            return $actual === null;
        }

        return (string) $actual === (string) $expected;
    }
}
