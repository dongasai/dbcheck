<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 固定数据检查基类（必需数据行是否存在）。
 *
 * 子类只声明 table() 与 rows()；行级通用机制在基类。
 * 缺行报声明标识；fix 走 where + insert + insertMerge()，数组值（JSON 列）入库前 json_encode；
 * 取值行缺失不代补，报错提示先补建。
 *
 * insert 只是新增时的默认值：已有行的字段值一律不比对、不强制回写（后台可自由调整排序/显隐/父级）。
 * 需要锁定的字段在 FieldCheck::rows() 显式声明后单独校验。
 *
 * 可选钩子 rowIssues($key, $def, $row)：声明表达不了的附加校验，需自行覆盖 fix()。
 */
abstract class AbstractTableDataCheck implements DbCheckInterface
{
    /**
     * 是否启用该检查器。
     */
    public static bool $enabled = true;

    /**
     * 表名。
     */
    abstract public static function table(): string;

    /**
     * 固定数据行声明。
     *
     * 每项：
     *  - where：判定条件，同时作为插入字段
     *  - insert：完整静态插入数据（数组值会被 json_encode）
     *  - item / problem / fix：展示用说明
     *  - insert_merge（可选）：运行期才能算出的列，或引用其它行（['where'=>..., 'value'=>列名]）
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
     * 固定数据检查：缺行报一项。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(): array
    {
        $issues = [];

        foreach (static::rows() as $key => $def) {
            $where = (array) ($def['where'] ?? []);
            if (!static::rowExists($where)) {
                $issues[] = [
                    'key' => (string) $key,
                    'type' => 'data',
                    'message' => '缺数据行: ' . ($def['item'] ?? $key),
                    'optional' => false,
                    'repair' => $def['fix'] ?? ('插入默认行 ' . ($def['item'] ?? $key)),
                ];
            }

            if (method_exists(static::class, 'rowIssues')) {
                $row = static::findRow($where);
                foreach (static::rowIssues((string) $key, $def, $row) as $extra) {
                    $issues[] = $extra;
                }
            }
        }

        return $issues;
    }

    /**
     * 补插缺失行。已存在则跳过。
     */
    public static function fix(string $key): void
    {
        $def = static::rows()[$key] ?? null;
        if ($def === null) {
            throw new LogicException('未知数据项: ' . $key);
        }

        $where = (array) ($def['where'] ?? []);
        if (static::rowExists($where)) {
            return;
        }

        $insert = (array) ($def['insert'] ?? []);
        $data = array_merge($where, static::applyInsertMerge($def, $insert));

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }

        $now = now()->format('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        DB::connection(static::connectionName())->table(static::table())->insert($data);
    }

    /**
     * 预览插入 SQL（pretend 模式，不落库）。
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
     * 应用 insert_merge（覆盖/补充运行期算出的列；支持引用其它行）。
     *
     * @param array<string, mixed> $def
     * @param array<string, mixed> $insert
     * @return array<string, mixed>
     */
    protected static function applyInsertMerge(array $def, array $insert): array
    {
        $merge = (array) ($def['insert_merge'] ?? []);
        $result = $insert;

        foreach ($merge as $col => $val) {
            if (is_array($val) && isset($val['where'])) {
                $refRow = static::findRow((array) $val['where']);
                if ($refRow === null) {
                    throw new LogicException('insert_merge 引用行不存在: ' . json_encode($val['where']));
                }
                $result[$col] = $refRow->{$val['value']};
            } else {
                $result[$col] = $val;
            }
        }

        return $result;
    }

    /**
     * 判定行是否存在。
     *
     * @param array<string, mixed> $where
     */
    protected static function rowExists(array $where): bool
    {
        return static::findRow($where) !== null;
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
     * 可选钩子：声明表达不了的附加校验，返回额外异常项。默认无。
     *
     * @param array<string, mixed> $def
     * @param object|null $row
     * @return array<int, array<string, mixed>>
     */
    protected static function rowIssues(string $key, array $def, $row): array
    {
        return [];
    }
}
