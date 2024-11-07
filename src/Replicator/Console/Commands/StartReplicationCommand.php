<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\Event;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

class StartReplicationCommand extends Command
{
    protected $signature = 'replicator:start';
    protected $description = 'Inicia o processo de replicação configurado no pacote Replicator';
    protected const CACHED_LAST_CHANGES = 'replicator_last_changes';

    public function handle(): void
    {
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

                if (!($event instanceof WriteRowsDTO || $event instanceof UpdateRowsDTO || $event instanceof DeleteRowsDTO)) {
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

                        $changedColumns = $this->getChangedColumns($event);

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
                                    $before = $row['before'];
                                    $after = $row['after'];

                                    if ($interceptorFunction) {
                                        $after = App::call($interceptorFunction, [
                                            'data' => $after,
                                            'nodePrimaryTable' => $nodePrimaryTable,
                                            'nodePrimaryDatabase' => $nodePrimaryDatabase,
                                        ]);
                                    }

                                    $this->handleUpdate(
                                        $nodePrimaryReferenceKey,
                                        $nodeSecondaryDatabase,
                                        $nodeSecondaryTable,
                                        $nodeSecondaryReferenceKey,
                                        $columnMappings,
                                        $before,
                                        $after
                                    );
                                    break;

                                case WriteRowsDTO::class:
                                    $data = $row;

                                    if ($interceptorFunction && method_exists($this->helper, $interceptorFunction)) {
                                        $data = $this->helper->{$interceptorFunction}($row);
                                    }

                                    $this->handleInsert(
                                        $nodeSecondaryDatabase,
                                        $nodeSecondaryTable,
                                        $columnMappings,
                                        $data
                                    );
                                    break;

                                case DeleteRowsDTO::class:
                                    $data = $row;

                                    if ($interceptorFunction && method_exists($this->helper, $interceptorFunction)) {
                                        $data = $this->helper->{$interceptorFunction}($row);
                                    }

                                    $this->handleDelete(
                                        $nodePrimaryReferenceKey,
                                        $nodeSecondaryDatabase,
                                        $nodeSecondaryTable,
                                        $nodeSecondaryReferenceKey,
                                        $data
                                    );
                                    break;
                            }
                        }

                        $binLogInfo = $event->getEventInfo()->binLogCurrent;

                        $envDB = env('REPLICATOR_DB');
                        DB::update(
                            "UPDATE {$envDB}.settings SET {$envDB}.settings.json_binlog = :json_binlog;",
                            ['json_binlog' => json_encode(['file' => $binLogInfo->getBinFileName(), 'position' => $binLogInfo->getBinLogPosition()])]
                        );

                    }
                }
            }

            private function getChangedColumns(EventDTO $event): array
            {
                $changedColumns = [];

                foreach ($event->values as $row) {
                    switch ($event::class) {
                        case UpdateRowsDTO::class:
                            $changedColumns = array_merge($changedColumns, array_keys(array_diff_assoc($row['after'], $row['before'])));
                            break;
                        case WriteRowsDTO::class:
                            $changedColumns = array_merge($changedColumns, array_keys($row));
                            break;
                        case DeleteRowsDTO::class:
                            $changedColumns = array_merge($changedColumns, array_keys($row['values'] ?? $row['before'] ?? $row));
                            break;
                    }
                }

                return array_unique($changedColumns);
            }

            private function handleUpdate(
                string $nodePrimaryReferenceKey,
                string $nodeSecondaryDatabase,
                string $nodeSecondaryTable,
                string $nodeSecondaryReferenceKey,
                array  $columnMappings,
                array  $before,
                array  $after
            ): void
            {
                $changedColumns = [];
                foreach ($columnMappings as $nodePrimaryColumn => $nodeSecondaryColumn) {
                    if ($before[$nodePrimaryColumn] !== $after[$nodePrimaryColumn]) {
                        $changedColumns[$nodeSecondaryColumn] = $after[$nodePrimaryColumn];
                    }
                }

                if (!empty($changedColumns)) {
                    $referenceKeyValue = $after[$nodePrimaryReferenceKey];

                    $binds = array_combine(
                        array_map(fn($column) => ":{$column}", array_keys($changedColumns)),
                        array_values($changedColumns)
                    );
                    $binds[":{$nodeSecondaryReferenceKey}"] = $referenceKeyValue;

                    $replicateTag = Event::REPLICATION_QUERY;
                    $clausule = implode(', ', array_map(function ($column) use ($nodeSecondaryDatabase, $nodeSecondaryTable) {
                        return "{$nodeSecondaryDatabase}.{$nodeSecondaryTable}.{$column} = :{$column}";
                    }, array_keys($changedColumns)));

                    DB::update(
                        "UPDATE {$nodeSecondaryDatabase}.{$nodeSecondaryTable}
                        SET {$clausule}
                        WHERE {$nodeSecondaryDatabase}.{$nodeSecondaryTable}.{$nodeSecondaryReferenceKey} = :{$nodeSecondaryReferenceKey} {$replicateTag};",
                        $binds
                    );

                }
            }

            private function handleInsert(
                string $nodeSecondaryDatabase,
                string $nodeSecondaryTable,
                array  $columnMappings,
                array  $data
            ): void
            {
                /*
                 * Mapear os nomes das colunas conforme o $columnMappings.
                 * Isso garante que colunas com nomes diferentes sejam replicadas corretamente.
                 *  */
                $mappedData = [];
                foreach ($data as $column => $value) {
                    if (isset($columnMappings[$column])) {
                        $mappedData[$columnMappings[$column]] = $value;
                    } else {
                        $mappedData[$column] = $value;
                    }
                }

                $replicateTag = Event::REPLICATION_QUERY;
                $binds = array_map(function ($value) {
                    return is_null($value) ? null : $value;
                }, $mappedData);

                $columns = implode(',', array_keys($mappedData));
                $placeholders = implode(',', array_map(fn($column) => ":{$column}", array_keys($mappedData)));

                $sql = "INSERT INTO {$nodeSecondaryDatabase}.{$nodeSecondaryTable} ({$columns}) VALUES ({$placeholders}) {$replicateTag};";

                DB::insert(
                    $sql,
                    $binds
                );

            }

            private function handleDelete(
                string $nodePrimaryReferenceKey,
                string $nodeSecondaryDatabase,
                string $nodeSecondaryTable,
                string $nodeSecondaryReferenceKey,
                array  $data
            ): void
            {

                $referenceKeyValue = $data[$nodePrimaryReferenceKey];

                $replicateTag = Event::REPLICATION_QUERY;
                DB::delete(
                    "DELETE FROM {$nodeSecondaryDatabase}.{$nodeSecondaryTable} WHERE {$nodeSecondaryDatabase}.{$nodeSecondaryTable}.{$nodeSecondaryReferenceKey} = :{$nodeSecondaryReferenceKey} {$replicateTag};",
                    [":{$nodeSecondaryReferenceKey}" => $referenceKeyValue]
                );

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
                ConstEventType::MARIA_ANNOTATE_ROWS_EVENT
            ])
            ->withDatabasesOnly($databases)
            ->withTablesOnly($tables);

        $envDB = env('REPLICATOR_DB');
        $lastBinlogChange = json_decode(DB::selectOne("SELECT {$envDB}.settings.json_binlog from {$envDB}.settings")->json_binlog, true);

        if (!empty($lastBinlogChange['file']) && !empty($lastBinlogChange['position'])) {
            $builder->withBinLogFileName($lastBinlogChange['file'])
                ->withBinLogPosition($lastBinlogChange['position']);
        }

        $replication = new MySQLReplicationFactory($builder->build());
        $replication->registerSubscriber($registration);

        $replication->run();
    }
}
