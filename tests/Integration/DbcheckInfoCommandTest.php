<?php

namespace Dongasai\Dbcheck\Tests\Integration;

use Dongasai\Dbcheck\Console\Commands\DbcheckInfoCommand;
use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Dongasai\Dbcheck\Tests\Fixtures\DemoSeederDataCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoTableCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DirectCheck;
use Dongasai\Dbcheck\Tests\Fixtures\TestdataDataCheck;
use Dongasai\Dbcheck\Tests\TestCase;
use Illuminate\Container\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * dbcheck:info 命令级集成测试。
 *
 * 用 Symfony Console 直接驱动命令：容器复用 TestCase 的最小容器（不连接数据库、不执行检查）。
 */
class DbcheckInfoCommandTest extends TestCase
{
    /**
     * 运行 dbcheck:info，返回 [退出码, 输出文本]。
     *
     * @param array<string, mixed> $options 命令行选项
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options = []): array
    {
        $container = Container::getInstance();

        $command = new DbcheckInfoCommand();
        $command->setLaravel($container);

        $app = new Application('dbcheck-test');
        $app->setAutoExit(false);
        $app->add($command);

        $input = new ArrayInput(array_merge(['command' => 'dbcheck:info'], $options));
        $output = new BufferedOutput();

        $code = (int) $app->run($input, $output);

        return [$code, $output->fetch()];
    }

    /**
     * 0 个检查器：仍返回 SUCCESS（信息命令不判失败），但必须告警提示配置检查。
     */
    public function testZeroCheckersWarnsButSucceeds(): void
    {
        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('dbcheck ', $out);
        $this->assertStringContainsString('PHP ' . PHP_VERSION, $out);
        $this->assertStringContainsString('检查器: 共 0 个', $out);
        $this->assertStringContainsString('未发现任何检查器', $out);
    }

    /**
     * 常规 + seeder + testdata + 未归类：计数行与分组统计表内容正确。
     */
    public function testPrintsCountsAndGroupTable(): void
    {
        DbCheckRegistry::register(DemoTableCheck::class);
        DbCheckRegistry::register(DemoSeederDataCheck::class);
        DbCheckRegistry::register(TestdataDataCheck::class);
        DbCheckRegistry::register(DirectCheck::class);

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('检查器: 共 4 个', $out);
        $this->assertStringContainsString('未归类 1', $out);
        $this->assertStringContainsString('分组', $out);
        $this->assertStringContainsString('表结构', $out);
        $this->assertStringContainsString('固定数据', $out);
        $this->assertStringContainsString('字段明细', $out);
        $this->assertStringContainsString('合计', $out);
        $this->assertStringContainsString('常规', $out);
        $this->assertStringContainsString('seeder', $out);
        $this->assertStringContainsString('testdata', $out);
    }

    /**
     * 版本行与框架行必须存在（不锚定具体版本号：取不到框架版本时不强求）。
     */
    public function testPrintsPackageAndFrameworkVersionLines(): void
    {
        [, $out] = $this->runCommand();

        $this->assertMatchesRegularExpression('/^dbcheck \S+/m', $out);
        $this->assertMatchesRegularExpression('/^PHP ' . preg_quote(PHP_VERSION, '/') . '$/m', $out);
        // 测试环境装有 illuminate/support，框架版本应可探测（只断言行存在，不断言具体版本）
        $this->assertMatchesRegularExpression('/^Laravel \S+/m', $out);
    }
}
