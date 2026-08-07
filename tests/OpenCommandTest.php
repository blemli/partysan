<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

afterEach(function () {
    File::delete(base_path('.env.production'));
});

it('opens the configured app url in the browser', function () {
    Process::fake();
    config()->set('app.url', 'https://myapp.test');

    $this->artisan('open')
        ->expectsOutputToContain('https://myapp.test')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('https://myapp.test', $process->command, true));
});

it('is also available under the visit alias', function () {
    Process::fake();
    config()->set('app.url', 'https://myapp.test');

    $this->artisan('visit')->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('https://myapp.test', $process->command, true));
});

it('takes the app url from the environment file when --env is given', function () {
    Process::fake();
    config()->set('app.url', 'https://local.test');
    File::put(base_path('.env.production'), "APP_NAME=Partysan\nAPP_URL=https://partysan.example\n");

    $this->artisan('open', ['--env' => 'production'])
        ->expectsOutputToContain('https://partysan.example')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('https://partysan.example', $process->command, true));
});

it('fails when the environment file does not exist', function () {
    Process::fake();

    $this->artisan('open', ['--env' => 'staging'])
        ->expectsOutputToContain('.env.staging')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when the environment file has no APP_URL', function () {
    Process::fake();
    File::put(base_path('.env.production'), "APP_NAME=Partysan\n");

    $this->artisan('open', ['--env' => 'production'])
        ->expectsOutputToContain('No APP_URL found')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when no app url is configured', function () {
    Process::fake();
    config()->set('app.url', '');

    $this->artisan('open')
        ->expectsOutputToContain('not configured')
        ->assertFailed();

    Process::assertNothingRan();
});

it('reports a failure when the browser could not be opened', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'no browser available', exitCode: 1),
    ]);
    config()->set('app.url', 'https://myapp.test');

    $this->artisan('open')
        ->expectsOutputToContain('no browser available')
        ->assertFailed();
});
