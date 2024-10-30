<?php

namespace robertogriel\Replicator\Console\Commands;

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
    public const CACHE_ULTIMA_ALTERACAO = 'replicador.colaborador_loja.ultima_alteracao';

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

        $registro = new class($configurations) extends EventSubscribers {
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

                        $sourcePrimaryKey = $sourceConfig['reference_key'];
                        $targetDatabase = $targetConfig['database'];
                        $targetTable = $targetConfig['table'];
                        $targetPrimaryKey = $targetConfig['reference_key'];

                        foreach ($event->values as $row) {

                            switch ($event::class) {
                                case UpdateRowsDTO::class:

                                    $before = $row['before'];
                                    $after = $row['after'];

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
                                    $this->handleInsert(
                                        $targetDatabase,
                                        $targetTable,
                                        $row
                                    );
                                    break;

                                case DeleteRowsDTO::class:
                                    $this->handleDelete(
                                        $targetDatabase,
                                        $targetTable,
                                        $targetPrimaryKey,
                                        $row
                                    );
                                    break;
                            }
                        }
                    }
                }
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
                        $changedColumns[$targetColumn] = $after[$sourceColumn];
                    }
                }

                if (!empty($changedColumns)) {
                    $primaryKeyValue = $after[$sourcePrimaryKey];

                    $binds = [];
                    foreach ($changedColumns as $column => $value) {
                        $binds[":{$column}"] = $value;
                    }
                    $binds[":{$targetPrimaryKey}"] = $primaryKeyValue;

                    $replicateTag = Event::REPLICATION_QUERY;

                    DB::update("UPDATE {$targetDatabase}.{$targetTable}
                        SET " . implode(', ', array_map(function ($column) use ($targetDatabase, $targetTable) {
                            return "{$targetDatabase}.{$targetTable}.{$column} = :{$column}";
                        }, array_keys($changedColumns))) . "
                        WHERE {$targetDatabase}.{$targetTable}.{$targetPrimaryKey} = :{$targetPrimaryKey} {$replicateTag};",
                        $binds);
                }
            }

            private function handleInsert(
                string $targetDatabase,
                string $targetTable,
                array  $data
            ): void
            {
                if (!empty($data)) {
                    DB::statement('SET SESSION sql_log_bin=0;');

                    DB::table("{$targetDatabase}.{$targetTable}")
                        ->insert($data);

                    DB::statement('SET SESSION sql_log_bin=1;');
                }
            }

            private function handleDelete(string $targetDatabase, string $targetTable, string $targetPrimaryKey, array $data): void
            {

                $primaryKeyValue = $data[$targetPrimaryKey];

                DB::table("{$targetDatabase}.{$targetTable}")
                    ->where($targetPrimaryKey, $primaryKeyValue)
                    ->delete();

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
