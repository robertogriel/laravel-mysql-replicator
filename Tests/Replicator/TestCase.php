<?php

namespace Tests\Replicator;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\TestCase as BaseTestCase;
use robertogriel\Replicator\ReplicatorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    // @codeCoverageIgnoreStart
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    protected function getPackageProviders($app): array
    {
        return [ReplicatorServiceProvider::class];
    }
    // @codeCoverageIgnoreEnd
}
