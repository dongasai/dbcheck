<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Console\Commands\CheckInfoCommand;
use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Dongasai\Dbcheck\Tests\Fixtures\DemoTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DirectCheck;
use Dongasai\Dbcheck\Tests\Fixtures\TestdataDataCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * dbcheck:check 命令级集成测试（P0-1 / P1-2 / P1-4 / P2-2）。
 *
 * 用 Symfony Console 直接驱动命令：容器复用 TestCase 的最小容器（Capsule + config + db.schema）。
 */
class CheckInfoCommandTest extends TestCase
{
    /**
     * 运行 dbcheck:check，返回 [退出码, 输出文本]。
     *
     * @param array<string, mixed> $options 命令行选项（如 ['--no-interaction' => true]）
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options = []): array
    {
        $container = Container::getInstance();

        $command = new CheckInfoCommand();
        $command->setLaravel($container);

        $app = new Application('dbcheck-test');
        $app->setAutoExit(false);
        $app->add($command);

        $input = new ArrayInput(array_merge(['command' => 'dbcheck:check'], $options));
        $output = new BufferedOutput();

        $code = (int) $app->run($input, $output);

        return [$code, $output->fetch()];
    }

    /**
     * 0 个检查器时不得静默全绿：必须告警并返回失败（P0-1）。
     */
    public function testZeroCheckersFailsInsteadOfSilentGreen(): void
    {
        [$code, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('发现 0 个检查器', $out);
        $this->assertStringContainsString('未发现任何检查器', $out);
    }

    /**
     * 发现计数行包含三类数量与分组明细（P0-1）。
     */
    public function testDiscoveryLineShowsCountsAndGroups(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);

        [, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertStringContainsString('发现 2 个检查器（表结构 1 / 固定数据 1 / 字段明细 0）', $out);
        $this->assertStringContainsString('分组：testdata(1)', $out);
    }

    /**
     * 只有可选处理项（额外索引）时非交互返回成功；--strict 恢复失败（P1-4）。
     */
    public function testOptionalOnlySucceedsUnlessStrict(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DemoTableCheck::fix('table');
        DB::connection('testing')->statement('create index demo_legacy_index on demo_table ("name")');

        [$code, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertSame(0, $code, '仅可选处理项时非交互应返回成功');
        $this->assertStringContainsString('可选处理项 1 个', $out);
        $this->assertStringContainsString('默认不处理', $out);
        // 预览的写 SQL 已过滤读语句：只剩 drop index
        $this->assertStringContainsString('drop index', strtolower($out));

        [$strictCode] = $this->runCommand(['--no-interaction' => true, '--strict' => true]);
        $this->assertSame(1, $strictCode, '--strict 时可选项也按失败返回');
    }

    /**
     * 存在必修复项时非交互返回失败，并预览建表 SQL（P1-2）。
     */
    public function testRequiredIssueFailsAndPreviewsCreateTable(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);

        [$code, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('create table', strtolower($out));
    }

    /**
     * 索引属性不一致时预览必须同时出现 drop 与 create（P1-2：pretend 下漏 drop 的回归）。
     */
    public function testIndexMismatchPreviewDropsThenRecreates(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DemoTableCheck::fix('table');
        // 同名索引改成 unique：属性不一致 → 应报 index.*，修复 = 先 drop 再 create unique
        DB::connection('testing')->statement('drop index demo_table_status_index');
        DB::connection('testing')->statement('create unique index demo_table_status_index on demo_table ("status")');

        [$code, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $lower = strtolower($out);
        $this->assertStringContainsString('drop index "demo_table_status_index"', $lower);
        // 声明是非唯一索引：重建语句不能带 unique（属性按声明收敛）
        $this->assertStringContainsString('create index "demo_table_status_index"', $lower);
        $this->assertStringNotContainsString('create unique index', $lower);
        $this->assertStringNotContainsString('add column', $lower);
    }

    /**
     * 直接实现接口未继承基类时，命令捕获归类异常并按失败返回（P2-2）。
     */
    public function testClassifyFailureIsReportedWithoutCrash(): void
    {
        DbCheckRegistry::register(DirectCheck::class);

        [$code, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('未归类 1', $out);
        $this->assertStringContainsString('检查器归类失败', $out);
    }

    /**
     * --group 拼错（零命中）时不得打印「检查通过」：按失败返回（与 0 检查器同属不可信场景）。
     */
    public function testUnknownGroupFailsInsteadOfSilentGreen(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);

        [$code, $out] = $this->runCommand(['--no-interaction' => true, '--group' => ['no_such_group']]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('未命中任何检查器', $out);
        $this->assertStringNotContainsString('结构/数据检查通过', $out);
    }

    /**
     * 默认集合里没有常规检查器（全部在分组中）时给出明确指引，且「没执行任何检查」按失败返回。
     */
    public function testAllGroupedCheckersWarnWithoutRegularOnes(): void
    {
        DbCheckRegistry::register(TestdataDataCheck::class);

        [$code, $out] = $this->runCommand(['--no-interaction' => true]);

        $this->assertSame(1, $code, '零执行不得被 CI 当成通过');
        $this->assertStringContainsString('未发现常规检查器', $out);
        $this->assertStringNotContainsString('结构/数据检查通过', $out);
    }

    /**
     * 多个 --group 中部分拼错也必须报错：拼错的组不能被静默忽略。
     */
    public function testPartiallyUnknownGroupFails(): void
    {
        DbCheckRegistry::register(TestdataDataCheck::class);

        [$code, $out] = $this->runCommand([
            '--no-interaction' => true,
            '--group' => ['testdata', 'no_such_group'],
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('no_such_group', $out);
        $this->assertStringContainsString('未命中任何检查器', $out);
    }

    /**
     * 有效分组正向路径：--group=testdata 只执行该分组（常规检查器不执行）。
     */
    public function testValidGroupRunsOnlyThatGroup(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);

        [$code, $out] = $this->runCommand(['--no-interaction' => true, '--group' => ['testdata']]);

        // demo_data 表不存在 → TestdataDataCheck 缺表守卫返回空；DemoTableCheck 不执行 → 全部通过
        $this->assertSame(0, $code);
        $this->assertStringContainsString('结构/数据检查通过', $out);
        $this->assertStringNotContainsString('demo_table', $out);
    }

    /**
     * --all-groups 同时执行常规与分组检查器。
     */
    public function testAllGroupsIncludesGroupedCheckers(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);

        [$code, $out] = $this->runCommand(['--no-interaction' => true, '--all-groups' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('demo_table', $out);
    }

    /**
     * --detail 打印扫描根命中情况（P2-2：定位「声明了但没扫到」）。
     */
    public function testDetailPrintsScanRoots(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);

        [, $out] = $this->runCommand(['--no-interaction' => true, '--detail' => true]);

        $this->assertStringContainsString('扫描根: （config/dbcheck.php 未声明 scan）', $out);
    }

    /**
     * --detail 已声明扫描根时打印「命中 n 个」（与发现计数同口径，过滤非检查器/禁用类）。
     */
    public function testDetailPrintsMatchedScanRoot(): void
    {
        $fixtures = dirname(__DIR__) . '/Fixtures';
        config(['dbcheck.scan' => [
            ['path' => $fixtures, 'namespace' => 'Dongasai\\Dbcheck\\Tests\\Fixtures'],
        ]]);

        [, $out] = $this->runCommand(['--no-interaction' => true, '--detail' => true]);

        $this->assertStringContainsString('扫描根: ' . $fixtures, $out);
        $this->assertStringContainsString('→ 命中 ', $out);
        $this->assertStringNotContainsString('未匹配', $out);
    }
}
