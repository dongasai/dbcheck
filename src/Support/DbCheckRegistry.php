<?php

namespace Dongasai\Dbcheck\Support;

use Dongasai\Dbcheck\Checks\AbstractTableCheck;
use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;
use Dongasai\Dbcheck\Checks\AbstractTableFieldCheck;
use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use LogicException;

/**
 * 检查器注册表：自动发现（配置驱动）+ 手动注册 + 按基类分组。
 *
 * 自动发现遍历 config('dbcheck.scan') 声明的目录根（支持 * 通配匹配模式，见 discover()），
 * 递归收集实现 DbCheckInterface 的具体类。
 * 手动注册（register）优先于自动发现，且按类名去重。
 * 分组依据：继承的抽象基类（表结构/固定数据/字段明细）；
 * 直接实现 DbCheckInterface 却不继承任一抽象基类的类 → 抛 LogicException。
 */
class DbCheckRegistry
{
    /**
     * 手动注册的类。
     *
     * @var array<int, class-string<DbCheckInterface>>
     */
    protected static array $manual = [];

    /**
     * 已发现的检查器缓存（惰性）。
     *
     * @var array<int, class-string<DbCheckInterface>>|null
     */
    protected static ?array $discovered = null;

    /**
     * 手动注册一个检查器（不受 $enabled 影响）。
     *
     * @param class-string<DbCheckInterface> $class
     */
    public static function register(string $class): void
    {
        static::$manual[] = $class;
        static::$discovered = null;
    }

    /**
     * 清空手动注册与发现缓存（测试用：重置静态状态，便于同一进程内重复构造场景）。
     */
    public static function clear(): void
    {
        static::$manual = [];
        static::$discovered = null;
    }

    /**
     * 收集全部应执行的检查器（自动发现 + 手动注册，去重，过滤抽象/$enabled=false）。
     *
     * @return array<int, class-string<DbCheckInterface>>
     */
    public static function all(): array
    {
        if (static::$discovered !== null) {
            return static::$discovered;
        }

        $classes = array_values(array_unique([
            ...static::discover(),
            ...static::$manual,
        ]));

        $result = [];
        foreach ($classes as $c) {
            if (!class_exists($c)) {
                continue;
            }
            $ref = new \ReflectionClass($c);
            if ($ref->isAbstract()) {
                continue;
            }
            if (!$ref->implementsInterface(DbCheckInterface::class)) {
                continue;
            }
            if ($ref->getStaticPropertyValue('enabled', true) === false) {
                continue;
            }
            $result[] = $c;
        }

        static::$discovered = $result;

        return $result;
    }

    /**
     * 解析检查器所属分组。
     *
     * 分组名完全由业务自行定义（如 'testdata'），框架不预置；
     * 未声明 group() 或返回空字符串的检查器归为「常规」（null）。
     *
     * @param class-string<DbCheckInterface> $class
     */
    public static function groupOf(string $class): ?string
    {
        if (!method_exists($class, 'group')) {
            return null;
        }

        $group = $class::group();

        return ($group === null || $group === '') ? null : (string) $group;
    }

    /**
     * 当前已注册检查器涉及的分组及各自数量（不含常规组）。
     *
     * @return array<string, int>
     */
    public static function groups(): array
    {
        $out = [];
        foreach (static::all() as $c) {
            $g = static::groupOf($c);
            if ($g === null) {
                continue;
            }
            $out[$g] = ($out[$g] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /**
     * 按继承的抽象基类分成三类（表结构 → 固定数据 → 字段明细）。
     *
     * @param string|null $group 只统计该分组的检查器；null 且 $all=false 时只统计「常规」（未分组）检查器
     * @param bool $all true 时不按分组过滤（含分组与常规）
     * @return array{table: array, data: array, field: array}
     */
    public static function grouped(?string $group = null, bool $all = false): array
    {
        $groups = [
            'table' => [],
            'data' => [],
            'field' => [],
        ];

        foreach (static::all() as $c) {
            if (!$all && static::groupOf($c) !== $group) {
                continue;
            }

            $ref = new \ReflectionClass($c);

            if ($ref->isSubclassOf(AbstractTableCheck::class)) {
                $groups['table'][] = $c;
            } elseif ($ref->isSubclassOf(AbstractTableDataCheck::class)) {
                $groups['data'][] = $c;
            } elseif ($ref->isSubclassOf(AbstractTableFieldCheck::class)) {
                $groups['field'][] = $c;
            } else {
                throw new LogicException(
                    "检查器 {$c} 直接实现 DbCheckInterface 但未继承任一抽象基类，无法归类（可能漏写 extends）"
                );
            }
        }

        return $groups;
    }

    /**
     * 按继承的抽象基类统计检查器数量（诊断用；不抛异常）。
     *
     * 直接实现 DbCheckInterface 却未继承任一抽象基类的类计入 unknown——
     * 与 grouped() 的 LogicException 口径不同：计数只用于展示，不能让诊断信息本身崩栈。
     *
     * @return array{table: int, data: int, field: int, unknown: int, total: int}
     */
    public static function counts(): array
    {
        $counts = [
            'table' => 0,
            'data' => 0,
            'field' => 0,
            'unknown' => 0,
            'total' => 0,
        ];

        foreach (static::all() as $c) {
            $ref = new \ReflectionClass($c);

            if ($ref->isSubclassOf(AbstractTableCheck::class)) {
                $counts['table']++;
            } elseif ($ref->isSubclassOf(AbstractTableDataCheck::class)) {
                $counts['data']++;
            } elseif ($ref->isSubclassOf(AbstractTableFieldCheck::class)) {
                $counts['field']++;
            } else {
                $counts['unknown']++;
            }
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * 按「分组 × 检查器类型」统计数量（dbcheck:info 展示用；不抛异常）。
     *
     * 一次遍历 all()，用 groupOf() 取分组（null = 常规），按继承的抽象基类
     * 分类为 table / data / field；直接实现 DbCheckInterface 却未继承任一抽象基类的
     * 类计入 unknown——与 counts() 同口径，统计只用于展示，不能让诊断信息本身崩栈。
     *
     * 顺序：常规（group=null）固定第一，其余分组名按字典序（strcmp）升序。
     *
     * @return array<int, array{group: string|null, table: int, data: int, field: int, unknown: int, total: int}>
     */
    public static function groupCounts(): array
    {
        $regular = null;
        $groups = [];

        foreach (static::all() as $c) {
            $group = static::groupOf($c);
            if ($group === null) {
                if ($regular === null) {
                    $regular = [
                        'group' => null,
                        'table' => 0,
                        'data' => 0,
                        'field' => 0,
                        'unknown' => 0,
                        'total' => 0,
                    ];
                }
                $bucket = &$regular;
            } else {
                if (!isset($groups[$group])) {
                    $groups[$group] = [
                        'group' => $group,
                        'table' => 0,
                        'data' => 0,
                        'field' => 0,
                        'unknown' => 0,
                        'total' => 0,
                    ];
                }
                $bucket = &$groups[$group];
            }

            $ref = new \ReflectionClass($c);

            if ($ref->isSubclassOf(AbstractTableCheck::class)) {
                $bucket['table']++;
            } elseif ($ref->isSubclassOf(AbstractTableDataCheck::class)) {
                $bucket['data']++;
            } elseif ($ref->isSubclassOf(AbstractTableFieldCheck::class)) {
                $bucket['field']++;
            } else {
                $bucket['unknown']++;
            }
            $bucket['total']++;

            unset($bucket);
        }

        $names = array_keys($groups);
        usort($names, function ($a, $b) {
            return strcmp($a, $b);
        });

        $out = [];
        if ($regular !== null) {
            $out[] = $regular;
        }
        foreach ($names as $name) {
            $out[] = $groups[$name];
        }

        return $out;
    }

    /**
     * 诊断用：逐条报告配置扫描根的展开与命中情况。
     *
     * 复用 expandRoot()（通配根展开为多条具体根）与 scanRoot()（递归扫描类名）：
     * 通配模板根无匹配目录时报告一条 matched=false / count=0，便于发现
     * 「扫描根声明了但目录不存在 / 命名空间拼错导致 class_exists 不通过」。
     *
     * @return array<int, array{path: string, namespace: string, matched: bool, count: int}>
     */
    public static function scanRoots(): array
    {
        $out = [];
        $scan = (array) config('dbcheck.scan', []);
        // 与 all() 同口径过滤（抽象/未实现接口/$enabled=false 不计入），
        // 否则「命中 n 个」会大于「发现 N 个检查器」，在诊断「声明了但没扫到」时误导。
        $available = array_flip(static::all());

        foreach ($scan as $root) {
            $path = (string) ($root['path'] ?? '');
            $ns = (string) ($root['namespace'] ?? '');

            $concrete = static::expandRoot($path, $ns);
            if ($concrete === []) {
                // 模板根无匹配目录（或 path/namespace 未声明）：原样报告未命中
                $out[] = [
                    'path' => $path,
                    'namespace' => $ns,
                    'matched' => false,
                    'count' => 0,
                ];
                continue;
            }

            foreach ($concrete as $one) {
                $found = [];
                static::scanRoot($one['path'], $one['namespace'], $found);
                $checkers = array_values(array_filter($found, static function ($class) use ($available) {
                    return isset($available[$class]);
                }));
                $out[] = [
                    'path' => $one['path'],
                    'namespace' => $one['namespace'],
                    'matched' => true,
                    'count' => count($checkers),
                ];
            }
        }

        return $out;
    }

    /**
     * 递归扫描配置声明的目录根，推导类名。
     *
     * 扫描根支持「匹配模式」：path / namespace 中含 `*`（path 至多一个）时按 glob
     * 展开为具体目录根（无匹配自动跳过），namespace 的 `*` 以匹配到的目录名替换——
     * 惯例目录一条声明即可，等价逐根穷举但免维护（具体示例见 config/dbcheck.php 注释）。
     *
     * @return array<int, class-string>
     */
    protected static function discover(): array
    {
        $found = [];
        $scan = (array) config('dbcheck.scan', []);

        foreach ($scan as $root) {
            foreach (static::expandRoot((string) ($root['path'] ?? ''), (string) ($root['namespace'] ?? '')) as $concrete) {
                static::scanRoot($concrete['path'], $concrete['namespace'], $found);
            }
        }

        return $found;
    }

    /**
     * 展开单个扫描根：path 含 `*` 时按 glob（仅目录）展开为 0..N 个具体根，否则原样单根。
     *
     * @return array<int, array{path: string, namespace: string}>
     */
    protected static function expandRoot(string $path, string $ns): array
    {
        if ($path === '' || $ns === '') {
            return [];
        }

        if (strpos($path, '*') === false) {
            return is_dir($path) ? [['path' => $path, 'namespace' => $ns]] : [];
        }

        if (substr_count($path, '*') > 1) {
            throw new LogicException('扫描根 path 至多支持一个 * 通配: ' . $path);
        }

        $star = (int) strpos($path, '*');
        $prefix = (string) substr($path, 0, $star);
        $suffix = (string) substr($path, $star + 1);

        $out = [];
        $dirs = glob($path, GLOB_ONLYDIR) ?: [];
        sort($dirs);
        foreach ($dirs as $dir) {
            // 匹配到的目录名 = 去掉模板前缀/后缀的中间段
            $val = (string) substr($dir, strlen($prefix), strlen($dir) - strlen($prefix) - strlen($suffix));
            $out[] = [
                'path' => $dir,
                'namespace' => str_replace('*', $val, $ns),
            ];
        }

        return $out;
    }

    /**
     * 递归扫描单个具体目录根，把发现的类追加进 $found。
     *
     * @param array<int, class-string> $found
     */
    protected static function scanRoot(string $path, string $ns, array &$found): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $rel = substr($file->getPathname(), strlen(rtrim($path, '/\\')));
            $rel = ltrim($rel, '/\\');
            $rel = preg_replace('/\.php$/', '', $rel);
            if ($rel === '' || $rel === null) {
                continue;
            }

            /** @var class-string $class */
            $class = rtrim($ns, '\\') . '\\' . str_replace(['/', '\\'], '\\', $rel);

            if (!class_exists($class)) {
                continue;
            }
            $found[] = $class;
        }
    }
}
