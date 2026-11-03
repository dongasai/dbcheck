<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Tests\Fixtures\DemoFieldCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoMapFieldCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoNullFieldCheck;
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

    /**
     * 声明值为 null（锁定字段必须为空）必须可检查、可预览、可修复。
     */
    public function testNullScalarCanBePreviewedAndFixed(): void
    {
        DB::table('demo_field')->insert(['key' => 'menu1', 'title' => '不该有值']);

        $this->assertContains('field.demo_null.title', array_column(DemoNullFieldCheck::check(), 'key'));

        $preview = DemoNullFieldCheck::preview('field.demo_null.title');
        $this->assertNotEmpty($preview);
        $query = strtolower((string) $preview[0]['query']);
        $this->assertStringContainsString('update', $query);
        // 预热 updated_at 列存在性后，预览才与真实修复一致地带上 updated_at
        $this->assertStringContainsString('updated_at', $query);
        $this->assertNull($preview[0]['bindings'][0]);

        DemoNullFieldCheck::fix('field.demo_null.title');

        $row = DB::table('demo_field')->where('key', 'menu1')->first();
        $this->assertNull($row->title);
        $this->assertSame([], DemoNullFieldCheck::check());
    }

    /**
     * item.* 预览在 pretend 下会读不到行，修复前必抛「行不存在」；修复后应能正常出 UPDATE。
     */
    public function testItemPreviewDoesNotThrowForMissingItem(): void
    {
        DB::table('demo_field')->insert([
            'key' => 'menu1',
            'title' => '锁定标题',
            'btn_list' => json_encode([['key' => 'a', 'name' => 'A', 'checked' => true]]),
        ]);

        $preview = DemoFieldCheck::preview('item.demo_btn.btn_list.b');

        $this->assertNotEmpty($preview);
        $this->assertStringContainsString('update', strtolower((string) $preview[0]['query']));
    }

    /**
     * 声明了库中不存在的列时不应产生 PHP Warning（取 null 兜底后按标量差异报出）。
     */
    public function testMissingColumnIsReportedWithoutWarning(): void
    {
        Schema::drop('demo_field');
        Schema::create('demo_field', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 50)->unique();
            // 故意不建 title 列
            $table->text('btn_list')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('demo_field')->insert(['key' => 'menu1']);

        // 显式把 Warning 升级为异常（不依赖 PHPUnit 版本对 notice 的处理口径）
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $keys = array_column(DemoFieldCheck::check(), 'key');
        } finally {
            restore_error_handler();
        }

        $this->assertContains('field.demo_btn.title', $keys);
    }

    /**
     * 数组列在库中不存在时，item.* 修复不得抛 PHP Warning（列缺失由表结构检查负责补齐）。
     */
    public function testItemFixOnMissingColumnEmitsNoPhpWarning(): void
    {
        Schema::drop('demo_field');
        Schema::create('demo_field', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 50)->unique();
            $table->string('title')->nullable();
            // 故意不建 btn_list 列
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('demo_field')->insert(['key' => 'menu1', 'title' => '锁定标题']);

        $this->assertContains('item.demo_btn.btn_list.b', array_column(DemoFieldCheck::check(), 'key'));

        // 把 PHP Warning 升级为异常：修复缺列时只允许 QueryException（未知列），不允许 Warning
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            DemoFieldCheck::fix('item.demo_btn.btn_list.b');
            $this->fail('缺列时修复应失败（未知列）');
        } catch (\ErrorException $e) {
            $this->fail('修复缺列时抛出了 PHP Warning: ' . $e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('btn_list', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }
}
