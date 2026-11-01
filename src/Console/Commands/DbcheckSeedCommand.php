<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Dongasai\Dbcheck\Support\DbCheckGroup;
use Dongasai\Dbcheck\Support\DbCheckRegistry;

/**
 * 种子数据播种命令（seeder 分组专用入口）。
 *
 * 只对**声明为播种分组（group() = DbCheckGroup::SEEDER）**的 DataCheck / FieldCheck 执行：
 * 缺行补插（data.*）与字段回写（field.* / item.*）。
 * 表结构差异（table / column / index）不归本命令——那是 dbcheck:check 的职责；
 * 多余行（data.extra.*）不归本命令——那是 dbcheck:delete 的职责。
 *
 * 用法：
 *   php artisan dbcheck:seed                  # 交互：逐条预览补插/回写 SQL → 确认 → 执行 → 复检
 *   php artisan dbcheck:seed --no-interaction # 只报告将执行的播种项，零变更（退出码 FAILURE）
 *   php artisan dbcheck:seed --yes            # 跳过逐条确认，直接执行全部播种项
 *   php artisan dbcheck:seed --group=<名>     # 改收指定分组（如自定义的 testdata），默认收 seeder 分组
 *   php artisan dbcheck:seed --connection=x   # 指定连接；--adapter= 覆盖驱动选择
 *
 * 与分组的关系：声明 group() = SEEDER 的检查器不随 dbcheck:check 默认执行（分组机制天然隔离）；
 * 本命令按分组收集，是种子数据检查器的显式触发入口。常规（null）与自定义分组检查器
 * 仍随 dbcheck:check / --group=<名> 执行，不受本命令影响。
 *
 * 退出码：无播种项 / 全部执行成功 = SUCCESS；非交互发现播种项 / 有执行失败 = FAILURE。
 */
class DbcheckSeedCommand extends CheckInfoCommand
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:seed
        {--connection= : 数据库连接名（默认用 database.default 默认连接）}
        {--adapter= : 驱动适配器（类名或驱动别名 mysql8/mysql/mariadb/mysql57/pgsql/postgres/sqlite，默认自动探测）}
        {--group= : 要播种的分组名（默认 DbCheckGroup::SEEDER；可指定任意自定义分组）}
        {--yes : 跳过逐条确认，直接执行全部报告的播种项}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '播种数据：执行声明为 seeder 分组的检查器，补插缺行并回写声明字段';

    /**
     * 执行命令。
     */
    public function handle(): int
    {
        // 与 dbcheck:check 同一套运行期参数语义：连接切换 + 驱动覆盖
        $connection = (string) $this->option('connection');
        if ($connection !== '') {
            if (!array_key_exists($connection, (array) config('database.connections'))) {
                $this->error('数据库连接不存在: ' . $connection);

                return self::FAILURE;
            }
            config(['database.default' => $connection]);
        }

        $adapter = (string) $this->option('adapter');
        if ($adapter !== '') {
            if (!DbDriver::supports($adapter)) {
                $this->error('未知的数据库驱动适配器: ' . $adapter);

                return self::FAILURE;
            }
            DbDriver::override($adapter);
        }

        // 按分组收集：默认 seeder 分组；--group= 可改收任意自定义分组（只取 data / field 两类）
        $group = (string) $this->option('group');
        if ($group === '') {
            $group = DbCheckGroup::SEEDER;
        }

        $grouped = DbCheckRegistry::grouped($group, false);
        $classes = array_merge($grouped['data'], $grouped['field']);

        if ($classes === []) {
            $this->info('当前扫描范围未发现分组为「' . $group . '」的数据/字段检查器，无事可做。');

            return self::SUCCESS;
        }

        $autoYes = (bool) $this->option('yes');
        $noInteraction = (bool) $this->option('no-interaction');

        $allIssues = [];
        $failedChecks = 0;
        foreach ($classes as $class) {
            $module = $class::moduleName();

            try {
                $issues = $class::check();
            } catch (\Throwable $e) {
                $this->line('✗ ' . $module);
                $this->error('  检查失败: ' . $e->getMessage());
                $failedChecks++;
                continue;
            }

            // 只取补插/回写项；多余行（data.extra.*）是删除职责，归 dbcheck:delete
            $seedIssues = array_values(array_filter($issues, static function ($issue) {
                return strpos((string) $issue['key'], 'data.extra.') !== 0;
            }));

            if ($seedIssues === []) {
                $this->line('✓ ' . $module . ' · 播种数据齐备');
                continue;
            }

            $this->line('✗ ' . $module . ' · ' . count($seedIssues) . ' 个播种项');
            foreach ($seedIssues as $issue) {
                $allIssues[] = ['class' => $class, 'label' => '播种数据', 'issue' => $issue];
            }
        }

        if ($allIssues === [] && $failedChecks === 0) {
            $this->info('全部通过，播种数据齐备。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('发现 ' . count($allIssues) . ' 个播种项'
            . ($failedChecks > 0 ? '，' . $failedChecks . ' 个检查器执行失败' : ''));

        if ($noInteraction) {
            // 非交互：只预览不落库（与其他 dbcheck 命令同口径，绝不静默写库）
            $this->previewAll($allIssues);

            return self::FAILURE;
        }

        $errorCount = $failedChecks;
        foreach ($allIssues as $it) {
            if ($autoYes) {
                $errorCount += $this->seedIssue($it['class'], $it['issue']) ? 0 : 1;
                continue;
            }
            if (!$this->fixIssue($it['class'], $it['issue'], '播种数据')) {
                $errorCount++;
            }
        }

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * --yes 模式：免确认直接执行并复检（fix 后该 key 仍报异常视为失败）。
     *
     * @param class-string $class
     * @param array<string, mixed> $issue
     */
    protected function seedIssue(string $class, array $issue): bool
    {
        try {
            $class::fix($issue['key']);
            foreach ($class::check() as $after) {
                if ($after['key'] === $issue['key']) {
                    $this->error('执行后仍异常: ' . $issue['key']);

                    return false;
                }
            }
            $this->info('已播种: ' . $class::moduleName() . ' / ' . $issue['key']);

            return true;
        } catch (\Throwable $e) {
            $this->error('播种失败 [' . $class::moduleName() . ' / ' . $issue['key'] . ']: ' . $e->getMessage());

            return false;
        }
    }
}
