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

        $this->mergeConfigFrom(
            __DIR__ . '/../config/Replicator.php',
            'replicator'
        );
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/Replicator.php' => config_path('replicator.php'),
        ], 'config');

    }
}
