<?php

use Illuminate\Support\Facades\DB;
use robertogriel\Replicator\Handlers\UpdateHandler;

beforeEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB=replicator');
});

test('should method successfully updates data with changes', function () {
    $nodePrimaryReferenceKey = 'id';
    $nodeSecondaryDatabase = 'secondary_db';
    $nodeSecondaryTable = 'users';
    $nodeSecondaryReferenceKey = 'user_id';
    $columnMappings = ['name' => 'user_name', 'email' => 'user_email'];
    $row = [
        'before' => ['id' => 1932, 'name' => 'Les Ismore', 'email' => 'les@ismore.com'],
        'after' => ['id' => 1932, 'name' => 'Al Coholic', 'email' => 'horse_with_no_name@yahoo.com'],
    ];

    $expectedSql = "UPDATE secondary_db.users SET secondary_db.users.user_name = :user_name, secondary_db.users.user_email = :user_email WHERE secondary_db.users.user_id = :user_id /* isReplicating */;";
    $expectedBinds = [
        ':user_name' => 'Al Coholic',
        ':user_email' => 'horse_with_no_name@yahoo.com',
        ':user_id' => 1932,
    ];

    DB::shouldReceive('update')
        ->once()
        ->with($expectedSql, $expectedBinds)
        ->andReturnTrue();

    UpdateHandler::handle(
        $nodePrimaryReferenceKey,
        $nodeSecondaryDatabase,
        $nodeSecondaryTable,
        $nodeSecondaryReferenceKey,
        $columnMappings,
        $row
    );

    expect(true)->toBeTrue();
});

test('should method does not update data when there are no changes', function () {
    $nodePrimaryReferenceKey = 'id';
    $nodeSecondaryDatabase = 'secondary_db';
    $nodeSecondaryTable = 'users';
    $nodeSecondaryReferenceKey = 'user_id';
    $columnMappings = ['name' => 'user_name', 'email' => 'user_email'];
    $row = [
        'before' => ['id' => 1932, 'name' => 'Al Coholic', 'email' => 'horse_with_no_name@yahoo.com'],
        'after' => ['id' => 1932, 'name' => 'Al Coholic', 'email' => 'horse_with_no_name@yahoo.com'],
    ];

    DB::shouldReceive('update')->never();

    UpdateHandler::handle(
        $nodePrimaryReferenceKey,
        $nodeSecondaryDatabase,
        $nodeSecondaryTable,
        $nodeSecondaryReferenceKey,
        $columnMappings,
        $row
    );

    expect(true)->toBeTrue();
});

afterEach(function () {
    Mockery::close();
    putenv('REPLICATOR_DB');
});
