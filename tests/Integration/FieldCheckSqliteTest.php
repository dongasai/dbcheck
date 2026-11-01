<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Tests\Fixtures\DemoFieldCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoMapFieldCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FieldCheck × SQLite 集成测试：标量回写、list/map 两种数组声明、补项保留运行期状态。
 */
class FieldCheckSqliteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('demo_field', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 50)->unique();
            $table->string('title')->nullable();
            $table->text('btn_list')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * @return array<int, string>
     */
    protected function issueKeys(): array
    {
        return array_column(DemoFieldCheck::check(), 'key');
    }

    public function testScalarMismatchIsReportedAndFixed(): void
    {
        DB::table('demo_field')->insert([
            'key' => 'menu1',
            'title' => '旧标题',
            'btn_list' => json_encode([
                ['key' => 'a', 'name' => 'A', 'checked' => false],
                ['key' => 'b', 'name' => 'B', 'checked' => false],
            ]),
        ]);

        $issues = DemoFieldCheck::check();
        $this->assertContains('field.demo_btn.title', array_column($issues, 'key'));

        DemoFieldCheck::fix('field.demo_btn.title');

        $row = DB::table('demo_field')->where('key', 'menu1')->first();
        $this->assertSame('锁定标题', $row->title);
        $this->assertNotNull($row->updated_at);
        $this->assertNotContains('field.demo_btn.title', $this->issueKeys());
    }

    public function testListItemMissingIsReported(): void
    {
        DB::table('demo_field')->insert([
            'key' => 'menu1',
            'title' => '锁定标题',
            'btn_list' => json_encode([['key' => 'a', 'name' => 'A', 'checked' => true]]),
        ]);

        $this->assertContains('item.demo_btn.btn_list.b', $this->issueKeys());
    }

    public function testListItemFixAppendsAndKeepsRuntimeState(): void
    {
        DB::table('demo_field')->insert([
            'key' => 'menu1',
            'title' => '锁定标题',
            'btn_list' => json_encode([['key' => 'a', 'name' => 'A', 'checked' => true]]),
        ]);

        DemoFieldCheck::fix('item.demo_btn.btn_list.b');

        $row = DB::table('demo_field')->where('key', 'menu1')->first();
        $items = json_decode($row->btn_list, true);

        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $this->assertSame('a', $items[0]['key']);
        $this->assertTrue($items[0]['checked'], '已存项的 checked 状态必须保留');
        $this->assertSame('b', $items[1]['key']);
        $this->assertSame([], DemoFieldCheck::check());
    }

    public function testMapDeclarationItemMissingAndFixed(): void
    {
        DB::table('demo_field')->insert([
            'key' => 'menu2',
            'btn_list' => json_encode(['a' => ['key' => 'a', 'name' => 'A']]),
        ]);

        $this->assertContains('item.demo_map.btn_list.b', array_column(DemoMapFieldCheck::check(), 'key'));

        DemoMapFieldCheck::fix('item.demo_map.btn_list.b');

        $row = DB::table('demo_field')->where('key', 'menu2')->first();
        $items = json_decode($row->btn_list, true);

        $this->assertArrayHasKey('a', $items);
        $this->assertArrayHasKey('b', $items);
    }

    public function testRowMissingIsSkipped(): void
    {
        $this->assertSame([], DemoFieldCheck::check());
    }

    public function testMissingTableReturnsEmpty(): void
    {
        Schema::drop('demo_field');

        $this->assertSame([], DemoFieldCheck::check());
    }

    public function testUnknownFixKeyThrows(): void
    {
        DB::table('demo_field')->insert(['key' => 'menu1', 'title' => '锁定标题']);

        $this->expectException(\LogicException::class);

        DemoFieldCheck::fix('field.demo_btn.not_declared');
    }
}
