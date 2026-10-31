<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Illuminate\Console\Command;

/**
 * 数据库自检与修复命令。
 *
 * 用法：
 *   php artisan dbcheck:check                # 交互：逐项询问是否修复（先看 SQL 再确认）
 *   php artisan dbcheck:check --no-interaction  # 只报告，不执行任何变更
 *
 * 执行顺序：存在 → 行差异 → 固定数据 → 字段明细（按 DbCheckRegistry::grouped()）。
 * 每张检查器返回异常项，交互模式下先预览 SQL 再确认执行，执行后自动复检。
 */
class CheckInfoCommand extends Command
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:check {--check=fields}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '数据库自检与修复（按表四类检查：存在 / 行差异 / 固定数据 / 字段明细）';

    /**
     * 类别顺序与展示名。
     *
     * @var array<string, string>
     */
    protected $order = [
        'exist' => '存在',
        'row' => '行差异',
        'data' => '固定数据',
        'field' => '字段明细',
    ];

    /**
     * 执行命令。
     */
    public function handle(): int
    {
        $check = (string) $this->option('check');
        if ($check !== 'fields') {
            $this->warn('暂仅支持 --check=fields');

            return self::SUCCESS;
        }

        $noInteraction = (bool) $this->option('no-interaction');
        $groups = DbCheckRegistry::grouped();

        $allIssues = [];

        foreach ($this->order as $gk => $label) {
            foreach ($groups[$gk] as $class) {
                $module = $class::moduleName();
                $issues = $class::check();

                if (empty($issues)) {
                    $this->line('✓ ' . $module . ' · ' . $label);
                    continue;
                }

                $this->line('✗ ' . $module . ' · ' . $label);
                foreach ($issues as $issue) {
                    $allIssues[] = ['class' => $class, 'issue' => $issue, 'label' => $label];
                }
            }
        }

        if (empty($allIssues)) {
            $this->info('全部检查通过，无异常。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('发现 ' . count($allIssues) . ' 个异常');
        $this->line('错误明细:');
        foreach ($allIssues as $i => $it) {
            $issue = $it['issue'];
            $this->line(($i + 1) . '. ' . $it['class']::moduleName() . ' · ' . $it['label'] . ' / ' . $issue['key']
                . ' — ' . $issue['message']);
        }

        if ($noInteraction) {
            foreach ($allIssues as $it) {
                $this->newLine();
                $this->line('【' . $it['class']::moduleName() . ' · ' . $it['label'] . '】' . $it['issue']['key']);
                $this->line('将执行 SQL:');
                $sql = $it['class']::preview($it['issue']['key']);
                foreach ($sql as $s) {
                    $formatted = $this->formatSql($s);
                    if ($formatted === '' || !$this->isWriteQuery($formatted)) {
                        continue;
                    }
                    $this->line('  ' . $formatted);
                }
                $this->line('（非交互模式，未执行修复）');
            }

            return self::FAILURE;
        }

        $errorCount = 0;
        foreach ($allIssues as $it) {
            $issue = $it['issue'];
            $isOptional = !empty($issue['optional']);

            $this->newLine();
            $this->line('【' . $it['class']::moduleName() . ' · ' . $it['label'] . '】' . $issue['key']
                . ' 检查异常：' . $issue['message']);
            if (!empty($issue['repair'])) {
                $this->line('修复方式: ' . $issue['repair']);
            }

            $sql = $it['class']::preview($issue['key']);
            if (!empty($sql)) {
                $this->line('将执行 SQL:');
                foreach ($sql as $s) {
                    $formatted = $this->formatSql($s);
                    if ($formatted === '' || !$this->isWriteQuery($formatted)) {
                        continue;
                    }
                    $this->line('  ' . $formatted);
                }
            }

            if ($isOptional) {
                $ans = (string) $this->ask('是否处理（默认不处理）？ (yes/no)', 'no');
                if (strtolower($ans) !== 'yes') {
                    $this->line('跳过（默认不处理）');
                    continue;
                }
            } else {
                $ans = (string) $this->ask('确认执行以上 SQL？ (yes/no)', 'no');
                if (strtolower($ans) !== 'yes') {
                    $this->line('已取消');
                    continue;
                }
            }

            try {
                $it['class']::fix($issue['key']);
                $remaining = 0;
                foreach ($it['class']::check() as $after) {
                    if ($after['key'] === $issue['key']) {
                        $remaining++;
                    }
                }
                if ($remaining > 0) {
                    $this->error('修复后仍异常: ' . $issue['key']);
                    $errorCount++;
                } else {
                    $this->info('修复成功: ' . $issue['key']);
                }
            } catch (\Throwable $e) {
                $this->error('修复失败 [' . $it['class']::moduleName() . ' / ' . $issue['key'] . ']: ' . $e->getMessage());
                $errorCount++;
            }
        }

        if ($errorCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 将 pretend 返回的查询（字符串或 ['query'=>..., 'bindings'=>...]）格式化为可读 SQL。
     *
     * 绑定值内联到语句中，多行压成一行。
     *
     * @param string|array<int, mixed> $entry
     */
    protected function formatSql($entry): string
    {
        if (is_string($entry)) {
            return trim(preg_replace('/\s+/', ' ', $entry));
        }

        $query = (string) ($entry['query'] ?? '');
        $bindings = (array) ($entry['bindings'] ?? []);
        $index = 0;
        $query = preg_replace_callback('/\?/', function () use (&$index, $bindings) {
            $value = $bindings[$index++] ?? null;
            if ($value === null) {
                return 'NULL';
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);

        return trim(preg_replace('/\s+/', ' ', $query));
    }

    /**
     * 判断是否为写操作语句（仅展示写操作，读操作如 SELECT/SHOW 不纳入预览）。
     */
    protected function isWriteQuery(string $query): bool
    {
        $first = strtoupper(trim(substr($query, 0, 6)));

        return !in_array($first, ['SELECT', 'SHOW', 'DESCR', 'PRAGMA', 'EXPLAI', 'USE'], true);
    }
}
