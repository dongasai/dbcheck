📍 [dbcheck](../../../AGENTS.md) / Console / **Commands**

# Artisan 命令（五条，仅控制台环境注册）

| 命令 | 类 | 用途 |
|------|-----|------|
| dbcheck:check | CheckInfoCommand | 主命令：自检 + 交互修复。`--check=fields\|db\|all`（逗号组合；fields 默认）；`--no-interaction` 只报告不变更（可选处理项全部命中时返回 SUCCESS；未执行任何检查/有必修复项时 FAILURE）；`--strict` 非交互模式下可选处理项（额外列/额外索引）也按失败返回；`--group=<名>` 只执行该分组（即「播种测试数据」）；`--all-groups` 全部分组；`--connection=<名>` 切换默认连接（未显式声明 connectionName() 的检查器随之切换，默认 database.default）；`--adapter=<别名\|类名>` 运行期覆盖驱动选择（默认由 DbDriver 按库类型检查判断）；`--fix-flow=batch\|inline` 修复节奏（默认 batch=先全量报告后逐条修复；inline=边查边修，仅交互模式生效）；`--detail` 数据库检查打印连接参数 + 结构检查打印扫描根命中情况；`--db-write` 数据库检查追加临时表读写自检（默认只读） |
| dbcheck:delete | DbcheckDeleteCommand | 删除验证专用入口：清理开启 `deleteExtra()` 的 DataCheck 中未声明的多余数据行（`data.extra.*`）。`--yes` 免确认直接删除；`--no-interaction` 只报告零变更；`--connection=` / `--adapter=` 语义同主命令；缺行（`data.*`）不归本命令 |
| dbcheck:seed | DbcheckSeedCommand | 播种专用入口：执行声明 `group()=SEEDER` 的 DataCheck / FieldCheck，缺行补插（`data.*`）+ 字段回写（`field.*` / `item.*`）。`--group=<名>` 改收任意自定义分组（默认 `DbCheckGroup::SEEDER`）；`--yes` 免确认直接执行；`--no-interaction` 只报告零变更；多余行（`data.extra.*`）与表结构差异不归本命令 |
| dbcheck:init | DbcheckInitCommand | 发布包内默认配置到项目 config/dbcheck.php（`--force` 覆盖）；等价 `vendor:publish --tag=dbcheck-config` |
| dbcheck:skill | DbcheckSkillCommand | 把 resources/skill/dbcheck 注册到项目已有 agent skill 目录 |

## dbcheck:check 执行流

发现计数与可信度保护（先于归类执行）：

- 打印 `发现 N 个检查器（表结构 a / 固定数据 b / 字段明细 c）`，非空时追加 `；分组：name(n), ...`，有未归类时追加 `；未归类 u`（counts() / groups()）。
- **N=0 → warn「未发现任何检查器：请检查 config/dbcheck.php 的 scan 根与类命名空间（0 个检查器时「检查通过」不可信）」并返回 FAILURE**：扫描根/命名空间配错不能被误判为「检查通过」。
- **选择器零命中**：`--group` 拼错（多个分组时逐个校验，任意一个 0 命中即报 `--group 未命中任何检查器: <拼错的组>`）→ 返回 FAILURE；未指定分组但常规集合为空（已发现检查器都在分组里）→ warn「未发现常规检查器」并提示用 `--group=<名>` / `--all-groups`，同样返回 FAILURE（「零执行」不得被 CI 当成通过，与「0 个检查器」同口径）。
- `--detail` 逐条打印扫描根：`扫描根: <path> (<namespace>) → 命中 n 个` / `→ 未匹配（目录不存在）`（DbCheckRegistry::scanRoots()；n 与发现计数同口径，过滤抽象/禁用/非检查器类）。
- **未知驱动降级提示（按检查器）**：对选中的每个表结构检查器调用 `hasAdapter()`（各自 `connectionName()`）判定，无适配器时 warn「<模块> · 当前连接无可用适配器：表结构检查降级为仅存在性比对（可用 --adapter= 指定适配器）」——降级行为在 AbstractTableCheck，命令侧只负责让「没做比对」可见，不按默认连接统一判定（避免自定义 connectionName() 指向未知驱动时既不提示也静默通过）。
- **归类容错**：计算 $groups 的整段（--all-groups / --group / 常规三分支）包在 try/catch (\Throwable) 中，出错时 `error('检查器归类失败: ...')` 并返回 FAILURE，不让 grouped() 的 LogicException 崩栈。

Registry::grouped() 三类定序（表结构 → 固定数据 → 字段明细）→ 逐检查器 check() 列异常项：

- **batch（默认）**：全量检查完 → 汇总「发现 N 个异常（其中可选处理项 Y 个）」+ 明细 → 交互逐条 fixIssue()（预览 SQL → 确认 → fix → 复检）。
- **非交互 batch（--no-interaction）**：previewAll() 后按项判定退出码——全部为可选处理项（issue.optional）且无检查器执行失败、无数据库检查失败时，info「可选处理项 N 个，默认不处理（加 --strict 时按失败返回）」并返回 SUCCESS；含必修复项 / 有失败 / `--strict` 时返回 FAILURE。避免「库中只要有多余列/索引就永远非 0」，同时保留 --strict 严格口径。
- **inline（--fix-flow=inline，仅交互）**：每个检查器查完若有异常立即 fixIssue()，再查下一个；结束汇总处理数与失败数。
- **容错**：单个检查器 `check()` 抛错只记「检查失败」并继续（不中断整体自检），末尾汇总带失败数；`preview()` 抛错只 warn 并继续确认；非交互模式下可选处理项（额外列/索引）提示「默认不处理」；`--check` 只接受 `fields` / `db` / `all`（未知值按失败返回，避免被当成"检查通过"）。
- SQL 预览经 formatSql()（内联绑定 + prettySql 关键字换行排版）；previewSql() / fixIssue() / previewAll() / runInline() / describeCounts() / printScanRoots() 为流程拆分。

## 数据库检查（--check=db）

先于结构检查执行（连接不通时后面的检查无意义），默认**只读**：

- 展示：连接名、驱动类型（DatabaseCompat::driver）、库名、适配器类名（DbDriver::adapterClass）、服务端版本、表数量；`--detail` 追加 host/port/username/charset/collation。
- 连通性：`SELECT 1`；适配器缺失（未知驱动）记失败并提示 `--adapter=`。
- `--db-write`：追加临时表读写自检（建表 → 插/查/改/删 → 必定 drop，表名 `dbcheck_probe_<随机>`）。
- 退出码与结构检查合并：任一失败即 FAILURE。

## dbcheck:delete 细节

- **按「能力」收集**：`DbCheckRegistry::grouped(null, true)['data']` 过滤 `deleteExtra() === true` 的 DataCheck——不受分组过滤影响，无需 `--group=delete`。
- 只处理 `data.extra.*` 异常项（多余行）；缺行（`data.*`）是插入职责，归 dbcheck:check / seeder 分组。
- 交互模式逐条复用父类 fixIssue()（预览 DELETE SQL → 确认 → fix → 复检）；`--yes` 走 deleteIssue()：免确认 fix + 复检，fix 后该 key 仍报异常视为失败。
- `--no-interaction`：只预览不落库（删除属破坏性动作，绝不静默执行），返回 FAILURE。
- 退出码：无多余行 / 全部删除成功 = SUCCESS；非交互发现多余行 / 有删除失败 = FAILURE；单个检查器 check() 抛错记「检查失败」继续。

## dbcheck:seed 细节

- **按分组收集**：`DbCheckRegistry::grouped(<组名>, false)` 只取 data + field 两类（表结构差异归 dbcheck:check）；默认组名 `DbCheckGroup::SEEDER`，`--group=` 可改收任意自定义分组。
- 只执行补插/回写项：过滤掉 `data.extra.*`（删除职责归 dbcheck:delete）；同一检查器可同时声明 SEEDER 分组与 deleteExtra，两命令正交。
- 交互模式逐条复用父类 fixIssue()（预览 SQL → 确认 → fix → 复检）；`--yes` 走 seedIssue()：免确认 fix + 复检，fix 后该 key 仍报异常视为失败。
- `--no-interaction`：复用父类 previewAll() 只报告零变更，返回 FAILURE（与其他 dbcheck 命令同口径，绝不静默写库）。
- 退出码：无播种项 / 全部执行成功 = SUCCESS；非交互发现播种项 / 有执行失败 = FAILURE；单个检查器 check() 抛错记「检查失败」继续。

## dbcheck:skill 细节

- **交互多选**（Symfony ChoiceQuestion + multiselect）：可 `0,2` 逗号分隔多选；快捷项「全部目录」「自定义路径」；默认选中 `.agents/skills`（不在候选时默认「全部目录」）。
- 候选探测：项目根 6 层内找名为 skills/skill 的目录，排除 vendor/node_modules/.git/public/storage 与包自身资源目录；内置候选 `.agents/skills`（生态通用惯例，Codex/Amp 在用）置顶。
- `--dir=*` 非交互（可多次）；`--list` 只列不注册；`--force` 覆盖。
- 写入 `<目标>/dbcheck/SKILL.md`，已存在默认跳过。

## 注册

DbcheckServiceProvider::boot() 中 runningInConsole() 时注册。

## 测试

`tests/Integration/CheckInfoCommandTest.php` 用 Symfony Console 直接驱动 `dbcheck:check`（容器复用 TestCase 最小容器），覆盖：0 检查器 FAILURE、发现计数/分组行、`--group` 零命中 FAILURE、多个 `--group` 部分拼错 FAILURE、有效 `--group`/`--all-groups` 正向路径、默认集合全为空 warn + FAILURE、可选处理项 SUCCESS 与 `--strict` FAILURE、必修复项 FAILURE + 建表预览、索引不一致预览含 drop+create、未归类类归类失败不崩栈、`--detail` 扫描根输出（含未声明与命中两种分支）。交互确认分支（fixIssue 问答）仍靠人工冒烟。
