# dbcheck

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)
![Laravel](https://img.shields.io/badge/Laravel-8–13-red.svg)

**目标驱动的数据库检查（迁移）工具——声明数据库「应该长什么样」，自动把实际库收敛到目标态。用于替代过程驱动的 Migration。**

## 为什么不是 Migration？

| | 过程驱动：Migration | 目标驱动：dbcheck |
|------|--------------------|-------------------|
| 声明的是 | **变更过程**：这次加了哪几列、改了哪个索引 | **最终状态**：这张表当前应该长什么样 |
| 执行语义 | 按历史顺序回放变更队列，靠执行记录推进 | 与历史无关：比对声明与实际库，缺什么补什么 |
| 环境漂移 | 迁移文件与实际库一旦不一致（手工改表、跨环境、漏跑），只会越漂越远 | 漂移本身就是检查报告，修复即收敛 |
| 搭建新环境 | 从零回放全部历史迁移 | 跑一遍检查：缺表建表、缺行插行、缺列补列 |
| 回滚 | 需要 `down()` 逆操作且常常没人写对 | 无需逆操作——回滚代码后再次 check，先前引入的列/索引/数据行即以额外项被报出，确认修复即完成回滚 |

一句话：Migration 回答「怎么从 A 走到 B」；dbcheck 只声明「B 长什么样」，A 是什么都不重要——工具负责把任何 A 收敛成 B。

**回滚**也是同一个机制：数据库始终与代码对齐。回滚代码（声明回到旧目标态）后再次 check，先前引入的列 / 索引 / 数据行会以额外项（`extra_column.*` / `extra_index.*` / `data.extra.*`）被报出，确认修复即完成回滚——目标态在哪，库就收敛到哪（额外列/索引默认逐条确认，多余行需开启删除验证）。

检查器是表最终结构的**唯一声明处**：不存在创建，存在比对差异并修复。库与声明不一致就是异常，修复即对齐，检查-修复循环直到全绿。

## 特性

- **三类检查，一张表一个检查器**
  - 表结构 `AbstractTableCheck`：表不存在按声明建表；存在则比对列/索引/表编码差异并修复（额外列/索引默认不动）
  - 固定数据 `AbstractTableDataCheck`：必需数据行缺则插；可选删除验证 `deleteExtra()` 报未声明的多余行
  - 字段明细 `AbstractTableFieldCheck`：声明字段的标量值 / JSON 数组项是否齐全，只补缺失
- **多驱动适配**：MySQL / MySQL8 / PostgreSQL / SQLite，按连接自动判断、参数可覆盖；比对深度由驱动决定（MySQL 精确比对，PG/SQLite 仅存在性，防误报）
- **SQL 预览**：修复前以 pretend 模式预览将执行的写 SQL（内联绑定值、排版），绝不静默写库
- **分组与专用入口**：`dbcheck:seed` 播种种子数据（`SEEDER` 分组）、`dbcheck:delete` 清理多余行（`DELETE` 分组）——补与删职责正交
- **扫描根匹配模式**：`Modules/*/DbChecks` 一条声明覆盖全部惯例目录，新模块建目录即自动纳入
- **agent 友好**：`dbcheck:skill` 把编写指南注册为 agent skill，适合 AI 参与的工程流程

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

### 更新

```bash
composer update dongasai/dbcheck            # composer 方式：升级到最新发布版
php artisan dbcheck:check --no-interaction  # 升级后先只报告复验一轮（零变更）
```

- 升级只换工具、不动库：检查类声明与数据库都不变，复验应保持全绿；有新告警再看对应版本的 Release Notes。
- path 仓库接入时更新 = 拉取包仓库最新代码即可，无需 composer 操作。

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

### 2. 编写检查类：声明目标（命名 = 表名整串 StudlyCase + 类型后缀，模块前缀保留）

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
- 固定数据 / 字段明细的声明写法见 [resources/skill/dbcheck/SKILL.md](resources/skill/dbcheck/SKILL.md)（可经 `dbcheck:skill` 注册为 agent skill）

### 3. 收敛：检查 → 修复 → 全绿

```bash
php artisan dbcheck:check --no-interaction   # 只报告，零变更（写完检查类必须复验到全绿）
php artisan dbcheck:check                    # 交互：逐条预览 SQL → 确认修复 → 复检
```

输出中的每个异常项都是「实际库 → 目标态」的一条差距，确认后由工具生成并执行对齐 SQL。全绿 = 库与声明完全一致。

## 命令

| 命令 | 用途 |
|------|------|
| `dbcheck:check` | 主命令：自检 + 交互修复（参数见下表） |
| `dbcheck:seed` | 播种：执行声明 `group()=SEEDER` 的数据/字段检查器，缺行补插 + 字段回写；`--group=` 改收任意自定义分组 |
| `dbcheck:delete` | 删除验证：清理开启 `deleteExtra()` 的检查器中未声明的多余数据行（逐条确认，`--yes` 免确认） |
| `dbcheck:init` | 发布包内默认配置到 config/dbcheck.php（`--force` 覆盖） |
| `dbcheck:skill` | 把编写指南注册为 agent skill（交互多选目标目录） |

`dbcheck:seed` 只补、`dbcheck:delete` 只删，职责正交；同一检查器可同时声明 `SEEDER` 分组与 `deleteExtra()`。

### dbcheck:check 参数

| 参数 | 说明 |
|------|------|
| `--check=fields\|db\|all` | 检查项：fields=三类检查（默认）；db=数据库连接/类型/读写自检；可逗号组合 `fields,db` |
| `--connection=` | 数据库连接名（默认 `database.default`；显式声明连接名的检查器不受影响） |
| `--adapter=` | 驱动覆盖：别名 `mysql8/mysql/mariadb/mysql57/pgsql/postgres/sqlite` 或适配器类名（默认自动探测） |
| `--fix-flow=batch\|inline` | 修复节奏：batch=先全量报告（默认）；inline=边查边修（仅交互） |
| `--group=*` / `--all-groups` | 分组执行（分组由 `group()` 声明） |
| `--detail` / `--db-write` | db 自检详细输出 / 执行临时表读写自检（默认只读） |

## 驱动适配

| 适配器 | 适用 | 特点 |
|--------|------|------|
| `Mysql8Adapter` | MySQL 8+（mysql/mysql8/mariadb 自动探测默认） | json 原生类型，精确比对 |
| `MysqlAdapter` | 5.7 等旧版（`mysql57`） | json 落 longText，精确比对 |
| `PgsqlAdapter` | PG / PG 系 | unsigned 回落、boolean 原生、仅存在性比对 |
| `SqliteAdapter` | SQLite（测试场景） | 存在性比对，全链集成测用 |

## 测试

```bash
composer test        # 或 phpunit --bootstrap tests/bootstrap.php tests
```

Unit 6 只（列类型/注册表/驱动判断/三类检查逻辑）+ Integration 5 只（SQLite 全链：建表比对修复、固定数据、删除验证、播种分组、字段明细），共 102 用例。

> 注意：tests/bootstrap.php 用 Capsule 起最小容器；在「全套 Laravel 宿主」的 vendor 下运行需先补 `db.schema` 容器绑定。

## License

[MIT](LICENSE)
