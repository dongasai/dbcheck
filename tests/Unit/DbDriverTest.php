<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Dongasai\Dbcheck\Adapters\MysqlAdapter;
use Dongasai\Dbcheck\Adapters\Mysql8Adapter;
use Dongasai\Dbcheck\Adapters\PgsqlAdapter;
use Dongasai\Dbcheck\Adapters\SqliteAdapter;
use Dongasai\Dbcheck\Tests\TestCase;

/**
 * DbDriver 驱动解析测试（全部走显式覆盖，不触发连库探测）。
 */
class DbDriverTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function aliasProvider(): array
    {
        return [
            'mysql' => ['mysql', Mysql8Adapter::class],
            'mysql8' => ['mysql8', Mysql8Adapter::class],
            'mariadb' => ['mariadb', Mysql8Adapter::class],
            'mysql57' => ['mysql57', MysqlAdapter::class],
            'pgsql' => ['pgsql', PgsqlAdapter::class],
            'postgres' => ['postgres', PgsqlAdapter::class],
            'sqlite' => ['sqlite', SqliteAdapter::class],
            '大小写不敏感' => ['SQLITE', SqliteAdapter::class],
            '类名直传' => [SqliteAdapter::class, SqliteAdapter::class],
        ];
    }

    /**
     * @dataProvider aliasProvider
     * @param class-string $expected
     */
    public function testAdapterClassResolvesAlias(string $alias, string $expected): void
    {
        $this->assertSame($expected, DbDriver::adapterClass(null, $alias));
    }

    public function testAdapterInstantiatesResolvedClass(): void
    {
        $this->assertInstanceOf(SqliteAdapter::class, DbDriver::adapter(null, 'sqlite'));
        $this->assertInstanceOf(PgsqlAdapter::class, DbDriver::adapter(null, 'pgsql'));
    }

    public function testSupportsRecognizesAliasesAndClasses(): void
    {
        $this->assertTrue(DbDriver::supports('sqlite'));
        $this->assertTrue(DbDriver::supports(SqliteAdapter::class));
        $this->assertFalse(DbDriver::supports('unknown-driver'));
    }

    public function testUnknownAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DbDriver::adapterClass(null, 'unknown-driver');
    }

    public function testRuntimeOverrideWinsOverAutoDetect(): void
    {
        DbDriver::override('mysql57');

        $this->assertSame(MysqlAdapter::class, DbDriver::adapterClass(null));

        DbDriver::override(null);
    }
}
