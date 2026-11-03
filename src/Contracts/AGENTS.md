📍 [dbcheck](../../AGENTS.md) / **Contracts**

# 契约层

两个接口，纯静态语义（实现方全静态）。

## 文件

| 文件 | 接口 | 职责 |
|------|------|------|
| DbCheckInterface.php | DbCheckInterface | 检查器契约：moduleName() / check() / fix($key) / preview($key) |
| TableSchemaProvider.php | TableSchemaProvider | 表最终结构声明：table() / columns() / indexes()（仅 TableCheck 实现） |

## 要点

- **check() 返回异常项数组**，每项固定结构：
  - `key`：修复派发标识（命名规则见根文档「异常项 key 命名」）
  - `type`：column / index / extra_column / extra_index / table / data / field / item
  - `message`：问题描述；`optional`：是否可选处理项（额外列/索引，默认不处理）；`repair`：修复方式展示文案
- **fix($key) 只认 check() 产出的 key**，未知 key 抛 LogicException。
- **preview($key)**：连接层 pretend 模式返回将执行的语句数组（每项 `['query'=>string,'bindings'=>array]`），已由 `Support/PreviewContext::writeStatements()` 过滤掉判重/元数据读，只保留写 SQL；绑定值未内联（命令侧 formatSql 内联并排版）。pretend 期间 SELECT 恒返回空，故实现方须在 pretend 前预热 fix() 依赖的读（见 Support/PreviewContext）。
- 直接实现 DbCheckInterface 却不继承三个抽象基类之一 → `DbCheckRegistry::grouped()` 抛 LogicException（通常漏写 extends）。
- TableSchemaProvider 是一张表「当前应有完整结构」的唯一声明处，由 AbstractTableCheck 实现（不存在建表，存在比对并修复差异）。

## 依赖

- 被 Checks/ 三基类实现；被 Support/DbCheckRegistry 自动发现与归类。
