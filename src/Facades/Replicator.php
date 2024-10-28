<?php

namespace robertogriel\Replicator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void startReplication()
 * @see \robertogriel\Replicator\Replicator::class
 */
class Replicator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \robertogriel\Replicator\Replicator::class;
    }
}
