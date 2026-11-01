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
     * 数组值支持两种写法（等价，二选一）：
     *  - list + 每项带 key：[['key' => 'a', 'name' => 'A'], ['key' => 'b', ...]]
     *  - map：[ 'a' => ['key' => 'a', 'name' => 'A'], 'b' => [...] ]
     *
     * 补项语义：list 声明 + list 落库时追加到末尾（保留已存项及运行期状态，如 checked）；
     * 其余情况按项 key 写回。已存 JSON 为 list（元素带 key）或 map 都能识别。
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
                    $stored = static::indexStoredItems($actualVal);
                    foreach (static::declaredItems($expected) as $itemKey => $itemDef) {
                        if (!array_key_exists($itemKey, $stored)) {
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
            $data = [$col => $expected] + static::touchedAt();
            DB::connection($conn)->table($table)->where((array) ($def['where'] ?? []))->update($data);

            return;
        }

        if (substr($key, 0, 5) === 'item.') {
            [, $rowKey, $col, $itemKey] = explode('.', $key, 4);
            $def = static::rows()[$rowKey] ?? null;
            if ($def === null) {
                throw new LogicException('未知行: ' . $rowKey);
            }
            $declared = $def['columns'][$col] ?? null;
            if (!is_array($declared)) {
                throw new LogicException('列 ' . $col . ' 非数组');
            }

            $items = static::declaredItems($declared);
            if (!array_key_exists($itemKey, $items)) {
                throw new LogicException('未声明项: ' . $itemKey);
            }

            $row = static::findRow((array) ($def['where'] ?? []));
            if ($row === null) {
                throw new LogicException('行不存在');
            }

            $stored = is_string($row->{$col}) ? json_decode($row->{$col}, true) : $row->{$col};
            $stored = is_array($stored) ? $stored : [];

            if (array_key_exists($itemKey, static::indexStoredItems($stored))) {
                return;
            }

            if (static::isListArray($declared) && static::isListArray($stored)) {
                // list 声明 + list 落库：追加到末尾，保留已存项及其运行期状态（如 checked）
                $stored[] = $items[$itemKey];
            } else {
                $stored[$itemKey] = $items[$itemKey];
            }

            DB::connection($conn)->table($table)->where((array) ($def['where'] ?? []))
                ->update([
                    $col => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ] + static::touchedAt());

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
     * 归一化数组列声明为「项 key => 项定义」。
     *
     * 兼容两种写法：
     *  - list + 每项带 key：`[['key'=>'a', ...], ['key'=>'b', ...]]`（v338 口径）
     *  - map：`['a' => [...], 'b' => [...]]`
     *
     * @param array<int|string, mixed> $declared
     * @return array<string, mixed>
     */
    protected static function declaredItems(array $declared): array
    {
        if (!static::isListArray($declared)) {
            /** @var array<string, mixed> $declared */
            return $declared;
        }

        $items = [];
        foreach ($declared as $item) {
            if (!is_array($item) || !isset($item['key'])) {
                // 不是 list + key 写法：按 map 语义原样返回
                return $declared;
            }
            $items[(string) $item['key']] = $item;
        }

        return $items;
    }

    /**
     * 按项 key 索引已存 JSON 列。
     *
     * 兼容两种落库形态：list（元素带 key，取元素 key 作下标）与 map（直接用键）。
     * 无法解析或元素缺 key 时按原样返回，交由调用方判缺失。
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    protected static function indexStoredItems($raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        if (!static::isListArray($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['key'])) {
                $items[(string) $item['key']] = $item;
            }
        }

        return $items;
    }

    /**
     * 是否为 list（连续 0..n-1 下标）数组（PHP 7.4 无 array_is_list）。
     *
     * @param array<mixed> $arr
     */
    protected static function isListArray(array $arr): bool
    {
        return $arr !== [] && array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * 需要一并回写的时间列（表里没有 updated_at 时不写，避免未知列报错）。
     *
     * @return array<string, mixed>
     */
    protected static function touchedAt(): array
    {
        if (!Schema::connection(static::connectionName())->hasColumn(static::table(), 'updated_at')) {
            return [];
        }

        return ['updated_at' => now()];
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
