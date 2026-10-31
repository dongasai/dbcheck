<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Adapters\AdapterFactory;
use Dongasai\Dbcheck\Contracts\TableSchemaProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * 行差异检查基类（列 / 索引与最终结构是否一致；额外列/索引为可选处理项）。
 *
 * 同时实现 TableSchemaProvider，是该表「最终结构」的唯一声明处（columns/indexes 全量声明）。
 * 存在检查（ExistsCheck）通过 schema() 指向本类，避免两处维护同一张表定义。
 *
 * 表不存在时 check() 返回空（仅由 ExistsCheck 报缺失），不重复报错。
 * 修复只做 ADD COLUMN / CHANGE COLUMN / ADD INDEX / 同名索引重建；额外项仅在确认后 DROP。
 *
 * 列/索引比对深度由驱动适配器决定（MySQL/MySQL8 精确；PG 仅存在性）。
 */
abstract class AbstractTableRowCheck extends AbstractTableExistsCheck implements TableSchemaProvider
{
    /**
     * 最终结构声明即自身。
     *
     * @return class-string
     */
    protected static function schema(): string
    {
        return static::class;
    }

    /**
     * 表名（由具体子类声明）。
     */
    abstract public static function table(): string;

    /**
     * 列定义（最终态全量）。
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function columns(): array;

    /**
     * 索引定义（最终态全量，不含主键）。
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function indexes(): array;

    /**
     * 行差异检查。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(): array
    {
        $table = static::table();
        if (!static::hasTable()) {
            return [];
        }

        $conn = static::connectionName();
        $adapter = AdapterFactory::make($conn);
        $actualCols = $adapter->actualColumns($conn, $table);
        $issues = [];

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

        foreach (static::indexes() as $name => $idx) {
            $cols = (array) ($idx['columns'] ?? []);
            $unique = !empty($idx['unique']);

            if (isset($actualIdx[$name])) {
                if ($adapter->comparesIndexAttributes()) {
                    $ai = $actualIdx[$name];
                    if ($ai['unique'] === $unique && $ai['columns'] === $cols) {
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
                if ($ai['unique'] === $unique && $ai['columns'] === $cols) {
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
                if ($ai['unique'] === $unique && $ai['columns'] === $cols) {
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
     * 修复行差异项。未知 key 抛异常。
     */
    public static function fix(string $key): void
    {
        if ($key === 'table') {
            parent::fix($key);

            return;
        }

        $table = static::table();
        $conn = static::connectionName();
        $adapter = AdapterFactory::make($conn);

        $prefix = substr($key, 0, 7);
        if ($prefix === 'column.') {
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

        if ($prefix === 'index.') {
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

        if ($prefix === 'extra_c') {
            // extra_column.
            $name = substr($key, 13);
            if (isset(static::columns()[$name])) {
                throw new LogicException('拒绝删除已声明列: ' . $name);
            }
            Schema::connection($conn)->table($table, function (Blueprint $t) use ($name) {
                $t->dropColumn($name);
            });

            return;
        }

        if ($prefix === 'extra_i') {
            // extra_index.
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
}
