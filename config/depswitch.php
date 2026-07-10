<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Local Package Directory
    |--------------------------------------------------------------------------
    |
    | The directory where your local package clones/forks are stored.
    | Package name (without vendor) will be appended: ~/git/overlook
    |
    */
    'directory' => env('DEPSWITCH_DIRECTORY', '~/git'),

    /*
    |--------------------------------------------------------------------------
    | GitHub Organization
    |--------------------------------------------------------------------------
    |
    | Your GitHub org/username where forks are created.
    | Used for VCS repositories and forking.
    |
    */
    'github_org' => env('DEPSWITCH_GITHUB_ORG', 'blemli'),

    /*
    |--------------------------------------------------------------------------
    | Default Branch
    |--------------------------------------------------------------------------
    |
    | The default branch name when switching to VCS mode.
    |
    */
    'default_branch' => 'main',
];
