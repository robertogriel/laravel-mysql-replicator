<?php

namespace robertogriel\Replicator\Logging;

class ReplicationLogger
{
    public static function log(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
