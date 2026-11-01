<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Tests\Fixtures\DemoDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoNoTimestampsDataCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DataCheck × SQLite 集成测试：缺行提示、补插、timestamps 开关、已有行不回写。
 */
class DataCheckSqliteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('demo_data', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 50)->unique();
            $table->string('title')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function testMissingRowIsReportedWithProblemText(): void
    {
        $issues = DemoDataCheck::check();

        $this->assertCount(1, $issues);
        $this->assertSame('demo_row', $issues[0]['key']);
        $this->assertSame('data', $issues[0]['type']);
        $this->assertStringContainsString('菜单缺失，前端无入口', $issues[0]['message']);
        $this->assertSame('插入 demo_data 行', $issues[0]['repair']);
    }

    public function testFixInsertsRowWithTimestamps(): void
    {
        DemoDataCheck::fix('demo_row');

        $row = DB::table('demo_data')->where('key', 'row1')->first();
        $this->assertNotNull($row);
        $this->assertSame('默认标题', $row->title);
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
        $this->assertSame([], DemoDataCheck::check());
    }

    public function testTimestampsFalseSkipsTimeColumns(): void
    {
        DemoNoTimestampsDataCheck::fix('demo_row_no_ts');

        $row = DB::table('demo_data')->where('key', 'row2')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->created_at);
        $this->assertNull($row->updated_at);
    }

    public function testExistingRowIsNotCompared(): void
    {
        DB::table('demo_data')->insert([
            'key' => 'row1',
            'title' => '后台改过的标题',
        ]);

        $this->assertSame([], DemoDataCheck::check());
    }

    public function testMissingTableReturnsEmpty(): void
    {
        Schema::drop('demo_data');

        $this->assertSame([], DemoDataCheck::check());
    }

    public function testUnknownFixKeyThrows(): void
    {
        $this->expectException(\LogicException::class);

        DemoDataCheck::fix('not_declared');
    }
}
