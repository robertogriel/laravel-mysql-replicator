<?php

namespace robertogriel\Replicator\Binlog;

use robertogriel\Replicator\Database\DatabaseService;

class BinlogManager
{
    public static function getLastBinlogPosition(): ?array
    {
        $databaseService = new DatabaseService();
        $lastBinlogChange = $databaseService->getLastBinlogPosition();

        return $lastBinlogChange;
    }

    public static function updateBinlogPosition(string $fileName, int $position): void
    {
        $databaseService = new DatabaseService();
        $databaseService->updateBinlogPosition($fileName, $position);
    }
}
