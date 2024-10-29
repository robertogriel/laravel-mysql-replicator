<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

class StartReplicationCommand extends Command
{
    protected $signature = 'replicator:start';
    protected $description = 'Inicia o processo de replicação configurado no pacote Replicator';
    public const CACHE_ULTIMA_ALTERACAO = 'replicador.colaborador_loja.ultima_alteracao';

    public function handle()
    {
        $configurations = config('replicator');

        $databases = [];
        $tables = [];

        foreach ($configurations as $config) {
            $databases[] = $config['database'];
            $databases[] = $config['sync']['database'];
            $tables[] = $config['table'];
            $tables[] = $config['sync']['table'];
        }

        $databases = array_unique($databases);
        $tables = array_unique($tables);

        $registro = new class($configurations) extends EventSubscribers {
            private array $configurations;

            public function __construct($configurations)
            {
                $this->configurations = $configurations;
            }

            public function allEvents(EventDTO $event): void
            {
                $database = $event->getTableMap()->getDatabase();
                $table = $event->getTableMap()->getTable();

                foreach ($this->configurations as $config) {
                    $sourceDatabase = $config['database'];
                    $sourceTable = $config['table'];
                    $targetDatabase = $config['sync']['database'];
                    $targetTable = $config['sync']['table'];

                    if (
                        ($database === $sourceDatabase && $table === $sourceTable) ||
                        ($database === $targetDatabase && $table === $targetTable)
                    ) {
                        $this->replicateEvent($event, $config, $database, $table);
                        break;
                    }
                }
            }

            private function replicateEvent(EventDTO $event, $config, $eventDatabase, $eventTable): void
            {
                if ($eventDatabase === $config['database'] && $eventTable === $config['table']) {
                    $sourceConfig = $config;
                    $targetConfig = $config['sync'];
                    $columnMappings = $config['sync']['columns'];
                } else {
                    $sourceConfig = $config['sync'];
                    $targetConfig = $config;
                    $columnMappings = array_flip($config['sync']['columns']);
                }

                $sourcePrimaryKey = $sourceConfig['primary_key'];
                $targetDatabase = $targetConfig['database'];
                $targetTable = $targetConfig['table'];
                $targetPrimaryKey = $targetConfig['primary_key'];

                foreach ($event->getValues() as $row) {
                    $before = $row['before'] ?? [];
                    $after = $row['after'] ?? [];

                    $eventType = $event->getType(); // 'update', 'write', 'delete'

                    switch ($eventType) {
                        case 'update':
                            $this->handleUpdate(
                                $sourcePrimaryKey,
                                $targetDatabase,
                                $targetTable,
                                $targetPrimaryKey,
                                $columnMappings,
                                $before,
                                $after
                            );
                            break;

                        case 'write':

                            break;

                        case 'delete':

                            break;
                    }
                }
            }

            private function handleUpdate(
                $sourcePrimaryKey,
                $targetDatabase,
                $targetTable,
                $targetPrimaryKey,
                $columnMappings,
                $before,
                $after
            ): void
            {
                $changedColumns = [];
                foreach ($columnMappings as $sourceColumn => $targetColumn) {
                    if ($before[$sourceColumn] !== $after[$sourceColumn]) {
                        $changedColumns[$targetColumn] = $after[$sourceColumn];
                    }
                }

                if (!empty($changedColumns)) {
                    $primaryKeyValue = $after[$sourcePrimaryKey];

                    DB::statement('SET SESSION sql_log_bin=0;');

                    DB::table("{$targetDatabase}.{$targetTable}")
                        ->where($targetPrimaryKey, $primaryKeyValue)
                        ->update($changedColumns);

                    DB::statement('SET SESSION sql_log_bin=1;');
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
                ConstEventType::DELETE_ROWS_EVENT_V1
            ])
            ->withDatabasesOnly($databases)
            ->withTablesOnly($tables);

        $ultimaAlteracao = Cache::get(self::CACHE_ULTIMA_ALTERACAO);
        if (!empty($ultimaAlteracao)) {
            $builder->withBinLogFileName($ultimaAlteracao['arquivo'])
                ->withBinLogPosition($ultimaAlteracao['posicao']);
        }

        $replicacao = new MySQLReplicationFactory($builder->build());
        $replicacao->registerSubscriber($registro);

        $replicacao->run();
    }
}
