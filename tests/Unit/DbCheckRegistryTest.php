<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Dongasai\Dbcheck\Tests\Fixtures\DemoDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoFieldCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoSeederDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DirectCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DisabledTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\TestdataDataCheck;
use Dongasai\Dbcheck\Tests\TestCase;

/**
 * DbCheckRegistry 自动发现/过滤/归类/分组测试（scan 为空，全走手动 register）。
 */
class DbCheckRegistryTest extends TestCase
{
    public function testManualRegisterIsIncludedAndDeduplicated(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(DemoTableCheck::class);

        $all = DbCheckRegistry::all();

        $this->assertCount(1, $all);
        $this->assertSame(DemoTableCheck::class, $all[0]);
    }

    public function testDisabledCheckIsSkipped(): void
    {
        DbCheckRegistry::register(DisabledTableCheck::class);

        $this->assertSame([], DbCheckRegistry::all());
    }

    public function testGroupedClassifiesByBaseClass(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(DemoDataCheck::class);
        DbCheckRegistry::register(DemoFieldCheck::class);

        $groups = DbCheckRegistry::grouped(null, true);

        $this->assertSame([DemoTableCheck::class], $groups['table']);
        $this->assertSame([DemoDataCheck::class], $groups['data']);
        $this->assertSame([DemoFieldCheck::class], $groups['field']);
    }

    public function testDirectImplementerThrowsLogicException(): void
    {
        DbCheckRegistry::register(DirectCheck::class);

        $this->expectException(\LogicException::class);

        DbCheckRegistry::grouped(null, true);
    }

    public function testGroupsAndGroupedFilterByGroupName(): void
    {
        DbCheckRegistry::register(TestdataDataCheck::class);
        DbCheckRegistry::register(DemoTableCheck::class);

        $this->assertSame(['testdata' => 1], DbCheckRegistry::groups());
        $this->assertSame('testdata', DbCheckRegistry::groupOf(TestdataDataCheck::class));
        $this->assertNull(DbCheckRegistry::groupOf(DemoTableCheck::class));

        $regular = DbCheckRegistry::grouped(null);
        $this->assertSame([DemoTableCheck::class], $regular['table']);
        $this->assertSame([], $regular['data']);

        $testdata = DbCheckRegistry::grouped('testdata');
        $this->assertSame([TestdataDataCheck::class], $testdata['data']);
        $this->assertSame([], $testdata['table']);
    }

    public function testClearResetsRegistration(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::clear();

        $this->assertSame([], DbCheckRegistry::all());
    }

    /**
     * counts() 按继承的抽象基类分类计数，直接实现接口的类计入 unknown。
     */
    public function testCountsClassifiesByBaseClass(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(DemoDataCheck::class);
        DbCheckRegistry::register(DemoFieldCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);
        DbCheckRegistry::register(DirectCheck::class);

        $this->assertSame([
            'table' => 1,
            'data' => 2,
            'field' => 1,
            'unknown' => 1,
            'total' => 5,
        ], DbCheckRegistry::counts());
    }

    /**
     * counts() 遇「直接实现接口未继承基类」的类不抛异常（仅供诊断展示，不阻断）。
     */
    public function testCountsToleratesDirectImplementer(): void
    {
        DbCheckRegistry::register(DirectCheck::class);

        $counts = DbCheckRegistry::counts();

        $this->assertSame(1, $counts['unknown']);
        $this->assertSame(0, $counts['table']);
        $this->assertSame(0, $counts['data']);
        $this->assertSame(0, $counts['field']);
        $this->assertSame(1, $counts['total']);
    }

    /**
     * groupCounts() 按「分组 × 类型」统计：常规第一，其余分组按字典序；未继承基类的类计入 unknown。
     */
    public function testGroupCountsByGroupAndType(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(DemoDataCheck::class);
        DbCheckRegistry::register(DemoFieldCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);
        DbCheckRegistry::register(DemoSeederDataCheck::class);
        DbCheckRegistry::register(DirectCheck::class);

        $this->assertSame([
            [
                'group' => null,
                'table' => 1,
                'data' => 1,
                'field' => 1,
                'unknown' => 1,
                'total' => 4,
            ],
            [
                'group' => 'seeder',
                'table' => 0,
                'data' => 1,
                'field' => 0,
                'unknown' => 0,
                'total' => 1,
            ],
            [
                'group' => 'testdata',
                'table' => 0,
                'data' => 1,
                'field' => 0,
                'unknown' => 0,
                'total' => 1,
            ],
        ], DbCheckRegistry::groupCounts());
    }

    /**
     * scan 配置为空时扫描根报告为空数组。
     */
    public function testScanRootsEmptyWithoutConfig(): void
    {
        $this->assertSame([], DbCheckRegistry::scanRoots());
    }

    /**
     * 普通扫描根：报告 matched=true 与实际命中的类数量。
     */
    public function testScanRootsReportsMatchedRoot(): void
    {
        $fixtures = dirname(__DIR__) . '/Fixtures';
        config(['dbcheck.scan' => [
            ['path' => $fixtures, 'namespace' => 'Dongasai\\Dbcheck\\Tests\\Fixtures'],
        ]]);

        $roots = DbCheckRegistry::scanRoots();

        $this->assertCount(1, $roots);
        $this->assertSame($fixtures, $roots[0]['path']);
        $this->assertSame('Dongasai\\Dbcheck\\Tests\\Fixtures', $roots[0]['namespace']);
        $this->assertTrue($roots[0]['matched']);
        // 与 all() 同口径：过滤掉非检查器类（FakeAttributeAdapter）与被禁用类（DisabledTableCheck）
        $this->assertSame(count(DbCheckRegistry::all()), $roots[0]['count']);
        $this->assertLessThan(count((array) glob($fixtures . '/*.php')), $roots[0]['count']);
    }

    /**
     * 通配模板根：展开为具体根（namespace 的 * 以目录名替换）并报告命中数。
     */
    public function testScanRootsExpandsWildcardRoot(): void
    {
        config(['dbcheck.scan' => [
            ['path' => dirname(__DIR__) . '/Fixture*', 'namespace' => 'Dongasai\\Dbcheck\\Tests\\Fixture*'],
        ]]);

        $roots = DbCheckRegistry::scanRoots();

        $this->assertCount(1, $roots);
        $this->assertTrue($roots[0]['matched']);
        $this->assertStringEndsWith('Fixtures', str_replace('\\', '/', $roots[0]['path']));
        $this->assertSame('Dongasai\\Dbcheck\\Tests\\Fixtures', $roots[0]['namespace']);
        $this->assertSame(count(DbCheckRegistry::all()), $roots[0]['count']);
    }

    /**
     * 目录不存在的扫描根：matched=false、count=0（不抛异常）。
     */
    public function testScanRootsReportsUnmatchedRoot(): void
    {
        $missing = dirname(__DIR__) . '/__no_such_dbcheck_dir__';
        config(['dbcheck.scan' => [
            ['path' => $missing, 'namespace' => 'Dongasai\\Dbcheck\\Tests\\Missing'],
        ]]);

        $roots = DbCheckRegistry::scanRoots();

        $this->assertCount(1, $roots);
        $this->assertSame($missing, $roots[0]['path']);
        $this->assertFalse($roots[0]['matched']);
        $this->assertSame(0, $roots[0]['count']);
    }
}
