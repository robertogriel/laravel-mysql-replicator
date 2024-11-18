<?php

use Illuminate\Support\Facades\App;
use robertogriel\Replicator\Interceptor\InterceptorManager;

test('should call the interceptor function correctly and returns modified data', function () {
    $data = ['notes' => 'Rise from within', 'motivational_notes' => 'If you really want it the world is yours'];
    $nodePrimaryTable = 'primary_table';
    $nodePrimaryDatabase = 'primary_database';
    $interceptorFunction = ['TestInterceptorClass', 'demotivateMethod'];

    App::shouldReceive('call')
        ->once()
        ->with($interceptorFunction, [
            'data' => $data,
            'nodePrimaryTable' => $nodePrimaryTable,
            'nodePrimaryDatabase' => $nodePrimaryDatabase
        ])
        ->andReturn(['notes' => 'Stay down within', 'demotivational_notes' => 'Even if you want it, the world won’t care']);

    $result = InterceptorManager::applyInterceptor($interceptorFunction, $data, $nodePrimaryTable, $nodePrimaryDatabase);

    expect($result)->toEqual(['notes' => 'Stay down within', 'demotivational_notes' => 'Even if you want it, the world won’t care']);
});
