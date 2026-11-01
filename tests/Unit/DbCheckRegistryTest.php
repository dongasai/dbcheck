<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Dongasai\Dbcheck\Tests\Fixtures\DemoDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoFieldCheck;
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
}
