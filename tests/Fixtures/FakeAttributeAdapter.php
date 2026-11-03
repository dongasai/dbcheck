<?php

namespace Dongasai\Dbcheck\Tests\Fixtures;

use Dongasai\Dbcheck\Adapters\MysqlAdapter;

/**
 * 测试用「MySQL 语义」适配器：列属性精确比对 + 固定元数据（不读 information_schema）。
 *
 * 用于在 SQLite 测试库里验证 MySQL 专属的比对/聚合逻辑（SQLite 适配器只做列存在性比对）。
 */
class FakeAttributeAdapter extends MysqlAdapter
{
    public function driverLabel(): string
    {
        return 'fake';
    }

    /**
     * @return array<string, array{type: string, nullable: bool, default: mixed}>
     */
    public function actualColumns(?string $connection, string $table): array
    {
        return [
            'id' => ['type' => 'bigint unsigned', 'nullable' => false, 'default' => null],
            // 与声明（varchar(50) / 非空 / 默认 ''）三处都不一致，用于验证聚合为一项
            'name' => ['type' => 'varchar(10)', 'nullable' => true, 'default' => 'x'],
            'status' => ['type' => 'tinyint unsigned', 'nullable' => false, 'default' => '1'],
            'created_at' => ['type' => 'timestamp', 'nullable' => true, 'default' => null],
        ];
    }

    /**
     * @return array<string, array{unique: bool, columns: array<int, string>}>
     */
    public function actualIndexes(?string $connection, string $table): array
    {
        return [];
    }

    /**
     * @return array{charset: string, collation: string}|null
     */
    public function actualTableCharset(?string $connection, string $table): ?array
    {
        return null;
    }
}
