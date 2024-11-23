<?php

namespace robertogriel\Replicator\Handlers;

use robertogriel\Replicator\Database\DatabaseService;

class DeleteHandler
{
    public static function handle(
        string $nodeSecondaryDatabase,
        string $nodeSecondaryTable,
        string $nodePrimaryReferenceKey,
        string $nodeSecondaryReferenceKey,
        array $data
    ): void {
        $referenceKeyValue = $data[$nodePrimaryReferenceKey];

        $binds = [":{$nodeSecondaryReferenceKey}" => $referenceKeyValue];

        $databaseHandler = new DatabaseService();
        $databaseHandler->delete($nodeSecondaryDatabase, $nodeSecondaryTable, $nodeSecondaryReferenceKey, $binds);
    }
}
