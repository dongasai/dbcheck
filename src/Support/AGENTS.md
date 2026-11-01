📍 [dbcheck](../../AGENTS.md) / **Support**

# 支撑层：注册表 + 列类型常量

## 文件

| 文件 | 职责 |
|------|------|
| DbCheckRegistry.php | 检查器注册表：自动发现 + 手动注册 + 过滤 + 分组 + 三类归类 |
| ColumnType.php | 22 个列类型常量（ID / BIG_INTEGER / … / DECIMAL / ENUM），DEFAULT = STRING；ENUM 需配 `values` 键 |

## DbCheckRegistry 机制

- **自动发现**：遍历 config('dbcheck.scan') 声明的根（path + namespace），递归扫 *.php → 去根路径前缀 → 分隔符转 `\` → 拼命名空间得类名 → class_exists 通过才收集。
- **手动注册** register()：优先于自动发现、按类名去重、清空发现缓存、不受 $enabled 影响。
- **过滤**（all()）：类不存在 / 抽象类 / 未实现 DbCheckInterface / 静态 `$enabled=false` 均跳过；结果惰性缓存。
- **分组**：groupOf() 读类静态 group()，空串视为常规（null）；groups() 汇总各分组数量；grouped($group,$all) 按继承基类归入 table/data/field 三组——**直接实现接口未继承基类 → LogicException**。
- **测试辅助** clear()：清空手动注册与发现缓存。
- 返回值均为 class-string 数组；执行顺序约定 table → data → field。

## ColumnType

检查器 columns() 的 type 必须用常量，禁裸字符串；缺省回落 STRING。

## 依赖

- 依赖 Checks/ 三基类做归类（isSubclassOf）；被 Console/Commands 调用。
