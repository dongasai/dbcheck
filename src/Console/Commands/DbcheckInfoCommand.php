<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Illuminate\Console\Command;

/**
 * 版本与注册检查器分组统计命令。
 *
 * 用法：
 *   php artisan dbcheck:info
 *
 * 输出：包版本、PHP 版本、Laravel 版本（能探测到才输出）、检查器总数与
 * 三类明细、按「分组 × 类型」的分组统计表。
 *
 * 只读诊断：不连接数据库、不执行任何检查；0 个检查器仅告警，仍返回 SUCCESS
 * （「0 检查器算失败」的口径留给 dbcheck:check）。注册表发现异常时输出错误并返回 FAILURE。
 */
class DbcheckInfoCommand extends Command
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:info';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '版本与注册检查器分组统计（不连接数据库、不执行检查）';

    /**
     * 执行命令。
     */
    public function handle(): int
    {
        $this->line('dbcheck ' . $this->packageVersion());
        $this->line('PHP ' . PHP_VERSION);

        $framework = $this->frameworkVersion();
        if ($framework !== null) {
            $this->line('Laravel ' . $framework);
        }

        $this->newLine();

        try {
            $counts = DbCheckRegistry::counts();
            $groupCounts = DbCheckRegistry::groupCounts();
        } catch (\Throwable $e) {
            $this->error('检查器发现失败: ' . $e->getMessage());

            return self::FAILURE;
        }

        $line = '检查器: 共 ' . $counts['total'] . ' 个（表结构 ' . $counts['table']
            . ' / 固定数据 ' . $counts['data'] . ' / 字段明细 ' . $counts['field'];
        if ($counts['unknown'] > 0) {
            $line .= ' / 未归类 ' . $counts['unknown'];
        }
        $line .= '）';
        $this->line($line);

        $this->newLine();

        $rows = [];
        foreach ($groupCounts as $row) {
            $rows[] = [
                $row['group'] === null ? '常规' : $row['group'],
                $row['table'],
                $row['data'],
                $row['field'],
                $row['unknown'],
                $row['total'],
            ];
        }
        // 0 个检查器时没有分组行：不渲染只有表头的空表，告警本身已足够说明问题
        if ($rows !== []) {
            $this->table(['分组', '表结构', '固定数据', '字段明细', '未归类', '合计'], $rows);
        }

        if ($counts['total'] === 0) {
            $this->warn('未发现任何检查器：请检查 config/dbcheck.php 的 scan 根与类命名空间');
        }

        return self::SUCCESS;
    }

    /**
     * 包版本：Composer 运行时元数据 → composer.json 的 version → git tag → dev 版本 → unknown。
     *
     * 宿主项目经 Composer 安装时直接命中元数据（tag 版本）；本包源码开发态下
     * Composer 把根包解析为 dev-main / 1.0.x-dev，此时优先用 git describe 的最近 tag，
     * 让 `dbcheck:info` 显示真实发布版本（tag 之后有提交时带 -N-g<hash> 后缀）。
     * 所有探测异常均吞掉并继续回落：版本展示失败不应影响信息命令。
     */
    protected function packageVersion(): string
    {
        $installed = $this->installedVersion('dongasai/dbcheck');

        if ($installed !== null && !$this->isDevVersion($installed)) {
            return $installed;
        }

        $declared = $this->declaredVersion();
        if ($declared !== null) {
            return $declared;
        }

        $git = $this->gitVersion();
        if ($git !== null) {
            return $git;
        }

        return $installed !== null ? $installed : 'unknown（未通过 Composer 安装）';
    }

    /**
     * 从 Composer 运行时元数据取包版本（未安装 / 不可用返回 null）。
     */
    protected function installedVersion(string $package): ?string
    {
        try {
            if (!class_exists('\\Composer\\InstalledVersions')
                || !\Composer\InstalledVersions::isInstalled($package)) {
                return null;
            }
            $version = \Composer\InstalledVersions::getPrettyVersion($package);

            return (is_string($version) && $version !== '') ? $version : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 是否 Composer 的「开发分支版本」（dev-main / 1.0.x-dev / 9999999-dev 等）。
     */
    protected function isDevVersion(string $version): bool
    {
        return strpos($version, 'dev-') === 0 || substr($version, -4) === '-dev';
    }

    /**
     * 包根 composer.json 显式声明的 version（未声明返回 null）。
     */
    protected function declaredVersion(): ?string
    {
        try {
            $file = dirname(__DIR__, 3) . '/composer.json';
            if (!is_file($file)) {
                return null;
            }
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json) && isset($json['version'])
                && is_string($json['version']) && $json['version'] !== '') {
                return $json['version'];
            }
        } catch (\Throwable $e) {
            // 继续回落
        }

        return null;
    }

    /**
     * 源码开发态的最近 git tag（无 .git / git 不可用 / 无 tag 时返回 null）。
     *
     * `git describe --tags`：HEAD 恰在 tag 上得到 `1.0.4`，tag 之后有提交得到
     * `1.0.4-1-g<hash>`；完全无 tag 时失败回落，由 packageVersion() 接着兜底。
     */
    protected function gitVersion(): ?string
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/.git') || !function_exists('exec')) {
            return null;
        }

        try {
            $output = [];
            $code = 1;
            @exec('git -C ' . escapeshellarg($root) . ' describe --tags 2>&1', $output, $code);
            if ($code !== 0 || $output === []) {
                return null;
            }
            $version = trim((string) $output[0]);

            return $version !== '' ? $version : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 框架版本：app()->version() → Composer 的 laravel/framework / illuminate/support；取不到返回 null。
     *
     * 所有探测异常均吞掉：取不到时命令行不输出该行，不得抛错。
     */
    protected function frameworkVersion(): ?string
    {
        try {
            if (function_exists('app')) {
                $app = app();
                if (is_object($app) && method_exists($app, 'version')) {
                    $version = $app->version();
                    if (is_string($version) && $version !== '') {
                        return $version;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 继续用 Composer 元数据探测
        }

        foreach (['laravel/framework', 'illuminate/support'] as $package) {
            $version = $this->installedVersion($package);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }
}
