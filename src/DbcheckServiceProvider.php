<?php

namespace Dongasai\Dbcheck;

use Dongasai\Dbcheck\Console\Commands\CheckInfoCommand;
use Dongasai\Dbcheck\Console\Commands\DbcheckDeleteCommand;
use Dongasai\Dbcheck\Console\Commands\DbcheckInitCommand;
use Dongasai\Dbcheck\Console\Commands\DbcheckSkillCommand;
use Illuminate\Support\ServiceProvider;

/**
 * DbCheck 服务供给器。
 *
 * - 合并配置 dbcheck.php；
 * - 在控制台环境注册 dbcheck:check 命令。
 *
 * 通过 composer 的 extra.laravel.providers 自动发现（Laravel 包自动发现机制），
 * 无需在主项目手动注册。
 */
class DbcheckServiceProvider extends ServiceProvider
{
    /**
     * 注册服务。
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dbcheck.php', 'dbcheck');
    }

    /**
     * 启动服务。
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckInfoCommand::class,
                DbcheckDeleteCommand::class,
                DbcheckInitCommand::class,
                DbcheckSkillCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/dbcheck.php' => base_path('config/dbcheck.php'),
            ], 'dbcheck-config');
        }
    }
}
