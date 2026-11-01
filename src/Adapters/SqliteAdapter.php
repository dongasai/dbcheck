<?php

namespace Dongasai\Dbcheck\Adapters;

use Illuminate\Support\Facades\DB;

/**
 * SQLite 适配器（本地开发 / 测试 / 轻量部署）。
 *
 * 取舍：
 *  - SQLite 是动态类型，声明类型只作亲和性，且不同 Laravel 版本的 SQLite 建表 grammar
 *    输出不同（integer / varchar / text / datetime 等），精确比对必大量误报，
 *    因此列只做存在性比对（comparesColumnAttributes=false）；
 *  - 索引的「唯一性 + 列（顺序）」可由 PRAGMA 稳定读到，仍做精确比对；
 *  - 建列复用 MySQL 映射（Laravel 的 SQLite grammar 会忽略 unsigned、json 落 text 等）；
 *  - 主键（INTEGER PRIMARY KEY）在 PRAGMA 里 notnull=0，按非空处理，避免 id 列误报。
 */
class SqliteAdapter extends MysqlAdapter
{
    public function driverLabel(): string
    {
        return 'sqlite';
    }

    public function comparesColumnAttributes(): bool
    {
        return false;
    }

    public function comparesIndexAttributes(): bool
    {
        return true;
    }

    /**
     * 读取实际列结构（PRAGMA table_info）。
     *
     * @return array<string, array{type: string, nullable: bool, default: mixed}>
     */
    public function actualColumns(?string $connection, string $table): array
    {
        $rows = DB::connection($connection)->select(
            'PRAGMA table_info(' . $this->quoteIdentifier($table) . ')'
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r->name] = [
                'type' => $this->normalizeColumnType((string) $r->type),
                // INTEGER PRIMARY KEY 允许 NULL 自增，PRAGMA 的 notnull 为 0，这里按非空处理
                'nullable' => (int) $r->notnull === 0 && (int) $r->pk === 0,
                'default' => $this->normalizeDefault($r->dflt_value),
            ];
        }

        return $out;
    }

    /**
     * 读取实际索引（PRAGMA index_list + index_info，跳过主键自动索引）。
     *
     * @return array<string, array{unique: bool, columns: array<int, string>}>
     */
    public function actualIndexes(?string $connection, string $table): array
    {
        $list = DB::connection($connection)->select(
            'PRAGMA index_list(' . $this->quoteIdentifier($table) . ')'
        );

        $map = [];
        foreach ($list as $idx) {
            // origin：c=CREATE INDEX、u=UNIQUE 约束、pk=主键；主键不参与比对
            if ((string) ($idx->origin ?? '') === 'pk') {
                continue;
            }

            $name = (string) $idx->name;
            $columns = [];
            $info = DB::connection($connection)->select(
                'PRAGMA index_info(' . $this->quoteIdentifier($name) . ')'
            );
            foreach ($info as $row) {
                if ($row->name !== null) {
                    $columns[(int) $row->seqno] = (string) $row->name;
                }
            }
            ksort($columns);

            $map[$name] = [
                'unique' => !empty($idx->unique),
                'columns' => array_values($columns),
            ];
        }

        return $map;
    }

    /**
     * SQLite 无表编码概念，编码比对自动跳过。
     *
     * @return array{charset: string, collation: string}|null
     */
    public function actualTableCharset(?string $connection, string $table): ?array
    {
        return null;
    }

    /**
     * PRAGMA 不支持绑定参数，标识符按 SQLite 规则（双引号 + 双写转义）内联。
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * PRAGMA 的默认值带引号（如 `''`、`'pending'`），去掉外层单引号并反转义，
     * 与声明里的字符串默认值同口径比较。
     *
     * @param mixed $value
     * @return mixed
     */
    protected function normalizeDefault($value)
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = str_replace("''", "'", substr($value, 1, -1));
        }
        if (strtoupper($value) === 'NULL') {
            return null;
        }

        return $value;
    }
}
