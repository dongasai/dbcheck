<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Dongasai\Dbcheck\Tests\Fixtures\BadColumnsIndexTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\BadIndexTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\FakeAttributeAdapter;
use Dongasai\Dbcheck\Tests\Fixtures\MissingTypeTableCheck;
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

    /**
     * 把 pretend 返回的语句拼成一段小写 SQL 文本（便于断言）。
     *
     * @param array<int, array<string, mixed>> $statements
     */
    protected function previewQueries(array $statements): string
    {
        $queries = [];
        foreach ($statements as $statement) {
            $queries[] = strtolower((string) ($statement['query'] ?? ''));
        }

        return implode("\n", $queries);
    }

    public function testPreviewMissingTableProducesCreateTable(): void
    {
        $sql = $this->previewQueries(DemoTableCheck::preview('table'));

        $this->assertStringContainsString('create table', $sql);
        $this->assertStringContainsString('demo_table', $sql);
    }

    public function testPreviewMissingColumnProducesAddColumn(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        $sql = $this->previewQueries(DemoTableCheck::preview('column.name'));

        $this->assertStringContainsString('add column', $sql);
        $this->assertStringContainsString('name', $sql);
    }

    /**
     * 已存在的索引与声明不一致时，预览必须包含 drop + create（pretend 下 SELECT 返回空，
     * 修复前预览只会出 create，用户照抄 SQL 会因同名索引已存在而报错）。
     */
    public function testPreviewExistingIndexDropsThenRecreates(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->default('');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->unique(['status'], 'demo_table_status_index');
        });

        $this->assertContains('index.demo_table_status_index', $this->issueKeys());

        $sql = $this->previewQueries(DemoTableCheck::preview('index.demo_table_status_index'));

        $this->assertStringContainsString('drop index', $sql);
        $this->assertStringContainsString('create index', $sql);
    }

    /**
     * MySQL 语义下同一列「类型 + 可空 + 默认值」三处不一致应聚合成一个异常项（一次报全、一次修好）。
     */
    public function testColumnDiffsAreAggregatedIntoOneIssue(): void
    {
        DbDriver::override(FakeAttributeAdapter::class);

        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 10)->nullable()->default('x');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        $columnIssues = array_values(array_filter(
            DemoTableCheck::check(),
            static function (array $issue): bool {
                return $issue['key'] === 'column.name';
            }
        ));

        $this->assertCount(1, $columnIssues);
        $this->assertStringContainsString('类型', $columnIssues[0]['message']);
        $this->assertStringContainsString('可空', $columnIssues[0]['message']);
        $this->assertStringContainsString('默认值', $columnIssues[0]['message']);
    }

    /**
     * 旧 list + name 形态的 indexes() 声明必须显式报错（否则整数下标会被当索引名）。
     */
    public function testLegacyListIndexesDeclarationIsRejected(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->default('');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('整数下标');

        BadIndexTableCheck::check();
    }

    /**
     * 索引声明漏写 columns 键（裸列数组）必须显式报错：否则 check 永远报索引缺失、
     * fix 生成 create index ... () 语法错误，该表永远修不好。
     */
    public function testBareColumnsIndexDeclarationIsRejected(): void
    {
        Schema::create('demo_table', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->default('');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('columns');

        BadColumnsIndexTableCheck::check();
    }

    /**
     * 列声明缺 type 键时不允许静默按 string 建列，必须显式抛 LogicException。
     */
    public function testMissingColumnTypeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('type');

        MissingTypeTableCheck::fix('table');
    }

    /**
     * 只做存在性比对的 SQLite 上，缺 type 的声明也必须在 check() 当场暴露；
     * 否则会表现为「列不存在」而 fix 必抛，产生永远修不好的异常项。
     */
    public function testMissingColumnTypeIsValidatedOnExistenceOnlyDriver(): void
    {
        Schema::create('demo_missing_type', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 10)->nullable();
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('type');

        MissingTypeTableCheck::check();
    }
}
