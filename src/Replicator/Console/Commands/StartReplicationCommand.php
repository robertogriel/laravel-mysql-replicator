<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;
use robertogriel\Replicator\Config\ReplicationConfigManager;
use robertogriel\Replicator\Binlog\BinlogManager;
use robertogriel\Replicator\Helpers\ChangedColumns;
use robertogriel\Replicator\Handlers\UpdateHandler;
use robertogriel\Replicator\Handlers\InsertHandler;
use robertogriel\Replicator\Handlers\DeleteHandler;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use robertogriel\Replicator\Interceptor\InterceptorManager;

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

        $configurations = Config::get('replicator');

        $databases = [];
        $tables = [];
        $columns = [];

        foreach ($configurations as $config) {
            $databases[] = $config['node_primary']['database'] ?? env('DB_DATABASE');
            $databases[] = $config['node_secondary']['database'];
            $tables[] = $config['node_primary']['table'];
            $tables[] = $config['node_secondary']['table'];
            $columns = array_merge($columns, array_keys($config['columns']));
        }

        $databases = array_unique($databases);
        $tables = array_unique($tables);

        $registration = new class($configurations) extends EventSubscribers {
            private array $configurations;

            public function __construct(array $configurations)
            {
                $this->configurations = $configurations;
            }

            public function allEvents(EventDTO $event): void
            {
                if (
                    !(
                        $event instanceof WriteRowsDTO ||
                        $event instanceof UpdateRowsDTO ||
                        $event instanceof DeleteRowsDTO
                    )
                ) {
                    return;
                }

                $database = $event->tableMap->database;
                $table = $event->tableMap->table;

                foreach ($this->configurations as $config) {
                    $nodePrimaryDatabase = $config['node_primary']['database'];
                    $nodePrimaryTable = $config['node_primary']['table'];
                    $nodeSecondaryDatabase = $config['node_secondary']['database'];
                    $nodeSecondaryTable = $config['node_secondary']['table'];

                    if (
                        ($database === $nodePrimaryDatabase && $table === $nodePrimaryTable) ||
                        ($database === $nodeSecondaryDatabase && $table === $nodeSecondaryTable)
                    ) {
                        if (
                            $event->tableMap->database === $nodePrimaryDatabase &&
                            $event->tableMap->table === $nodePrimaryTable
                        ) {
                            $nodePrimaryConfig = $config['node_primary'];
                            $nodeSecondaryConfig = $config['node_secondary'];
                            $columnMappings = $config['columns'];
                        } else {
                            $nodePrimaryConfig = $config['node_secondary'];
                            $nodeSecondaryConfig = $config['node_primary'];
                            $columnMappings = array_flip($config['columns']);
                        }

                        $configuredColumns = array_keys($columnMappings);

                        $changedColumns = ChangedColumns::getChangedColumns($event);

                        if (empty(array_intersect($configuredColumns, $changedColumns))) {
                            continue;
                        }

                        $nodePrimaryReferenceKey = $nodePrimaryConfig['reference_key'];
                        $nodeSecondaryDatabase = $nodeSecondaryConfig['database'];
                        $nodeSecondaryTable = $nodeSecondaryConfig['table'];
                        $nodeSecondaryReferenceKey = $nodeSecondaryConfig['reference_key'];

                        $interceptorFunction = $config['interceptor'] ?? false;

                        foreach ($event->values as $row) {
                            switch ($event::class) {
                                case UpdateRowsDTO::class:
                                    if ($interceptorFunction) {
                                        $row['after'] = InterceptorManager::applyInterceptor(
                                            $interceptorFunction,
                                            $row['after'],
                                            $nodePrimaryTable,
                                            $nodePrimaryDatabase
                                        );
                                    }

                                    UpdateHandler::handle(
                                        $nodePrimaryReferenceKey,
                                        $nodeSecondaryDatabase,
                                        $nodeSecondaryTable,
                                        $nodeSecondaryReferenceKey,
                                        $columnMappings,
                                        $row
                                    );
                                    break;

                                case WriteRowsDTO::class:
                                    if ($interceptorFunction) {
                                        $row = InterceptorManager::applyInterceptor(
                                            $interceptorFunction,
                                            $row,
                                            $nodePrimaryTable,
                                            $nodePrimaryDatabase
                                        );
                                    }

                                    InsertHandler::handle(
                                        $nodeSecondaryDatabase,
                                        $nodeSecondaryTable,
                                        $columnMappings,
                                        $row
                                    );
                                    break;

                                case DeleteRowsDTO::class:
                                    if ($interceptorFunction) {
                                        $row = InterceptorManager::applyInterceptor(
                                            $interceptorFunction,
                                            $row,
                                            $nodePrimaryTable,
                                            $nodePrimaryDatabase
                                        );
                                    }

                                    DeleteHandler::handle(
                                        $nodeSecondaryDatabase,
                                        $nodeSecondaryTable,
                                        $nodePrimaryReferenceKey,
                                        $nodeSecondaryReferenceKey,
                                        $row
                                    );
                                    break;
                            }
                        }

                        $binLogInfo = $event->getEventInfo()->binLogCurrent;
                        BinlogManager::updateBinlogPosition(
                            $binLogInfo->getBinFileName(),
                            $binLogInfo->getBinLogPosition()
                        );
                    }
                }
            }
        };

        $builder = (new ConfigBuilder())
            ->withHost(env('DB_HOST'))
            ->withPort(env('DB_PORT'))
            ->withUser(env('REPLICADOR_DB_USERNAME'))
            ->withPassword(env('REPLICADOR_DB_PASSWORD'))
            ->withEventsOnly([
                ConstEventType::UPDATE_ROWS_EVENT_V1,
                ConstEventType::WRITE_ROWS_EVENT_V1,
                ConstEventType::DELETE_ROWS_EVENT_V1,
                ConstEventType::MARIA_ANNOTATE_ROWS_EVENT,
            ])
            ->withDatabasesOnly($databases)
            ->withTablesOnly($tables);

        $lastBinlogPosition = BinlogManager::getLastBinlogPosition();

        if (!empty($lastBinlogPosition['file']) && !empty($lastBinlogPosition['position'])) {
            $builder
                ->withBinLogFileName($lastBinlogPosition['file'])
                ->withBinLogPosition($lastBinlogPosition['position']);
        }

        $replication = new MySQLReplicationFactory($builder->build());
        $replication->registerSubscriber($registration);

        $replication->run();
    }
}
