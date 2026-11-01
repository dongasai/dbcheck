# dbcheck

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)
![Laravel](https://img.shields.io/badge/Laravel-8–13-red.svg)

数据库结构/数据自检与修复框架：按表声明「最终结构 + 固定数据」，自动比对实际库差异并驱动修复。

把「这次迁移加了哪几列」的迁移式思维，换成「这张表当前应该长什么样」的声明式思维——检查器是表最终结构的唯一声明处，库与声明不一致就是异常，修复即对齐。

## 特性

- **三类检查**，一张表一个检查器
  - 表结构 `AbstractTableCheck`：表不存在按声明建表；存在则比对列/索引差异并修复（额外列/索引默认不动）
  - 固定数据 `AbstractTableDataCheck`：必需数据行是否存在，缺则插
  - 字段明细 `AbstractTableFieldCheck`：声明字段的标量值 / JSON 数组项是否齐全，只补缺失
- **多驱动适配**：MySQL / MySQL8 / PostgreSQL / SQLite，由 `DbDriver` 按连接自动检查判断，可参数覆盖；比对深度由驱动决定（MySQL 精确比对，PG/SQLite 仅存在性，防误报）
- **扫描根匹配模式**：`Modules/*/DbChecks` 一条声明覆盖全部惯例目录，新模块建目录即自动纳入
- **声明制表编码**：`charset()/collation()` 声明了才比对（建表时覆盖连接默认），不一致报 `table.charset`，修复 = `ALTER TABLE ... CONVERT TO`
- **SQL 预览**：修复前以 pretend 模式预览将执行的写 SQL（已内联绑定值、关键字换行排版），不落库
- **修复节奏**：`batch`（先全量报告后逐条修复，默认）/ `inline`（边查边修）

## 安装

```bash
composer require dongasai/dbcheck
```

服务提供者经 composer `extra.laravel.providers` 自动发现，无需手动注册。

本地开发可用 path 仓库接入宿主项目：

```json
// 宿主项目 composer.json
{
    "repositories": [{ "type": "path", "url": "extensions/db-check" }],
    "require": { "dongasai/dbcheck": "*" }
}
```

## 快速开始

### 1. 声明扫描根（config/dbcheck.php，可由 `dbcheck:init` 生成）

框架无任何隐式默认扫描目录，所有要扫描的根都必须显式定义；支持 `*` 匹配模式（path/namespace 各至多一个）：

```php
'scan' => [
    // 惯例目录一条匹配：Modules/<模块>/DbChecks（目录不存在自动跳过）
    ['path' => base_path('Modules/*/DbChecks'), 'namespace' => 'Modules\\*\\DbChecks\\'],
    // 惯例之外的特殊目录用无通配普通根逐条声明
    ['path' => base_path('app/DbChecks'), 'namespace' => 'App\\DbChecks\\'],
],
```

### 2. 编写检查类（命名 = 表名整串 StudlyCase + 类型后缀，模块前缀保留）

```php
<?php

namespace App\DbChecks;

use Dongasai\Dbcheck\Checks\AbstractTableCheck;
use Dongasai\Dbcheck\Support\ColumnType;

/**
 * 用户表最终结构声明：不存在创建，存在比对差异并修复。
 */
class UserProfilesTableCheck extends AbstractTableCheck
{
    public static function table(): string
    {
        return 'user_profiles';
    }

    public static function columns(): array
    {
        return [
            'id'         => ['type' => ColumnType::ID],
            'user_id'    => ['type' => ColumnType::UNSIGNED_BIG_INTEGER],
            'nickname'   => ['type' => ColumnType::STRING, 'length' => 50, 'default' => '', 'comment' => '昵称'],
            'status'     => ['type' => ColumnType::ENUM, 'values' => ['active', 'banned'], 'default' => 'active'],
            'created_at' => ['type' => ColumnType::TIMESTAMP, 'nullable' => true],
        ];
    }

    public static function indexes(): array
    {
        return [
            'user_profiles_user_id_index' => ['unique' => false, 'columns' => ['user_id']],
        ];
    }

    /** 建表字符集（仅建表生效；声明了才参与比对） */
    protected static function charset(): ?string
    {
        return 'utf8mb4';
    }

    /** 建表排序规则 */
    protected static function collation(): ?string
    {
        return 'utf8mb4_unicode_ci';
    }
}
```

- `type` 一律用 `Dongasai\Dbcheck\Support\ColumnType` 常量（22 种，含 `ENUM` 配 `values` 键）
- `'primary' => true` 声明主键（`ColumnType::ID` 自带）；`comment` 仅建表生效不参与比对
- 完整编写规范见 [resources/skill/dbcheck/SKILL.md](resources/skill/dbcheck/SKILL.md)（可经 `dbcheck:skill` 注册为 agent skill）

### 3. 跑检查

```bash
php artisan dbcheck:check --no-interaction   # 只报告，零变更（写完检查类必须复验到全绿）
php artisan dbcheck:check                    # 交互：逐条预览 SQL → 确认修复 → 复检
```

## 命令

### dbcheck:check —— 自检 + 修复

| 参数 | 说明 |
|------|------|
| `--check=fields\|db\|all` | 检查项：fields=三类检查（默认）；db=数据库连接/类型/读写自检；可逗号组合 `fields,db` |
| `--connection=` | 数据库连接名（默认 `database.default`；显式声明连接名的检查器不受影响） |
| `--adapter=` | 驱动覆盖：别名 `mysql8/mysql/mariadb/mysql57/pgsql/postgres/sqlite` 或适配器类名（默认自动探测） |
| `--fix-flow=batch\|inline` | 修复节奏：batch=先全量报告（默认）；inline=边查边修（仅交互） |
| `--group=*` / `--all-groups` | 分组执行（分组由 `group()` 声明；「seeder」= 执行数据分组） |
| `--detail` / `--db-write` | db 自检详细输出 / 执行临时表读写自检（默认只读） |

### 其他

```bash
php artisan dbcheck:init     # 发布包内默认配置到 config/dbcheck.php（--force 覆盖）
php artisan dbcheck:skill    # 把编写指南注册为 agent skill（交互多选目标目录）
```

## 驱动适配

| 适配器 | 适用 | 特点 |
|--------|------|------|
| `Mysql8Adapter` | MySQL 8+（mysql/mysql8/mariadb 自动探测默认） | json 原生类型 |
| `MysqlAdapter` | 5.7 等旧版（`mysql57`） | json 落 longText |
| `PgsqlAdapter` | PG / PG 系 | unsigned 回落、boolean 原生、仅存在性比对 |
| `SqliteAdapter` | SQLite（测试场景） | 存在性比对，全链集成测用 |

## 测试

```bash
composer test        # 或 phpunit --bootstrap tests/bootstrap.php tests
```

Unit 6 只（列类型/注册表/驱动判断/三类检查逻辑）+ Integration 3 只（SQLite 全链：建表比对修复、固定数据、字段明细）。

> 注意：tests/bootstrap.php 用 Capsule 起最小容器；在「全套 Laravel 宿主」的 vendor 下运行需先补 `db.schema` 容器绑定。

## License

[MIT](LICENSE)
