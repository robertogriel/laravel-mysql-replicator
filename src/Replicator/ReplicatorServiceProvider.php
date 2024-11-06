<?php

namespace robertogriel\Replicator;

use Illuminate\Support\ServiceProvider;
use robertogriel\Replicator\Console\Commands\StartReplicationCommand;

class ReplicatorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            StartReplicationCommand::class,
        ]);
    }
}
