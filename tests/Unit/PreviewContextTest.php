<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Support\PreviewContext;
use PHPUnit\Framework\TestCase;

/**
 * PreviewContext 缓存语义测试（预览上下文内只读一次 / 上下文外不缓存）。
 */
class PreviewContextTest extends TestCase
{
    protected function tearDown(): void
    {
        PreviewContext::end();

        parent::tearDown();
    }

    public function testOutsidePreviewEveryCallExecutesResolver(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return $calls;
        };

        $this->assertFalse(PreviewContext::active());
        $this->assertSame(1, PreviewContext::remember('k', $resolver));
        $this->assertSame(2, PreviewContext::remember('k', $resolver));
        $this->assertSame(2, $calls);
    }

    public function testInsidePreviewSameKeyIsResolvedOnce(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return ['value' => $calls];
        };

        PreviewContext::begin();
        $this->assertTrue(PreviewContext::active());

        $this->assertSame(['value' => 1], PreviewContext::remember('k', $resolver));
        $this->assertSame(['value' => 1], PreviewContext::remember('k', $resolver));
        $this->assertSame(1, $calls);

        PreviewContext::end();
        $this->assertFalse(PreviewContext::active());

        // 退出上下文后缓存清空，重新真正执行
        $this->assertSame(['value' => 2], PreviewContext::remember('k', $resolver));
        $this->assertSame(2, $calls);
    }

    public function testNullResultIsCachedToo(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return null;
        };

        PreviewContext::begin();
        $this->assertNull(PreviewContext::remember('k', $resolver));
        $this->assertNull(PreviewContext::remember('k', $resolver));
        $this->assertSame(1, $calls);
    }

    public function testBeginResetsPreviousCache(): void
    {
        PreviewContext::begin();
        PreviewContext::remember('k', function () {
            return 'first';
        });
        PreviewContext::begin();

        $this->assertSame('second', PreviewContext::remember('k', function () {
            return 'second';
        }));
    }

    public function testWriteStatementsFiltersReads(): void
    {
        $statements = [
            ['query' => 'select exists(select * from t)', 'bindings' => []],
            ['query' => 'SHOW TABLES', 'bindings' => []],
            ['query' => 'pragma table_info("t")', 'bindings' => []],
            ['query' => 'insert into t (a) values (?)', 'bindings' => [1]],
            ['query' => 'alter table t add column b int', 'bindings' => []],
        ];

        $writes = PreviewContext::writeStatements($statements);

        $this->assertCount(2, $writes);
        $this->assertSame('insert into t (a) values (?)', $writes[0]['query']);
        $this->assertSame('alter table t add column b int', $writes[1]['query']);
    }
}
