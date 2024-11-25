<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use robertogriel\Replicator\Config\ReplicationConfigManager;
use robertogriel\Replicator\Database\DatabaseService;
use robertogriel\Replicator\Subscribers\Registration;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\MySQLReplicationFactory;

class StartReplicationCommand extends Command
{
    protected $signature = 'replicator:start';
    protected $description = 'Starts the replication process configured in the Replicator package';

    public function handle(): void
    {
        $configManager = new ReplicationConfigManager();
        [$databases, $tables] = $configManager->getGroupDatabaseConfigurations();

        $builder = (new ConfigBuilder())
            ->withHost(Config::get('database.connections.replicator-bridge.host'))
            ->withPort(Config::get('database.connections.replicator-bridge.port'))
            ->withUser(Config::get('database.connections.replicator-bridge.username'))
            ->withPassword(Config::get('database.connections.replicator-bridge.password'))
            ->withEventsOnly([
                ConstEventType::UPDATE_ROWS_EVENT_V1,
                ConstEventType::WRITE_ROWS_EVENT_V1,
                ConstEventType::DELETE_ROWS_EVENT_V1,
                ConstEventType::MARIA_ANNOTATE_ROWS_EVENT,
            ])
            ->withDatabasesOnly($databases)
            ->withTablesOnly($tables);

        $databaseService = new DatabaseService();
        $lastBinlogPosition = $databaseService->getLastBinlogPosition();

        if (!empty($lastBinlogPosition['file']) && !empty($lastBinlogPosition['position'])) {
            $builder
                ->withBinLogFileName($lastBinlogPosition['file'])
                ->withBinLogPosition($lastBinlogPosition['position']);
        }

        $registrationSubscriber = new Registration();
        $replication = new MySQLReplicationFactory($builder->build());
        $replication->registerSubscriber($registrationSubscriber);

        $replication->run();
    }
}
