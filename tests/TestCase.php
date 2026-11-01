<?php

namespace Dongasai\Dbcheck\Tests;

use Dongasai\Dbcheck\Adapters\DbDriver;
use Dongasai\Dbcheck\Support\DbCheckRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * 测试基类：每个测试起一个独立的 SQLite 内存库（Capsule 最小容器）。
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * 每个测试用全新的 SQLite 内存库，互不污染。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);

        $container->instance('config', new Repository([
            'database' => [
                'default' => 'testing',
                'connections' => [
                    'testing' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                        'foreign_key_constraints' => false,
                    ],
                ],
            ],
            'dbcheck' => ['scan' => []],
        ]));

        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ], 'testing');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Capsule 会把 database.default 重置为 default，这里改回测试连接
        config(['database.default' => 'testing']);

        $container->instance('db', $capsule->getDatabaseManager());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        DbCheckRegistry::clear();
        DbDriver::override(null);
    }

    /**
     * 清理静态状态（注册表 / 驱动覆盖 / facade）。
     */
    protected function tearDown(): void
    {
        DbDriver::override(null);
        DbCheckRegistry::clear();
        Facade::clearResolvedInstances();

        parent::tearDown();
    }
}
