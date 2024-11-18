<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\MySQLReplicationFactory;
use robertogriel\Replicator\Config\ReplicationConfigManager;
use robertogriel\Replicator\Database\DatabaseService;
use robertogriel\Replicator\Subscribers\Registration;

class StartReplicationCommand extends Command
{
    protected $signature = 'replicator:start';
    protected $description = 'Inicia o processo de replicação configurado no pacote Replicator';

    public function handle(): void
    {
        $configManager = new ReplicationConfigManager();
        $configurations = $configManager->getConfigurations();

        $databases = $configManager->getDatabases();
        $tables = $configManager->getTables();

        $builder = (new ConfigBuilder())
            ->withHost(env('DB_HOST'))
            ->withPort(env('DB_PORT'))
            ->withUser(env('REPLICATOR_DB_USERNAME'))
            ->withPassword(env('REPLICATOR_DB_PASSWORD'))
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

        $registrationSubscriber = new Registration($configurations);
        $replication = new MySQLReplicationFactory($builder->build());
        $replication->registerSubscriber($registrationSubscriber);

        $replication->run();
    }
}
