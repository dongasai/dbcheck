<?php

namespace Dongasai\Dbcheck\Support;

/**
 * 预览上下文：pretend 模式下「读库结果」的缓存。
 *
 * 背景：Laravel 连接层的 pretend() 会让该连接上的所有 SELECT 直接返回空数组
 * （Connection::select() 在 pretending 时短路返回 []）。而 fix() 为了决定
 * 「新增列还是修改列」「drop 后重建索引还是只新增」「行是否存在」需要读库，
 * 若在 pretend 内读，一律读到空，预览出的 SQL 与真实修复不一致：
 *  - 已存在的列被预览成 `add column`（真实是 `modify`）；
 *  - 已存在的索引预览里只 `add index` 不 `drop index`（真实是先删后建）；
 *  - FieldCheck 的 `item.*` 预览必抛「行不存在」；insert_merge 预览抛「引用行不存在」；
 *  - 预览漏写 updated_at（hasColumn 在 pretend 下恒为 false）。
 *
 * 做法：各基类 preview() 先 begin() 进入预览上下文，在 pretend **之前**把 fix() 依赖的
 * 读操作经 remember() 预热进缓存；随后 pretend 内再读同一 key 直接命中缓存，拿到真实元数据。
 * end() 清空缓存（不跨预览残留，避免元数据过期）。非预览上下文（active()=false）时
 * remember() 直接执行、不缓存，行为与普通调用完全一致。
 */
class PreviewContext
{
    /**
     * 预览期间的读缓存（null = 不在预览上下文）。
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $cache = null;

    /**
     * 外层预览上下文的缓存栈（支持嵌套 preview()：内层结束恢复外层缓存，不丢外层预热）。
     *
     * @var array<int, array<string, mixed>|null>
     */
    protected static array $frames = [];

    /**
     * 进入预览上下文（清空当前缓存；若在嵌套中则把外层缓存压栈）。
     */
    public static function begin(): void
    {
        static::$frames[] = static::$cache;
        static::$cache = [];
    }

    /**
     * 退出预览上下文：有外层则恢复外层缓存，否则清空缓存（务必放在 finally 中，异常也不残留）。
     */
    public static function end(): void
    {
        static::$cache = static::$frames === [] ? null : array_pop(static::$frames);
    }

    /**
     * 强制清空缓存与嵌套栈（测试用：防止异常路径残留跨用例污染）。
     */
    public static function reset(): void
    {
        static::$cache = null;
        static::$frames = [];
    }

    /**
     * 是否处于预览上下文。
     */
    public static function active(): bool
    {
        return static::$cache !== null;
    }

    /**
     * 读操作缓存：预览上下文中同一 key 只真正执行一次；其余情况直接执行。
     *
     * @param string $key 缓存键（同一 key 必须对应同一读操作与参数）
     * @param callable $resolver 真正的读操作
     * @return mixed
     */
    public static function remember(string $key, callable $resolver)
    {
        if (static::$cache !== null && array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }

        $value = $resolver();

        if (static::$cache !== null) {
            static::$cache[$key] = $value;
        }

        return $value;
    }

    /**
     * 从 pretend 记录中挑出写语句（预览对外只展示将要执行的写 SQL）。
     *
     * fix() 内部可能有判重/读元数据的 SELECT（如 insertIfMissing 的 exists()），
     * pretend 会把它们一并记录；这里按语句首关键字过滤掉 SELECT/SHOW/DESCR/PRAGMA/EXPLAIN/USE。
     *
     * @param array<int, array<string, mixed>> $statements pretend() 返回值
     * @return array<int, array<string, mixed>>
     */
    public static function writeStatements(array $statements): array
    {
        $writes = [];
        foreach ($statements as $statement) {
            $query = ltrim((string) ($statement['query'] ?? ''));
            $parts = preg_split('/\s+/', $query, 2);
            $keyword = strtoupper((string) ($parts[0] ?? ''));
            if (in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'PRAGMA', 'EXPLAIN', 'USE'], true)) {
                continue;
            }
            $writes[] = $statement;
        }

        return $writes;
    }
}
