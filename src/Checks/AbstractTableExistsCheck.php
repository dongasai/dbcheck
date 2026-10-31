<?php

namespace Dongasai\Dbcheck\Checks;

use Dongasai\Dbcheck\Adapters\AdapterFactory;
use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * 结构检查基类：承载结构工具（建列 / 读 information_schema / 差异计算入口），并实现「存在」类检查。
 *
 * - ExistsCheck（本类的直接子类）：check = 表是否存在；fix = 按 columns()+indexes() 建表。
 * - RowCheck（间接子类）：继承本类并在此之上追加列/索引差异检查与修复；通过 schema() 指向自身声明结构。
 *
 * 本类通过 schema() 取得「最终结构声明」（RowCheck 类），避免存在/行差异两处维护同一张表定义。
 * 实际建列由驱动适配器（AdapterFactory）完成，以屏蔽 MySQL / PG 类型差异。
 */
abstract class AbstractTableExistsCheck implements DbCheckInterface
{
    /**
     * 是否启用该检查器（自动发现时 $enabled=false 会被跳过）。
     */
    public static bool $enabled = true;

    /**
     * 所属分组（分组名由业务自行定义，框架不预置）。
     *
     *  - 返回 null：常规检查，dbcheck:check 默认执行；
     *  - 返回任意字符串（如 'testdata'）：分组检查，默认不执行，
     *    需 dbcheck:check --group=<分组名> 显式触发（所谓 seeder 即执行该分组的 row/field 检查）。
     */
    public static function group(): ?string
    {
        return null;
    }

    /**
     * 最终结构声明类（实现 TableSchemaProvider）。
     *
     * 直接子类（ExistsCheck）覆盖此方法指向对应的 RowCheck；RowCheck 自身返回 static::class。
     *
     * @return class-string
     */
    protected static function schema(): string
    {
        return static::class;
    }

    /**
     * 使用的数据库连接名（null = 默认连接）。具体检查器可覆盖。
     */
    protected static function connectionName(): ?string
    {
        return null;
    }

    /**
     * 表名（由结构声明类提供；存在检查的具体子类经 schema* 辅助方法委托）。
     */
    abstract public static function table(): string;

    /**
     * 列定义（由结构声明类提供）。
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function columns(): array;

    /**
     * 索引定义（由结构声明类提供）。
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function indexes(): array;

    /**
     * 经 schema() 取得结构声明类的表名（供「存在检查」具体子类委托）。
     */
    protected static function schemaTable(): string
    {
        $schema = static::schema();

        return $schema::table();
    }

    /**
     * 经 schema() 取得结构声明类的列定义。
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function schemaColumns(): array
    {
        $schema = static::schema();

        return $schema::columns();
    }

    /**
     * 经 schema() 取得结构声明类的索引定义。
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function schemaIndexes(): array
    {
        $schema = static::schema();

        return $schema::indexes();
    }

    /**
     * 展示名（默认取表名）。
     */
    public static function moduleName(): string
    {
        return static::table();
    }

    /**
     * 表是否存在。
     */
    protected static function hasTable(): bool
    {
        return Schema::connection(static::connectionName())->hasTable(static::table());
    }

    /**
     * 存在类检查：表缺失则报一项，否则空。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(): array
    {
        if (static::hasTable()) {
            return [];
        }

        return [
            [
                'key' => 'table',
                'type' => 'table',
                'message' => '表 ' . static::table() . ' 不存在',
                'optional' => false,
                'repair' => '按最终结构建表 ' . static::table(),
            ],
        ];
    }

    /**
     * 修复：表缺失 → 建表。未知 key 抛异常。
     */
    public static function fix(string $key): void
    {
        if ($key !== 'table') {
            throw new LogicException('未知修复项: ' . $key);
        }
        static::createTable();
    }

    /**
     * 预览建表 SQL（pretend 模式，不落库）。
     *
     * @return array<int, string>
     */
    public static function preview(string $key): array
    {
        return DB::connection(static::connectionName())->pretend(function () use ($key) {
            static::fix($key);
        });
    }

    /**
     * 按 columns()+indexes() 建表。
     */
    protected static function createTable(): void
    {
        $adapter = AdapterFactory::make(static::connectionName());

        Schema::connection(static::connectionName())->create(static::table(), function ($table) use ($adapter) {
            foreach (static::columns() as $name => $def) {
                $adapter->buildColumn($table, (string) $name, $def);
            }
            foreach (static::indexes() as $name => $idx) {
                $cols = (array) ($idx['columns'] ?? []);
                if (!empty($idx['unique'])) {
                    $table->unique($cols, (string) $name);
                } else {
                    $table->index($cols, (string) $name);
                }
            }
        });
    }
}
