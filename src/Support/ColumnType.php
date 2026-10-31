<?php

namespace Dongasai\Dbcheck\Support;

/**
 * 列定义 type 常量。
 *
 * 检查器的 columns() 里应统一使用本类常量，不要写裸字符串：
 *
 *  'id'          => ['type' => ColumnType::ID],
 *  'name'        => ['type' => ColumnType::STRING, 'length' => 50, 'default' => ''],
 *  'status'      => ['type' => ColumnType::UNSIGNED_TINY_INTEGER, 'default' => 1],
 *  'created_at'  => ['type' => ColumnType::TIMESTAMP, 'nullable' => true],
 */
class ColumnType
{
    /**
     * 自增主键：bigIncrements → bigint unsigned
     */
    public const ID = 'id';

    public const BIG_INTEGER = 'bigInteger';

    public const UNSIGNED_BIG_INTEGER = 'unsignedBigInteger';

    public const INTEGER = 'integer';

    public const UNSIGNED_INTEGER = 'unsignedInteger';

    public const SMALL_INTEGER = 'smallInteger';

    public const UNSIGNED_SMALL_INTEGER = 'unsignedSmallInteger';

    public const TINY_INTEGER = 'tinyInteger';

    public const UNSIGNED_TINY_INTEGER = 'unsignedTinyInteger';

    public const BOOLEAN = 'boolean';

    public const STRING = 'string';

    public const CHAR = 'char';

    public const TEXT = 'text';

    public const MEDIUM_TEXT = 'mediumText';

    public const LONG_TEXT = 'longText';

    public const JSON = 'json';

    public const DATE = 'date';

    public const TIME = 'time';

    public const TIMESTAMP = 'timestamp';

    public const DATETIME = 'datetime';

    public const DECIMAL = 'decimal';

    /**
     * 默认类型（列声明未给 type 时回落）。
     */
    public const DEFAULT = self::STRING;

    /**
     * 全部常量值。
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ID,
            self::BIG_INTEGER,
            self::UNSIGNED_BIG_INTEGER,
            self::INTEGER,
            self::UNSIGNED_INTEGER,
            self::SMALL_INTEGER,
            self::UNSIGNED_SMALL_INTEGER,
            self::TINY_INTEGER,
            self::UNSIGNED_TINY_INTEGER,
            self::BOOLEAN,
            self::STRING,
            self::CHAR,
            self::TEXT,
            self::MEDIUM_TEXT,
            self::LONG_TEXT,
            self::JSON,
            self::DATE,
            self::TIME,
            self::TIMESTAMP,
            self::DATETIME,
            self::DECIMAL,
        ];
    }
}
