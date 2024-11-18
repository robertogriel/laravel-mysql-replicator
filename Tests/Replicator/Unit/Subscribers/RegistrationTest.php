<?php
/*

use MySQLReplication\Repository\FieldDTO;
use robertogriel\Replicator\Subscribers\Registration;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\RowEvent\TableMap;
use MySQLReplication\Event\RowEvent\ColumnDTO;
use MySQLReplication\Event\RowEvent\ColumnDTOCollection;
use MySQLReplication\BinLog\BinLogCurrent;
use robertogriel\Replicator\Database\DatabaseService;
use robertogriel\Replicator\Handlers\InsertHandler;
use robertogriel\Replicator\Handlers\UpdateHandler;
use robertogriel\Replicator\Handlers\DeleteHandler;
use robertogriel\Replicator\Helpers\ChangedColumns;

beforeEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB=replicator');
});

afterEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB');
});

test('allEvents handles WriteRowsDTO event correctly', function () {
    $columnDTOCollection = new ColumnDTOCollection([
        new ColumnDTO(new FieldDTO('id', 'primary_db', 'primary_table', 'INT', true, false), 1, 255, 10, 0, 0, 0, 0, 0, 0),
        new ColumnDTO(new FieldDTO('name', 'primary_db', 'primary_table', 'VARCHAR', true, false), 1, 255, 10, 0, 0, 0, 0, 0, 0),
    ]);

    $tableMap = new TableMap(
        'primary_db',
        'primary_table',
        '12345',
        2,
        $columnDTOCollection
    );

    $binLogCurrentMock = Mockery::mock(BinLogCurrent::class);
    $binLogCurrentMock->shouldReceive('getBinFileName')->andReturn('file');
    $binLogCurrentMock->shouldReceive('getBinLogPosition')->andReturn(12345);
    $binLogCurrentMock->shouldReceive('setBinLogPosition')->once()->with('position');

    $eventInfo = new EventInfo(
        12345,
        1,
        1,
        10,
        'position',
        1,
        true,
        $binLogCurrentMock
    );

    $eventMock = Mockery::mock(WriteRowsDTO::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $eventMock->__construct(
        $eventInfo,
        $tableMap,
        3,
        [['id' => 123, 'name' => 'John Doe']]
    );

    $eventMock->shouldReceive('getTableMap')->andReturn($tableMap)->atLeast()->once();

    $config = [
        [
            'node_primary' => ['database' => 'primary_db', 'table' => 'primary_table', 'reference_key' => 'id'],
            'node_secondary' => ['database' => 'secondary_db', 'table' => 'secondary_table', 'reference_key' => 'user_id'],
            'columns' => ['id' => 'user_id', 'name' => 'user_name'],
            'interceptor' => false,
        ],
    ];

    $changedColumnsStub = Mockery::mock(ChangedColumns::class);
    $changedColumnsStub->shouldReceive('getChangedColumns')
        ->once()
        ->with($eventMock)
        ->andReturn(['id', 'name']);

    $databaseServiceStub = Mockery::mock(DatabaseService::class);
    $databaseServiceStub->shouldReceive('updateBinlogPosition')
        ->once()
        ->with('file', 12345);

    $insertHandlerStub = Mockery::mock(InsertHandler::class);
    $insertHandlerStub->shouldReceive('handle')
        ->once()
        ->with('secondary_db', 'secondary_table', ['id' => 'user_id', 'name' => 'user_name'], ['id' => 123, 'name' => 'John Doe']);

    $updateHandlerStub = Mockery::mock(UpdateHandler::class);
    $updateHandlerStub->shouldReceive('handle')->never();

    $deleteHandlerStub = Mockery::mock(DeleteHandler::class);
    $deleteHandlerStub->shouldReceive('handle')->never();

    $registration = new Registration(
        $config,
        $changedColumnsStub,
        $databaseServiceStub,
        $insertHandlerStub,
        $updateHandlerStub,
        $deleteHandlerStub
    );

    $registration->allEvents($eventMock);

    expect(true)->toBeTrue();
});






//test('allEvents handles UpdateRowsDTO event correctly', function () {
//    $columnDTOCollection = new ColumnDTOCollection([
//        new ColumnDTO(new \MySQLReplication\Repository\FieldDTO('id', 'primary_db', 'primary_table', 'INT', true, false), 1, 255, 10, 0, 0, 0, 0, 0, 0),
//        new ColumnDTO(new \MySQLReplication\Repository\FieldDTO('name', 'primary_db', 'primary_table', 'VARCHAR', true, false), 1, 255, 10, 0, 0, 0, 0, 0, 0),
//    ]);
//
//    $tableMap = new TableMap(
//        'primary_db',
//        'primary_table',
//        '12345',
//        2,
//        $columnDTOCollection
//    );
//
//    $eventMock = Mockery::mock(UpdateRowsDTO::class)
//        ->makePartial()
//        ->shouldAllowMockingProtectedMethods();
//    $eventMock->__construct(
//        new EventInfo(12345, 1, 1, 10, 'position', 1, true, new \MySQLReplication\BinLog\BinLogCurrent('file', 12345)),
//        $tableMap,
//        1,
//        [['before' => ['name' => 'John'], 'after' => ['name' => 'John Doe']]]
//    );
//
//    $config = [
//        [
//            'node_primary' => ['database' => 'primary_db', 'table' => 'primary_table', 'reference_key' => 'id'],
//            'node_secondary' => ['database' => 'secondary_db', 'table' => 'secondary_table', 'reference_key' => 'user_id'],
//            'columns' => ['id' => 'user_id', 'name' => 'user_name'],
//            'interceptor' => false,
//        ],
//    ];
//
//    $registration = new Registration($config);
//
//    Mockery::mock('overload:robertogriel\Replicator\Helpers\ChangedColumns')
//        ->shouldReceive('getChangedColumns')
//        ->once()
//        ->andReturn(['name']);
//
//    Mockery::mock('overload:robertogriel\Replicator\Handlers\UpdateHandler')
//        ->shouldReceive('handle')
//        ->once()
//        ->with(
//            'id',
//            'secondary_db',
//            'secondary_table',
//            'user_id',
//            ['id' => 'user_id', 'name' => 'user_name'],
//            ['before' => ['name' => 'John'], 'after' => ['name' => 'John Doe']]
//        );
//
//    Mockery::mock('overload:robertogriel\Replicator\Database\DatabaseService')
//        ->shouldReceive('updateBinlogPosition')
//        ->once()
//        ->with('file', 12345);
//
//    $registration->allEvents($eventMock);
//
//    expect(true)->toBeTrue();
//});
//
//test('allEvents handles DeleteRowsDTO event correctly', function () {
//    $columnDTOCollection = new ColumnDTOCollection([
//        new ColumnDTO(new \MySQLReplication\Repository\FieldDTO('id', 'primary_db', 'primary_table', 'INT', true, false), 1, 255, 10, 0, 0, 0, 0, 0, 0),
//        new ColumnDTO(new \MySQLReplication\Repository\FieldDTO('name', 'primary_db', 'primary_table', 'VARCHAR', true, false), 1, 255, 10, 0, 0, 0, 0, 0, 0),
//    ]);
//
//    $tableMap = new TableMap(
//        'primary_db',
//        'primary_table',
//        '12345',
//        2,
//        $columnDTOCollection
//    );
//
//    $eventMock = Mockery::mock(DeleteRowsDTO::class)
//        ->makePartial()
//        ->shouldAllowMockingProtectedMethods();
//    $eventMock->__construct(
//        new EventInfo(12345, 1, 1, 10, 'position', 1, true, new \MySQLReplication\BinLog\BinLogCurrent('file', 12345)),
//        $tableMap,
//        1,
//        [['id' => 123, 'name' => 'John Doe']]
//    );
//
//    $config = [
//        [
//            'node_primary' => ['database' => 'primary_db', 'table' => 'primary_table', 'reference_key' => 'id'],
//            'node_secondary' => ['database' => 'secondary_db', 'table' => 'secondary_table', 'reference_key' => 'user_id'],
//            'columns' => ['id' => 'user_id', 'name' => 'user_name'],
//            'interceptor' => false,
//        ],
//    ];
//
//    $registration = new Registration($config);
//
//    Mockery::mock('overload:robertogriel\Replicator\Helpers\ChangedColumns')
//        ->shouldReceive('getChangedColumns')
//        ->once()
//        ->andReturn(['id', 'name']);
//
//    Mockery::mock('overload:robertogriel\Replicator\Handlers\DeleteHandler')
//        ->shouldReceive('handle')
//        ->once()
//        ->with('secondary_db', 'secondary_table', 'id', 'user_id', ['id' => 123, 'name' => 'John Doe']);
//
//    Mockery::mock('overload:robertogriel\Replicator\Database\DatabaseService')
//        ->shouldReceive('updateBinlogPosition')
//        ->once()
//        ->with('file', 12345);
//
//    $registration->allEvents($eventMock);
//
//    expect(true)->toBeTrue();
//});
*/
