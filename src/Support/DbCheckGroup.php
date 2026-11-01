<?php

namespace Dongasai\Dbcheck\Support;

/**
 * 框架标准分组常量。
 *
 * 业务分组名仍由检查器 group() 自行声明（返回 null = 常规，随默认扫执行）；
 * 本类收编「框架内置命令/能力」归属的标准分组，供检查器与文档统一引用：
 * DELETE 是能力默认归属（deleteExtra 开启即入组），SEEDER 是声明制（显式覆盖 group() 才入组）。
 */
class DbCheckGroup
{
    /**
     * 删除验证分组（多余数据行清理）。
     *
     * 删除属危险动作：启用 deleteExtra() 的检查器默认归入本分组，
     * 不随 dbcheck:check 默认执行，需 --group=delete / dbcheck:delete 显式触发；
     * 子类覆盖 group() 后以子类声明为准。
     */
    public const DELETE = 'delete';

    /**
     * 播种分组（固定数据 / 字段明细灌入）。
     *
     * 检查器显式覆盖 group() 返回本常量即声明为「种子数据」：不随 dbcheck:check
     * 默认执行，由 dbcheck:seed 显式播种（--group=<名> 可改收任意自定义分组）。
     */
    public const SEEDER = 'seeder';

    /**
     * 禁止实例化（纯常量类）。
     */
    private function __construct()
    {
    }
}
