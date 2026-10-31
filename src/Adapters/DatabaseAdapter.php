<?php

namespace Dongasai\Dbcheck\Adapters;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * 数据库驱动适配器抽象基类。
 *
 * 屏蔽 MySQL / MySQL8 / PostgreSQL 在「建列类型映射」「列/索引差异比对深度」「索引命名」上的差异。
 * 新增驱动只需继承本类并覆盖对应方法。
 */
abstract class DatabaseAdapter
{
    /**
     * 驱动标识（mysql / mysql8 / pgsql）。
     */
    abstract public function driverLabel(): string;

    /**
     * 是否精确比对列属性（类型 / 可空 / 默认值）。
     *
     * MySQL / MySQL8 为 true；PG 为 false（PG 类型口径与声明差异大，精确比对会大量误报，仅做存在性）。
     */
    abstract public function comparesColumnAttributes(): bool;

    /**
     * 是否精确比对索引属性（唯一性 / 列与顺序）。
     *
     * MySQL / MySQL8 为 true；PG 为 false（仅存在性判定，按「唯一性 + 列」等价判断）。
     */
    abstract public function comparesIndexAttributes(): bool;

    /**
     * 在 Blueprint 上建列（不调用 ->change()）。
     *
     * @param array<string, mixed> $def
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    abstract public function buildColumn(Blueprint $table, string $name, array $def);

    /**
     * 归一化实际列类型，用于与声明类型严格比对。
     */
    abstract public function normalizeColumnType(string $raw): string;

    /**
     * 由列声明推导「期望的实际类型串」，与 normalizeColumnType 同口径比对。
     *
     * @param array<string, mixed> $def
     */
    abstract public function expectedColumnType(array $def): string;

    /**
     * 读取实际索引结构，按索引名聚合。
     *
     * @return array<string, array{unique: bool, columns: array<int, string>}>
     */
    abstract public function actualIndexes(?string $connection, string $table): array;

    /**
     * 默认值是否一致（驱动无关，共享实现）。
     *
     * @param mixed $actualDefault
     * @param array<string, mixed> $def
     */
    public function defaultMatches($actualDefault, array $def): bool
    {
        if (!array_key_exists('default', $def)) {
            return $actualDefault === null;
        }

        $expected = $def['default'];
        if ($expected === null) {
            return $actualDefault === null;
        }
        if ($expected === 'CURRENT_TIMESTAMP') {
            return strtoupper((string) $actualDefault) === 'CURRENT_TIMESTAMP';
        }

        return (string) $actualDefault === (string) $expected;
    }

    /**
     * 读取实际列结构（标准 information_schema，MySQL / PG 通用）。
     *
     * @return array<string, array{type: string, nullable: bool, default: mixed}>
     */
    public function actualColumns(?string $connection, string $table): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            . ' ORDER BY ORDINAL_POSITION',
            [$this->schemaName($connection), $table]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r->COLUMN_NAME] = [
                'type' => $this->normalizeColumnType($r->COLUMN_TYPE),
                'nullable' => strtoupper((string) $r->IS_NULLABLE) === 'YES',
                'default' => $r->COLUMN_DEFAULT,
            ];
        }

        return $out;
    }

    /**
     * 解析索引创建时使用的名字。
     *
     * MySQL：原样返回。
     * PG：索引名在 schema 内全局唯一，若声明名已被别的表占用则自动改名建为 {table}_{声明名}（绝不 drop 别人的索引）。
     */
    public function resolveIndexName(string $declared, string $table, ?string $connection): string
    {
        return $declared;
    }

    /**
     * 当前连接数据库名。
     */
    protected function schemaName(?string $connection): string
    {
        return DB::connection($connection)->getDatabaseName();
    }
}
