<?php

use Illuminate\Support\Facades\DB;
use robertogriel\Replicator\Handlers\InsertHandler;

beforeEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB=replicator');
});

test('should method successfully inserts data', function () {
    $nodeSecondaryDatabase = 'secondary_db';
    $nodeSecondaryTable = 'users';
    $columnMappings = ['id' => 'user_id', 'name' => 'user_name'];
    $data = ['id' => 1932, 'name' => 'Les Ismore'];

    $expectedSql = 'INSERT INTO secondary_db.users (user_id,user_name) VALUES (:user_id,:user_name) /* isReplicating */;';
    $expectedBinds = [
        ':user_id' => 1932,
        ':user_name' => 'Les Ismore',
    ];

    DB::shouldReceive('insert')
        ->once()
        ->with($expectedSql, $expectedBinds)
        ->andReturnTrue();

    InsertHandler::handle($nodeSecondaryDatabase, $nodeSecondaryTable, $columnMappings, $data);

    expect(true)->toBeTrue();
});

test('should method successfully inserts data without column mappings', function () {
    $nodeSecondaryDatabase = 'secondary_db';
    $nodeSecondaryTable = 'users';
    $columnMappings = [];
    $data = ['id' => 1932, 'name' => 'Les Ismore'];

    $expectedSql = 'INSERT INTO secondary_db.users (id,name) VALUES (:id,:name) /* isReplicating */;';
    $expectedBinds = [
        ':id' => 1932,
        ':name' => 'Les Ismore',
    ];

    DB::shouldReceive('insert')
        ->once()
        ->with($expectedSql, $expectedBinds)
        ->andReturnTrue();

    InsertHandler::handle($nodeSecondaryDatabase, $nodeSecondaryTable, $columnMappings, $data);

    expect(true)->toBeTrue();
});

afterEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB');
});
