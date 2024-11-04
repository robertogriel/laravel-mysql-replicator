<?php

namespace robertogriel\Replicator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \robertogriel\Replicator\Replicator
 */
class Replicator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \robertogriel\Replicator\Replicator::class;
    }
}
