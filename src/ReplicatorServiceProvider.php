<?php

namespace robertogriel\Replicator;

use Illuminate\Support\ServiceProvider;
use robertogriel\Replicator\Commands\ReplicatorCommand;

class ReplicatorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('replicator', function ($app) {
            return new Replicator();
        });

        $this->mergeConfigFrom(
            __DIR__ . '/../config/replicator.php',
            'replicator'
        );
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/replicator.php' => config_path('replicator.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReplicatorCommand::class,
            ]);
        }
    }
}
