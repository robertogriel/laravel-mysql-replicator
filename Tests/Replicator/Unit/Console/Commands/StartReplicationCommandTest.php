<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use robertogriel\Replicator\Console\Commands\StartReplicationCommand;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\MySQLReplicationFactory;
use MySQLReplication\Event\EventSubscribers;

test('should executes handle method and configures replication correctly', function () {

    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=3306');
    putenv('REPLICATOR_DB_USERNAME=root');
    putenv('REPLICATOR_DB_PASSWORD=toor');
    putenv('REPLICATOR_DB=replicator_test_db');

    Config::shouldReceive('get')
        ->once()
        ->with('replicator')
        ->andReturn([
            'replication_1' => [
                'node_primary' => [
                    'database' => 'primary_db',
                    'table' => 'primary_table',
                    'reference_key' => 'id',
                ],
                'node_secondary' => [
                    'database' => 'secondary_db',
                    'table' => 'secondary_table',
                    'reference_key' => 'id',
                ],
                'columns' => [
                    'column_with_name' => 'column_with_alias',
                ],
            ],
        ]);

    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['json_binlog' => json_encode(['file' => 'binlog_file', 'position' => 12345])]);

    DB::shouldReceive('update')->andReturnUsing(function () {
        return 1;
    });

    DB::shouldReceive('insert')->andReturnUsing(function () {
        return true;
    });

    DB::shouldReceive('delete')->andReturnUsing(function () {
        return 1;
    });

    Mockery::mock('overload:' . ConfigBuilder::class)
        ->shouldReceive('withHost')->andReturnSelf()
        ->shouldReceive('withPort')->andReturnSelf()
        ->shouldReceive('withUser')->andReturnSelf()
        ->shouldReceive('withPassword')->andReturnSelf()
        ->shouldReceive('withEventsOnly')->andReturnSelf()
        ->shouldReceive('withDatabasesOnly')->andReturnSelf()
        ->shouldReceive('withTablesOnly')->andReturnSelf()
        ->shouldReceive('withBinLogFileName')->andReturnSelf()
        ->shouldReceive('withBinLogPosition')->andReturnSelf()
        ->shouldReceive('build')->andReturn('mocked_build')
        ->getMock();

    Mockery::mock('overload:' . MySQLReplicationFactory::class)
        ->shouldReceive('registerSubscriber')
        ->andReturnNull()
        ->shouldReceive('run')
        ->andReturnNull()
        ->getMock();

    Mockery::mock('overload:' . EventSubscribers::class)
        ->shouldReceive('allEvents')
        ->andReturnNull()
        ->getMock();

    $command = app(StartReplicationCommand::class);
    $command->handle();

    expect(true)->toBeTrue();
});
