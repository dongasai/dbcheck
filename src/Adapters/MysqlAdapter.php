<?php

namespace Dongasai\Dbcheck\Adapters;

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
        $type = $def['type'] ?? 'string';
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
            case 'id':
                return $table->bigIncrements($name);
            case 'bigInteger':
                return $table->bigInteger($name);
            case 'unsignedBigInteger':
                return $table->unsignedBigInteger($name);
            case 'integer':
                return $table->integer($name);
            case 'unsignedInteger':
                return $table->unsignedInteger($name);
            case 'smallInteger':
                return $table->smallInteger($name);
            case 'unsignedSmallInteger':
                return $table->unsignedSmallInteger($name);
            case 'tinyInteger':
                return $table->tinyInteger($name);
            case 'unsignedTinyInteger':
                return $table->unsignedTinyInteger($name);
            case 'boolean':
                return $table->boolean($name);
            case 'string':
                return $table->string($name, (int) ($def['length'] ?? 255));
            case 'char':
                return $table->char($name, (int) ($def['length'] ?? 255));
            case 'text':
                return $table->text($name);
            case 'mediumText':
                return $table->mediumText($name);
            case 'longText':
                return $table->longText($name);
            case 'json':
                return $this->jsonColumn($table, $name);
            case 'date':
                return $table->date($name);
            case 'time':
                return $table->time($name);
            case 'timestamp':
                return $table->timestamp($name);
            case 'datetime':
                return $table->dateTime($name);
            case 'decimal':
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
        $type = $def['type'] ?? 'string';

        switch ($type) {
            case 'id':
                return 'bigint unsigned';
            case 'bigInteger':
                return 'bigint';
            case 'unsignedBigInteger':
                return 'bigint unsigned';
            case 'integer':
                return 'int';
            case 'unsignedInteger':
                return 'int unsigned';
            case 'smallInteger':
                return 'smallint';
            case 'unsignedSmallInteger':
                return 'smallint unsigned';
            case 'tinyInteger':
                return 'tinyint';
            case 'unsignedTinyInteger':
                return 'tinyint unsigned';
            case 'boolean':
                return 'tinyint';
            case 'string':
                return 'varchar(' . ((int) ($def['length'] ?? 255)) . ')';
            case 'char':
                return 'char(' . ((int) ($def['length'] ?? 255)) . ')';
            case 'text':
                return 'text';
            case 'mediumText':
                return 'mediumtext';
            case 'longText':
                return 'longtext';
            case 'json':
                return $this->jsonExpectedType();
            case 'date':
                return 'date';
            case 'time':
                return 'time';
            case 'timestamp':
                return 'timestamp';
            case 'datetime':
                return 'datetime';
            case 'decimal':
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
