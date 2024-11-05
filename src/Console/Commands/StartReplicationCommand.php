<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
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
            $databases[] = $config['source']['database'];
            $databases[] = $config['target']['database'];
            $tables[] = $config['source']['table'];
            $tables[] = $config['target']['table'];
            $columns = array_merge($columns, array_keys($config['columns']));
        }

        $databases = array_unique($databases);
        $tables = array_unique($tables);

        $registration = new class($configurations) extends EventSubscribers {
            private array $configurations;
            protected const CACHED_LAST_CHANGES = 'replicator_last_changes';

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
                    $sourceDatabase = $config['source']['database'];
                    $sourceTable = $config['source']['table'];
                    $targetDatabase = $config['target']['database'];
                    $targetTable = $config['target']['table'];

                    if (
                        ($database === $sourceDatabase && $table === $sourceTable) ||
                        ($database === $targetDatabase && $table === $targetTable)
                    ) {

                        if (
                            $event->tableMap->database === $sourceDatabase &&
                            $event->tableMap->table === $sourceTable
                        ) {
                            $sourceConfig = $config['source'];
                            $targetConfig = $config['target'];
                            $columnMappings = $config['columns'];
                        } else {
                            $sourceConfig = $config['target'];
                            $targetConfig = $config['source'];
                            $columnMappings = array_flip($config['columns']);
                        }

                        $configuredColumns = array_keys($columnMappings);

                        $changedColumns = $this->getChangedColumns($event);

                        if (empty(array_intersect($configuredColumns, $changedColumns))) {
                            continue;
                        }

                        $sourcePrimaryKey = $sourceConfig['reference_key'];
                        $targetDatabase = $targetConfig['database'];
                        $targetTable = $targetConfig['table'];
                        $targetPrimaryKey = $targetConfig['reference_key'];

                        $interceptorFunction = $config['interceptor'] ?? false;

                        foreach ($event->values as $row) {
                            switch ($event::class) {
                                case UpdateRowsDTO::class:
                                    $before = $row['before'];
                                    $after = $row['after'];

                                    if ($interceptorFunction) {
                                        $after = App::call($interceptorFunction, [
                                            'data' => $after,
                                            'sourceTable' => $sourceTable,
                                            'sourceDatabase' => $sourceDatabase,
                                        ]);
                                    }

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

                                case WriteRowsDTO::class:
                                    $data = $row;

                                    if ($interceptorFunction && method_exists($this->helper, $interceptorFunction)) {
                                        $data = $this->helper->{$interceptorFunction}($row);
                                    }

                                    $this->handleInsert(
                                        $targetDatabase,
                                        $targetTable,
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
                                        $sourcePrimaryKey,
                                        $targetDatabase,
                                        $targetTable,
                                        $targetPrimaryKey,
                                        $data
                                    );
                                    break;
                            }
                        }

                        $binLogInfo = $event->getEventInfo()->binLogCurrent;
                        Cache::put(
                            self::CACHED_LAST_CHANGES,
                            [
                                'position' => $binLogInfo->getBinLogPosition(),
                                'file' => $binLogInfo->getBinFileName(),
                            ],
                            60 * 60 * 24
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
                string $sourcePrimaryKey,
                string $targetDatabase,
                string $targetTable,
                string $targetPrimaryKey,
                array  $columnMappings,
                array  $before,
                array  $after
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

                    $binds = array_combine(
                        array_map(fn($column) => ":{$column}", array_keys($changedColumns)),
                        array_values($changedColumns)
                    );
                    $binds[":{$targetPrimaryKey}"] = $primaryKeyValue;

                    $replicateTag = Event::REPLICATION_QUERY;
                    $clausule = implode(', ', array_map(function ($column) use ($targetDatabase, $targetTable) {
                        return "{$targetDatabase}.{$targetTable}.{$column} = :{$column}";
                    }, array_keys($changedColumns)));

                    DB::update(
                        "UPDATE {$targetDatabase}.{$targetTable}
                        SET {$clausule}
                        WHERE {$targetDatabase}.{$targetTable}.{$targetPrimaryKey} = :{$targetPrimaryKey} {$replicateTag};",
                        $binds
                    );

                }
            }

            private function handleInsert(
                string $targetDatabase,
                string $targetTable,
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

                $sql = "INSERT INTO {$targetDatabase}.{$targetTable} ({$columns}) VALUES ({$placeholders}) {$replicateTag};";

                DB::insert(
                    $sql,
                    $binds
                );

            }

            private function handleDelete(
                string $sourcePrimaryKey,
                string $targetDatabase,
                string $targetTable,
                string $targetPrimaryKey,
                array  $data
            ): void
            {

                $primaryKeyValue = $data[$sourcePrimaryKey];

                $replicateTag = Event::REPLICATION_QUERY;
                DB::delete(
                    "DELETE FROM {$targetDatabase}.{$targetTable} WHERE {$targetDatabase}.{$targetTable}.{$targetPrimaryKey} = :{$targetPrimaryKey} {$replicateTag};",
                    [":{$targetPrimaryKey}" => $primaryKeyValue]
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

        $lastBinlogChange = Cache::get(self::CACHED_LAST_CHANGES);
        if (!empty($lastBinlogChange)) {
            $builder->withBinLogFileName($lastBinlogChange['file'])
                ->withBinLogPosition($lastBinlogChange['position']);
        }

        $replication = new MySQLReplicationFactory($builder->build());
        $replication->registerSubscriber($registration);

        $replication->run();
    }
}
