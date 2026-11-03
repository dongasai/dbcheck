<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use Dongasai\Dbcheck\Support\DatabaseCompat;
use Dongasai\Dbcheck\Support\DbCheckGroup;
use Dongasai\Dbcheck\Support\PreviewContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 *
 * 可选删除验证 deleteExtra()：与缺行检查相反——库中未被 rows() 声明的行视为多余数据，
 * 修复 = 按行删除。默认关闭（删除属破坏性动作）；启用后该检查器默认归入
 * DbCheckGroup::DELETE 分组，不随 dbcheck:check 默认执行，需 --group=delete 显式触发。
 */
abstract class AbstractTableDataCheck implements DbCheckInterface
{
    /**
     * 是否启用该检查器。
     */
    public static bool $enabled = true;

    /**
     * 所属分组（分组名由业务自行定义，框架不预置业务名）。
     *
     *  - 返回 null：常规检查，dbcheck:check 默认执行；
     *  - 返回任意字符串（如 'testdata'）：分组检查，默认不执行，
     *    需 dbcheck:check --group=<分组名> 显式触发。
     *
     * 默认值：启用删除验证（deleteExtra()）的检查器归入 DbCheckGroup::DELETE
     * （危险动作不随默认扫执行）；未启用则常规。子类覆盖本方法后以子类为准。
     */
    public static function group(): ?string
    {
        return static::deleteExtra() ? DbCheckGroup::DELETE : null;
    }

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
     *  - item / problem / fix：展示用说明（problem 缺省时回落到「缺数据行」文案）
     *  - insert_merge（可选）：运行期才能算出的列，或引用其它行（['where'=>..., 'value'=>列名]）
     *  - timestamps（可选，默认 true）：是否自动补 created_at / updated_at；声明 false 时不写这两列
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected static function rows(): array;

    /**
     * 是否启用「删除验证」：删除库中未被 rows() 声明的多余数据行（与缺行检查相反）。
     *
     * 默认 false——删除属破坏性动作，仅适用于**数据全量受声明控制**的字典/固定表；
     * 业务表（含用户产生的行）严禁开启，否则正常业务数据会被当多余行删除。
     *
     * 启用后的行为：
     *  - check() 额外报告多余行（key = `data.extra.<身份JSON>`，身份列 = 各声明 where 列的并集）；
     *  - fix('data.extra.<身份JSON>') 按身份列等值删除该行（null 值走 whereNull）；
     *  - 检查器默认归入 DbCheckGroup::DELETE 分组（需 --group=delete 显式触发）。
     */
    public static function deleteExtra(): bool
    {
        return false;
    }

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
     * 表是否存在（表缺失时数据检查返回空，由表结构检查统一报缺表，避免重复报错与查询异常）。
     */
    protected static function hasTable(): bool
    {
        return Schema::connection(static::connectionName())->hasTable(static::table());
    }

    /**
     * 固定数据检查：缺行报一项；行存在时再执行行内附加校验。
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
                $item = (string) ($def['item'] ?? $key);
                $issues[] = [
                    'key' => (string) $key,
                    'type' => 'data',
                    // problem 是声明里的业务后果说明（如「菜单缺失，前端无入口」），有则带上定位项
                    'message' => isset($def['problem'])
                        ? $item . '：' . $def['problem']
                        : '缺数据行: ' . $item,
                    'optional' => false,
                    'repair' => $def['fix'] ?? ('插入默认行 ' . $item),
                ];

                // 行不存在时没有可校验的对象（子类 rowIssues 可能声明 object 类型）
                continue;
            }

            foreach (static::rowIssues((string) $key, $def, $row) as $extra) {
                $issues[] = $extra;
            }
        }

        // 删除验证：报告未被声明的多余行（与缺行检查相反）
        if (static::deleteExtra()) {
            foreach (static::extraRowIssues() as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * 修复数据异常项：`data.extra.<身份JSON>` 删多余行；其余 key 补插缺失行。
     * 未知 key 抛 LogicException。
     */
    public static function fix(string $key): void
    {
        if (strpos($key, 'data.extra.') === 0) {
            static::deleteExtraRow(substr($key, strlen('data.extra.')));

            return;
        }

        $def = static::rows()[$key] ?? null;
        if ($def === null) {
            throw new LogicException('未知数据项: ' . $key);
        }

        $where = (array) ($def['where'] ?? []);

        // 已存在则短路（fix 的幂等语义）：不比对、不回写、不执行 insert_merge
        if (static::rowExists($where)) {
            return;
        }

        $insert = (array) ($def['insert'] ?? []);
        $data = array_merge($where, static::applyInsertMerge($def, $insert));

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        // timestamps 缺省 true；声明 false 时不写时间列（由 insert 自行声明 created_at 等）
        if (!array_key_exists('timestamps', $def) || $def['timestamps']) {
            $now = now()->format('Y-m-d H:i:s');
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $data['updated_at'] ?? $now;
        }

        // 不用 insertOrIgnore（PG 系会编译成无冲突目标的 on conflict do nothing，部分库不支持）
        DB::connection(static::connectionName())->table(static::table())->insert($data);
    }

    /**
     * 预览插入 SQL（pretend 模式，不落库）。
     *
     * pretend 期间 SELECT 返回空，因此先把判重行（fix 经 rowExists() 判重）
     * 与 insert_merge 引用的行在真实连接上读好放进预览上下文：
     * 已存在的行预览不再误出 INSERT，引用行也能取到真实值。
     *
     * @return array<int, array{query: string, bindings: array<int, mixed>}> 将要执行的写 SQL（已过滤判重 SELECT）
     */
    public static function preview(string $key): array
    {
        $def = static::rows()[$key] ?? null;

        PreviewContext::begin();
        try {
            if ($def !== null) {
                // 判重读预热：已存在则 pretend 内 fix() 直接短路，与真实修复同口径
                static::rowExists((array) ($def['where'] ?? []));
                // insert_merge 引用的行：fix 会读它们取列值，pretend 下需预热
                foreach ((array) ($def['insert_merge'] ?? []) as $val) {
                    if (is_array($val) && isset($val['where'])) {
                        static::findRow((array) $val['where']);
                    }
                }
            }

            return PreviewContext::writeStatements(DB::connection(static::connectionName())->pretend(function () use ($key) {
                static::fix($key);
            }));
        } finally {
            PreviewContext::end();
            // 预览抛错时 Laravel 8 的 pretend() 不会复位 pretending，必须兜底（否则后续写全部静默跳过）
            DatabaseCompat::stopPretending(static::connectionName());
        }
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
     * 判定行是否存在（判重读经 PreviewContext 缓存，预览内与 fix 同口径）。
     *
     * @param array<string, mixed> $where
     */
    protected static function rowExists(array $where): bool
    {
        return DatabaseCompat::rowExists(static::table(), $where, static::connectionName());
    }

    /**
     * 按 where 取首行（预览上下文内命中缓存，避免 pretend 下读到空行）。
     *
     * @param array<string, mixed> $where
     * @return object|null
     */
    protected static function findRow(array $where)
    {
        $conn = static::connectionName();

        return PreviewContext::remember(
            'row.' . ($conn ?? 'default') . '.' . static::table() . '.' . json_encode($where),
            function () use ($where, $conn) {
                return DB::connection($conn)->table(static::table())->where($where)->first();
            }
        );
    }

    /**
     * 收集未被 rows() 声明的多余数据行（仅 deleteExtra() 启用时被调用）。
     *
     * 身份列 = 各声明 where 列的并集（实际使用中即业务唯一键）；
     * 无任何声明时退化为整行值做身份（语义：声明为空 = 表应为空）。
     * 异常项 key = `data.extra.<身份JSON>`，fix 经同一编码反向定位删除。
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function extraRowIssues(): array
    {
        $identityCols = [];
        foreach (static::rows() as $def) {
            foreach (array_keys((array) ($def['where'] ?? [])) as $col) {
                $identityCols[$col] = true;
            }
        }
        $identityCols = array_keys($identityCols);

        $rows = DB::connection(static::connectionName())->table(static::table())->get();
        $issues = [];
        $seen = [];
        foreach ($rows as $row) {
            if (static::rowMatchesDeclaration($row)) {
                continue;
            }

            $where = [];
            foreach ($identityCols as $col) {
                $where[$col] = $row->{$col} ?? null;
            }
            if ($where === []) {
                foreach ((array) $row as $col => $val) {
                    $where[$col] = $val;
                }
            }

            $identity = json_encode($where, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $issues[] = [
                'key' => 'data.extra.' . $identity,
                'type' => 'data',
                'message' => '多余数据行（未在声明中，deleteExtra 已启用）: ' . $identity,
                'optional' => false,
                'repair' => '删除该行',
            ];
        }

        return $issues;
    }

    /**
     * 判断实际行是否被某条声明命中（where 各列等值，null 对 null；字符串化比较消除驱动类型差异）。
     *
     * @param object $row
     */
    protected static function rowMatchesDeclaration($row): bool
    {
        foreach (static::rows() as $def) {
            $matched = true;
            foreach ((array) ($def['where'] ?? []) as $col => $val) {
                $rowVal = $row->{$col} ?? null;
                if ($val === null) {
                    if ($rowVal !== null) {
                        $matched = false;
                        break;
                    }
                    continue;
                }
                if ((string) $rowVal !== (string) $val) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * 删除多余数据行（fix 的 data.extra.* 分支）。
     *
     * @param string $identity 身份 JSON（check() 产出的 key 后缀）
     */
    protected static function deleteExtraRow(string $identity): void
    {
        if (!static::deleteExtra()) {
            throw new LogicException('该检查器未启用删除验证（deleteExtra）: data.extra.' . $identity);
        }

        $where = json_decode($identity, true);
        if (!is_array($where) || $where === []) {
            throw new LogicException('无效的多余行身份标识: data.extra.' . $identity);
        }

        $query = DB::connection(static::connectionName())->table(static::table());
        foreach ($where as $col => $val) {
            if ($val === null) {
                $query->whereNull($col);
            } else {
                $query->where($col, $val);
            }
        }
        $query->delete();
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
