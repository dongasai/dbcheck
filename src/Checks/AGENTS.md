📍 [dbcheck](../../AGENTS.md) / **Checks**

# 三类检查抽象基类（核心层）

模型：一个 **TableCheck** 定义一张表的最终结果——不存在创建，存在比对差异并修复；Data / Field 独立实现 DbCheckInterface。

## 文件

| 基类 | 检查 | fix 方式 | 行数 |
|------|------|---------|------|
| AbstractTableCheck.php | 表不存在 → 报 table 缺失项；存在 → 表编码（声明制）与列/索引和最终结构比对（额外列/索引为可选项） | 缺失按声明建表（列可 `'primary' => true`，charset()/collation() 覆盖连接默认）；编码不一致 `CONVERT TO CHARACTER SET`；ADD/CHANGE COLUMN、ADD/同名重建 INDEX；额外项确认后 DROP | 482 |
| AbstractTableDataCheck.php | 必需数据行是否存在（先 hasTable()，缺表返回空）；可选删除验证 deleteExtra() 报未声明的多余行 | where+insert+insertMerge()；数组值入库前 json_encode；`timestamps => false` 时不写时间列；`data.extra.*` 按身份 JSON 删多余行 | 383 |
| AbstractTableFieldCheck.php | 声明字段标量值 / JSON 数组项齐全性（先 hasTable()，缺表返回空） | 回写标量；只补缺失数组项（已存项与运行期状态保留）；list/map 两种声明都支持 | 336 |

## 要点

- **TableCheck 是结构声明与工具的唯一承载者**：实现 TableSchemaProvider（table/columns/indexes 全量声明），hasTable、createTable、information_schema 读取（经适配器）、差异计算与修复一体——不存在「存在/行差异拆两个类」的旧模型。
- **表编码是声明制**：可选覆盖 `charset()` / `collation()`（默认 null = 用连接默认且**不比对**）；任一声明后读实际表编码（适配器 actualTableCharset）比对，不一致报 `table.charset`；修复 = `ALTER TABLE ... CONVERT TO`（CONVERT 会连带转换已有数据字符集，且可能把 TEXT 类列升级类型——如 utf8mb3 下 mediumtext→longtext，修回类型交给 column.* 项）。PG 驱动不支持读表编码，自动跳过。
- 表缺失时 check() 只报一项 `table` 缺失（修复=建表），不重复报列/索引差异。
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

- TableCheck：charset()/collation():58/68 · check():113 · indexEquivalent():313 · fix():336 · preview():442 · createTable():452
- DataCheck：group():44 · deleteExtra():79 · hasTable():103 · check():113 · fix():162 · preview():204 · applyInsertMerge():218 · extraRowIssues():268 · rowMatchesDeclaration():319 · deleteExtraRow():350
- FieldCheck：hasTable():81 · check():91 · fix():145 · preview():217 · declaredItems():245 · indexStoredItems():273 · isListArray():300 · touchedAt():310

## 依赖

- 上游：Contracts/ 契约、Support/ColumnType 常量；下游：Adapters/AdapterFactory（建列与比对深度）。
