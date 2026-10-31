<?php

namespace Dongasai\Dbcheck\Support;

use Dongasai\Dbcheck\Checks\AbstractTableDataCheck;
use Dongasai\Dbcheck\Checks\AbstractTableExistsCheck;
use Dongasai\Dbcheck\Checks\AbstractTableFieldCheck;
use Dongasai\Dbcheck\Checks\AbstractTableRowCheck;
use Dongasai\Dbcheck\Contracts\DbCheckInterface;
use LogicException;

/**
 * 检查器注册表：自动发现（配置驱动）+ 手动注册 + 按基类分组。
 *
 * 自动发现遍历 config('dbcheck.scan') 声明的目录根，递归收集实现 DbCheckInterface 的具体类。
 * 手动注册（register）优先于自动发现，且按类名去重。
 * 分组依据：继承的抽象基类（存在/行差异/固定数据/字段明细）；
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
     * 按继承的抽象基类分成四类（存在 → 行差异 → 固定数据 → 字段明细）。
     *
     * @return array{exist: array, row: array, data: array, field: array}
     */
    public static function grouped(): array
    {
        $groups = [
            'exist' => [],
            'row' => [],
            'data' => [],
            'field' => [],
        ];

        foreach (static::all() as $c) {
            $ref = new \ReflectionClass($c);

            if ($ref->isSubclassOf(AbstractTableRowCheck::class)) {
                $groups['row'][] = $c;
            } elseif ($ref->isSubclassOf(AbstractTableExistsCheck::class)) {
                $groups['exist'][] = $c;
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
     * 递归扫描配置声明的目录根，推导类名。
     *
     * @return array<int, class-string>
     */
    protected static function discover(): array
    {
        $found = [];
        $scan = (array) config('dbcheck.scan', []);

        foreach ($scan as $root) {
            $path = $root['path'] ?? null;
            $ns = $root['namespace'] ?? null;
            if (!$path || !$ns || !is_dir($path)) {
                continue;
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

        return $found;
    }
}
