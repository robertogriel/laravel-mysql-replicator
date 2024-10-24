<?php

namespace robertogriel\Replicator\Commands;

use Illuminate\Console\Command;

class ReplicatorCommand extends Command
{
    public $signature = 'replicator';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
