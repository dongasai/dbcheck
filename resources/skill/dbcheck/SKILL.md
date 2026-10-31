---
name: dbcheck
description: dbcheck 数据库自检框架——按表编写四类检查类（存在 / 行差异 / 固定数据 / 字段明细），并驱动修复。
---

# dbcheck 检查类编写指南

## 核心口径

1. **以表为单位**：一张表一个检查器，类名由表名推导。
2. **以最终结构为准**：检查器声明该表**当前应有的完整结构**（全部列 + 全部索引），不描述「这次加了哪几列」的迁移过程。
3. **以实际库结构为第一依据，迁移文件只作参考**：本项目多处迁移与实际库不一致（有符号/无符号、varchar 长度、索引名与数量、库里多出迁移没写的列）。
   落声明后必须用 `dbcheck:check --no-interaction` 复验到全绿。
4. **按需建类**，不建空检查类。

## 四类检查

| 类型 | 基类 | 检查 | 修复 |
|------|------|------|------|
| 存在 | `AbstractTableExistsCheck` | 表是否存在 | 按最终结构建表 |
| 行差异 | `AbstractTableRowCheck` | 列 / 索引与最终结构是否一致 | 补列、改列、补/重建索引 |
| 固定数据 | `AbstractTableDataCheck` | 必需数据行是否存在 | 插行 |
| 字段明细 | `AbstractTableFieldCheck` | 声明字段的值 / JSON 数组项是否齐全 | 回写标量、补数组项 |

`RowCheck` 同时是 `TableSchemaProvider`，是该表最终结构的**唯一声明处**；`ExistsCheck` 通过 `schema()` 指向它，避免两处维护同一张表的定义。

## 命名规则

表名转 StudlyCase 后加类型后缀（`ExistsCheck` / `RowCheck` / `DataCheck` / `FieldCheck`）。

库前缀**不参与类名**（先去掉前缀再 StudlyCase），例如 `hy26_meetings` → `MeetingsRowCheck` 而非 `Hy26MeetingsRowCheck`。

## 放置位置

- 模块表：`Modules/<模块>/DbChecks/`，命名空间 `Modules\<模块>\DbChecks`
- 项目级（非模块）表：`app/DbChecks/`，命名空间 `App\DbChecks`

通过 `config/dbcheck.php` 的 `scan` 声明扫描根（可用 `dbcheck:init` 生成）：

```php
'scan' => [
    ['path' => base_path('app/DbChecks'), 'namespace' => 'App\\DbChecks\\'],
    ['path' => base_path('Modules'), 'namespace' => 'Modules\\'],
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

## 驱动适配

| 适配器 | 适用 | 特点 |
|--------|------|------|
| `Mysql8Adapter` | MySQL 8+（默认） | json 原生类型 |
| `MysqlAdapter` | 5.7 等旧版 | json 落 longText |
| `PgsqlAdapter` | PG / PG 系 | unsigned 回落、boolean 原生、忽略 comment、仅存在性比对 |

按连接覆盖（`config/dbcheck.php`）：

```php
'adapters' => [
    'default' => Dongasai\Dbcheck\Adapters\MysqlAdapter::class,
],
```

## 分组

检查器覆盖 `group(): ?string` 自行声明分组名（**框架不预置任何分组名**）：

- 返回 `null` → 常规检查，`dbcheck:check` 默认执行；
- 返回任意字符串（如 `'testdata'`）→ 分组检查，默认**不执行**，需 `--group=<名称>` 显式触发。

所谓 **seeder 就是执行某分组的 row 检查 + field 检查**：

```bash
php artisan dbcheck:check --group=testdata
```

## 命令

```bash
php artisan dbcheck:init                        # 把包内配置发布到项目 config/dbcheck.php
php artisan dbcheck:check                       # 交互：先看 SQL 再确认修复
php artisan dbcheck:check --no-interaction      # 只报告，零变更
php artisan dbcheck:check --group=testdata      # 只跑指定分组
php artisan dbcheck:check --all-groups          # 跑全部分组
```

## 边界

- 不做破坏性修复：行差异只 `ADD COLUMN` / `CHANGE COLUMN` / `ADD INDEX` / 同名索引重建。
- 额外列 / 额外索引交互询问且**默认不处理**，非交互只报告、不计错误。
- 不比较注释、字符集、排序规则、`AUTO_INCREMENT`、主键。
- 索引按「类型（唯一/普通）+ 字段」判存在，**索引名不作为存在依据**（避免重复建）。

## 验证清单

写完检查类后必须执行：

```bash
php artisan dbcheck:check --no-interaction
```

确认无异常输出；有异常则修正声明直至全绿。
