<?php

/**
 * PHPUnit 引导：加载 Composer autoload，并为「非 Laravel 应用」补齐全局 helper。
 *
 * 包内代码在宿主 Laravel 中运行，用到 config() / now() 两个全局 helper；
 * 本包的测试用 Capsule 起一个最小容器，因此在这里按 Laravel 同名语义补上（已存在则跳过）。
 */

require __DIR__ . '/../vendor/autoload.php';

if (!function_exists('app')) {
    /**
     * 从全局容器取实例。
     *
     * @param string|null $abstract
     * @return mixed
     */
    function app($abstract = null)
    {
        $container = \Illuminate\Container\Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * 读写容器里的 config 仓库（支持数组批量设置）。
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        $repository = \Illuminate\Container\Container::getInstance()->make('config');

        if ($key === null) {
            return $repository;
        }

        if (is_array($key)) {
            foreach ($key as $name => $value) {
                $repository->set($name, $value);
            }

            return $repository;
        }

        return $repository->get($key, $default);
    }
}

if (!function_exists('now')) {
    /**
     * 当前时间（Carbon）。
     *
     * @param \DateTimeZone|string|null $tz
     * @return \Carbon\Carbon
     */
    function now($tz = null)
    {
        return \Carbon\Carbon::now($tz);
    }
}
