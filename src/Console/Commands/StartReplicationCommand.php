<?php

namespace robertogriel\Replicator\Console\Commands;

use Illuminate\Console\Command;
use robertogriel\Replicator\Facades\Replicator;

class StartReplicationCommand extends Command
{
    protected $signature = 'replicator:start';
    protected $description = 'Inicia o processo de replicação configurado no pacote Replicator';

    public function handle()
    {
        echo 'Caiu aqui' . PHP_EOL;
        Replicator::startReplication();
    }
}
