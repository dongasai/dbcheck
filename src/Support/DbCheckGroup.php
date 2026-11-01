<?php

namespace Dongasai\Dbcheck\Support;

/**
 * 框架标准分组常量。
 *
 * 框架不预置任何业务分组名——分组语义由检查器 group() 自行声明（返回 null = 常规，随默认扫执行）；
 * 本类只收编「框架内置能力默认归属」的分组，供检查器与文档统一引用。
 */
class DbCheckGroup
{
    /**
     * 删除验证分组（多余数据行清理）。
     *
     * 删除属危险动作：启用 deleteExtra() 的检查器默认归入本分组，
     * 不随 dbcheck:check 默认执行，需 --group=delete 显式触发；
     * 子类覆盖 group() 后以子类声明为准。
     */
    public const DELETE = 'delete';

    /**
     * 禁止实例化（纯常量类）。
     */
    private function __construct()
    {
    }
}
