<?php

namespace Dongasai\Dbcheck\Adapters;

use Illuminate\Database\Schema\Blueprint;

/**
 * MySQL 8+ 适配器。
 *
 * 与 MysqlAdapter 的唯一差异：json 使用原生 JSON 类型（建列 $table->json()、期望类型 json）。
 * 列属性与索引属性均做精确比对。
 */
class Mysql8Adapter extends MysqlAdapter
{
    public function driverLabel(): string
    {
        return 'mysql8';
    }

    /**
     * MySQL 8+ 原生 JSON 类型。
     */
    protected function jsonExpectedType(): string
    {
        return 'json';
    }

    /**
     * MySQL 8+ 原生 JSON 类型。
     *
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    protected function jsonColumn(Blueprint $table, string $name)
    {
        return $table->json($name);
    }
}
