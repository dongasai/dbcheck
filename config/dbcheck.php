<?php

return [
    /*
     * 自动发现扫描根（配置驱动，不依赖 laravel-modules）。
     *
     * 每个根包含：
     *  - path：要递归扫描的目录（绝对路径，或相对 base_path() 的相对路径）
     *  - namespace：该目录对应的 PSR-4 基命名空间
     *
     * 注册表会递归扫描 <path> 下的所有 *.php，按「去掉 path 前缀 → 命名空间化」推导类名，
     * 收集其中实现 Dongasai\Dbcheck\Contracts\DbCheckInterface 的具体（非抽象）类。
     *
     * 例：扫描本项目的各模块 DbChecks 目录（约定 Modules/<模块>/DbChecks/）。
     * 若使用 nwidart/laravel-modules，可仅配置 Modules 根，由检查器自身的 $enabled 控制开关；
     * 也可为每个模块单独配置一个根，从而只扫描已启用模块。
     */
    'scan' => [
        // ['path' => base_path('Modules'), 'namespace' => 'Modules\\'],
    ],

    /*
     * 驱动适配器覆盖（可选）。
     *
     * 键为连接名（默认连接用 'default'），值为适配器类名。
     * 默认按 Laravel 驱动自动选择：mysql/mariadb → Mysql8Adapter（MySQL 8+，json 原生类型）；
     * pgsql/postgres → PgsqlAdapter（存在性检查，DDL 适配 PG）。
     * 若数据库为 MySQL 5.7 等无原生 JSON 的旧版本，可显式覆盖为 MysqlAdapter（json 落 longText）：
     *  'adapters' => ['default' => Dongasai\Dbcheck\Adapters\MysqlAdapter::class],
     */
    'adapters' => [],
];
