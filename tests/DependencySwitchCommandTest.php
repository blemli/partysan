<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->composerBackup = File::get($this->composerPath);

    File::put($this->composerPath, json_encode([
        'require' => [
            'php' => '^8.2',
            'vendor/direct-package' => '^1.0',
            'vendor/local-package' => '^3.0',
        ],
        'require-dev' => [
            'vendor/dev-package' => '^2.0',
        ],
        'repositories' => [
            [
                'name' => 'vendor-local-package',
                'type' => 'path',
                'url' => '~/git/local-package',
            ],
        ],
    ], JSON_PRETTY_PRINT));
});

afterEach(function () {
    File::put($this->composerPath, $this->composerBackup);
    File::delete(base_path('.depswitch.json'));
});

it('lists dependencies with their current source', function () {
    Process::fake([
        'gh pr list*' => Process::result('[]'),
    ]);

    File::put(base_path('.depswitch.json'), json_encode([
        'packages' => [
            'vendor/local-package' => ['mode' => 'local', 'branch' => 'fix-thing'],
        ],
    ]));

    // One expectation per table row: each expectation consumes a whole
    // output line, so asserting two substrings of the same row would fail.
    $this->artisan('dependency:switch', ['--list' => true])
        ->expectsOutputToContain('vendor/direct-package')
        ->expectsOutputToContain('vendor/dev-package')
        ->expectsOutputToContain('fix-thing')
        ->assertSuccessful();
});

it('shows the pr status for switched packages', function () {
    Process::fake([
        'gh pr list*' => Process::result(json_encode([
            [
                'number' => 7,
                'title' => 'Fix the thing',
                'state' => 'OPEN',
                'url' => 'https://github.com/vendor/local-package/pull/7',
                'reviewRequests' => [],
                'reviewDecision' => 'APPROVED',
                'headRefName' => 'fix-thing',
            ],
        ])),
    ]);

    File::put(base_path('.depswitch.json'), json_encode([
        'packages' => [
            'vendor/local-package' => ['mode' => 'local', 'branch' => 'fix-thing'],
        ],
    ]));

    $this->artisan('dependency:switch', ['--list' => true])
        ->expectsOutputToContain('#7 approved')
        ->assertSuccessful();
});

it('leaves the pr column empty when gh fails', function () {
    Process::fake([
        'gh pr list*' => Process::result('', 'gh: command not found', 1),
    ]);

    File::put(base_path('.depswitch.json'), json_encode([
        'packages' => [
            'vendor/local-package' => ['mode' => 'local', 'branch' => 'fix-thing'],
        ],
    ]));

    $this->artisan('dependency:switch', ['--list' => true])
        ->expectsOutputToContain('fix-thing')
        ->assertSuccessful();
});

it('filters the list by source', function () {
    Process::fake();

    $this->artisan('dependency:switch', ['--list' => true, '--local' => true])
        ->expectsOutputToContain('vendor/local-package')
        ->doesntExpectOutputToContain('vendor/direct-package')
        ->assertSuccessful();
});
