<?php

use robertogriel\Replicator\Handlers\DeleteHandler;
use Illuminate\Support\Facades\DB;



test('should method successfully deletes data', function () {

    putenv('REPLICATOR_DB=replicator');

    $nodeSecondaryDatabase = 'secondary_db';
    $nodeSecondaryTable = 'users';
    $nodePrimaryReferenceKey = 'id';
    $nodeSecondaryReferenceKey = 'user_id';
    $data = ['id' => 1932];

    DB::shouldReceive('delete')
        ->once()
        ->with(
            'DELETE FROM secondary_db.users WHERE secondary_db.users.user_id = :user_id /* isReplicating */;',
            [':user_id' => 1932]
        )
        ->andReturnNull();

    DeleteHandler::handle(
        $nodeSecondaryDatabase,
        $nodeSecondaryTable,
        $nodePrimaryReferenceKey,
        $nodeSecondaryReferenceKey,
        $data
    );

    expect(true)->toBeTrue();
});
