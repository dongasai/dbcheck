<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Adapters\MysqlAdapter;
use Dongasai\Dbcheck\Support\ColumnType;
use PHPUnit\Framework\TestCase;

/**
 * MySQL 适配器纯逻辑测试（期望类型 / 类型归一 / 默认值比较，不连库）。
 */
class MysqlAdapterTest extends TestCase
{
    /**
     * @var MysqlAdapter
     */
    protected $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MysqlAdapter();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function expectedTypeProvider(): array
    {
        return [
            'id' => [['type' => ColumnType::ID], 'bigint unsigned'],
            'bigInteger' => [['type' => ColumnType::BIG_INTEGER], 'bigint'],
            'unsignedBigInteger' => [['type' => ColumnType::UNSIGNED_BIG_INTEGER], 'bigint unsigned'],
            'integer' => [['type' => ColumnType::INTEGER], 'int'],
            'unsignedInteger' => [['type' => ColumnType::UNSIGNED_INTEGER], 'int unsigned'],
            'smallInteger' => [['type' => ColumnType::SMALL_INTEGER], 'smallint'],
            'tinyInteger' => [['type' => ColumnType::TINY_INTEGER], 'tinyint'],
            'unsignedTinyInteger' => [['type' => ColumnType::UNSIGNED_TINY_INTEGER], 'tinyint unsigned'],
            'boolean' => [['type' => ColumnType::BOOLEAN], 'tinyint'],
            'string 默认长度' => [['type' => ColumnType::STRING], 'varchar(255)'],
            'string 指定长度' => [['type' => ColumnType::STRING, 'length' => 50], 'varchar(50)'],
            'char' => [['type' => ColumnType::CHAR, 'length' => 2], 'char(2)'],
            'text' => [['type' => ColumnType::TEXT], 'text'],
            'mediumText' => [['type' => ColumnType::MEDIUM_TEXT], 'mediumtext'],
            'longText' => [['type' => ColumnType::LONG_TEXT], 'longtext'],
            'json 落 longText' => [['type' => ColumnType::JSON], 'longtext'],
            'date' => [['type' => ColumnType::DATE], 'date'],
            'time' => [['type' => ColumnType::TIME], 'time'],
            'timestamp' => [['type' => ColumnType::TIMESTAMP], 'timestamp'],
            'datetime' => [['type' => ColumnType::DATETIME], 'datetime'],
            'decimal 默认精度' => [['type' => ColumnType::DECIMAL], 'decimal(10,0)'],
            'decimal 指定精度' => [['type' => ColumnType::DECIMAL, 'precision' => 8, 'scale' => 2], 'decimal(8,2)'],
            'enum' => [['type' => ColumnType::ENUM, 'values' => ['a', 'b']], "enum('a','b')"],
        ];
    }

    /**
     * @dataProvider expectedTypeProvider
     * @param array<string, mixed> $def
     */
    public function testExpectedColumnType(array $def, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->expectedColumnType($def));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function normalizeProvider(): array
    {
        return [
            'bigint 去宽度' => ['bigint(20) unsigned', 'bigint unsigned'],
            'int 去宽度' => ['int(11)', 'int'],
            'tinyint 去宽度' => ['TINYINT(1)', 'tinyint'],
            '无宽度不变' => ['varchar(50)', 'varchar(50)'],
            '多余空格压缩' => ['bigint(20)   unsigned', 'bigint unsigned'],
        ];
    }

    /**
     * @dataProvider normalizeProvider
     */
    public function testNormalizeColumnType(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->normalizeColumnType($raw));
    }

    /**
     * @return array<string, array{0: mixed, 1: array<string, mixed>, 2: bool}>
     */
    public function defaultMatchesProvider(): array
    {
        return [
            '未声明 default 且实际 NULL' => [null, [], true],
            '未声明 default 但实际有值' => ['0', [], false],
            '声明 null 且实际 NULL' => [null, ['default' => null], true],
            '声明字符串一致' => ['pending', ['default' => 'pending'], true],
            '字符串数字比较' => ['0', ['default' => 0], true],
            '声明 CURRENT_TIMESTAMP 大小写不敏感' => ['current_timestamp', ['default' => 'CURRENT_TIMESTAMP'], true],
            '不一致' => ['1', ['default' => 0], false],
        ];
    }

    /**
     * @dataProvider defaultMatchesProvider
     * @param mixed $actual
     * @param array<string, mixed> $def
     */
    public function testDefaultMatches($actual, array $def, bool $expected): void
    {
        $this->assertSame($expected, $this->adapter->defaultMatches($actual, $def));
    }

    public function testDriverLabel(): void
    {
        $this->assertSame('mysql', $this->adapter->driverLabel());
    }
}
