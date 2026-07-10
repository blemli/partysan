<?php

namespace Blemli\Partysan;

use Blemli\Partysan\Commands\PluginSwitchCommand;
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
            ->hasConfigFile('depswitch')
            ->hasCommand(PluginSwitchCommand::class);
    }
}
