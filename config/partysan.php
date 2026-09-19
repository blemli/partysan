<?php

return [

    /*
    |--------------------------------------------------------------------------
    | rename
    |--------------------------------------------------------------------------
    |
    | `php artisan rename` runs a list of plugins. The built-in ones (env,
    | composer, namespace, kamal, 1password, github, scan, caches, directory)
    | are always present; add your own classes implementing
    | Blemli\Partysan\Rename\RenamePlugin here, or register them from a
    | service provider with app(Partysan::class)->renamePlugin(...).
    |
    */
    'rename' => [
        'plugins' => [
            // App\Partysan\MyRenamePlugin::class,
        ],

        'kamal' => [
            // Path of the kamal deploy config, relative to the project root.
            'config' => env('PARTYSAN_KAMAL_CONFIG', 'config/deploy.yml'),
        ],

        'forge' => [
            // Laravel Forge API token; the forge plugin is skipped when empty.
            'token' => env('FORGE_API_TOKEN'),
            'url' => env('FORGE_API_URL', 'https://forge.laravel.com/api/v1'),
        ],

        'onepassword' => [
            // Restrict the 1Password item search to one vault (null = all vaults).
            'vault' => env('PARTYSAN_ONEPASSWORD_VAULT'),
        ],
    ],

];
