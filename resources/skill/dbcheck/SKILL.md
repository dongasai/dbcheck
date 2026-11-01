---
name: dbcheck
description: dbcheck 数据库自检框架——按表编写三类检查类（表结构 / 固定数据 / 字段明细），并驱动修复。
---

# dbcheck 检查类编写指南

## 核心口径

1. **以表为单位**：一张表一个检查器，类名由表名推导。
2. **以最终结构为准**：检查器声明该表**当前应有的完整结构**（全部列 + 全部索引），不描述「这次加了哪几列」的迁移过程。
3. **以实际库结构为第一依据，迁移文件只作参考**：本项目多处迁移与实际库不一致（有符号/无符号、varchar 长度、索引名与数量、库里多出迁移没写的列）。
   落声明后必须用 `dbcheck:check --no-interaction` 复验到全绿。
4. **按需建类**，不建空检查类。

## 三类检查

| 类型 | 基类 | 检查 | 修复 |
|------|------|------|------|
| 表结构 | `AbstractTableCheck` | 表不存在 → 报缺失；存在 → 表编码（声明了才比）与列/索引和最终结构比对 | 缺失按声明建表；编码不一致 `CONVERT TO`；补列、改列、补/重建索引 |
| 固定数据 | `AbstractTableDataCheck` | 必需数据行是否存在；可选删除验证（`deleteExtra()`）报未声明的多余行 | 缺行插行；启用删除验证时多余行删除（key `data.extra.*`，默认关） |
| 字段明细 | `AbstractTableFieldCheck` | 声明字段的值 / JSON 数组项是否齐全 | 回写标量、补数组项 |

`TableCheck` 同时是 `TableSchemaProvider`，是该表最终结构的**唯一声明处**：不存在创建，存在比对差异并修复——一张表一个检查器。

## 命名规则

表名整串转 StudlyCase 后加类型后缀（`TableCheck` / `DataCheck` / `FieldCheck`）：**模块前缀属于表名本体，保留**，如 `admin_settings` → `AdminSettingsTableCheck`、`user1_users` → `User1UsersTableCheck`。

只有**站点级库前缀**（整站统一的历史前缀，如 `hy26_`）不参与类名：`hy26_meetings` → `MeetingsTableCheck` 而非 `Hy26MeetingsTableCheck`。

## 放置位置

- 模块表：`Modules/<模块>/DbChecks/`，命名空间 `Modules\<模块>\DbChecks`
- 项目级（非模块）表：`Modules/ABase/DbChecks/`，命名空间 `Modules\ABase\DbChecks`（挂 ABase 基础模块；旧口径 app/DbChecks 与「代码只在 Modules/」铁律及编辑钩子冲突，已废弃）

通过 `config/dbcheck.php` 的 `scan` 声明扫描根（可用 `dbcheck:init` 生成）。
**框架无任何隐式默认扫描目录，所有要扫描的根都必须显式定义**；扫描根支持**匹配模式**——
path/namespace 各至多一个 `*`，按 glob（仅目录）展开为具体根，namespace 的 `*` 以匹配到的目录名替换，
无匹配自动跳过；path 多于一个 `*` 抛 LogicException。包默认模板只预置常规根
app/DbChecks（目录不存在时自动跳过），本项目用一条惯例匹配覆盖全部模块：

```php
// 包默认模板（约定根，目录不存在自动跳过）
'scan' => [
    ['path' => base_path('app/DbChecks'), 'namespace' => 'App\\DbChecks\\'],
],

// 本项目：惯例目录一条匹配模式（ABase 框架表 / DcatAdmin admin_* / 全部业务模块自动覆盖）；
// 新模块按约定建 DbChecks/ 目录即自动纳入，无需改配置；惯例外的目录用无通配普通根逐条声明
'scan' => [
    ['path' => base_path('Modules/*/DbChecks'), 'namespace' => 'Modules\\*\\DbChecks\\'],
],
```

## 列定义格式

**type 一律使用 `Dongasai\Dbcheck\Support\ColumnType` 常量，禁止裸字符串。**

```php
use Dongasai\Dbcheck\Support\ColumnType;

public static function columns(): array
{
    return [
        'id'            => ['type' => ColumnType::ID],
        'name'          => ['type' => ColumnType::STRING, 'length' => 50, 'default' => '', 'comment' => '名称'],
        'status'        => ['type' => ColumnType::UNSIGNED_TINY_INTEGER, 'default' => 1],
        'register_date' => ['type' => ColumnType::DATE, 'nullable' => true],
        'created_at'    => ['type' => ColumnType::TIMESTAMP, 'nullable' => true],
    ];
}
```

- `nullable` 缺省 `false`；有 `default` 键才设置默认值（显式 `null` 视为默认 NULL）。
- `'primary' => true` 声明该列为主键（仅建表时生效；主键不参与差异比较，`ColumnType::ID` 自带主键）。
- `comment` 仅用于建表/补列，不参与差异比较。

类型映射（MySQL 口径）：

| 常量 | 建列方法 | 期望类型 |
|------|----------|----------|
| `ID` | `bigIncrements` | `bigint unsigned` |
| `BIG_INTEGER` | `bigInteger` | `bigint` |
| `UNSIGNED_BIG_INTEGER` | `unsignedBigInteger` | `bigint unsigned` |
| `INTEGER` | `integer` | `int` |
| `UNSIGNED_INTEGER` | `unsignedInteger` | `int unsigned` |
| `SMALL_INTEGER` | `smallInteger` | `smallint` |
| `UNSIGNED_SMALL_INTEGER` | `unsignedSmallInteger` | `smallint unsigned` |
| `TINY_INTEGER` | `tinyInteger` | `tinyint` |
| `UNSIGNED_TINY_INTEGER` | `unsignedTinyInteger` | `tinyint unsigned` |
| `BOOLEAN` | `boolean` | `tinyint` |
| `STRING` | `string($len)` | `varchar($len)` |
| `CHAR` | `char($len)` | `char($len)` |
| `TEXT` / `MEDIUM_TEXT` / `LONG_TEXT` / `JSON` | 同名方法 | 同名类型 |
| `DATE` / `TIME` / `TIMESTAMP` | 同名方法 | 同名类型 |
| `DATETIME` | `dateTime` | `datetime` |
| `DECIMAL` | `decimal($precision,$scale)` | `decimal(10,7)` |
| `ENUM` | `enum($values)` | `enum('a','b')`（取值列表放 `values` 键） |

时间与小数类的默认值按**字符串严格比对**：`TIME` 默认写 `'09:00:00'`、`DECIMAL` 默认写 `'0.0000000'`，不能简写成 `0` 或 `'9:00'`。

`default` 写字符串 `'CURRENT_TIMESTAMP'` 时建列编译为 `useCurrent()`，比对仍按 `COLUMN_DEFAULT = 'CURRENT_TIMESTAMP'` 严格相等。

## 索引定义格式

```php
public static function indexes(): array
{
    return [
        'workflow_instances_status_index' => ['unique' => false, 'columns' => ['status']],
        'idx_code'                        => ['unique' => true,  'columns' => ['code', 'id']],
    ];
}
```

## 固定数据 / 字段明细声明

`DataCheck::rows()`（固定数据）每项：

```php
'document_center' => [
    'where'        => ['key' => '/document-center'],   // 判定条件，补插时同时作为字段
    'insert'       => ['title' => '文件中心', 'btn_list' => [/* 数组会编码成 JSON */]],
    'item'         => 'admin_menu（key=/document-center）',
    'problem'      => '菜单缺失，前端无入口',
    'fix'          => '插入菜单 文件中心',
    'insert_merge' => [                                // 运行期才能算出的列
        'parent_id' => ['where' => ['key' => 'parent'], 'value' => 'id'],
    ],
    'timestamps'   => false,                           // 可选，默认 true（不写 created_at/updated_at）
],
```

`FieldCheck::rows()`（字段明细）只锁定显式声明的字段（其余后台可自由调整）；数组列支持两种等价写法：

```php
'btn_list' => [                                  // list + 每项带 key
    ['key' => 'document-center-shou-edit', 'name' => '编辑', 'checked' => false],
],
'btn_list' => [                                  // map：项 key => 项定义
    'document-center-shou-edit' => ['key' => 'document-center-shou-edit', 'name' => '编辑'],
],
```

- 标量不一致报 `field.<标识>.<列>`，修复回写声明值；
- 数组缺项报 `item.<标识>.<列>.<项key>`；list 声明 + list 落库时补项**追加**（已存项与 `checked` 等运行期状态保留）；
- 已存 JSON 是 list（元素带 `key`）或 map 都能识别；行不存在时跳过（由 DataCheck 报缺行）。

## 表编码声明（可选）

默认不声明 = 建表用连接默认字符集，且**不比对**表编码。声明了（任一非 null）就比对：

```php
/**
 * 建表字符集（仅建表生效；未声明用连接默认）。
 */
protected static function charset(): ?string
{
    return 'utf8mb4';
}

/**
 * 建表排序规则（仅建表生效；未声明用连接默认）。
 */
protected static function collation(): ?string
{
    return 'utf8mb4_unicode_ci';
}
```

- 建表时覆盖连接默认（编译进 `default character set ... collate ...`）。
- 比对读实际表编码（MySQL 读 information_schema），不一致报 `table.charset` 项，
  修复 = `ALTER TABLE ... CONVERT TO CHARACTER SET ...`（会连带转换已有数据字符集；
  CONVERT 可能同时把 TEXT 类列升级类型，如 utf8mb3 下 mediumtext→longtext，修回类型交给 column.* 项）。
- 只声明 `collation()` 不声明 `charset()` 时：能比对但不能修复（CONVERT 必须带字符集）。
- PG 驱动不支持读表编码，编码比对自动跳过。

## 驱动适配

数据库类型判断由 `Dongasai\Dbcheck\Adapters\DbDriver` 负责：默认**按连接检查判断**自动选择，
适配器映射不做配置化硬编码；例外用运行参数覆盖。选择优先级：
**显式参数**（`AdapterFactory::make($connection, $override)`）→ **运行期覆盖**（`DbDriver::override('mysql57')` 或命令行 `--adapter=`）→ **自动探测**（mysql/mariadb → Mysql8Adapter，pgsql/postgres → PgsqlAdapter，sqlite → SqliteAdapter）。

| 适配器 | 适用 | 特点 |
|--------|------|------|
| `Mysql8Adapter` | MySQL 8+（自动探测默认） | json 原生类型 |
| `MysqlAdapter` | 5.7 等旧版 | json 落 longText |
| `PgsqlAdapter` | PG / PG 系 | unsigned 回落、boolean 原生、忽略 comment、仅存在性比对 |
| `SqliteAdapter` | SQLite（本地/测试） | 列仅存在性比对（动态类型）、索引仍精确比对、无表编码 |

驱动别名：`mysql8` / `mysql` / `mariadb` / `mysql57` / `pgsql` / `postgres` / `sqlite`（也可用适配器类名）。

```bash
php artisan dbcheck:check --adapter=mysql57   # 5.7 等无原生 JSON 的旧版本，运行期覆盖驱动
```

## 分组

检查器覆盖 `group(): ?string` 自行声明分组名（**框架不预置任何分组名**）：

- 返回 `null` → 常规检查，`dbcheck:check` 默认执行；
- 返回任意字符串（如 `'testdata'`）→ 分组检查，默认**不执行**，需 `--group=<名称>` 显式触发。

所谓 **seeder 就是执行某分组的检查（如 data + field）**：

```bash
php artisan dbcheck:check --group=testdata
```

框架内置一个标准分组常量 `Dongasai\Dbcheck\Support\DbCheckGroup::DELETE`（`'delete'`）：
DataCheck 覆盖 `deleteExtra(): true`（删除验证——删除未声明的多余数据行，与缺行检查相反）时，
默认自动归入该分组——**删除属危险动作，不随 `dbcheck:check` 默认执行**，需 `--group=delete` 显式触发；
子类覆盖 `group()` 后以子类为准。**只允许在数据全量受声明控制的字典/固定表上开启**，
业务表开启会把正常业务数据当多余行删掉。

`dbcheck:delete` 是删除验证检查器的专用触发入口：按 `deleteExtra()` 能力收集（不受分组过滤影响），
逐条预览 DELETE SQL 确认后清理多余行，无需 `--group=delete`。

## 命令

```bash
php artisan dbcheck:init                        # 把包内配置发布到项目 config/dbcheck.php
php artisan dbcheck:check                       # 交互：先看 SQL 再确认修复
php artisan dbcheck:check --no-interaction      # 只报告，零变更
php artisan dbcheck:check --group=testdata      # 只跑指定分组
php artisan dbcheck:check --all-groups          # 跑全部分组
php artisan dbcheck:check --connection=mysql    # 指定数据库连接名（默认用 database.default；显式声明连接的检查器不受影响）
php artisan dbcheck:check --fix-flow=inline     # 边查边修（每个检查器查完即逐条确认修复；默认 batch=先全量报告）
php artisan dbcheck:check --check=db            # 数据库自检：连接/驱动类型/适配器/版本/表数量（默认只读）
php artisan dbcheck:check --check=all --detail --db-write  # 库自检 + 结构数据检查；含连接参数与临时表读写测试
php artisan dbcheck:delete                      # 删除验证：清理开启 deleteExtra 的检查器中未声明的多余数据行（逐条确认）
php artisan dbcheck:delete --yes                # 免确认直接删除全部报告的多余行
php artisan dbcheck:delete --no-interaction     # 只报告多余行，零变更（退出码非 0）
```

## 边界

- 不做破坏性修复：行差异只 `ADD COLUMN` / `CHANGE COLUMN` / `ADD INDEX` / 同名索引重建；唯一例外是数据删除——仅当 DataCheck 显式开启 `deleteExtra()` 时才删未声明的多余行，且该检查器默认隔离在 `delete` 分组。
- 额外列 / 额外索引交互询问且**默认不处理**，非交互只报告、不计错误。
- 不比较注释、`AUTO_INCREMENT`、主键；表级字符集/排序规则按**声明制**：`charset()`/`collation()` 声明了才比对（未声明不比对）。
- 索引按「类型（唯一/普通）+ 字段」判存在，**索引名不作为存在依据**（避免重复建）。

## 验证清单

写完检查类后必须执行：

```bash
php artisan dbcheck:check --no-interaction
```

确认无异常输出；有异常则修正声明直至全绿。
