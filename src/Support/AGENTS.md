📍 [dbcheck](../../AGENTS.md) / **Support**

# 支撑层：注册表 + 列类型常量 + 数据库兼容工具

## 文件

| 文件 | 职责 |
|------|------|
| DbCheckRegistry.php | 检查器注册表：自动发现（扫描根支持 `*` 匹配模式）+ 手动注册 + 过滤 + 分组 + 三类归类 + 发现计数 counts() + 扫描根诊断 scanRoots() |
| ColumnType.php | 22 个列类型常量（ID / BIG_INTEGER / … / DECIMAL / ENUM），DEFAULT = STRING；ENUM 需配 `values` 键 |
| DatabaseCompat.php | 数据库兼容工具：驱动类型判断（driver/is/isMySQL/isPostgreSQL/isSqlite/isDM/isSqlServer）+ adapterClass() + rowExists（判重读，预览上下文内命中缓存）+ insertIfMissing（先判重再插入，规避 PG 系 on conflict 无冲突目标不支持）+ stopPretending（预览异常后复位 pretending） |
| DbCheckGroup.php | 框架标准分组常量：`DELETE = 'delete'`（启用 deleteExtra() 的检查器默认归入，危险动作不随默认扫执行）；业务分组名仍由检查器 group() 自行定义 |
| PreviewContext.php | 预览上下文缓存：`begin()`/`end()`/`active()` + `remember($key, $resolver)` + `writeStatements($statements)` + `reset()`（测试用）；begin/end 栈式支持嵌套预览；解决 pretend 下 SELECT 恒返回空导致的预览失真 |

## DbCheckRegistry 机制

- **自动发现**：遍历 config('dbcheck.scan') 声明的根（path + namespace）。扫描根支持**匹配模式**：path 含 `*`（至多一个，多于一个抛 LogicException）时 expandRoot() 按 glob 仅目录展开为 0..N 个具体根，namespace 的 `*` 以匹配目录名替换，无匹配自动跳过；随后 scanRoot() 递归扫 *.php → 去根路径前缀 → 分隔符转 `\` → 拼命名空间得类名 → class_exists 通过才收集。
- **手动注册** register()：优先于自动发现、按类名去重、清空发现缓存、不受 $enabled 影响。
- **过滤**（all()）：类不存在 / 抽象类 / 未实现 DbCheckInterface / 静态 `$enabled=false` 均跳过；结果惰性缓存。
- **分组**：groupOf() 读类静态 group()，空串视为常规（null）；groups() 汇总各分组数量；grouped($group,$all) 按继承基类归入 table/data/field 三组——**直接实现接口未继承基类 → LogicException**。
- **发现计数** counts()：复用 all()，按继承基类统计 `['table'=>int,'data'=>int,'field'=>int,'unknown'=>int,'total'=>int]`；直接实现接口未继承基类的类计入 unknown（**不抛异常**——计数只用于展示，不能让诊断本身崩栈；与 grouped() 的 LogicException 口径不同）。命令侧据此打印「发现 N 个检查器」并在 total=0 时告警失败。
- **扫描根诊断** scanRoots()：遍历 config('dbcheck.scan')，复用 expandRoot()/scanRoot()，返回 `[['path'=>string,'namespace'=>string,'matched'=>bool,'count'=>int], ...]`；通配模板根展开为多条，无匹配目录（或 path/namespace 未声明）的根报告一条 matched=false、count=0。**count 与 all() 同口径过滤**（抽象类/未实现接口/`$enabled=false`/非检查器类不计入），避免「命中 n 个」大于「发现 N 个检查器」误导诊断。命令 `--detail` 用它打印「扫描根: <path> (<namespace>) → 命中 n 个 / → 未匹配（目录不存在）」，定位「声明了但没扫到」。
- **测试辅助** clear()：清空手动注册与发现缓存。
- 返回值均为 class-string 数组；执行顺序约定 table → data → field。

## ColumnType

检查器 columns() 的 type 必须显式声明且用常量，禁裸字符串；**缺 type 键由适配器 `declaredType()` 抛 LogicException（不再静默回落 STRING）**，未知类型同样抛 LogicException。DEFAULT 常量仍保留（显式写 `ColumnType::DEFAULT` 等价 STRING）。

## PreviewContext（预览缓存）

- 背景：Laravel `pretend()` 期间连接上所有 SELECT 直接返回空数组；fix() 内为决定「加列还是改列」「索引是否先 drop」「行是否存在」的读会全部读到空，导致预览 SQL 与真实修复不一致（改列被预览成加列、索引漏 drop、item.* 误抛「行不存在」、已存在行误出 INSERT、漏写 updated_at）。
- 机制：各基类 `preview()` 先 `begin()`，在 pretend **之前**把 fix() 依赖的读经 `remember($key, $resolver)` 预热（同一 key 只真正执行一次），pretend 内命中缓存；`finally` 里 `end()` 清缓存（`begin`/`end` 栈式，嵌套预览恢复外层缓存），不跨预览残留。
- `writeStatements()`：从 pretend 记录中过滤掉 SELECT/SHOW/DESCR/PRAGMA/EXPLAIN/USE，`preview()` 只对外返回写语句。
- 非预览上下文（`active()=false`）时 `remember()` 直接执行、不缓存——检查/修复主链路行为与普通调用一致。
- **pretend 异常兜底**：Laravel 8 的 `Connection::pretend()` 无 try/finally，预览在 pretend 内抛错会把连接永久留在 pretending（之后 SELECT 恒空、写全静默跳过）；各 `preview()` 的 finally 调 `DatabaseCompat::stopPretending()` 复位。

## DatabaseCompat

- **类型判断取实际驱动名**（`DB::connection()->getDriverName()`），不读 `config('database.connections.*.driver')`——运行期切换默认连接或自定义连接时以实际连接为准。
- `driver()` / `is()` / `isMySQL()` / `isPostgreSQL()` / `isSqlite()` / `isDM()` / `isSqlServer()`：业务与诊断代码用它回答「当前是什么库」；与 Adapters\DbDriver（按连接选适配器）分工不同。
- `adapterClass()`：当前连接将使用的适配器类名，无可用适配器返回 null（诊断命令展示用）。
- `rowExists()`：判重读；预览上下文内经 PreviewContext 缓存（preview() 预热），非预览上下文直接查库。
- `insertIfMissing()`：先经 rowExists() 判重再插入；**不用 insertOrIgnore**（PG 系会编译成不带冲突目标的 on conflict do nothing，部分库不支持）。
- `stopPretending()`：仅当连接仍处于 pretending 时用反射复位（Laravel 8 `pretend()` 异常路径不复位）；各 preview() 的 finally 调用，正常路径是 no-op。

## 依赖

- 依赖 Checks/ 三基类做归类（isSubclassOf）；被 Console/Commands 调用；DatabaseCompat 依赖 Adapters/DbDriver 与 DB facade。
