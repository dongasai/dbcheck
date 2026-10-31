<?php

namespace Dongasai\Dbcheck\Adapters;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL 适配器。
 *
 * 建列规则与 MySQL 不同：
 *  - 无 unsigned 概念，unsigned* 系列回落到对应有符号类型；
 *  - tinyInteger 落到 smallint；
 *  - boolean 使用原生 boolean（不落 tinyint）；
 *  - 注释（comment）在 PG 下不生效，忽略；
 *  - 默认值 CURRENT_TIMESTAMP 编译为 PG 关键字（DB::raw）。
 *
 * 比对深度（按规范 §8.1）：仅做存在性检查（缺列 / 缺索引 / 额外列 / 额外索引），
 * 不比类型、可空、默认值与索引列——PG 类型口径与声明差异大，精确比对会大量误报。
 * 因此 comparesColumnAttributes / comparesIndexAttributes 均为 false。
 * 主键索引（{table}_pkey / indisprimary）不参与比对。
 */
class PgsqlAdapter extends DatabaseAdapter
{
    public function driverLabel(): string
    {
        return 'pgsql';
    }

    public function comparesColumnAttributes(): bool
    {
        return false;
    }

    public function comparesIndexAttributes(): bool
    {
        return false;
    }

    /**
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    public function buildColumn(Blueprint $table, string $name, array $def)
    {
        $type = $def['type'] ?? 'string';

        switch ($type) {
            case 'id':
                $col = $table->bigIncrements($name);
                break;
            case 'bigInteger':
            case 'unsignedBigInteger':
                $col = $table->bigInteger($name);
                break;
            case 'integer':
            case 'unsignedInteger':
                $col = $table->integer($name);
                break;
            case 'smallInteger':
            case 'unsignedSmallInteger':
            case 'tinyInteger':
            case 'unsignedTinyInteger':
                $col = $table->smallInteger($name);
                break;
            case 'boolean':
                $col = $table->boolean($name);
                break;
            case 'string':
                $col = $table->string($name, (int) ($def['length'] ?? 255));
                break;
            case 'char':
                $col = $table->char($name, (int) ($def['length'] ?? 255));
                break;
            case 'text':
                $col = $table->text($name);
                break;
            case 'mediumText':
                $col = $table->mediumText($name);
                break;
            case 'longText':
                $col = $table->longText($name);
                break;
            case 'json':
                $col = $table->json($name);
                break;
            case 'date':
                $col = $table->date($name);
                break;
            case 'time':
                $col = $table->time($name);
                break;
            case 'timestamp':
                $col = $table->timestamp($name);
                break;
            case 'datetime':
                $col = $table->dateTime($name);
                break;
            case 'decimal':
                $col = $table->decimal($name, (int) ($def['precision'] ?? 10), (int) ($def['scale'] ?? 0));
                break;
            default:
                throw new \InvalidArgumentException('未知的列类型声明: ' . $type);
        }

        if (!empty($def['nullable'])) {
            $col->nullable();
        }

        if (array_key_exists('default', $def)) {
            $default = $def['default'];
            if ($default === 'CURRENT_TIMESTAMP') {
                $col->default(DB::raw('CURRENT_TIMESTAMP'));
            } else {
                $col->default($default);
            }
        }
        // PG 注释不生效，忽略 $def['comment']

        return $col;
    }

    public function normalizeColumnType(string $raw): string
    {
        return strtolower(preg_replace('/\s+/', ' ', $raw));
    }

    public function expectedColumnType(array $def): string
    {
        // PG 仅做存在性比对，期望类型不参与严格比较；返回声明类型的小写形式即可。
        return strtolower($def['type'] ?? 'string');
    }

    public function actualIndexes(?string $connection, string $table): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT c.relname AS index_name, i.indisunique AS is_unique, i.indisprimary AS is_primary,'
            . ' pg_get_indexdef(i.indexrelid) AS def'
            . ' FROM pg_class c'
            . ' JOIN pg_index i ON i.indexrelid = c.oid'
            . ' JOIN pg_class t ON t.oid = i.indrelid'
            . ' WHERE LOWER(t.relname) = LOWER(?)'
            . ' AND t.relnamespace NOT IN ('
            . '   SELECT oid FROM pg_namespace WHERE nspname IN (\'pg_catalog\', \'information_schema\')'
            . ' )',
            [$table]
        );

        $map = [];
        foreach ($rows as $r) {
            if (!empty($r->is_primary)) {
                continue;
            }
            preg_match('/\(([^)]*)\)/', $r->def, $m);
            $cols = isset($m[1]) ? explode(',', $m[1]) : [];
            $cols = array_map(function ($c) {
                return trim(trim($c), '"');
            }, $cols);
            $map[$r->index_name] = [
                'unique' => (bool) $r->is_unique,
                'columns' => array_values($cols),
            ];
        }

        return $map;
    }

    public function resolveIndexName(string $declared, string $table, ?string $connection): string
    {
        $row = DB::connection($connection)->selectOne(
            "SELECT 1 FROM pg_class c WHERE c.relkind = 'i' AND c.relname = ?",
            [$declared]
        );

        if ($row) {
            return $table . '_' . $declared;
        }

        return $declared;
    }
}
