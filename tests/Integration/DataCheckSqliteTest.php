<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Tests\Fixtures\DemoDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoInsertMergeDataCheck;
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

    /**
     * 行已存在时预览不得再出 INSERT（与真实修复同口径：fix 经判重会跳过已存在行）。
     */
    public function testExistingRowPreviewProducesNoInsert(): void
    {
        DB::table('demo_data')->insert(['key' => 'row1', 'title' => '后台改过的标题']);

        $this->assertSame([], DemoDataCheck::preview('demo_row'));

        DemoDataCheck::fix('demo_row');
        $this->assertSame('后台改过的标题', DB::table('demo_data')->where('key', 'row1')->value('title'));
    }

    /**
     * insert_merge 引用其它行取值：pretend 下读不到引用行，修复前预览必抛「引用行不存在」。
     */
    public function testInsertMergePreviewResolvesReferenceRow(): void
    {
        DB::table('demo_data')->insert(['key' => 'row1', 'title' => '来源标题']);

        $preview = DemoInsertMergeDataCheck::preview('merged_row');

        // 预览只返回写语句（判重 SELECT 已由 PreviewContext::writeStatements 过滤）
        $this->assertCount(1, $preview);
        $this->assertStringContainsString('insert', strtolower((string) $preview[0]['query']));
        $this->assertTrue(in_array('来源标题', (array) $preview[0]['bindings'], true), 'insert_merge 应解析出引用行的值');
    }

    /**
     * 预览在 pretend 内抛错后必须复位 pretending：Laravel 8 的 pretend() 无 try/finally，
     * 不兜底会让后续 fix/写操作全部静默跳过，复检时误报「修复成功」。
     */
    public function testPreviewExceptionResetsPretendingState(): void
    {
        // 引用行不存在 → 预览在 pretend 内抛「insert_merge 引用行不存在」
        try {
            DemoInsertMergeDataCheck::preview('merged_row');
            $this->fail('引用行不存在时预览应抛异常');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('引用行不存在', $e->getMessage());
        }

        $this->assertFalse(DB::connection('testing')->pretending(), '预览异常后连接必须退出 pretend 状态');

        // pretending 未复位时这条 INSERT 会被静默跳过
        DB::table('demo_data')->insert(['key' => 'after_preview_throw', 'title' => 'x']);
        $this->assertSame(1, DB::table('demo_data')->where('key', 'after_preview_throw')->count());
    }
}
