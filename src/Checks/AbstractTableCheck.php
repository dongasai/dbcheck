<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Adapters\AdapterFactory;
use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use Dongasai\Dbcheck\Contracts\TableSchemaProvider;
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
        $adapter = AdapterFactory::make($conn);
        $actualCols = $adapter->actualColumns($conn, $table);
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
                $expectedType = $adapter->expectedColumnType($def);
                if ($actual['type'] !== $expectedType) {
                    $issues[] = [
                        'key' => 'column.' . $name,
                        'type' => 'column',
                        'message' => '列 ' . $name . ' 类型不一致：实际 ' . $actual['type'] . ' ≠ 期望 ' . $expectedType,
                        'optional' => false,
                        'repair' => '改为 ' . $expectedType,
                    ];
                    continue;
                }
                if ($actual['nullable'] !== !empty($def['nullable'])) {
                    $issues[] = [
                        'key' => 'column.' . $name,
                        'type' => 'column',
                        'message' => '列 ' . $name . ' 可空不一致：实际 ' . ($actual['nullable'] ? '可空' : '非空')
                            . ' ≠ 期望 ' . (!empty($def['nullable']) ? '可空' : '非空'),
                        'optional' => false,
                        'repair' => '调整可空属性',
                    ];
                    continue;
                }
                if (!$adapter->defaultMatches($actual['default'], $def)) {
                    $issues[] = [
                        'key' => 'column.' . $name,
                        'type' => 'column',
                        'message' => '列 ' . $name . ' 默认值不一致：实际 ' . var_export($actual['default'], true)
                            . ' ≠ 期望 ' . var_export($def['default'] ?? null, true),
                        'optional' => false,
                        'repair' => '调整默认值',
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

        $actualIdx = $adapter->actualIndexes($conn, $table);
        $strictIndex = $adapter->comparesIndexAttributes();

        foreach (static::indexes() as $name => $idx) {
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
            if (isset(static::indexes()[$an])) {
                continue;
            }
            $equivalent = false;
            foreach (static::indexes() as $idx) {
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
            $exists = isset($adapter->actualColumns($conn, $table)[$name]);
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
            $idx = static::indexes()[$name] ?? null;
            if ($idx === null) {
                throw new LogicException('未知索引: ' . $name);
            }
            $cols = (array) ($idx['columns'] ?? []);
            $unique = !empty($idx['unique']);
            $actual = $adapter->actualIndexes($conn, $table);
            $createName = $adapter->resolveIndexName($name, $table, $conn);
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
            if (isset(static::indexes()[$name])) {
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
     * @return array<int, string> 将要执行的 SQL 语句（仅写操作，已内联绑定值）
     */
    public static function preview(string $key): array
    {
        return DB::connection(static::connectionName())->pretend(function () use ($key) {
            static::fix($key);
        });
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
            foreach (static::indexes() as $name => $idx) {
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
