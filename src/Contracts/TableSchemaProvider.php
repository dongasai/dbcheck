<?php

namespace Dongasai\Dbcheck\Contracts;

/**
 * 表最终结构声明契约。
 *
 * 行差异检查（AbstractTableRowCheck）实现本接口，是该表「当前应有完整结构」的唯一声明处：
 * 列（columns）与索引（indexes）均以最终态声明，不描述迁移增量过程。
 * 存在检查（AbstractTableExistsCheck）通过 schema() 指向本实现，避免两处维护同一张表的定义。
 */
interface TableSchemaProvider
{
    /**
     * 表名。
     */
    public static function table(): string;

    /**
     * 列定义（全部列，最终态）。
     *
     * @return array<string, array<string, mixed>> 列名 => 定义
     */
    public static function columns(): array;

    /**
     * 索引定义（全部索引，最终态，不含主键）。
     *
     * @return array<string, array<string, mixed>> 索引名 => ['columns'=>[...], 'unique'=>bool]
     */
    public static function indexes(): array;
}
