<?php

namespace robertogriel\Replicator\Handlers;

use robertogriel\Replicator\Database\DatabaseService;

class InsertHandler
{
    public static function handle(
        string $nodeSecondaryDatabase,
        string $nodeSecondaryTable,
        array $columnMappings,
        array $data
    ): void {
        $mappedData = [];
        foreach ($data as $column => $value) {
            if (isset($columnMappings[$column])) {
                $mappedData[$columnMappings[$column]] = $value;
            } else {
                $mappedData[$column] = $value;
            }
        }

        $binds = array_map(function ($value) {
            return is_null($value) ? null : $value;
        }, $mappedData);

        $columns = implode(',', array_keys($mappedData));
        $placeholders = implode(',', array_map(fn($column) => ":{$column}", array_keys($mappedData)));

        $databaseHandler = new DatabaseService();
        $databaseHandler->insert($nodeSecondaryDatabase, $nodeSecondaryTable, $columns, $placeholders, $binds);

    }
}
