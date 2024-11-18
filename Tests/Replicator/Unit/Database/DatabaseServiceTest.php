<?php

use Illuminate\Support\Facades\DB;
use robertogriel\Replicator\Database\DatabaseService;

beforeEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB=replicator');
});

test('should method calls DB::update with correct query and binds', function () {
    $database = 'another_database';
    $table = 'users';
    $clausule = 'name = :name';
    $referenceKey = 'user_id';
    $binds = [':name' => 'Capi Bara', ':user_id' => 1932];

    $expectedSql = "UPDATE {$database}.{$table} SET {$clausule} WHERE {$database}.{$table}.{$referenceKey} = :{$referenceKey} /* isReplicating */;";

    DB::shouldReceive('update')
        ->once()
        ->with($expectedSql, $binds)
        ->andReturn(true);

    $databaseService = new DatabaseService();
    $databaseService->update($database, $table, $clausule, $referenceKey, $binds);

    expect(true)->toBeTrue();
});

test('insert method calls DB::insert with correct query and binds', function () {
    $database = 'secondary_db';
    $table = 'users';
    $columns = 'name,email';
    $placeholders = ':name,:email';
    $binds = [':name' => 'Capi Bara', ':email' => 'capibara@bababoe.com'];

    $expectedSql = "INSERT INTO {$database}.{$table} ({$columns}) VALUES ({$placeholders}) /* isReplicating */;";

    DB::shouldReceive('insert')
        ->once()
        ->with($expectedSql, $binds)
        ->andReturn(true);

    $databaseService = new DatabaseService();
    $databaseService->insert($database, $table, $columns, $placeholders, $binds);

    expect(true)->toBeTrue();
});

test('delete method calls DB::delete with correct query and binds', function () {
    $database = 'secondary_db';
    $table = 'users';
    $referenceKey = 'user_id';
    $binds = [':user_id' => 1932];

    $expectedSql = "DELETE FROM {$database}.{$table} WHERE {$database}.{$table}.{$referenceKey} = :{$referenceKey} /* isReplicating */;";

    DB::shouldReceive('delete')
        ->once()
        ->with($expectedSql, $binds)
        ->andReturn(true);

    $databaseService = new DatabaseService();
    $databaseService->delete($database, $table, $referenceKey, $binds);

    expect(true)->toBeTrue();
});

test('getLastBinlogPosition returns correct binlog position', function () {
    $expectedResult = ['file' => 'mariadb-bin.000070', 'position' => 344];
    DB::shouldReceive('selectOne')
        ->once()
        ->with("SELECT json_binlog FROM replicator.settings")
        ->andReturn((object) ['json_binlog' => json_encode($expectedResult)]);

    $databaseService = new DatabaseService();
    $result = $databaseService->getLastBinlogPosition();

    expect($result)->toEqual($expectedResult);
});

test('getLastBinlogPosition returns null when no binlog position found', function () {
    DB::shouldReceive('selectOne')
        ->once()
        ->with("SELECT json_binlog FROM replicator.settings")
        ->andReturn(null);

    $databaseService = new DatabaseService();
    $result = $databaseService->getLastBinlogPosition();

    expect($result)->toBeNull();
});

test('updateBinlogPosition updates binlog position correctly', function () {
    $fileName = 'binlog.000002';
    $position = 67890;

    DB::shouldReceive('update')
        ->once()
        ->with(
            "UPDATE replicator.settings SET json_binlog = :json_binlog WHERE true;",
            ['json_binlog' => json_encode(['file' => $fileName, 'position' => $position])]
        )
        ->andReturn(true);

    $databaseService = new DatabaseService();
    $databaseService->updateBinlogPosition($fileName, $position);

    expect(true)->toBeTrue();
});

afterEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB');
});
