<?php

namespace robertogriel\Replicator;

use Illuminate\Support\ServiceProvider;
use robertogriel\Replicator\Console\Commands\StartReplicationCommand;

class ReplicatorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('Replicator', function ($app) {
            return new Replicator();
        });
    }

    public function boot()
    {
        $this->publishes(
            [
                __DIR__ . '/../config/replicator.php' => config_path('replicator.php'),
            ],
            'config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([StartReplicationCommand::class]);
        }
    }
}
