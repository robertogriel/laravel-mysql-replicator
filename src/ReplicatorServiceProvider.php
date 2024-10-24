<?php

namespace robertogriel\Replicator;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use robertogriel\Replicator\Replicator\Commands\ReplicatorCommand;

class ReplicatorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('replicator')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_replicator_table')
            ->hasCommand(ReplicatorCommand::class);
    }
}
