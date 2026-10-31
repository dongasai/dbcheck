<?php

namespace Dongasai\Dbcheck\Adapters;

use Dongasai\Dbcheck\Support\ColumnType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 适配器（含 5.7 等无原生 JSON 的旧版本）。
 *
 * json 类型落地为 longText（旧版本无 JSON 类型）；
 * 列属性与索引属性均做精确比对。
 *
 * MySQL 8+ 应使用 Mysql8Adapter（json 原生类型）。
 */
class MysqlAdapter extends DatabaseAdapter
{
    public function driverLabel(): string
    {
        return 'mysql';
    }

    public function comparesColumnAttributes(): bool
    {
        return true;
    }

    public function comparesIndexAttributes(): bool
    {
        return true;
    }

    /**
     * json 期望类型（旧版本无原生 JSON，落 longText）。
     */
    protected function jsonExpectedType(): string
    {
        return 'longtext';
    }

    /**
     * json 建列方式（旧版本无原生 JSON，落 longText）。
     *
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    protected function jsonColumn(Blueprint $table, string $name)
    {
        return $table->longText($name);
    }

    /**
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    public function buildColumn(Blueprint $table, string $name, array $def)
    {
        $type = $def['type'] ?? ColumnType::DEFAULT;
        $col = $this->newColumn($table, $name, $type, $def);
        $this->applyModifiers($col, $def);

        return $col;
    }

    /**
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    protected function newColumn(Blueprint $table, string $name, string $type, array $def)
    {
        switch ($type) {
            case ColumnType::ID:
                return $table->bigIncrements($name);
            case ColumnType::BIG_INTEGER:
                return $table->bigInteger($name);
            case ColumnType::UNSIGNED_BIG_INTEGER:
                return $table->unsignedBigInteger($name);
            case ColumnType::INTEGER:
                return $table->integer($name);
            case ColumnType::UNSIGNED_INTEGER:
                return $table->unsignedInteger($name);
            case ColumnType::SMALL_INTEGER:
                return $table->smallInteger($name);
            case ColumnType::UNSIGNED_SMALL_INTEGER:
                return $table->unsignedSmallInteger($name);
            case ColumnType::TINY_INTEGER:
                return $table->tinyInteger($name);
            case ColumnType::UNSIGNED_TINY_INTEGER:
                return $table->unsignedTinyInteger($name);
            case ColumnType::BOOLEAN:
                return $table->boolean($name);
            case ColumnType::STRING:
                return $table->string($name, (int) ($def['length'] ?? 255));
            case ColumnType::CHAR:
                return $table->char($name, (int) ($def['length'] ?? 255));
            case ColumnType::TEXT:
                return $table->text($name);
            case ColumnType::MEDIUM_TEXT:
                return $table->mediumText($name);
            case ColumnType::LONG_TEXT:
                return $table->longText($name);
            case ColumnType::JSON:
                return $this->jsonColumn($table, $name);
            case ColumnType::DATE:
                return $table->date($name);
            case ColumnType::TIME:
                return $table->time($name);
            case ColumnType::TIMESTAMP:
                return $table->timestamp($name);
            case ColumnType::DATETIME:
                return $table->dateTime($name);
            case ColumnType::DECIMAL:
                return $table->decimal($name, (int) ($def['precision'] ?? 10), (int) ($def['scale'] ?? 0));
            default:
                throw new \InvalidArgumentException('未知的列类型声明: ' . $type);
        }
    }

    /**
     * 应用可空 / 默认值 / 注释（json 等不影响）。
     *
     * @param \Illuminate\Database\Schema\ColumnDefinition $col
     * @param array<string, mixed> $def
     */
    protected function applyModifiers($col, array $def): void
    {
        if (!empty($def['nullable'])) {
            $col->nullable();
        }

        if (array_key_exists('default', $def)) {
            $default = $def['default'];
            if ($default === 'CURRENT_TIMESTAMP') {
                $col->useCurrent();
            } else {
                $col->default($default);
            }
        }

        if (!empty($def['comment'])) {
            $col->comment((string) $def['comment']);
        }
    }

    public function normalizeColumnType(string $raw): string
    {
        $type = strtolower($raw);
        $type = preg_replace('/^(big|small|tiny|medium)?int(eger)?\(\d+\)/', '$1int$2', $type);
        $type = preg_replace('/\s+/', ' ', $type);

        return trim($type);
    }

    public function expectedColumnType(array $def): string
    {
        $type = $def['type'] ?? ColumnType::DEFAULT;

        switch ($type) {
            case ColumnType::ID:
                return 'bigint unsigned';
            case ColumnType::BIG_INTEGER:
                return 'bigint';
            case ColumnType::UNSIGNED_BIG_INTEGER:
                return 'bigint unsigned';
            case ColumnType::INTEGER:
                return 'int';
            case ColumnType::UNSIGNED_INTEGER:
                return 'int unsigned';
            case ColumnType::SMALL_INTEGER:
                return 'smallint';
            case ColumnType::UNSIGNED_SMALL_INTEGER:
                return 'smallint unsigned';
            case ColumnType::TINY_INTEGER:
                return 'tinyint';
            case ColumnType::UNSIGNED_TINY_INTEGER:
                return 'tinyint unsigned';
            case ColumnType::BOOLEAN:
                return 'tinyint';
            case ColumnType::STRING:
                return 'varchar(' . ((int) ($def['length'] ?? 255)) . ')';
            case ColumnType::CHAR:
                return 'char(' . ((int) ($def['length'] ?? 255)) . ')';
            case ColumnType::TEXT:
                return 'text';
            case ColumnType::MEDIUM_TEXT:
                return 'mediumtext';
            case ColumnType::LONG_TEXT:
                return 'longtext';
            case ColumnType::JSON:
                return $this->jsonExpectedType();
            case ColumnType::DATE:
                return 'date';
            case ColumnType::TIME:
                return 'time';
            case ColumnType::TIMESTAMP:
                return 'timestamp';
            case ColumnType::DATETIME:
                return 'datetime';
            case ColumnType::DECIMAL:
                return 'decimal(' . ((int) ($def['precision'] ?? 10)) . ',' . ((int) ($def['scale'] ?? 0)) . ')';
            default:
                throw new \InvalidArgumentException('未知的列类型声明: ' . $type);
        }
    }

    public function actualIndexes(?string $connection, string $table): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->schemaName($connection), $table]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[$r->INDEX_NAME]['unique'] = !(int) $r->NON_UNIQUE;
            $map[$r->INDEX_NAME]['columns'][(int) $r->SEQ_IN_INDEX] = $r->COLUMN_NAME;
        }

        foreach ($map as &$m) {
            ksort($m['columns']);
            $m['columns'] = array_values($m['columns']);
        }
        unset($m);

        return $map;
    }
}
