<?php

namespace robertogriel\Replicator;

use Illuminate\Support\ServiceProvider;
use robertogriel\Replicator\Commands\ReplicatorCommand;

class ReplicatorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('Replicator', function ($app) {
            return new Replicator();
        });

        $this->mergeConfigFrom(
            __DIR__ . '/../config/Replicator.php',
            'replicator'
        );
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/Replicator.php' => config_path('Replicator.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReplicatorCommand::class,
            ]);
        }
    }
}
