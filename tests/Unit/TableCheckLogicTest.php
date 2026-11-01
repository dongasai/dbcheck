<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Tests\Fixtures\DemoTableCheck;
use Dongasai\Dbcheck\Tests\TestCase;

/**
 * TableCheck 索引等价判定测试（严格模式比列序 / 存在性模式忽略列序）。
 */
class TableCheckLogicTest extends TestCase
{
    /**
     * @param array<string, mixed> $actual
     * @param array<int, string> $columns
     */
    protected function equivalent(array $actual, array $columns, bool $unique, bool $strict): bool
    {
        $reflection = new \ReflectionMethod(DemoTableCheck::class, 'indexEquivalent');
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, [$actual, $columns, $unique, $strict]);
    }

    public function testStrictModeComparesColumnOrder(): void
    {
        $actual = ['unique' => false, 'columns' => ['b', 'a']];

        $this->assertFalse($this->equivalent($actual, ['a', 'b'], false, true));
        $this->assertTrue($this->equivalent($actual, ['b', 'a'], false, true));
    }

    public function testExistentialModeIgnoresColumnOrder(): void
    {
        $actual = ['unique' => false, 'columns' => ['b', 'a']];

        $this->assertTrue($this->equivalent($actual, ['a', 'b'], false, false));
    }

    public function testUniquenessMustMatch(): void
    {
        $actual = ['unique' => false, 'columns' => ['a']];

        $this->assertFalse($this->equivalent($actual, ['a'], true, false));
        $this->assertTrue($this->equivalent($actual, ['a'], false, false));
    }

    public function testEmptyColumnsNeverEquivalent(): void
    {
        $actual = ['unique' => false, 'columns' => []];

        $this->assertFalse($this->equivalent($actual, [], false, false));
    }

    public function testIdenticalIndexIsEquivalent(): void
    {
        $actual = ['unique' => true, 'columns' => ['code', 'id']];

        $this->assertTrue($this->equivalent($actual, ['code', 'id'], true, true));
    }
}
