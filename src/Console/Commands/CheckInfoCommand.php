<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Illuminate\Console\Command;

/**
 * 数据库自检与修复命令。
 *
 * 用法：
 *   php artisan dbcheck:check                    # 交互：逐项询问是否修复（先看 SQL 再确认）
 *   php artisan dbcheck:check --no-interaction  # 只报告，不执行任何变更
 *   php artisan dbcheck:check --group=testdata  # 只执行 testdata 分组（即播种测试数据）
 *   php artisan dbcheck:check --all-groups      # 执行全部分组（含测试数据）
 *   php artisan dbcheck:check --connection=mysql # 指定数据库连接名（默认用 database.default 默认连接）
 *   php artisan dbcheck:check --adapter=mysql57  # 指定驱动适配器（类名或驱动别名；默认按数据库类型自动探测）
 *   php artisan dbcheck:check --fix-flow=inline  # 边查边修：每个检查器查完即逐条确认修复（默认 batch：先全量报告）
 *
 * 分组：检查器通过 group() 自行声明分组名（任意字符串，框架不预置）；
 * group() 返回 null 表示常规检查（默认执行），返回非空字符串表示分组检查
 * （默认不执行，需 --group=<名称> 显式触发）——所谓 seeder 即执行该分组的 row/field 检查。
 *
 * 连接：--connection 把「默认连接」指向目标连接，未显式声明 connectionName() 的检查器
 * 全部随之切换；显式声明了连接名的检查器不受影响。
 * 适配器：--adapter 运行期覆盖驱动选择（DbDriver::override），默认按数据库类型检查判断。
 * 修复节奏：--fix-flow=batch（默认）先全量报告再逐条确认修复；--fix-flow=inline 边查边修（仅交互模式生效）。
 *
 * 执行顺序：表结构 → 固定数据 → 字段明细（按 DbCheckRegistry::grouped()）。
 * 每张检查器返回异常项，交互模式下先预览 SQL 再确认执行，执行后自动复检。
 */
class CheckInfoCommand extends Command
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:check {--check=fields} {--connection= : 数据库连接名（默认用 database.default 默认连接）} {--adapter= : 驱动适配器（类名或驱动别名 mysql8/mysql/mariadb/mysql57/pgsql/postgres，默认自动探测）} {--fix-flow= : 修复节奏：batch=先全量报告后逐条修复（默认）；inline=边查边修（仅交互模式生效）} {--group=* : 只执行指定分组（可多次），如 --group=testdata} {--all-groups : 执行全部分组（含 testdata）}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '数据库自检与修复（按表三类检查：表结构 / 固定数据 / 字段明细）';

    /**
     * 类别顺序与展示名。
     *
     * @var array<string, string>
     */
    protected $order = [
        'table' => '表结构',
        'data' => '固定数据',
        'field' => '字段明细',
    ];

    /**
     * 执行命令。
     */
    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        if ($connection !== '') {
            if (!array_key_exists($connection, (array) config('database.connections'))) {
                $this->error('数据库连接不存在: ' . $connection);

                return self::FAILURE;
            }
            // 把「默认连接」指向目标连接：未显式声明 connectionName() 的检查器随之切换
            config(['database.default' => $connection]);
        }

        $adapter = (string) $this->option('adapter');
        if ($adapter !== '') {
            if (!DbDriver::supports($adapter)) {
                $this->error('未知的数据库驱动适配器: ' . $adapter);

                return self::FAILURE;
            }
            // 运行期覆盖驱动选择（检查器内部经 AdapterFactory → DbDriver 生效）
            DbDriver::override($adapter);
        }

        $check = (string) $this->option('check');
        if ($check !== 'fields') {
            $this->warn('暂仅支持 --check=fields');

            return self::SUCCESS;
        }

        $noInteraction = (bool) $this->option('no-interaction');

        $flow = strtolower((string) $this->option('fix-flow'));
        if ($flow !== '' && !in_array($flow, ['batch', 'inline'], true)) {
            $this->error('--fix-flow 仅支持 batch / inline');

            return self::FAILURE;
        }

        $wanted = array_values(array_filter(array_map('strval', (array) $this->option('group'))));
        $allGroups = (bool) $this->option('all-groups');

        if ($allGroups) {
            $groups = DbCheckRegistry::grouped(null, true);
        } elseif (count($wanted) > 0) {
            // 指定了分组：合并多个分组的检查器（去重，保持类别顺序）
            $groups = ['table' => [], 'data' => [], 'field' => []];
            foreach ($wanted as $g) {
                foreach (DbCheckRegistry::grouped($g) as $k => $list) {
                    foreach ($list as $c) {
                        if (!in_array($c, $groups[$k], true)) {
                            $groups[$k][] = $c;
                        }
                    }
                }
            }
        } else {
            // 未指定：只执行「常规」（group() 返回 null）的检查器，排除 testdata 等分组
            $groups = DbCheckRegistry::grouped(null);
        }

        // inline：边查边修（每个检查器查完若有异常，立即逐条确认修复）；非交互无修复动作，统一走 batch 报告
        if ($flow === 'inline' && !$noInteraction) {
            return $this->runInline($groups);
        }

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
            $this->previewAll($allIssues);

            return self::FAILURE;
        }

        $errorCount = 0;
        foreach ($allIssues as $it) {
            if (!$this->fixIssue($it['class'], $it['issue'], $it['label'])) {
                $errorCount++;
            }
        }

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * inline 节奏：边查边修——每个检查器查完若有异常，立即逐条预览并确认修复，再查下一个。
     *
     * @param array<string, array<int, class-string>> $groups grouped() 归类结果
     */
    protected function runInline(array $groups): int
    {
        $total = 0;
        $errorCount = 0;

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
                    $total++;
                    if (!$this->fixIssue($class, $issue, $label)) {
                        $errorCount++;
                    }
                }
            }
        }

        $this->newLine();
        if ($total === 0) {
            $this->info('全部检查通过，无异常。');

            return self::SUCCESS;
        }

        $this->line('共 ' . $total . ' 个异常项处理完毕（失败 ' . $errorCount . ' 个）。');

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 非交互模式：逐项预览修复 SQL，不执行任何变更。
     *
     * @param array<int, array{class: class-string, issue: array<string, mixed>, label: string}> $allIssues
     */
    protected function previewAll(array $allIssues): void
    {
        foreach ($allIssues as $it) {
            $this->newLine();
            $this->line('【' . $it['class']::moduleName() . ' · ' . $it['label'] . '】' . $it['issue']['key']);
            $this->previewSql($it['class'], $it['issue']['key']);
            $this->line('（非交互模式，未执行修复）');
        }
    }

    /**
     * 预览单个修复项将要执行的写 SQL（经 formatSql 排版）。
     *
     * @param class-string $class
     */
    protected function previewSql(string $class, string $key): void
    {
        foreach ($class::preview($key) as $s) {
            $formatted = $this->formatSql($s);
            if ($formatted === '' || !$this->isWriteQuery($formatted)) {
                continue;
            }
            $this->line('  ' . $formatted);
        }
    }

    /**
     * 交互修复单个异常项：展示详情 → 预览 SQL → 确认 → 执行 → 复检。
     *
     * 跳过 / 取消不算失败，返回 true；执行失败或修复后仍异常返回 false。
     *
     * @param class-string $class
     * @param array<string, mixed> $issue
     */
    protected function fixIssue(string $class, array $issue, string $label): bool
    {
        $isOptional = !empty($issue['optional']);

        $this->newLine();
        $this->line('【' . $class::moduleName() . ' · ' . $label . '】' . $issue['key']
            . ' 检查异常：' . $issue['message']);
        if (!empty($issue['repair'])) {
            $this->line('修复方式: ' . $issue['repair']);
        }

        $this->previewSql($class, $issue['key']);

        if ($isOptional) {
            $ans = (string) $this->ask('是否处理（默认不处理）？ (yes/no)', 'no');
            if (strtolower($ans) !== 'yes') {
                $this->line('跳过（默认不处理）');

                return true;
            }
        } else {
            $ans = (string) $this->ask('确认执行以上 SQL？ (yes/no)', 'no');
            if (strtolower($ans) !== 'yes') {
                $this->line('已取消');

                return true;
            }
        }

        try {
            $class::fix($issue['key']);
            $remaining = 0;
            foreach ($class::check() as $after) {
                if ($after['key'] === $issue['key']) {
                    $remaining++;
                }
            }
            if ($remaining > 0) {
                $this->error('修复后仍异常: ' . $issue['key']);

                return false;
            }
            $this->info('修复成功: ' . $issue['key']);

            return true;
        } catch (\Throwable $e) {
            $this->error('修复失败 [' . $class::moduleName() . ' / ' . $issue['key'] . ']: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 将 pretend 返回的查询（字符串或 ['query'=>..., 'bindings'=>...]）格式化为可读 SQL。
     *
     * 绑定值内联到语句中，再按关键字边界换行排版（见 prettySql）。
     *
     * @param string|array<int, mixed> $entry
     */
    protected function formatSql($entry): string
    {
        if (is_string($entry)) {
            return $this->prettySql($entry);
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

        return $this->prettySql($query);
    }

    /**
     * 语句排版：在关键字边界插入换行提升长语句可读性（不改变语句语义）。
     *
     * - alter table：每个动作（add/drop/modify/change/rename）独立一行；
     * - insert：values 换行，多行元组分隔；
     * - update / delete：set / where 换行；
     * - create table：列定义逐行（对齐 SHOW CREATE TABLE 风格）。
     *
     * 单引号字符串字面量内不做任何处理。
     */
    protected function prettySql(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql));

        // set/where 规则只作用于对应语句类型（避免 create table 的 character set 被误换行）
        $isUpdate = preg_match('/^update\s/i', $sql) === 1;
        $isDelete = preg_match('/^delete\s/i', $sql) === 1;

        // 按单引号字符串切片（'' 为字面内引号转义，配对切分即可），规则只作用于非字符串片段
        $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $part = preg_replace('/,\s*(add\s|drop\s|modify\s|change\s|rename\s)/i', ",\n  $1", $part);
            $part = preg_replace('/\s+values\s*\(/i', " values\n  (", $part);
            $part = str_replace('), (', "),\n  (", $part);
            if ($isUpdate) {
                $part = preg_replace('/\s+set\s+/i', "\nset ", $part);
            }
            if ($isUpdate || $isDelete) {
                $part = preg_replace('/\s+where\s+/i', "\nwhere ", $part);
            }
            $parts[$i] = $part;
        }
        $sql = implode('', $parts);

        if (preg_match('/^create\s+table\s/i', $sql)) {
            $sql = $this->prettyCreateTable($sql);
        }

        return $sql;
    }

    /**
     * create table 排版：把顶层括号内的列/索引定义逐行展开，表选项随闭括号留在末行。
     */
    protected function prettyCreateTable(string $sql): string
    {
        $open = strpos($sql, '(');
        if ($open === false) {
            return $sql;
        }

        // 引号感知地找顶层闭括号
        $depth = 0;
        $inString = false;
        $close = -1;
        $len = strlen($sql);
        for ($i = $open; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'") {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        if ($close === -1) {
            return $sql;
        }

        $items = $this->splitTopLevel(substr($sql, $open + 1, $close - $open - 1));
        if (count($items) === 0) {
            return $sql;
        }

        return substr($sql, 0, $open + 1) . "\n  " . implode(",\n  ", $items) . "\n" . substr($sql, $close);
    }

    /**
     * 顶层逗号切分（引号与括号感知），返回去除首尾空白的片段。
     *
     * @return array<int, string>
     */
    protected function splitTopLevel(string $body): array
    {
        $items = [];
        $buf = '';
        $depth = 0;
        $inString = false;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === "'") {
                $inString = !$inString;
            }
            if ($inString) {
                $buf .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            }
            if ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $items[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $items[] = trim($buf);
        }

        return $items;
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
