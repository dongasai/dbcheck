<?php

namespace Dongasai\Dbcheck\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 初始化 dbcheck 配置。
 *
 * 把包内默认配置 extensions/db-check/config/dbcheck.php 复制到项目 config/dbcheck.php，
 * 之后即可在项目的 config/dbcheck.php 里编辑 scan（扫描根）与 adapters（驱动覆盖）。
 *
 * 等价也可用：php artisan vendor:publish --tag=dbcheck-config
 */
class DbcheckInitCommand extends Command
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'dbcheck:init {--force : 覆盖已存在的 config/dbcheck.php}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '初始化 dbcheck 配置到项目 config/ 目录';

    /**
     * 执行命令。
     */
    public function handle(): int
    {
        $source = realpath(__DIR__ . '/../../../config/dbcheck.php');
        $target = base_path('config/dbcheck.php');

        if ($source === false || !File::exists($source)) {
            $this->error('未找到包内默认配置: ' . __DIR__ . '/../../config/dbcheck.php');

            return self::FAILURE;
        }

        if (File::exists($target) && !$this->option('force')) {
            $this->warn('config/dbcheck.php 已存在，如需覆盖请加 --force');

            return self::SUCCESS;
        }

        $dir = dirname($target);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::copy($source, $target);

        $this->info('已初始化配置到 config/dbcheck.php');
        $this->line('可编辑该文件配置 scan（自动发现扫描根）与 adapters（驱动覆盖）。');

        return self::SUCCESS;
    }
}
