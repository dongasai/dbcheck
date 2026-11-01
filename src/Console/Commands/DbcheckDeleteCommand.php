<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;
use Dongasai\Dbcheck\Support\DbCheckRegistry;

/**
 * 多余数据行清理命令（删除验证专用入口）。
 *
 * 只对**开启删除验证（deleteExtra() = true）**的 DataCheck 执行：
 * 收集库中未被 rows() 声明的多余行（data.extra.*）并驱动删除。
 * 缺行插行（data.*）不属于本命令——那是 dbcheck:check / seeder 分组的职责。
 *
 * 用法：
 *   php artisan dbcheck:delete                  # 交互：逐条预览 DELETE SQL → 确认 → 删除 → 复检
 *   php artisan dbcheck:delete --no-interaction # 只报告多余行，零变更（退出码 FAILURE）
 *   php artisan dbcheck:delete --yes            # 跳过逐条确认，直接删除全部报告的多余行
 *   php artisan dbcheck:delete --connection=x   # 指定连接；--adapter= 覆盖驱动选择
 *
 * 与分组的关系：开启 deleteExtra() 的检查器在 dbcheck:check 默认被隔离进
 * DbCheckGroup::DELETE 分组（危险动作不随默认扫执行）；本命令按「能力」（deleteExtra）
 * 收集检查器，是该类检查器的显式触发入口，不受分组过滤影响。
 *
 * 退出码：无多余行 / 全部删除成功 = SUCCESS；非交互发现多余行 / 有删除失败 = FAILURE。
 */
class DbcheckDeleteCommand extends CheckInfoCommand
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:delete
        {--connection= : 数据库连接名（默认用 database.default 默认连接）}
        {--adapter= : 驱动适配器（类名或驱动别名 mysql8/mysql/mariadb/mysql57/pgsql/postgres/sqlite，默认自动探测）}
        {--yes : 跳过逐条确认，直接删除全部报告的多余行}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '删除验证：清理开启 deleteExtra 的检查器中未声明的多余数据行';

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

        // 按「能力」收集：所有开启删除验证的 DataCheck（不受分组过滤影响）
        $classes = [];
        foreach (DbCheckRegistry::grouped(null, true)['data'] as $class) {
            if (is_a($class, AbstractTableDataCheck::class, true) && $class::deleteExtra()) {
                $classes[] = $class;
            }
        }

        if ($classes === []) {
            $this->info('当前扫描范围未发现开启删除验证（deleteExtra）的检查器，无事可做。');

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

            // 只取多余行项；缺行（data.*）是插入职责，归 dbcheck:check / seeder
            $extras = array_values(array_filter($issues, static function ($issue) {
                return strpos((string) $issue['key'], 'data.extra.') === 0;
            }));

            if ($extras === []) {
                $this->line('✓ ' . $module . ' · 无多余数据行');
                continue;
            }

            $this->line('✗ ' . $module . ' · ' . count($extras) . ' 个多余数据行');
            foreach ($extras as $issue) {
                $allIssues[] = ['class' => $class, 'issue' => $issue];
            }
        }

        if ($allIssues === [] && $failedChecks === 0) {
            $this->info('全部通过，无多余数据行。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('发现 ' . count($allIssues) . ' 个多余数据行'
            . ($failedChecks > 0 ? '，' . $failedChecks . ' 个检查器执行失败' : ''));

        if ($noInteraction) {
            // 非交互：只预览不落库（删除属破坏性动作，绝不静默执行）
            foreach ($allIssues as $it) {
                $this->newLine();
                $this->line('【' . $it['class']::moduleName() . '】' . $it['issue']['key']);
                $this->previewSql($it['class'], $it['issue']['key']);
                $this->line('（非交互模式，未执行删除）');
            }

            return self::FAILURE;
        }

        $errorCount = $failedChecks;
        foreach ($allIssues as $it) {
            if ($autoYes) {
                $errorCount += $this->deleteIssue($it['class'], $it['issue']) ? 0 : 1;
                continue;
            }
            if (!$this->fixIssue($it['class'], $it['issue'], '删除验证')) {
                $errorCount++;
            }
        }

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * --yes 模式：免确认直接删除并复检（fix 后该 key 仍报异常视为失败）。
     *
     * @param class-string $class
     * @param array<string, mixed> $issue
     */
    protected function deleteIssue(string $class, array $issue): bool
    {
        try {
            $class::fix($issue['key']);
            foreach ($class::check() as $after) {
                if ($after['key'] === $issue['key']) {
                    $this->error('删除后仍异常: ' . $issue['key']);

                    return false;
                }
            }
            $this->info('已删除: ' . $class::moduleName() . ' / ' . $issue['key']);

            return true;
        } catch (\Throwable $e) {
            $this->error('删除失败 [' . $class::moduleName() . ' / ' . $issue['key'] . ']: ' . $e->getMessage());

            return false;
        }
    }
}
