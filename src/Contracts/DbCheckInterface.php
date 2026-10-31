<?php

namespace Dongasai\Dbcheck\Contracts;

/**
 * 数据库检查器契约。
 *
 * 每个检查器（具体类）实现本接口，由 DbCheckRegistry 自动发现并分类执行。
 * 三类检查（表结构 / 固定数据 / 字段明细）对应三个抽象基类，
 * 它们均实现本接口；直接实现本接口却不继承任一抽象基类的类会被 grouped() 判定为错误。
 */
interface DbCheckInterface
{
    /**
     * 检查器所属模块/表的展示名（命令输出首列）。
     *
     * 框架默认取表名；具体检查器可覆盖为更友好的模块名。
     */
    public static function moduleName(): string;

    /**
     * 执行检查，返回异常项列表。
     *
     * 每项结构：
     *  - key：稳定标识，供 fix() 派发修复（如 column.name / index.name / data.xxx / field.xxx.col）
     *  - type：类型（column|index|extra_column|extra_index|table|data|field|item）
     *  - message：问题描述
     *  - optional：是否为可选处理项（额外列/索引），默认不处理
     *  - repair：修复方式描述（用于交互展示）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(): array;

    /**
     * 按 key 修复单个异常项。未知 key 抛 LogicException。
     */
    public static function fix(string $key): void;

    /**
     * 预览修复将要执行的 SQL（使用连接层 pretend 模式，不落库）。
     *
     * @return array<int, string> 将要执行的 SQL 语句（仅写操作，已内联绑定值）
     */
    public static function preview(string $key): array;
}
