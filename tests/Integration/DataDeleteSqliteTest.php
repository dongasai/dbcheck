<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Support\DbCheckGroup;
use Dongasai\Dbcheck\Tests\Fixtures\DemoCleanupDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoDataCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 删除验证 × SQLite 集成测试：未声明行报 data.extra、按身份删除、分组默认值、
 * 未启用检查器拒绝删除、声明为空 = 表应为空。
 */
class DataDeleteSqliteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('demo_cleanup', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique();
            $table->string('title')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('demo_data', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 50)->unique();
            $table->string('title')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function testExtraRowIsReportedWithIdentityKey(): void
    {
        DB::table('demo_cleanup')->insert(['code' => 'keep1', 'title' => '保留行一']);
        DB::table('demo_cleanup')->insert(['code' => 'keep2', 'title' => '保留行二']);
        DB::table('demo_cleanup')->insert(['code' => 'junk', 'title' => '多余行']);

        $issues = DemoCleanupDataCheck::check();

        $this->assertCount(1, $issues);
        $this->assertSame('data.extra.{"code":"junk"}', $issues[0]['key']);
        $this->assertSame('data', $issues[0]['type']);
        $this->assertStringContainsString('多余数据行', $issues[0]['message']);
        $this->assertFalse($issues[0]['optional']);
    }

    public function testDeclaredMissingRowAndExtraRowAreBothReported(): void
    {
        // 只插多余行：缺 keep1/keep2（缺行）+ junk（多余）→ 两类异常并存
        DB::table('demo_cleanup')->insert(['code' => 'junk', 'title' => '多余行']);

        $issues = DemoCleanupDataCheck::check();

        $this->assertCount(3, $issues);
        $keys = [$issues[0]['key'], $issues[1]['key'], $issues[2]['key']];
        $this->assertContains('cleanup_row1', $keys);
        $this->assertContains('cleanup_row2', $keys);
        $this->assertContains('data.extra.{"code":"junk"}', $keys);
    }

    public function testFixDeletesExtraRowOnly(): void
    {
        DB::table('demo_cleanup')->insert(['code' => 'keep1', 'title' => '保留行一']);
        DB::table('demo_cleanup')->insert(['code' => 'keep2', 'title' => '保留行二']);
        DB::table('demo_cleanup')->insert(['code' => 'junk', 'title' => '多余行']);

        DemoCleanupDataCheck::fix('data.extra.{"code":"junk"}');

        $this->assertNull(DB::table('demo_cleanup')->where('code', 'junk')->first());
        $this->assertNotNull(DB::table('demo_cleanup')->where('code', 'keep1')->first());
        $this->assertSame([], DemoCleanupDataCheck::check());
    }

    public function testPreviewShowsDeleteSqlWithoutWriting(): void
    {
        DB::table('demo_cleanup')->insert(['code' => 'junk', 'title' => '多余行']);

        $sqls = DemoCleanupDataCheck::preview('data.extra.{"code":"junk"}');

        $this->assertNotEmpty($sqls);
        $this->assertNotNull(DB::table('demo_cleanup')->where('code', 'junk')->first(), 'preview 不得落库');
    }

    public function testGroupDefaultsToDeleteWhenDeleteExtraEnabled(): void
    {
        $this->assertSame(DbCheckGroup::DELETE, DemoCleanupDataCheck::group());
        $this->assertSame('delete', DbCheckGroup::DELETE);
        // 未启用删除验证的检查器保持常规分组
        $this->assertNull(DemoDataCheck::group());
    }

    public function testFixRejectsExtraKeyWhenDeleteExtraDisabled(): void
    {
        $this->expectException(\LogicException::class);

        DemoDataCheck::fix('data.extra.{"key":"row1"}');
    }

    public function testEmptyDeclarationMeansTableMustBeEmpty(): void
    {
        // 无任何声明 + deleteExtra：全部行都是多余行（声明为空 = 表应为空）
        DB::table('demo_cleanup')->insert(['code' => 'a', 'title' => '行a']);

        $check = new class extends \Dongasai\Dbcheck\Checks\AbstractTableDataCheck {
            public static function table(): string
            {
                return 'demo_cleanup';
            }

            protected static function rows(): array
            {
                return [];
            }

            protected static function deleteExtra(): bool
            {
                return true;
            }
        };

        $issues = $check::check();

        $this->assertCount(1, $issues);
        // 无身份列声明 → 退化为整行值做身份
        $this->assertStringStartsWith('data.extra.{"id":', $issues[0]['key']);

        $identity = substr($issues[0]['key'], strlen('data.extra.'));
        $check::fix('data.extra.' . $identity);
        $this->assertSame(0, DB::table('demo_cleanup')->count());
    }
}
