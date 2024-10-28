<?php

namespace robertogriel\Replicator;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Env;
use InvalidArgumentException;

class Replicator
{
    protected array $configurations;

    public function __construct()
    {
        $this->configurations = Config::get('replicator', []);
    }

    public function getConfigurations(): array
    {
        return $this->configurations;
    }

    public function startReplication()
    {
        foreach ($this->configurations as $name => $config) {
            $this->syncDatabase($config);
        }
    }

    protected function syncDatabase(array $config)
    {
        $host = env('DB_HOST');
        $user = env('REPLICADOR_DB_USERNAME');
        $password = env('REPLICADOR_DB_PASSWORD');

        //        $host = Env::get("DB_HOST_{$config['database']}", Env::get('DB_HOST'));
        //        $user = Env::get("DB_USERNAME_{$config['database']}", Env::get('DB_USERNAME'));
        //        $password = Env::get("DB_PASSWORD_{$config['database']}", Env::get('DB_PASSWORD')) ?? '';
        $slaveId = $config['slave_id'] ?? 12345;

        if (empty($host) || empty($user) || empty($slaveId)) {
            throw new InvalidArgumentException(
                "As configurações de conexão para o banco '{$config['database']}' estão incompletas ou inválidas."
            );
        }

        $replicatorManager = new ReplicatorManager(
            [$config['database']],
            $host,
            $user,
            $password,
            [$config['table']],
            $slaveId,
            function ($table, $database, $before, $after) use ($config) {
                (new ReplicatorManager([], '', '', '', [], 0, null))->syncData(
                    $table,
                    $database,
                    $before,
                    $after,
                    $config
                );
            }
        );

        $replicatorManager->runReplication();
    }
}
