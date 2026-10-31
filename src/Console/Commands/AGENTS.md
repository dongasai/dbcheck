📍 [dbcheck](../../../AGENTS.md) / Console / **Commands**

# Artisan 命令（三条，仅控制台环境注册）

| 命令 | 类 | 用途 |
|------|-----|------|
| dbcheck:check | CheckInfoCommand | 主命令：自检 + 交互修复。`--no-interaction` 只报告不变更；`--group=<名>` 只执行该分组（即「播种测试数据」）；`--all-groups` 全部分组；`--connection=<名>` 切换默认连接（未显式声明 connectionName() 的检查器随之切换，默认 database.default）；`--adapter=<别名\|类名>` 运行期覆盖驱动选择（默认由 DbDriver 按库类型检查判断）；`--fix-flow=batch\|inline` 修复节奏（默认 batch=先全量报告后逐条修复；inline=边查边修，仅交互模式生效） |
| dbcheck:init | DbcheckInitCommand | 发布包内默认配置到项目 config/dbcheck.php（`--force` 覆盖）；等价 `vendor:publish --tag=dbcheck-config` |
| dbcheck:skill | DbcheckSkillCommand | 把 resources/skill/dbcheck 注册到项目已有 agent skill 目录 |

## dbcheck:check 执行流

Registry::grouped() 三类定序（表结构 → 固定数据 → 字段明细）→ 逐检查器 check() 列异常项：

- **batch（默认）**：全量检查完 → 汇总「发现 N 个异常」+ 明细 → 交互逐条 fixIssue()（预览 SQL → 确认 → fix → 复检）；非交互 previewAll() 后返回 FAILURE。
- **inline（--fix-flow=inline，仅交互）**：每个检查器查完若有异常立即 fixIssue()，再查下一个；结束汇总处理数与失败数。
- SQL 预览经 formatSql()（内联绑定 + prettySql 关键字换行排版）；previewSql() / fixIssue() / previewAll() / runInline() 为流程拆分。

## dbcheck:skill 细节

- **交互多选**（Symfony ChoiceQuestion + multiselect）：可 `0,2` 逗号分隔多选；快捷项「全部目录」「自定义路径」；默认选中 `.agent/skills`（不在候选时默认「全部目录」）。
- 候选探测：项目根 6 层内找名为 skills/skill 的目录，排除 vendor/node_modules/.git/public/storage 与包自身资源目录；内置候选 `.agent/skills` 置顶。
- `--dir=*` 非交互（可多次）；`--list` 只列不注册；`--force` 覆盖。
- 写入 `<目标>/dbcheck/SKILL.md`，已存在默认跳过。

## 注册

DbcheckServiceProvider::boot() 中 runningInConsole() 时注册。
