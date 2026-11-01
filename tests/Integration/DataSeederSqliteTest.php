<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Support\DbCheckGroup;
use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Dongasai\Dbcheck\Tests\Fixtures\DemoDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoSeederDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\TestdataDataCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 播种分组 × SQLite 集成测试：SEEDER 声明生效、默认扫隔离（分组机制）、
 * 自定义分组不被 seed 口径收集、缺行补插后复检全绿。
 */
class DataSeederSqliteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('demo_seed', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 50)->unique();
            $table->string('title')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function testSeederGroupConstantAndDeclaration(): void
    {
        $this->assertSame('seeder', DbCheckGroup::SEEDER);
        $this->assertSame(DbCheckGroup::SEEDER, DemoSeederDataCheck::group());
        // 未声明分组的检查器保持常规
        $this->assertNull(DemoDataCheck::group());
    }

    public function testSeederGroupIsExcludedFromDefaultScanAndCollectedBySeeder(): void
    {
        DbCheckRegistry::register(DemoSeederDataCheck::class);
        DbCheckRegistry::register(DemoDataCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);

        // 默认扫（常规）不含 seeder 分组检查器；dbcheck:seed 口径（grouped(seeder)）只收它
        $regular = DbCheckRegistry::grouped(null);
        $this->assertSame([DemoDataCheck::class], $regular['data']);
        $this->assertSame([], DbCheckRegistry::grouped(DbCheckGroup::SEEDER)['table']);

        $seeders = DbCheckRegistry::grouped(DbCheckGroup::SEEDER);
        $this->assertSame([DemoSeederDataCheck::class], $seeders['data']);
        $this->assertSame([], $seeders['field']);

        // 自定义分组（testdata）不被 seed 默认口径收集
        $this->assertNotContains(TestdataDataCheck::class, DbCheckRegistry::grouped(DbCheckGroup::SEEDER)['data']);
        $this->assertSame([TestdataDataCheck::class], DbCheckRegistry::grouped('testdata')['data']);
    }

    public function testSeedPlantsMissingRowsAndRechecksGreen(): void
    {
        $issues = DemoSeederDataCheck::check();

        $this->assertCount(2, $issues);
        $this->assertSame('seed_row1', $issues[0]['key']);
        $this->assertSame('seed_row2', $issues[1]['key']);

        foreach ($issues as $issue) {
            DemoSeederDataCheck::fix($issue['key']);
        }

        $this->assertSame([], DemoSeederDataCheck::check());
        $this->assertNotNull(DB::table('demo_seed')->where('key', 'seed1')->first());
        $this->assertNotNull(DB::table('demo_seed')->where('key', 'seed2')->first());
    }

    public function testSeedPreviewDoesNotWrite(): void
    {
        $sqls = DemoSeederDataCheck::preview('seed_row1');

        $this->assertNotEmpty($sqls);
        $this->assertSame(0, DB::table('demo_seed')->count(), 'preview 不得落库');
    }
}
