📍 [dbcheck](../../AGENTS.md) / **Adapters**

# 驱动适配层

屏蔽 MySQL / MySQL8 / PostgreSQL 在「建列类型映射、列/索引比对深度、索引命名」上的差异。新增驱动只需继承 DatabaseAdapter，无需动 Checks/。

## 文件

| 文件 | 职责 |
|------|------|
| DatabaseAdapter.php | 抽象基类：driverLabel / comparesColumnAttributes / comparesIndexAttributes / buildColumn / normalizeColumnType / expectedColumnType / actualIndexes / actualColumns / actualTableCharset（默认 null=不支持表编码读取）/ defaultMatches / resolveIndexName |
| DbDriver.php | 数据库类型判断：**显式参数 > 运行期覆盖（override() / --adapter=）> 按连接自动探测**（mysql/mariadb → Mysql8，pgsql/postgres → Pgsql）；detect() 返回 Laravel 驱动名；supports() 校验别名/类名；未知值抛 InvalidArgumentException。适配器映射不做配置化硬编码 |
| AdapterFactory.php | 稳定入口：转发 DbDriver::adapter()（可传第二参 $override 显式指定），保持检查器调用面不变 |
| MysqlAdapter.php | MySQL 5.7 等无原生 JSON：json → longText；列/索引属性精确比对；表编码读取（information_schema.TABLES + COLLATIONS）；ENUM 建列/期望类型按 `enum('a','b')` 口径 |
| Mysql8Adapter.php | 仅覆盖 json 差异：原生 JSON 类型；继承 MysqlAdapter |
| PgsqlAdapter.php | 无 unsigned 概念（unsigned* 回落有符号）、tinyInteger→smallint、原生 boolean、comment 忽略、CURRENT_TIMESTAMP 编译为 PG 关键字；**仅存在性比对**，主键索引（{table}_pkey）不参与 |

## 要点

- **判断优先级**：显式参数（`AdapterFactory::make($connection, $override)`，别名或类名）> 运行期覆盖（`DbDriver::override()`，命令行 `--adapter=`）> 自动探测；别名 mysql/mysql8/mariadb → Mysql8，mysql57 → MysqlAdapter（5.7 旧版）。
- **比对深度开关**：comparesColumnAttributes() / comparesIndexAttributes()——MySQL 系 true，PG false（PG 类型口径与声明差异大，精确比对必大量误报）。
- **表编码读取**：actualTableCharset() 基类默认返回 null（= 不支持，检查器声明 charset()/collation() 才会触发），MySQL 系覆写实现；PG 继承默认值，编码比对自动跳过。
- **MySQL 5.7 老库注意**：自动选择会落到 Mysql8Adapter，需在 config/dbcheck.php 显式覆盖为 MysqlAdapter。
- 索引解析：resolveIndexName() 处理声明名与实际名的对应（PG 系统命名差异在 PgsqlAdapter 覆写）。

## 依赖

- 被 Checks/AbstractTableCheck（建表与差异比对）经 AdapterFactory 调用；驱动选择经 DbDriver（自动探测 + 运行期覆盖），不读配置。
