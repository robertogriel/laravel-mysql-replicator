<?php

use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Event\RowEvent\TableMap;
use MySQLReplication\Event\RowEvent\ColumnDTO;
use MySQLReplication\Repository\FieldDTO;
use MySQLReplication\Event\RowEvent\ColumnDTOCollection;
use robertogriel\Replicator\Helpers\ChangedColumns;

test('should get changed rows on update correctly', function () {
    $binLogCurrent = new BinLogCurrent();
    $eventInfo = new EventInfo(
        1699862400,
        1,
        1,
        100,
        '12345',
        0,
        true,
        $binLogCurrent
    );

    $columns = ['name', 'surname'];
    $columnDTOCollection = new ColumnDTOCollection(array_map(function($col) {
        $fieldDTO = new FieldDTO($col, 'test_database', 'test_table', 'VARCHAR', true, false);
        return new ColumnDTO(
            $fieldDTO,
            1,
            255,
            10,
            0,
            0,
            0,
            0,
            0,
            0
        );
    }, $columns));

    $tableMap = new TableMap(
        1,
        'test_database',
        'test_table',
        count($columns),
        $columnDTOCollection
    );

    $event = new UpdateRowsDTO(
        $eventInfo,
        $tableMap,
        1,
        [
            [
                'before' => ['name' => 'ddThor', 'surname' => 'Odinson'],
                'after' => ['name' => 'ddThor', 'surname' => 'new_Odinson'],
            ],
        ],
    );

    $changedColumns = ChangedColumns::getChangedColumns($event);

    expect($changedColumns)
        ->toBeArray()
        ->toEqual(['surname']);
});

test('should get changed rows on write correctly', function () {
    $binLogCurrent = new BinLogCurrent();
    $eventInfo = new EventInfo(
        1699862400,
        1,
        1,
        100,
        '12345',
        0,
        true,
        $binLogCurrent
    );

    $columns = ['name', 'surname'];
    $columnDTOCollection = new ColumnDTOCollection(array_map(function($col) {
        $fieldDTO = new FieldDTO($col, 'test_database', 'test_table', 'VARCHAR', true, false);
        return new ColumnDTO(
            $fieldDTO,
            1,
            255,
            10,
            0,
            0,
            0,
            0,
            0,
            0
        );
    }, $columns));

    $tableMap = new TableMap(
        1,
        'test_database',
        'test_table',
        count($columns),
        $columnDTOCollection
    );

    $event = new WriteRowsDTO(
        $eventInfo,
        $tableMap,
        1,
        [
            ['name' => 'ddThor', 'surname' => 'Odinson'],
        ]
    );

    $changedColumns = ChangedColumns::getChangedColumns($event);

    expect($changedColumns)
        ->toBeArray()
        ->toEqual(['name', 'surname']);
});

test('should get changed rows on delete correctly', function () {
    $binLogCurrent = new BinLogCurrent();
    $eventInfo = new EventInfo(
        1699862400,
        1,
        1,
        100,
        '12345',
        0,
        true,
        $binLogCurrent
    );

    $columns = ['name', 'surname'];
    $columnDTOCollection = new ColumnDTOCollection(array_map(function($col) {
        $fieldDTO = new FieldDTO($col, 'test_database', 'test_table', 'VARCHAR', true, false);
        return new ColumnDTO(
            $fieldDTO,
            1,
            255,
            10,
            0,
            0,
            0,
            0,
            0,
            0
        );
    }, $columns));

    $tableMap = new TableMap(
        1,
        'test_database',
        'test_table',
        count($columns),
        $columnDTOCollection
    );

    $event = new DeleteRowsDTO(
        $eventInfo,
        $tableMap,
        1,
        [
            'before' => ['name' => 'ddThor', 'surname' => 'Odinson'],
        ]
    );

    $changedColumns = ChangedColumns::getChangedColumns($event);

    expect($changedColumns)
        ->toBeArray()
        ->toEqual(['name', 'surname']);
});
