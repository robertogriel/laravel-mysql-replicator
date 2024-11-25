<?php

namespace robertogriel\Replicator\Config;

use Illuminate\Support\Facades\Config;

class ReplicationConfigManager
{
    public function getGroupDatabaseConfigurations(): array
    {
        $databases = [];
        $tables = [];

        foreach (Config::get('replicator') as $config) {
            $databases = [$config['node_primary']['database'], $config['node_secondary']['database']];
            $tables = array_merge($tables, [$config['node_primary']['table'], $config['node_secondary']['table']]);
        }

        return [array_unique($databases), array_unique($tables)];
    }
}
