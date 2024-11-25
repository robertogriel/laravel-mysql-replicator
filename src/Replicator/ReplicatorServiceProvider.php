<?php

namespace robertogriel\Replicator;

use Illuminate\Support\ServiceProvider;
use robertogriel\Replicator\Console\Commands\StartReplicationCommand;

class ReplicatorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__ . '/../../migrations/' => database_path('migrations'),
            ],
            'replicator-migrations'
        );
    }

    public function register(): void
    {
        $this->commands(StartReplicationCommand::class);
    }
}
