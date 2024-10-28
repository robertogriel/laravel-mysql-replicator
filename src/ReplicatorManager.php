<?php

namespace robertogriel\Replicator;

use Illuminate\Support\Facades\DB;
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
    protected string $host;
    protected string $user;
    protected string $password;

    public function __construct(
        array $databases,
        string $host,
        string $user,
        string $password,
        array $tables,
        int $slaveId,
        callable $callback
    ) {
        $this->databases = $databases;
        $this->tables = $tables;
        $this->callback = $callback;
        $this->cacheKey = str_replace(',', '_', implode(',', $tables));
        $this->slaveId = $slaveId;
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
    }

    public function runReplication(): void
    {
        // Exibe detalhes da conexão para depuração
        echo "Tentando conexão com host: {$this->host}, usuário: {$this->user}" . PHP_EOL;

        // Configura a conexão com a biblioteca php-mysql-replication
        $builder = (new ConfigBuilder())
            ->withPort(3306)
            ->withHost($this->host)
            ->withUser($this->user)
            ->withPassword($this->password)
            ->withEventsOnly([ConstEventType::UPDATE_ROWS_EVENT_V1])
            ->withDatabasesOnly($this->databases)
            ->withTablesOnly($this->tables)
            ->withSlaveId($this->slaveId);

        try {
            $replication = new MySQLReplicationFactory($builder->build());
            echo 'Conexão com o MySQL estabelecida pela biblioteca php-mysql-replication' . PHP_EOL;
        } catch (\Exception $e) {
            echo 'Erro ao conectar com a biblioteca php-mysql-replication: ' . $e->getMessage() . PHP_EOL;
            return;
        }

        // Continua com o processo de replicação
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

                    echo $rawQuery . PHP_EOL;

                    if ($rawQuery && mb_strpos($rawQuery, '/* isReplicating */') !== false) {
                        ArrayCache::setRawQuery('');
                        return;
                    }
                }
            }
        );

        $replication->run();
    }

    public function syncData(string $table, string $database, array $before, array $after, array $config)
    {
        $syncTable = $config['sync']['table'];
        $primaryKey = $config['sync']['primary_key'];
        $mappedColumns = $config['sync']['columns'];

        $queryData = [];

        foreach ($mappedColumns as $sourceColumn => $targetColumn) {
            $queryData[$targetColumn] = $after[$sourceColumn] ?? null;
        }

        DB::connection($config['sync']['database'])
            ->table($syncTable)
            ->where($primaryKey, $before[$config['primary_key']])
            ->update($queryData);
    }
}
