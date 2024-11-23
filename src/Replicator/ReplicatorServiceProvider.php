<?php

namespace robertogriel\Replicator;

use Illuminate\Support\ServiceProvider;
use robertogriel\Replicator\Console\Commands\StartReplicationCommand;

class ReplicatorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/replicator-connection.php', 'database.connections.replicator');

        $this->commands(StartReplicationCommand::class);
    }
}
