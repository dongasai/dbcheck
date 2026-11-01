📍 [dbcheck](../../AGENTS.md) / **Checks**

# 三类检查抽象基类（核心层）

模型：一个 **TableCheck** 定义一张表的最终结果——不存在创建，存在比对差异并修复；Data / Field 独立实现 DbCheckInterface。

## 文件

| 基类 | 检查 | fix 方式 | 行数 |
|------|------|---------|------|
| AbstractTableCheck.php | 表不存在 → 报 table 缺失项；存在 → 表编码（声明制）与列/索引和最终结构比对（额外列/索引为可选项） | 缺失按声明建表（列可 `'primary' => true`，charset()/collation() 覆盖连接默认）；编码不一致 `CONVERT TO CHARACTER SET`；ADD/CHANGE COLUMN、ADD/同名重建 INDEX；额外项确认后 DROP | 452 |
| AbstractTableDataCheck.php | 必需数据行是否存在（先 hasTable()，缺表返回空） | where+insert+insertMerge()；数组值入库前 json_encode | 224 |
| AbstractTableFieldCheck.php | 声明字段标量值 / JSON 数组项齐全性（先 hasTable()，缺表返回空） | 回写标量；只补缺失数组项（已存项与运行期状态保留） | 227 |

## 要点

- **TableCheck 是结构声明与工具的唯一承载者**：实现 TableSchemaProvider（table/columns/indexes 全量声明），hasTable、createTable、information_schema 读取（经适配器）、差异计算与修复一体——不存在「存在/行差异拆两个类」的旧模型。
- **表编码是声明制**：可选覆盖 `charset()` / `collation()`（默认 null = 用连接默认且**不比对**）；任一声明后读实际表编码（适配器 actualTableCharset）比对，不一致报 `table.charset`；修复 = `ALTER TABLE ... CONVERT TO`（CONVERT 会连带转换已有数据字符集，且可能把 TEXT 类列升级类型——如 utf8mb3 下 mediumtext→longtext，修回类型交给 column.* 项）。PG 驱动不支持读表编码，自动跳过。
- 表缺失时 check() 只报一项 `table` 缺失（修复=建表），不重复报列/索引差异。
- 异常项 key：`table` / `table.charset` / `column.<名>` / `index.<名>` / `extra_column.<名>` / `extra_index.<名>`；fix() 对未知 key 抛 LogicException（仅声明 collation() 未声明 charset() 时 fix table.charset 抛 LogicException——CONVERT 必须带字符集）。
- DataCheck：insert 仅是**新增时的默认值**，已有行字段不比对不回写（后台可自由调整）；需锁定的字段去 FieldCheck 显式声明。钩子 `rowIssues($key,$def,$row)` 承载声明表达不了的附加校验（覆盖时需自行覆盖 fix()）；**仅在该行存在时调用**（行不存在时由缺行项覆盖、body 传 null 会让 `object` 类型签名报错）。
- **缺表守卫**：Data / Field 的 `check()` 先 `hasTable()`，表不存在直接返回空——缺表由 TableCheck 报一项，避免这里 SELECT 抛 QueryException、也不重复报错。
- FieldCheck：只校验 rows() 显式声明的字段，**无隐式全字段锁定**；行不存在跳过（由 DataCheck 报缺行）；fix() 拒绝未声明的行/列/项。
- 公共能力：静态 `$enabled` 开关；`group()` 分组（null=常规）。
- 建列经 AdapterFactory 落驱动适配器，屏蔽 MySQL/PG 差异。

## 关键方法（行号）

- TableCheck：charset()/collation():58/68 · check():113 · fix():306 · preview():412 · createTable():422
- DataCheck：hasTable():78 · check():88 · fix():125 · preview():158 · applyInsertMerge():172
- FieldCheck：hasTable():74 · check():84 · fix():139 · preview():192

## 依赖

- 上游：Contracts/ 契约、Support/ColumnType 常量；下游：Adapters/AdapterFactory（建列与比对深度）。
