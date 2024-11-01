<?php

namespace robertogriel\Replicator\Console\Commands;

use App\Helpers\ReplicatorHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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

        $registration = new class($configurations) extends EventSubscribers {
            private ReplicatorHelper $helper;
            private array $configurations;
            protected const CACHED_LAST_CHANGES = 'replicator_last_changes';

            public function __construct(array $configurations)
            {
                $this->helper = new ReplicatorHelper();
                $this->configurations = $configurations;
            }

            public function allEvents(EventDTO $event): void
            {

                if (!($event instanceof WriteRowsDTO || $event instanceof UpdateRowsDTO || $event instanceof DeleteRowsDTO)) {
                    return;
                }

                $database = $event->tableMap->database;
                $table = $event->tableMap->table;

                $binLogInfo = $event->getEventInfo()->binLogCurrent;
                Cache::put(
                    self::CACHED_LAST_CHANGES,
                    [
                        'position' => $binLogInfo->getBinLogPosition(),
                        'file' => $binLogInfo->getBinFileName(),
                    ],
                    60 * 60 * 24
                );

                foreach ($this->configurations as $config) {
                    $sourceDatabase = $config['database'];
                    $sourceTable = $config['table'];
                    $targetDatabase = $config['sync']['database'];
                    $targetTable = $config['sync']['table'];

                    if (
                        ($database === $sourceDatabase && $table === $sourceTable) ||
                        ($database === $targetDatabase && $table === $targetTable)
                    ) {

                        if (
                            $event->tableMap->database === $config['database'] &&
                            $event->tableMap->table === $config['table']
                        ) {
                            $sourceConfig = $config;
                            $targetConfig = $config['sync'];
                            $columnMappings = $config['sync']['columns'];
                        } else {
                            $sourceConfig = $config['sync'];
                            $targetConfig = $config;
                            $columnMappings = array_flip($config['sync']['columns']);
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

                        $interceptFunction = $sourceConfig['intercept'] ?? $targetConfig['intercept'] ?? false;

                        foreach ($event->values as $row) {
                            switch ($event::class) {
                                case UpdateRowsDTO::class:
                                    $before = $row['before'];
                                    $after = $row['after'];

                                    if ($interceptFunction && method_exists($this->helper, $interceptFunction)) {
                                        $after = $this->helper->{$interceptFunction}($after);
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

                                    if ($interceptFunction && method_exists($this->helper, $interceptFunction)) {
                                        $data = $this->helper->{$interceptFunction}($row);
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

                                    if ($interceptFunction && method_exists($this->helper, $interceptFunction)) {
                                        $data = $this->helper->{$interceptFunction}($row);
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
                    }
                }
            }

            private function getChangedColumns(EventDTO $event): array
            {
                $changedColumns = [];

                if ($event instanceof UpdateRowsDTO) {
                    foreach ($event->values as $row) {
                        $before = $row['before'];
                        $after = $row['after'];

                        foreach ($before as $column => $value) {
                            if ($before[$column] !== $after[$column]) {
                                $changedColumns[] = $column;
                            }
                        }
                    }
                } elseif ($event instanceof WriteRowsDTO) {
                    foreach ($event->values as $row) {
                        $changedColumns = array_keys($row);
                    }
                } elseif ($event instanceof DeleteRowsDTO) {
                    foreach ($event->values as $row) {
                        $changedColumns = array_keys($row['values'] ?? $row['before'] ?? $row);
                    }
                }

                return $changedColumns;
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

                    if (array_diff_assoc($before, $after)) {
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
