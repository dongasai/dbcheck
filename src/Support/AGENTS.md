📍 [dbcheck](../../AGENTS.md) / **Support**

# 支撑层：注册表 + 列类型常量 + 数据库兼容工具

## 文件

| 文件 | 职责 |
|------|------|
| DbCheckRegistry.php | 检查器注册表：自动发现（扫描根支持 `*` 匹配模式）+ 手动注册 + 过滤 + 分组 + 三类归类 |
| ColumnType.php | 22 个列类型常量（ID / BIG_INTEGER / … / DECIMAL / ENUM），DEFAULT = STRING；ENUM 需配 `values` 键 |
| DatabaseCompat.php | 数据库兼容工具：驱动类型判断（driver/is/isMySQL/isPostgreSQL/isSqlite/isDM/isSqlServer）+ adapterClass() + insertIfMissing（先判重再插入，规避 PG 系 on conflict 无冲突目标不支持） |

## DbCheckRegistry 机制

- **自动发现**：遍历 config('dbcheck.scan') 声明的根（path + namespace）。扫描根支持**匹配模式**：path 含 `*`（至多一个，多于一个抛 LogicException）时 expandRoot() 按 glob 仅目录展开为 0..N 个具体根，namespace 的 `*` 以匹配目录名替换，无匹配自动跳过；随后 scanRoot() 递归扫 *.php → 去根路径前缀 → 分隔符转 `\` → 拼命名空间得类名 → class_exists 通过才收集。
- **手动注册** register()：优先于自动发现、按类名去重、清空发现缓存、不受 $enabled 影响。
- **过滤**（all()）：类不存在 / 抽象类 / 未实现 DbCheckInterface / 静态 `$enabled=false` 均跳过；结果惰性缓存。
- **分组**：groupOf() 读类静态 group()，空串视为常规（null）；groups() 汇总各分组数量；grouped($group,$all) 按继承基类归入 table/data/field 三组——**直接实现接口未继承基类 → LogicException**。
- **测试辅助** clear()：清空手动注册与发现缓存。
- 返回值均为 class-string 数组；执行顺序约定 table → data → field。

## ColumnType

检查器 columns() 的 type 必须用常量，禁裸字符串；缺省回落 STRING。

## DatabaseCompat

- **类型判断取实际驱动名**（`DB::connection()->getDriverName()`），不读 `config('database.connections.*.driver')`——运行期切换默认连接或自定义连接时以实际连接为准。
- `driver()` / `is()` / `isMySQL()` / `isPostgreSQL()` / `isSqlite()` / `isDM()` / `isSqlServer()`：业务与诊断代码用它回答「当前是什么库」；与 Adapters\DbDriver（按连接选适配器）分工不同。
- `adapterClass()`：当前连接将使用的适配器类名，无可用适配器返回 null（诊断命令展示用）。
- `insertIfMissing()`：先判重再插入；**不用 insertOrIgnore**（PG 系会编译成不带冲突目标的 on conflict do nothing，部分库不支持）。

## 依赖

- 依赖 Checks/ 三基类做归类（isSubclassOf）；被 Console/Commands 调用；DatabaseCompat 依赖 Adapters/DbDriver 与 DB facade。
