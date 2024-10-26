<?php

namespace robertogriel\Replicator;

use Illuminate\Support\Facades\Cache;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\DTO\RowsDTO;
use MySQLReplication\MySQLReplicationFactory;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\EventSubscribers;

class ReplicatorManager
{
    protected array $databases;
    protected array $tables;
    protected string $cacheKey = '';
    protected $callback;
    protected int $slaveId;

    public function __construct(array $databases, array $tables, int $slaveId, callable $callback)
    {
        $this->databases = $databases;
        $this->tables = $tables;
        $this->callback = $callback;
        $this->cacheKey = str_replace(',', '_', implode(',', $tables));
        $this->slaveId = $slaveId;
    }

    public function runReplication(): void
    {
        $builder = (new ConfigBuilder())
            ->withPort(3306)
            ->withHost(env('MYSQL_HOST'))
            ->withUser(env('REPLICADOR_DB_USERNAME'))
            ->withPassword(env('REPLICADOR_DB_PASSWORD'))
            ->withEventsOnly([ConstEventType::UPDATE_ROWS_EVENT_V1])
            ->withDatabasesOnly($this->databases)
            ->withTablesOnly($this->tables)
            ->withSlaveId($this->slaveId);

        $lastChanges = Cache::get($this->cacheKey);
        if (!empty($lastChanges)) {
            $builder->withBinLogFileName($lastChanges['arquivo'])->withBinLogPosition($lastChanges['posicao']);
        }

        $replication = new MySQLReplicationFactory($builder->build());

        $replication->registerSubscriber(
            new class ($this->callback) extends EventSubscribers {
                private $callback;

                public function __construct(callable $callback)
                {
                    $this->callback = $callback;
                }

                public function allEvents(EventDTO $evento): void
                {
                    if ($evento instanceof RowsDTO) {
                        $tabela = $evento->getTableMap()->getTable();
                        $database = $evento->getTableMap()->getDatabase();
                        ['before' => $antes, 'after' => $depois] = current($evento->getValues());
                        call_user_func($this->callback, $tabela, $database, $antes, $depois);
                    }

                    $rawQuery = ArrayCache::getRawQuery();

                    if ($rawQuery && mb_strpos($rawQuery, '/* isReplicating */') !== false) {
                        ArrayCache::setRawQuery('');
                        return;
                    }

                    // TODO: reativar
                    //                    $binlogAtual = $evento->getEventInfo()->getBinLogCurrent();
                    //                    Cache::put(
                    //                        $this->cacheKey,
                    //                        ['posicao' => $binlogAtual->getBinLogPosition(), 'arquivo' => $binlogAtual->getBinFileName()],
                    //                        60 * 60 * 24
                    //                    );
                }
            }
        );

        $replication->run();
    }
}
