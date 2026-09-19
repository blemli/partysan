<?php

namespace Blemli\Partysan;

use Blemli\Partysan\Commands\DbCreateCommand;
use Blemli\Partysan\Commands\DependencySwitchCommand;
use Blemli\Partysan\Commands\OpenCommand;
use Blemli\Partysan\Commands\RenameCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PartysanServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('partysan')
            ->hasConfigFile(['depswitch', 'partysan'])
            ->hasCommand(DependencySwitchCommand::class)
            ->hasCommand(DbCreateCommand::class)
            ->hasCommand(OpenCommand::class)
            ->hasCommand(RenameCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Partysan::class);
    }
}
