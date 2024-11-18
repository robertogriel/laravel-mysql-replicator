<?php

use Illuminate\Support\Facades\Config;
use robertogriel\Replicator\Config\ReplicationConfigManager;

beforeEach(function () {

    Config::shouldReceive('get')
        ->once()
        ->with('replicator')
        ->andReturn([
            'replication_1' => [
                'node_primary' => [
                    'database' => 'has_anyone_in_production',
                    'table' => 'primary_table',
                    'reference_key' => 'id',
                ],
                'node_secondary' => [
                    'database' => 'has_anyone_in_remote',
                    'table' => 'secondary_table',
                    'reference_key' => 'id',
                ],
                'columns' => [
                    'column1' => 'mapped_column1',
                ],
                'interceptor' => [SomeInterceptorClass::class, 'iReallyDontKnowWhatHappenHere'],
            ],
        ]);
});

test('should load configurations correctly', function () {
    $manager = new ReplicationConfigManager();

    expect($manager->getConfigurations())
        ->toBeArray()
        ->toHaveKey('replication_1');
});

test('should retrieves unique databases', function () {
    $manager = new ReplicationConfigManager();

    expect($manager->getDatabases())
        ->toBeArray()
        ->toEqualCanonicalizing(['has_anyone_in_production', 'has_anyone_in_remote']);
});

test('should retrieves unique tables', function () {
    $manager = new ReplicationConfigManager();

    expect($manager->getTables())
        ->toBeArray()
        ->toEqualCanonicalizing(['primary_table', 'secondary_table']);
});

test('should support interceptor functionality', function () {

    $interceptor = new SomeInterceptorClass();
    $data = ['column1' => 'value1'];
    $modifiedData = $interceptor->iReallyDontKnowWhatHappenHere($data);

    expect($modifiedData)
        ->toBeArray()
        ->toHaveKey('column1', 'Is this the real life? Is this just fantasy?');
});

class SomeInterceptorClass
{
    public function iReallyDontKnowWhatHappenHere(array $data): array
    {
        $data['column1'] = 'Is this the real life? Is this just fantasy?';
        return $data;
    }
}
