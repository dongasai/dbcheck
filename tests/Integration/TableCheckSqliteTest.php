<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Tests\Fixtures\DemoTableCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TableCheck × SQLite 集成测试：缺表建表、缺列补列、额外列/索引、索引等价比对。
 */
class TableCheckSqliteTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    protected function issueKeys(): array
    {
        return array_column(DemoTableCheck::check(), 'key');
    }

    public function testMissingTableIsReportedAndFixCreatesIt(): void
    {
        $issues = DemoTableCheck::check();

        $this->assertCount(1, $issues);
        $this->assertSame('table', $issues[0]['key']);
        $this->assertStringContainsString('不存在', $issues[0]['message']);

        DemoTableCheck::fix('table');

        $this->assertTrue(Schema::hasTable('demo_table'));
        $this->assertSame([], DemoTableCheck::check());
    }

    public function testMissingColumnIsReportedAndFixAddsIt(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        $this->assertContains('column.name', $this->issueKeys());

        DemoTableCheck::fix('column.name');

        $this->assertTrue(Schema::hasColumn('demo_table', 'name'));
        $keys = $this->issueKeys();
        $this->assertNotContains('column.name', $keys);
        // 索引仍未建，应继续报
        $this->assertContains('index.demo_table_status_index', $keys);
    }

    public function testExtraColumnIsOptionalAndFixDropsIt(): void
    {
        DemoTableCheck::fix('table');
        Schema::table('demo_table', function (Blueprint $table) {
            $table->string('extra_col')->nullable();
        });

        $issues = DemoTableCheck::check();
        $keys = array_column($issues, 'key');
        $this->assertContains('extra_column.extra_col', $keys);

        $optional = null;
        foreach ($issues as $issue) {
            if ($issue['key'] === 'extra_column.extra_col') {
                $optional = $issue['optional'];
            }
        }
        $this->assertTrue($optional);

        DemoTableCheck::fix('extra_column.extra_col');

        $this->assertFalse(Schema::hasColumn('demo_table', 'extra_col'));
        $this->assertNotContains('extra_column.extra_col', $this->issueKeys());
    }

    public function testMissingIndexIsReportedAndFixCreatesIt(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->default('');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        $this->assertContains('index.demo_table_status_index', $this->issueKeys());

        DemoTableCheck::fix('index.demo_table_status_index');

        $this->assertSame([], DemoTableCheck::check());
    }

    public function testExtraIndexIsOptionalAndFixDropsIt(): void
    {
        DemoTableCheck::fix('table');
        DB::statement('create index demo_extra_idx on demo_table (created_at)');

        $this->assertContains('extra_index.demo_extra_idx', $this->issueKeys());

        DemoTableCheck::fix('extra_index.demo_extra_idx');

        $this->assertNotContains('extra_index.demo_extra_idx', $this->issueKeys());
    }

    public function testEquivalentIndexWithOtherNameIsNotReported(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->default('');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->index(['status'], 'other_status_idx');
        });

        $this->assertSame([], DemoTableCheck::check());
    }

    public function testUnknownFixKeyThrows(): void
    {
        DemoTableCheck::fix('table');

        $this->expectException(\LogicException::class);

        DemoTableCheck::fix('column.not_declared');
    }
}
