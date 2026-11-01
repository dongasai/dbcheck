<?php

namespace Dongasai\Dbcheck\Tests\Unit;

use Dongasai\Dbcheck\Tests\Fixtures\DemoFieldCheck;
use Dongasai\Dbcheck\Tests\Fixtures\DemoMapFieldCheck;
use Dongasai\Dbcheck\Tests\TestCase;

/**
 * FieldCheck 数组列声明的纯逻辑测试（list+key / map 双格式、已存 JSON 双形态）。
 */
class FieldCheckLogicTest extends TestCase
{
    /**
     * 调用受保护的静态方法。
     *
     * @param class-string $class
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    protected function callProtected(string $class, string $method, array $args)
    {
        $reflection = new \ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    public function testDeclaredItemsNormalizesListWithKeys(): void
    {
        $declared = [
            ['key' => 'a', 'name' => 'A'],
            ['key' => 'b', 'name' => 'B'],
        ];

        $this->assertSame([
            'a' => ['key' => 'a', 'name' => 'A'],
            'b' => ['key' => 'b', 'name' => 'B'],
        ], $this->callProtected(DemoFieldCheck::class, 'declaredItems', [$declared]));
    }

    public function testDeclaredItemsKeepsMapAsIs(): void
    {
        $declared = ['a' => ['key' => 'a', 'name' => 'A']];

        $this->assertSame($declared, $this->callProtected(DemoMapFieldCheck::class, 'declaredItems', [$declared]));
    }

    public function testDeclaredItemsKeepsEmptyArray(): void
    {
        $this->assertSame([], $this->callProtected(DemoFieldCheck::class, 'declaredItems', [[]]));
    }

    public function testDeclaredItemsKeepsListWithoutKeyAsIs(): void
    {
        $declared = [['name' => '没有 key']];

        $this->assertSame($declared, $this->callProtected(DemoFieldCheck::class, 'declaredItems', [$declared]));
    }

    public function testIndexStoredItemsIndexesListByItemKey(): void
    {
        $stored = json_encode([
            ['key' => 'a', 'checked' => true],
            ['key' => 'b', 'checked' => false],
        ]);

        $this->assertSame([
            'a' => ['key' => 'a', 'checked' => true],
            'b' => ['key' => 'b', 'checked' => false],
        ], $this->callProtected(DemoFieldCheck::class, 'indexStoredItems', [$stored]));
    }

    public function testIndexStoredItemsKeepsMap(): void
    {
        $this->assertSame(
            ['a' => ['key' => 'a']],
            $this->callProtected(DemoFieldCheck::class, 'indexStoredItems', [['a' => ['key' => 'a']]])
        );
    }

    public function testIndexStoredItemsHandlesInvalidJson(): void
    {
        $this->assertSame([], $this->callProtected(DemoFieldCheck::class, 'indexStoredItems', ['{bad']));
        $this->assertSame([], $this->callProtected(DemoFieldCheck::class, 'indexStoredItems', [null]));
        $this->assertSame([], $this->callProtected(DemoFieldCheck::class, 'indexStoredItems', ['[]']));
    }

    public function testIsListArray(): void
    {
        $this->assertTrue($this->callProtected(DemoFieldCheck::class, 'isListArray', [[0, 1, 2]]));
        $this->assertFalse($this->callProtected(DemoFieldCheck::class, 'isListArray', [['a' => 1]]));
        $this->assertFalse($this->callProtected(DemoFieldCheck::class, 'isListArray', [[]]));
    }
}
