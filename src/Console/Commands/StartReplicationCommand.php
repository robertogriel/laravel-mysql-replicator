<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MySQLReplication\Cache\ArrayCache;
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
        $configuracoes = config('replicator');

        $databases = [];
        $tables = [];

        foreach ($configuracoes as $config) {
            $databases[] = $config['database'];
            $tables[] = $config['table'];
        }

        $databases = array_unique($databases);
        $tables = array_unique($tables);

        $registro = new class extends EventSubscribers {
            public function allEvents(EventDTO $evento): void
            {
                $rawQuery = ArrayCache::getRawQuery();
                echo $rawQuery . PHP_EOL;
            }
        };

        $builder = (new ConfigBuilder())
            ->withHost(env('DB_HOST'))
            ->withPort(3306)
            ->withUser(env('REPLICADOR_DB_USERNAME'))
            ->withPassword(env('REPLICADOR_DB_PASSWORD'))
            ->withEventsOnly([ConstEventType::UPDATE_ROWS_EVENT_V1])
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
