<?php

namespace Dongasai\Dbcheck\Contracts;

/**
 * 表最终结构声明契约。
 *
 * 表结构检查（AbstractTableCheck）实现本接口，是该表「当前应有完整结构」的唯一声明处：
 * 列（columns）与索引（indexes）均以最终态声明，不描述迁移增量过程。
 * 表不存在时按声明建表，存在时按声明比对并修复差异。
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
