<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Console\Commands\CheckInfoCommand;
use Dongasai\Dbcheck\Tests\TestCase;

/**
 * 预览 SQL 排版测试：绑定值内联与空白压缩都不能改写字符串字面量
 * （预览必须与真实执行同口径，用户会直接复制预览 SQL 执行）。
 */
class PreviewSqlFormattingTest extends TestCase
{
    /**
     * @param string|array<int, mixed> $entry
     */
    private function format($entry): string
    {
        $command = new CheckInfoCommand();
        $method = new \ReflectionMethod($command, 'formatSql');
        $method->setAccessible(true);

        return (string) $method->invoke($command, $entry);
    }

    /**
     * 字面量里的 ? 不是绑定占位符：不能被替换成 NULL。
     */
    public function testQuestionMarkInsideLiteralIsNotReplaced(): void
    {
        $sql = $this->format(['query' => "create table t (a varchar(10) default '?')", 'bindings' => []]);

        $this->assertStringContainsString("default '?'", $sql);
        $this->assertStringNotContainsString("default 'NULL'", $sql);
    }

    /**
     * 占位符替换不能把字符串值里的 ? 当成占位符。
     */
    public function testBindingValueWithQuestionMarkIsKept(): void
    {
        $sql = $this->format(['query' => 'insert into t (a) values (?)', 'bindings' => ['x?y']]);

        $this->assertStringContainsString("'x?y'", $sql);
    }

    /**
     * 字符串字面量内的连续空格不得被排版压缩（真实修复会原样写入）。
     */
    public function testWhitespaceInsideLiteralIsPreserved(): void
    {
        $sql = $this->format(['query' => 'insert into t (a) values (?)', 'bindings' => ['a  b']]);

        $this->assertStringContainsString("'a  b'", $sql);
        $this->assertStringNotContainsString("'a b'", $sql);
    }

    /**
     * 字符串语句（pretend 日志）同样保留字面量内空白。
     */
    public function testStringStatementKeepsLiteralWhitespace(): void
    {
        $sql = $this->format("insert into t (a) values ('a  b')");

        $this->assertStringContainsString("'a  b'", $sql);
    }

    /**
     * 多个占位符按顺序替换；字面量里的问号不消耗绑定。
     */
    public function testMultiplePlaceholdersAreReplacedInOrder(): void
    {
        $sql = $this->format(['query' => 'insert into t (a, b) values (?, ?)', 'bindings' => ['x', 2]]);

        $this->assertStringContainsString("'x'", $sql);
        $this->assertStringContainsString('2', $sql);
    }

    /**
     * 字面量问号不消耗绑定：后续占位符仍取到正确的值。
     */
    public function testLiteralQuestionMarkDoesNotConsumeBinding(): void
    {
        $sql = $this->format(['query' => "insert into t (a, b) values (?, '?')", 'bindings' => ['x']]);

        $this->assertStringContainsString("'x'", $sql);
        $this->assertStringContainsString("'?'", $sql);
        $this->assertStringNotContainsString('NULL', $sql);
    }
}
