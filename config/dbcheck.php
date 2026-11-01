<?php

return [
    /*
     * 自动发现扫描根（配置驱动，不依赖 laravel-modules）。
     *
     * 框架没有任何隐式默认扫描目录——所有要扫描的目录都必须在此显式定义。
     * 本默认模板仅预置常规约定根 app/DbChecks（目录不存在时自动跳过，不报错），
     * 项目按自身约定显式追加对应根即可。
     *
     * 每个根包含：
     *  - path：要递归扫描的目录（绝对路径，或相对 base_path() 的相对路径）
     *  - namespace：该目录对应的 PSR-4 基命名空间
     *
     * 扫描根支持「匹配模式」：path / namespace 各含至多一个 *（path 多于一个抛 LogicException），
     * 按 glob（仅目录）展开为具体根，namespace 的 * 以匹配到的目录名替换，
     * 无匹配自动跳过——惯例目录一条声明即可，免逐根穷举；惯例之外的特殊目录仍用普通根逐条声明。
     *
     * 注册表会递归扫描 <path> 下的所有 *.php，按「去掉 path 前缀 → 命名空间化」推导类名，
     * 收集其中实现 Dongasai\Dbcheck\Contracts\DbCheckInterface 的具体（非抽象）类。
     */
    'scan' => [
        // 匹配模式示例（模块惯例目录一条覆盖）：
        // ['path' => base_path('Modules/*/DbChecks'), 'namespace' => 'Modules\\*\\DbChecks\\'],
        ['path' => base_path('app/DbChecks'), 'namespace' => 'App\\DbChecks\\'],
    ],

    /*
     * 驱动适配器不做配置化硬编码：数据库类型由 Adapters\DbDriver 按连接检查判断
     * （mysql/mariadb → Mysql8Adapter；pgsql/postgres → PgsqlAdapter），
     * 例外用运行参数覆盖（命令行 --adapter= 或 DbDriver::override()，
     * 如 MySQL 5.7 旧版指定 mysql57，json 落 longText）。
     */
];
