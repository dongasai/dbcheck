<?php

namespace Dongasai\Dbcheck\Adapters;

use Dongasai\Dbcheck\Support\ColumnType;
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
        $type = $def['type'] ?? ColumnType::DEFAULT;

        switch ($type) {
            case ColumnType::ID:
                $col = $table->bigIncrements($name);
                break;
            case ColumnType::BIG_INTEGER:
            case ColumnType::UNSIGNED_BIG_INTEGER:
                $col = $table->bigInteger($name);
                break;
            case ColumnType::INTEGER:
            case ColumnType::UNSIGNED_INTEGER:
                $col = $table->integer($name);
                break;
            case ColumnType::SMALL_INTEGER:
            case ColumnType::UNSIGNED_SMALL_INTEGER:
            case ColumnType::TINY_INTEGER:
            case ColumnType::UNSIGNED_TINY_INTEGER:
                $col = $table->smallInteger($name);
                break;
            case ColumnType::BOOLEAN:
                $col = $table->boolean($name);
                break;
            case ColumnType::STRING:
                $col = $table->string($name, (int) ($def['length'] ?? 255));
                break;
            case ColumnType::CHAR:
                $col = $table->char($name, (int) ($def['length'] ?? 255));
                break;
            case ColumnType::TEXT:
                $col = $table->text($name);
                break;
            case ColumnType::MEDIUM_TEXT:
                $col = $table->mediumText($name);
                break;
            case ColumnType::LONG_TEXT:
                $col = $table->longText($name);
                break;
            case ColumnType::JSON:
                $col = $table->json($name);
                break;
            case ColumnType::DATE:
                $col = $table->date($name);
                break;
            case ColumnType::TIME:
                $col = $table->time($name);
                break;
            case ColumnType::TIMESTAMP:
                $col = $table->timestamp($name);
                break;
            case ColumnType::DATETIME:
                $col = $table->dateTime($name);
                break;
            case ColumnType::DECIMAL:
                $col = $table->decimal($name, (int) ($def['precision'] ?? 10), (int) ($def['scale'] ?? 0));
                break;
            case ColumnType::ENUM:
                // PG 下由 Laravel 编译为带 check 约束的文本列
                $col = $table->enum($name, array_map('strval', (array) ($def['values'] ?? [])));
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
        return strtolower($def['type'] ?? ColumnType::DEFAULT);
    }

    /**
     * 读取实际列结构（PG 专用实现）。
     *
     * PG 的 information_schema.COLUMNS.TABLE_SCHEMA 是 schema 名（public 等），
     * 不是数据库名，因此不能像 MySQL 那样用 getDatabaseName() 过滤；
     * 改为按表名（大小写不敏感）跨 schema 匹配并排除系统 schema，
     * 避免多 schema / 非默认 search_path 部署下「列明明存在却报缺失」。
     *
     * @return array<string, array{type: string, nullable: bool, default: mixed}>
     */
    public function actualColumns(?string $connection, string $table): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT c.column_name AS column_name,'
            . ' c.data_type AS data_type,'
            . ' c.character_maximum_length AS max_length,'
            . ' c.is_nullable AS is_nullable,'
            . ' c.column_default AS column_default'
            . ' FROM information_schema.columns c'
            . ' JOIN information_schema.tables t'
            . '   ON t.table_schema = c.table_schema AND t.table_name = c.table_name'
            . ' WHERE LOWER(c.table_name) = LOWER(?)'
            . ' AND c.table_schema NOT IN (\'pg_catalog\', \'information_schema\')'
            . ' AND t.table_type = \'BASE TABLE\''
            . ' ORDER BY c.ordinal_position',
            [$table]
        );

        $out = [];
        foreach ($rows as $r) {
            $type = (string) $r->data_type;
            // 定长/变长字符带上长度，与 MySQL 口径一致，便于额外列提示展示
            if ($r->max_length !== null && in_array($type, ['character varying', 'character'], true)) {
                $type .= '(' . (int) $r->max_length . ')';
            }
            $out[$r->column_name] = [
                'type' => $this->normalizeColumnType($type),
                'nullable' => strtoupper((string) $r->is_nullable) === 'YES',
                'default' => $r->column_default,
            ];
        }

        return $out;
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
