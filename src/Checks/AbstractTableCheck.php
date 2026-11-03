<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Adapters\AdapterFactory;
use Dongasai\Dbcheck\Adapters\DatabaseAdapter;
use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use Dongasai\Dbcheck\Contracts\TableSchemaProvider;
use Dongasai\Dbcheck\Support\DatabaseCompat;
use Dongasai\Dbcheck\Support\PreviewContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * 表结构检查基类：一张表「当前应有完整结构」的唯一声明处。
 *
 * 模型：一个检查器定义一张表的最终结构（全部列 + 全部索引）——
 *  - 表不存在：报表缺失项，修复 = 按最终结构建表；
 *  - 表存在：比对列/索引差异并修复（额外列/索引为可选处理项，默认不处理）。
 *
 * 可选声明表编码 charset()/collation()：建表时覆盖连接默认；声明了才比对，
 * 不一致报 table.charset 项，修复 = ALTER TABLE ... CONVERT TO CHARACTER SET。
 *
 * 同时实现 TableSchemaProvider；实际建列由驱动适配器（AdapterFactory）完成，
 * 以屏蔽 MySQL / PG 差异；列/索引比对深度由适配器决定（MySQL/MySQL8 精确；PG 仅存在性）。
 */
abstract class AbstractTableCheck implements DbCheckInterface, TableSchemaProvider
{
    /**
     * 是否启用该检查器（自动发现时 $enabled=false 会被跳过）。
     */
    public static bool $enabled = true;

    /**
     * 所属分组（分组名由业务自行定义，框架不预置）。
     *
     *  - 返回 null：常规检查，dbcheck:check 默认执行；
     *  - 返回任意字符串（如 'testdata'）：分组检查，默认不执行，
     *    需 dbcheck:check --group=<分组名> 显式触发（所谓 seeder 即执行该分组的检查）。
     */
    public static function group(): ?string
    {
        return null;
    }

    /**
     * 使用的数据库连接名（null = 默认连接）。具体检查器可覆盖。
     */
    protected static function connectionName(): ?string
    {
        return null;
    }

    /**
     * 建表字符集（null = 用连接默认，且不参与比对）。具体检查器可覆盖。
     *
     * 仅建表时生效；比对为「声明制」——返回 null 即不比对表编码。
     */
    protected static function charset(): ?string
    {
        return null;
    }

    /**
     * 建表排序规则（null = 用连接默认，且不参与比对）。具体检查器可覆盖。
     *
     * 仅建表时生效；比对为「声明制」——返回 null 即不比对表编码。
     */
    protected static function collation(): ?string
    {
        return null;
    }

    /**
     * 表名。
     */
    abstract public static function table(): string;

    /**
     * 列定义（全部列，最终态）。
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function columns(): array;

    /**
     * 索引定义（全部索引，最终态，不含主键）。
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function indexes(): array;

    /**
     * 展示名（默认取表名）。
     */
    public static function moduleName(): string
    {
        return static::table();
    }

    /**
     * 表是否存在。
     */
    protected static function hasTable(): bool
    {
        return Schema::connection(static::connectionName())->hasTable(static::table());
    }

    /**
     * 当前连接是否有可用的驱动适配器（供命令提示「表结构检查已降级」，不参与差异判定）。
     *
     * 无适配器（DM / SQLServer 等）时 check() 只做「表是否存在」的降级比对；
     * 命令侧据本方法逐个检查器 warn，避免「没做比对」被当成「比对通过」。
     * 连接本身不可用等其它异常不在这里下结论（返回 true），交给 check() 报「检查失败」。
     */
    public static function hasAdapter(): bool
    {
        try {
            AdapterFactory::make(static::connectionName());

            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * 表结构检查：表缺失报表缺失项；表存在比对表编码（声明了才比）与列/索引差异。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(): array
    {
        if (!static::hasTable()) {
            return [
                [
                    'key' => 'table',
                    'type' => 'table',
                    'message' => '表 ' . static::table() . ' 不存在',
                    'optional' => false,
                    'repair' => '按最终结构建表 ' . static::table(),
                ],
            ];
        }

        $table = static::table();
        $conn = static::connectionName();

        // 未知驱动（达梦 / SQLServer 等无适配器）优雅降级：不整体失败，只做存在性比对
        // （表不存在已在上面报过 table 项）；精确比对跳过，命令层按检查器逐个提示「已降级」
        try {
            $adapter = AdapterFactory::make($conn);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        // 列声明校验（与驱动比对深度无关）：缺 type / 未知 type 当场抛错。
        // 只做存在性比对的 PG/SQLite 不调用 expectedColumnType()，不校验会把错误声明表现为
        // 「列不存在」且 fix 必抛 —— 永远修不好，所以在进入比对前统一校验。
        foreach (static::columns() as $def) {
            $adapter->expectedColumnType($def);
        }

        $actualCols = static::readActualColumns($conn, $table, $adapter);
        $issues = [];

        // 表级字符集/排序规则（声明制：声明了才比对；驱动不支持读表编码时自动跳过）
        $declaredCharset = static::charset();
        $declaredCollation = static::collation();
        if ($declaredCharset !== null || $declaredCollation !== null) {
            $actualCharset = $adapter->actualTableCharset($conn, $table);
            if ($actualCharset !== null) {
                $diffs = [];
                if ($declaredCharset !== null && $actualCharset['charset'] !== $declaredCharset) {
                    $diffs[] = '字符集 实际 ' . $actualCharset['charset'] . ' ≠ 期望 ' . $declaredCharset;
                }
                if ($declaredCollation !== null && $actualCharset['collation'] !== $declaredCollation) {
                    $diffs[] = '排序规则 实际 ' . $actualCharset['collation'] . ' ≠ 期望 ' . $declaredCollation;
                }
                if ($diffs !== []) {
                    $issues[] = [
                        'key' => 'table.charset',
                        'type' => 'table',
                        'message' => '表 ' . $table . ' 编码不一致：' . implode('；', $diffs),
                        'optional' => false,
                        'repair' => 'CONVERT TO ' . $declaredCharset . ($declaredCollation !== null ? ' COLLATE ' . $declaredCollation : ''),
                    ];
                }
            }
        }

        // 列存在性（所有驱动）
        foreach (static::columns() as $name => $def) {
            if (!isset($actualCols[$name])) {
                $issues[] = [
                    'key' => 'column.' . $name,
                    'type' => 'column',
                    'message' => '列 ' . $name . ' 不存在（代码写库/查询会报 Unknown column）',
                    'optional' => false,
                    'repair' => '按最终定义新增列 ' . $name,
                ];
                continue;
            }

            // 列属性比对（仅 MySQL / MySQL8）
            if ($adapter->comparesColumnAttributes()) {
                $actual = $actualCols[$name];
                $diffs = [];

                $expectedType = $adapter->expectedColumnType($def);
                if ($actual['type'] !== $expectedType) {
                    $diffs[] = '类型 实际 ' . $actual['type'] . ' ≠ 期望 ' . $expectedType;
                }
                if ($actual['nullable'] !== !empty($def['nullable'])) {
                    $diffs[] = '可空 实际 ' . ($actual['nullable'] ? '可空' : '非空')
                        . ' ≠ 期望 ' . (!empty($def['nullable']) ? '可空' : '非空');
                }
                if (!$adapter->defaultMatches($actual['default'], $def)) {
                    $diffs[] = '默认值 实际 ' . var_export($actual['default'], true)
                        . ' ≠ 期望 ' . var_export($def['default'] ?? null, true);
                }

                // 同一列的多个属性差异聚合成一个异常项：一次报全、一次整列重写修好，
                // 避免「类型→可空→默认值」逐轮 continue 导致要跑多轮才能到全绿
                if ($diffs !== []) {
                    $issues[] = [
                        'key' => 'column.' . $name,
                        'type' => 'column',
                        'message' => '列 ' . $name . ' 与最终定义不一致：' . implode('；', $diffs),
                        'optional' => false,
                        'repair' => '按最终定义整列重写 ' . $name,
                    ];
                }
            }
        }

        // 额外列（声明中没有，库中多出）
        foreach ($actualCols as $name => $actual) {
            if (!isset(static::columns()[$name])) {
                $issues[] = [
                    'key' => 'extra_column.' . $name,
                    'type' => 'extra_column',
                    'message' => '额外列 ' . $name . '（声明中无，库中多出）',
                    'optional' => true,
                    'repair' => '删除列 ' . $name,
                ];
            }
        }

        $actualIdx = static::readActualIndexes($conn, $table, $adapter);
        $strictIndex = $adapter->comparesIndexAttributes();
        $declaredIdx = static::declaredIndexes();

        foreach ($declaredIdx as $name => $idx) {
            $cols = (array) ($idx['columns'] ?? []);
            $unique = !empty($idx['unique']);

            if (isset($actualIdx[$name])) {
                if ($strictIndex) {
                    $ai = $actualIdx[$name];
                    if (static::indexEquivalent($ai, $cols, $unique, true)) {
                        continue;
                    }
                    $issues[] = [
                        'key' => 'index.' . $name,
                        'type' => 'index',
                        'message' => '索引 ' . $name . ' 与声明不一致（唯一性 / 列不同）',
                        'optional' => false,
                        'repair' => '按声明重建索引 ' . $name,
                    ];
                }
                // 存在性模式：同名索引存在即视为已具备
                continue;
            }

            // 无同名索引：本表是否已有「唯一性 + 列」等价的其它索引
            $equivalent = false;
            foreach ($actualIdx as $an => $ai) {
                if ($an === 'PRIMARY') {
                    continue;
                }
                if (static::indexEquivalent($ai, $cols, $unique, $strictIndex)) {
                    $equivalent = true;
                    break;
                }
            }
            if ($equivalent) {
                continue;
            }

            $issues[] = [
                'key' => 'index.' . $name,
                'type' => 'index',
                'message' => '索引 ' . $name . ' 缺失',
                'optional' => false,
                'repair' => '新增索引 ' . $name,
            ];
        }

        // 额外索引（声明中没有，库中多出；与某条声明等价的同名字段差异由 index.* 负责）
        foreach ($actualIdx as $an => $ai) {
            if ($an === 'PRIMARY') {
                continue;
            }
            if (isset($declaredIdx[$an])) {
                continue;
            }
            $equivalent = false;
            foreach ($declaredIdx as $idx) {
                $cols = (array) ($idx['columns'] ?? []);
                $unique = !empty($idx['unique']);
                if (static::indexEquivalent($ai, $cols, $unique, $strictIndex)) {
                    $equivalent = true;
                    break;
                }
            }
            if ($equivalent) {
                continue;
            }

            $issues[] = [
                'key' => 'extra_index.' . $an,
                'type' => 'extra_index',
                'message' => '额外索引 ' . $an . '（声明中无，库中多出）',
                'optional' => true,
                'repair' => '删除索引 ' . $an,
            ];
        }

        return $issues;
    }

    /**
     * 实际索引是否与声明「类型 + 字段」等价。
     *
     * MySQL 系比较列顺序（最左前缀有意义）；存在性模式（PG 等）忽略列顺序，
     * 避免同名等价索引只因列顺序不同被误报为缺失 / 额外。
     *
     * @param array<string, mixed> $actual 实际索引（unique / columns）
     * @param array<int, string> $columns 声明的索引列
     */
    protected static function indexEquivalent(array $actual, array $columns, bool $unique, bool $strict): bool
    {
        if ((bool) ($actual['unique'] ?? false) !== $unique) {
            return false;
        }

        $actualColumns = array_values((array) ($actual['columns'] ?? []));
        $expectedColumns = array_values($columns);
        if ($actualColumns === [] || $expectedColumns === []) {
            return false;
        }

        if (!$strict) {
            sort($actualColumns);
            sort($expectedColumns);
        }

        return $actualColumns === $expectedColumns;
    }

    /**
     * 修复表结构异常项。未知 key 抛 LogicException。
     */
    public static function fix(string $key): void
    {
        if ($key === 'table') {
            static::createTable();

            return;
        }

        if ($key === 'table.charset') {
            $charset = static::charset();
            if ($charset === null) {
                throw new LogicException('表 ' . static::table() . ' 未声明字符集（charset()），无法生成 CONVERT 语句');
            }
            $collation = static::collation();
            DB::connection(static::connectionName())->statement(
                'ALTER TABLE `' . str_replace('`', '', static::table()) . '`'
                . ' CONVERT TO CHARACTER SET ' . $charset
                . ($collation !== null ? ' COLLATE ' . $collation : '')
            );

            return;
        }

        $table = static::table();
        $conn = static::connectionName();
        $adapter = AdapterFactory::make($conn);

        if (strpos($key, 'column.') === 0) {
            $name = substr($key, 7);
            $def = static::columns()[$name] ?? null;
            if ($def === null) {
                throw new LogicException('未知列: ' . $name);
            }
            $exists = isset(static::readActualColumns($conn, $table, $adapter)[$name]);
            Schema::connection($conn)->table($table, function (Blueprint $t) use ($adapter, $name, $def, $exists) {
                $col = $adapter->buildColumn($t, $name, $def);
                if ($exists) {
                    $col->change();
                }
            });

            return;
        }

        if (strpos($key, 'index.') === 0) {
            $name = substr($key, 6);
            $idx = static::declaredIndexes()[$name] ?? null;
            if ($idx === null) {
                throw new LogicException('未知索引: ' . $name);
            }
            $cols = (array) ($idx['columns'] ?? []);
            $unique = !empty($idx['unique']);
            $actual = static::readActualIndexes($conn, $table, $adapter);
            $createName = PreviewContext::remember('index.name.' . $name, function () use ($adapter, $name, $table, $conn) {
                return $adapter->resolveIndexName($name, $table, $conn);
            });
            Schema::connection($conn)->table($table, function (Blueprint $t) use ($name, $cols, $unique, $actual, $createName) {
                if (isset($actual[$name])) {
                    $t->dropIndex($name);
                }
                if ($unique) {
                    $t->unique($cols, $createName);
                } else {
                    $t->index($cols, $createName);
                }
            });

            return;
        }

        if (strpos($key, 'extra_column.') === 0) {
            $name = substr($key, 13);
            if (isset(static::columns()[$name])) {
                throw new LogicException('拒绝删除已声明列: ' . $name);
            }
            Schema::connection($conn)->table($table, function (Blueprint $t) use ($name) {
                $t->dropColumn($name);
            });

            return;
        }

        if (strpos($key, 'extra_index.') === 0) {
            $name = substr($key, 12);
            if (isset(static::declaredIndexes()[$name])) {
                throw new LogicException('拒绝删除已声明索引: ' . $name);
            }
            if ($name === 'PRIMARY') {
                throw new LogicException('拒绝删除主键');
            }
            Schema::connection($conn)->table($table, function (Blueprint $t) use ($name) {
                $t->dropIndex($name);
            });

            return;
        }

        throw new LogicException('未知修复项: ' . $key);
    }

    /**
     * 预览修复将要执行的 SQL（使用连接层 pretend 模式，不落库）。
     *
     * pretend 期间所有 SELECT 返回空，因此先把 fix() 依赖的列/索引元数据在真实连接上读好
     * 放进预览上下文，pretend 内命中缓存——否则已存在的列会被预览成 add、索引漏掉 drop。
     *
     * @return array<int, array{query: string, bindings: array<int, mixed>}> 将要执行的写 SQL（已过滤判重/元数据读）
     */
    public static function preview(string $key): array
    {
        $conn = static::connectionName();
        $table = static::table();

        PreviewContext::begin();
        try {
            if (strpos($key, 'column.') === 0) {
                static::readActualColumns($conn, $table, AdapterFactory::make($conn));
            } elseif (strpos($key, 'index.') === 0) {
                $adapter = AdapterFactory::make($conn);
                $name = substr($key, 6);
                static::readActualIndexes($conn, $table, $adapter);
                PreviewContext::remember('index.name.' . ($conn ?? 'default') . '.' . $table . '.' . $name, function () use ($adapter, $name, $table, $conn) {
                    return $adapter->resolveIndexName($name, $table, $conn);
                });
            }

            return PreviewContext::writeStatements(DB::connection($conn)->pretend(function () use ($key) {
                static::fix($key);
            }));
        } finally {
            PreviewContext::end();
            // 预览抛错时 Laravel 8 的 pretend() 不会复位 pretending，必须兜底（否则后续写全部静默跳过）
            DatabaseCompat::stopPretending($conn);
        }
    }

    /**
     * 读取实际列结构（预览上下文内命中缓存，避免 pretend 下读到空数组）。
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function readActualColumns(?string $conn, string $table, DatabaseAdapter $adapter): array
    {
        return PreviewContext::remember('columns.' . ($conn ?? 'default') . '.' . $table, function () use ($adapter, $conn, $table) {
            return $adapter->actualColumns($conn, $table);
        });
    }

    /**
     * 读取实际索引结构（预览上下文内命中缓存）。
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function readActualIndexes(?string $conn, string $table, DatabaseAdapter $adapter): array
    {
        return PreviewContext::remember('indexes.' . ($conn ?? 'default') . '.' . $table, function () use ($adapter, $conn, $table) {
            return $adapter->actualIndexes($conn, $table);
        });
    }

    /**
     * 归一化并校验索引声明（必须是 name => ['columns' => [...], 'unique' => bool] 映射）。
     *
     * 旧实现（v338 之前）的 indexes() 是 list + 每项带 name，迁移时若漏改形态，
     * 数值下标会被当成索引名建出名为 "0"/"1" 的索引，且 check 不会报错——这里显式拒绝。
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function declaredIndexes(): array
    {
        $out = [];
        foreach (static::indexes() as $name => $idx) {
            if (is_int($name)) {
                throw new LogicException(
                    '表 ' . static::table() . ' 的 indexes() 必须返回「索引名 => [columns, unique]」映射，'
                    . '收到整数下标 ' . $name . '（疑似旧 list + name 形态，请改为以索引名作键）'
                );
            }
            if (!is_array($idx) || (isset($idx['name']) && !isset($idx['columns']))) {
                throw new LogicException(
                    '表 ' . static::table() . ' 的索引声明 ' . $name . ' 形态不合法：'
                    . '应为 [\'columns\' => [...], \'unique\' => bool]，不要在内层再写 name'
                );
            }
            // 裸列数组（如 ['uk_status' => ['status']]，漏写 columns 键）会被当成空列索引：
            // check 永远报「索引缺失」，fix 生成 create index ... () 直接 SQL 语法错误——必须当场拒绝。
            $columns = $idx['columns'] ?? null;
            if (!is_array($columns) || $columns === []) {
                throw new LogicException(
                    '表 ' . static::table() . ' 的索引声明 ' . $name . ' 缺少有效的 columns：'
                    . 'columns 必须是非空列名字符串数组'
                );
            }
            foreach ($columns as $column) {
                if (!is_string($column) || $column === '') {
                    throw new LogicException(
                        '表 ' . static::table() . ' 的索引声明 ' . $name . ' 的 columns 必须是非空列名字符串数组'
                    );
                }
            }
            $out[(string) $name] = $idx;
        }

        return $out;
    }

    /**
     * 按 columns()+indexes() 建表（列可声明 'primary' => true 指定主键，ColumnType::ID 自带主键）。
     */
    protected static function createTable(): void
    {
        $adapter = AdapterFactory::make(static::connectionName());

        Schema::connection(static::connectionName())->create(static::table(), function ($table) use ($adapter) {
            // 声明了表编码则覆盖连接默认
            $charset = static::charset();
            $collation = static::collation();
            if ($charset !== null) {
                $table->charset = $charset;
            }
            if ($collation !== null) {
                $table->collation = $collation;
            }
            foreach (static::columns() as $name => $def) {
                $adapter->buildColumn($table, (string) $name, $def);
                if (!empty($def['primary'])) {
                    $table->primary($name);
                }
            }
            foreach (static::declaredIndexes() as $name => $idx) {
                $cols = (array) ($idx['columns'] ?? []);
                if (!empty($idx['unique'])) {
                    $table->unique($cols, (string) $name);
                } else {
                    $table->index($cols, (string) $name);
                }
            }
        });
    }
}
