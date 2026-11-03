📍 [dbcheck](../../AGENTS.md) / **Checks**

# 三类检查抽象基类（核心层）

模型：一个 **TableCheck** 定义一张表的最终结果——不存在创建，存在比对差异并修复；Data / Field 独立实现 DbCheckInterface。

## 文件

| 基类 | 检查 | fix 方式 | 行数 |
|------|------|---------|------|
| AbstractTableCheck.php | 表不存在 → 报 table 缺失项；存在 → 表编码（声明制）与列/索引和最终结构比对（额外列/索引为可选项） | 缺失按声明建表（列可 `'primary' => true`，charset()/collation() 覆盖连接默认）；编码不一致 `CONVERT TO CHARACTER SET`；ADD/CHANGE COLUMN、ADD/同名重建 INDEX；额外项确认后 DROP | 608 |
| AbstractTableDataCheck.php | 必需数据行是否存在（先 hasTable()，缺表返回空）；可选删除验证 deleteExtra() 报未声明的多余行 | 已存在则短路；where+insert+insertMerge()；数组值入库前 json_encode；`timestamps => false` 时不写时间列；`data.extra.*` 按身份 JSON 删多余行 | 419 |
| AbstractTableFieldCheck.php | 声明字段标量值 / JSON 数组项齐全性（先 hasTable()，缺表返回空） | 回写标量；只补缺失数组项（已存项与运行期状态保留）；list/map 两种声明都支持 | 374 |

## 要点

- **TableCheck 是结构声明与工具的唯一承载者**：实现 TableSchemaProvider（table/columns/indexes 全量声明），hasTable、createTable、information_schema 读取（经适配器）、差异计算与修复一体——不存在「存在/行差异拆两个类」的旧模型。
- **表编码是声明制**：可选覆盖 `charset()` / `collation()`（默认 null = 用连接默认且**不比对**）；任一声明后读实际表编码（适配器 actualTableCharset）比对，不一致报 `table.charset`；修复 = `ALTER TABLE ... CONVERT TO`（CONVERT 会连带转换已有数据字符集，且可能把 TEXT 类列升级类型——如 utf8mb3 下 mediumtext→longtext，修回类型交给 column.* 项）。PG 驱动不支持读表编码，自动跳过。
- 表缺失时 check() 只报一项 `table` 缺失（修复=建表），不重复报列/索引差异。
- **列属性差异聚合**：同一列的「类型 / 可空 / 默认值」差异聚合成**一个** `column.<名>` 异常项（message 列出全部差异，repair=整列重写）——一次报全、一次修好，不再逐属性 continue 导致要跑多轮才全绿。
- **未知驱动降级**：`AdapterFactory::make()` 抛 InvalidArgumentException（DM / SQLServer 等无适配器）时，check() 不整体失败、返回空（表存在即通过；缺表仍报 `table` 项），精确比对跳过；命令层对每个表结构检查器调用 `hasAdapter()` 逐个 warn「表结构检查已降级」（按各自 connectionName() 判定，不按默认连接统一判定）。
- **列声明统一校验**：check() 在进入比对前对全部声明列调用适配器 `expectedColumnType()`——缺 `type` / 未知 type 当场抛 LogicException，与驱动比对深度无关（否则 PG/SQLite 只做存在性比对时，错误声明会表现为「列不存在」且 fix 必抛，永远修不好）。
- **索引声明校验**：check()/fix()/createTable() 统一经 `declaredIndexes()` 归一化——`indexes()` 必须是「索引名 => [columns, unique]」映射；整数下标（旧 list + name 形态）、内层只有 name 没 columns、或 `columns` 不是非空字符串数组（如裸列数组 `['uk' => ['col']]`）时抛 LogicException，防止建出名为 "0" 的索引或 `create index ... ()` 的非法 SQL（该表会永远报缺失、永远修不好）。
- **预览必须与真实修复同口径**：`preview($key)` 先 `PreviewContext::begin()`，在 pretend **之前**经 `readActualColumns()` / `readActualIndexes()` / `rowExists()` / `findRow()` / `touchedAt()` 预热 fix() 依赖的读，pretend 内命中缓存；最后 `PreviewContext::writeStatements()` 过滤掉判重/元数据 SELECT。不预热会把「改列」预览成「加列」、索引漏 drop、`item.*` 预览误抛「行不存在」、已存在行预览误出 INSERT、预览漏写 updated_at。pretend 内抛错时 `finally` 还会经 `DatabaseCompat::stopPretending()` 复位 pretending（Laravel 8 的 `pretend()` 无 try/finally，不复位会让后续写全部静默跳过）。
- 异常项 key：`table` / `table.charset` / `column.<名>` / `index.<名>` / `extra_column.<名>` / `extra_index.<名>` / `data.extra.<身份JSON>`；fix() 对未知 key 抛 LogicException（仅声明 collation() 未声明 charset() 时 fix table.charset 抛 LogicException——CONVERT 必须带字符集；未启用 deleteExtra() 时 fix data.extra.* 抛 LogicException）。
- DataCheck：insert 仅是**新增时的默认值**，已有行字段不比对不回写（后台可自由调整）；需锁定的字段去 FieldCheck 显式声明。钩子 `rowIssues($key,$def,$row)` 承载声明表达不了的附加校验（覆盖时需自行覆盖 fix()）；**仅在该行存在时调用**（行不存在时由缺行项覆盖、body 传 null 会让 `object` 类型签名报错）。
- **缺表守卫**：Data / Field 的 `check()` 先 `hasTable()`，表不存在直接返回空——缺表由 TableCheck 报一项，避免这里 SELECT 抛 QueryException、也不重复报错。
- **索引等价判定**：按「唯一性 + 列」判断，MySQL 系（`comparesIndexAttributes()=true`）比较列顺序，存在性模式（PG 等）忽略列顺序（`indexEquivalent()`）——否则同列不同序会同时误报「缺索引 + 额外索引」。
- FieldCheck：只校验 rows() 显式声明的字段，**无隐式全字段锁定**；行不存在跳过（由 DataCheck 报缺行）；fix() 拒绝未声明的行/列/项。数组列声明**同时支持** `list+每项带 key` 与 `map`（itemKey => 定义）两种写法，落库形态也兼容 list/map；list 声明 + list 落库时补项**追加**（保留已存项与 `checked` 等运行期状态），其余按项 key 写回；回写标量/补项时若表有 `updated_at` 会一并刷新。
- DataCheck：`problem` 文案会拼进缺行提示（缺省回落「缺数据行」）；`timestamps => false` 时不自动写 created_at / updated_at。
- **删除验证（与缺行检查相反）**：DataCheck 覆盖 `deleteExtra(): true` 后，库中未被 rows() 声明的行报 `data.extra.<身份JSON>`（身份列 = 各声明 where 列的并集；无声明退化为整行值），修复按身份等值删除（null 走 whereNull）；**默认关闭**——删除属破坏性动作，仅限数据全量受声明控制的字典/固定表。启用后检查器默认归入 `DbCheckGroup::DELETE` 分组（不随默认扫执行，需 `--group=delete`），子类覆盖 group() 后以子类为准。
- 公共能力：静态 `$enabled` 开关；`group()` 分组（null=常规）。
- 建列经 AdapterFactory 落驱动适配器，屏蔽 MySQL/PG 差异。

## 关键方法（行号）

- TableCheck：charset()/collation():61/71 · check():136 · hasAdapter():118 · indexEquivalent():346 · fix():369 · preview():477 · readActualColumns():510 · readActualIndexes():522 · declaredIndexes():537 · createTable():578
- DataCheck：group():46 · deleteExtra():81 · hasTable():105 · check():115 · fix():164 · preview():213 · applyInsertMerge():247 · rowExists():272 · findRow():283 · extraRowIssues():304 · rowMatchesDeclaration():355 · deleteExtraRow():386
- FieldCheck：hasTable():83 · check():93 · fix():148 · preview():228 · findRow():255 · declaredItems():277 · indexStoredItems():305 · isListArray():332 · touchedAt():342

## 依赖

- 上游：Contracts/ 契约、Support/ColumnType 常量、Support/PreviewContext（预览缓存）；下游：Adapters/AdapterFactory（建列与比对深度）。
