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
        $configurations = config('replicator');

        $databases = [];
        $tables = [];

        foreach ($configurations as $config) {
            $databases[] = $config['database'];
            $tables[] = $config['table'];
        }

        $databases = array_unique($databases);
        $tables = array_unique($tables);

        $registro = new class extends EventSubscribers {
            public function allEvents(EventDTO $event): void
            {
                $rawQuery = ArrayCache::getRawQuery();
                echo $rawQuery . PHP_EOL;

                echo "Evento: " . $event->getType() . PHP_EOL;

                /*
                 * $event->getType() returns:
                 * - update
                 * - write
                 * - delete

                */
            }
        };

        $builder = (new ConfigBuilder())
            ->withHost(env('DB_HOST'))
            ->withPort(env('DB_PORT'))
            ->withUser(env('REPLICADOR_DB_USERNAME'))
            ->withPassword(env('REPLICADOR_DB_PASSWORD'))
            ->withEventsOnly([ConstEventType::UPDATE_ROWS_EVENT_V1, ConstEventType::WRITE_ROWS_EVENT_V1, ConstEventType::DELETE_ROWS_EVENT_V1])
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
