<?php

use Blemli\Partysan\Partysan;
use Blemli\Partysan\Rename\Plugins\ClearCachesPlugin;
use Blemli\Partysan\Rename\Plugins\ComposerPlugin;
use Blemli\Partysan\Rename\Plugins\DirectoryPlugin;
use Blemli\Partysan\Rename\Plugins\EnvFilesPlugin;
use Blemli\Partysan\Rename\Plugins\ForgePlugin;
use Blemli\Partysan\Rename\Plugins\GitHubPlugin;
use Blemli\Partysan\Rename\Plugins\KamalPlugin;
use Blemli\Partysan\Rename\Plugins\NamespacePlugin;
use Blemli\Partysan\Rename\Plugins\OnePasswordPlugin;
use Blemli\Partysan\Rename\Plugins\ResidualScanPlugin;
use Blemli\Partysan\Tests\Fixtures\RecordingPlugin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Build a throw-away project directory and point the app's base path at it.
 *
 * @param  array<string, string>  $files
 */
function project(array $files = [], string $name = 'my-app'): string
{
    $root = sys_get_temp_dir().'/partysan-rename-'.uniqid();
    $dir = "{$root}/{$name}";
    test()->projectRoots = [...test()->projectRoots, $root];

    $files += [
        '.env' => "APP_NAME=\"My App\"\nAPP_URL=https://my-app.test\nDB_DATABASE=my_app\n",
        'composer.json' => "{\n    \"name\": \"acme/my-app\",\n    \"description\": \"My App does things\",\n    \"autoload\": {\n        \"psr-4\": {\n            \"App\\\\\": \"app/\"\n        }\n    }\n}\n",
    ];

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$dir}/{$path}"));
        File::put("{$dir}/{$path}", $contents);
    }

    app()->setBasePath($dir);

    return $dir;
}

/**
 * @param  array<string, mixed>  $extra
 */
function fakeProcesses(array $extra = []): void
{
    $quoted = [];

    foreach ($extra + [
        'op *' => Process::result(exitCode: 1),
        'git remote *' => Process::result(exitCode: 1),
        '*' => Process::result(''),
    ] as $pattern => $result) {
        $quoted[shellPattern($pattern)] = $result;
    }

    Process::fake($quoted);
}

/**
 * Array commands are recorded with every argument single-quoted; quote the
 * pattern the same way so "git remote *" matches "'git' 'remote' 'get-url' 'origin'".
 */
function shellPattern(string $pattern): string
{
    if ($pattern === '*') {
        return $pattern;
    }

    return implode(' ', array_map(fn (string $word) => $word === '*' ? '*' : "'{$word}'", explode(' ', $pattern)));
}

function ranCommand(string $needle): bool
{
    $ran = false;
    Process::assertRan(function ($process) use ($needle, &$ran) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, $needle)) {
            $ran = true;
        }

        return true;
    });

    return $ran;
}

beforeEach(function () {
    $this->projectRoots = [];
    $this->originalBasePath = base_path();
    RecordingPlugin::$runs = [];
});

afterEach(function () {
    app()->setBasePath($this->originalBasePath);

    foreach ($this->projectRoots as $root) {
        File::deleteDirectory($root);
    }
});

it('rewrites the env files, composer.json and package.json', function () {
    fakeProcesses();
    $dir = project([
        '.env.example' => "APP_NAME=\"My App\"\nVITE_APP_NAME=\"\${APP_NAME}\"\n",
        'package.json' => "{\n  \"name\": \"my-app\",\n  \"private\": true\n}\n",
    ]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory'])
        ->assertSuccessful();

    expect(File::get("{$dir}/.env"))->toBe("APP_NAME=\"New App\"\nAPP_URL=https://new-app.test\nDB_DATABASE=new_app\n")
        ->and(File::get("{$dir}/.env.example"))->toContain('APP_NAME="New App"')
        ->and(File::get("{$dir}/composer.json"))->toContain('"name": "acme/new-app"')
        ->and(File::get("{$dir}/composer.json"))->toContain('"description": "New App does things"')
        ->and(File::get("{$dir}/composer.json"))->toContain('"App\\\\": "app/"')
        ->and(File::get("{$dir}/package.json"))->toBe("{\n  \"name\": \"new-app\",\n  \"private\": true\n}\n");
});

it('leaves the default App namespace alone', function () {
    fakeProcesses();
    $dir = project(['app/Models/User.php' => "<?php\n\nnamespace App\\Models;\n\nclass User {}\n"]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory'])
        ->expectsOutputToContain('namespace')
        ->assertSuccessful();

    expect(File::get("{$dir}/app/Models/User.php"))->toContain('namespace App\Models;');
    expect(ranCommand('composer dump-autoload'))->toBeFalse();
});

it('renames a custom root namespace', function () {
    fakeProcesses();
    $dir = project([
        'composer.json' => "{\n    \"name\": \"acme/my-app\",\n    \"autoload\": {\n        \"psr-4\": {\n            \"MyApp\\\\\": \"app/\"\n        }\n    }\n}\n",
        'app/Models/User.php' => "<?php\n\nnamespace MyApp\\Models;\n\nuse MyApp\\Support\\Thing;\nuse MyApple\\Other;\n\nclass User {}\n",
        'config/app.php' => "<?php\n\nreturn ['providers' => [MyApp\\Providers\\AppServiceProvider::class]];\n",
    ]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory'])
        ->assertSuccessful();

    expect(File::get("{$dir}/app/Models/User.php"))
        ->toContain('namespace NewApp\Models;')
        ->toContain('use NewApp\Support\Thing;')
        ->toContain('use MyApple\Other;')
        ->and(File::get("{$dir}/config/app.php"))->toContain('NewApp\Providers\AppServiceProvider::class')
        ->and(File::get("{$dir}/composer.json"))->toContain('"NewApp\\\\": "app/"');
    expect(ranCommand('composer dump-autoload'))->toBeTrue();
});

it('rewrites the kamal deploy config', function () {
    fakeProcesses();
    $dir = project([
        'config/deploy.yml' => "service: my-app\nimage: acme/my-app\nservers:\n  web:\n    - my-app.example\nvolumes:\n  - /data/my-app:/data\n",
        '.kamal/secrets' => "APP_KEY=\$(op read op://vault/my-app/key)\n",
    ]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory'])
        ->expectsOutputToContain('kamal')
        ->assertSuccessful();

    expect(File::get("{$dir}/config/deploy.yml"))->toBe("service: new-app\nimage: acme/new-app\nservers:\n  web:\n    - new-app.example\nvolumes:\n  - /data/new-app:/data\n")
        ->and(File::get("{$dir}/.kamal/secrets"))->toContain('op://vault/new-app/key');
});

it('renames matching 1password items after confirmation', function () {
    fakeProcesses([
        'op --version' => Process::result('2.30.0'),
        'op whoami' => Process::result('you@example.com'),
        'op item list *' => Process::result(json_encode([
            ['id' => 'abc', 'title' => 'My App admin', 'urls' => [['primary' => true, 'href' => 'https://my-app.example/admin']]],
            ['id' => 'def', 'title' => 'Unrelated', 'urls' => []],
        ])),
    ]);
    project();

    $this->artisan('rename', ['name' => 'New App', '--only' => '1password'])
        ->expectsConfirmation('Rename [My App] to [New App]?', 'yes')
        ->expectsConfirmation('1Password: rename item [My App admin] to [New App admin] and its URL to [https://new-app.example/admin]?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['op', 'item', 'edit', 'abc', '--title', 'New App admin', '--url', 'https://new-app.example/admin']);
    Process::assertNotRan(fn ($process) => is_array($process->command) && in_array('def', $process->command, true));
});

it('skips 1password when the cli is not signed in', function () {
    fakeProcesses([
        'op --version' => Process::result('2.30.0'),
        'op whoami' => Process::result(exitCode: 1),
    ]);
    project();

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => '1password'])
        ->expectsOutputToContain('not signed in')
        ->assertSuccessful();

    Process::assertNotRan(fn ($process) => is_array($process->command) && in_array('edit', $process->command, true));
});

it('flags remaining occurrences and replaces them on confirmation', function () {
    fakeProcesses();
    $dir = project([
        'README.md' => "# My App\n\nWelcome to my-app.\n",
        'resources/views/welcome.blade.php' => "<title>MyApp</title>\n",
        'public/my-app-logo.png' => "\x89PNG\0binary",
    ]);

    $this->artisan('rename', ['name' => 'New App', '--without' => 'directory'])
        ->expectsConfirmation('Rename [My App] to [New App]?', 'yes')
        ->expectsOutputToContain('README.md')
        ->expectsConfirmation('Replace 2 occurrence(s) in [README.md]?', 'yes')
        ->expectsConfirmation('Replace 1 occurrence(s) in [resources/views/welcome.blade.php]?', 'no')
        ->expectsConfirmation('Rename [public/my-app-logo.png] to [public/new-app-logo.png]?', 'yes')
        ->expectsOutputToContain('Still mentions the old name: resources/views/welcome.blade.php')
        ->assertSuccessful();

    expect(File::get("{$dir}/README.md"))->toBe("# New App\n\nWelcome to new-app.\n")
        ->and(File::get("{$dir}/resources/views/welcome.blade.php"))->toContain('MyApp')
        ->and(File::exists("{$dir}/public/new-app-logo.png"))->toBeTrue()
        ->and(File::exists("{$dir}/public/my-app-logo.png"))->toBeFalse();
});

it('changes nothing in dry-run mode', function () {
    fakeProcesses();
    $dir = project(['README.md' => "# My App\n"]);
    $before = collect(File::allFiles($dir, true))->mapWithKeys(fn ($f) => [$f->getPathname() => md5_file($f->getPathname())])->all();

    $this->artisan('rename', ['name' => 'New App', '--dry-run' => true])
        ->expectsOutputToContain('Would rename')
        ->expectsOutputToContain('README.md')
        ->expectsOutputToContain('nothing was changed')
        ->assertSuccessful();

    $after = collect(File::allFiles($dir, true))->mapWithKeys(fn ($f) => [$f->getPathname() => md5_file($f->getPathname())])->all();

    expect($after)->toBe($before)->and(File::isDirectory($dir))->toBeTrue();
    Process::assertNotRan(fn ($process) => is_array($process->command) && $process->command[0] === 'gh');
});

it('renames the project directory last', function () {
    fakeProcesses();
    $dir = project();

    $this->artisan('rename', ['name' => 'New App', '--force' => true])
        ->expectsOutputToContain('cd '.dirname($dir).'/new-app')
        ->assertSuccessful();

    expect(File::isDirectory(dirname($dir).'/new-app'))->toBeTrue()
        ->and(File::isDirectory($dir))->toBeFalse()
        ->and(File::get(dirname($dir).'/new-app/.env'))->toContain('APP_NAME="New App"');
});

it('does not rename the directory when the target exists', function () {
    fakeProcesses();
    $dir = project();
    File::ensureDirectoryExists(dirname($dir).'/new-app');

    $this->artisan('rename', ['name' => 'New App', '--force' => true])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::isDirectory($dir))->toBeTrue();
});

it('renames the github repository through gh', function () {
    fakeProcesses(['git remote *' => Process::result("git@github.com:acme/my-app.git\n")]);
    project();

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'github'])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['gh', 'repo', 'rename', 'new-app', '--yes']);
});

it('reports a failing gh rename and carries on', function () {
    fakeProcesses([
        'git remote *' => Process::result("https://github.com/acme/my-app\n"),
        'gh repo rename *' => Process::result(exitCode: 1, errorOutput: 'HTTP 403'),
    ]);
    project();

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory'])
        ->expectsOutputToContain('HTTP 403')
        ->expectsOutputToContain('manually')
        ->assertSuccessful();
});

it('skips github when origin is not on github', function () {
    fakeProcesses(['git remote *' => Process::result("git@gitlab.com:acme/my-app.git\n")]);
    project();

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'github'])
        ->expectsOutputToContain('skipped')
        ->assertSuccessful();

    Process::assertNotRan(fn ($process) => is_array($process->command) && $process->command[0] === 'gh');
});

it('refuses to run on a dirty working tree without --force', function () {
    fakeProcesses(['git status *' => Process::result(" M app/Models/User.php\n")]);
    $dir = project();

    $this->artisan('rename', ['name' => 'New App', '--no-interaction' => true])
        ->expectsOutputToContain('uncommitted changes')
        ->assertFailed();

    expect(File::get("{$dir}/.env"))->toContain('APP_NAME="My App"');
});

it('takes the old name from --from', function () {
    fakeProcesses();
    $dir = project(['README.md' => "Legacy Name here\n"]);

    $this->artisan('rename', ['name' => 'New App', '--from' => 'Legacy Name', '--force' => true, '--without' => 'directory'])
        ->expectsOutputToContain('Legacy Name')
        ->assertSuccessful();

    expect(File::get("{$dir}/.env"))->toContain('APP_NAME="My App"');
});

it('asks which old name to use when APP_NAME and the directory disagree', function () {
    fakeProcesses();
    $dir = project(['.env' => "APP_NAME=\"Something Else\"\n"]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory,scan'])
        ->expectsChoice('Which is the current app name?', 'my-app (directory)', ['Something Else (APP_NAME)', 'my-app (directory)'])
        ->assertSuccessful();

    expect(File::get("{$dir}/composer.json"))->toContain('acme/new-app');
});

it('fails on an ambiguous old name without interaction', function () {
    fakeProcesses();
    project(['.env' => "APP_NAME=\"Something Else\"\n"]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('ambiguous')
        ->assertFailed();
});

it('fails when the new name equals the old one', function () {
    fakeProcesses();
    project();

    $this->artisan('rename', ['name' => 'my_app', '--force' => true])
        ->expectsOutputToContain('already called')
        ->assertFailed();
});

it('rejects unknown plugin keys', function () {
    fakeProcesses();
    project();

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'nope'])
        ->expectsOutputToContain('Unknown plugin(s) [nope]')
        ->assertFailed();
});

it('lists the plugins and whether they apply', function () {
    fakeProcesses();
    project(['config/deploy.yml' => "service: my-app\n"]);

    $this->artisan('rename', ['name' => 'New App', '--plugins' => true])
        ->expectsTable(['Plugin', 'Order', 'Applies', 'Class'], [
            ['env', 10, 'yes', EnvFilesPlugin::class],
            ['composer', 20, 'yes', ComposerPlugin::class],
            ['namespace', 30, 'no', NamespacePlugin::class],
            ['kamal', 40, 'yes', KamalPlugin::class],
            ['1password', 50, 'no', OnePasswordPlugin::class],
            ['forge', 60, 'no', ForgePlugin::class],
            ['github', 800, 'no', GitHubPlugin::class],
            ['scan', 900, 'yes', ResidualScanPlugin::class],
            ['caches', 950, 'yes', ClearCachesPlugin::class],
            ['directory', 1000, 'yes', DirectoryPlugin::class],
        ])
        ->assertSuccessful();
});

it('runs plugins registered through config and the registry in order', function () {
    fakeProcesses();
    project();
    config()->set('partysan.rename.plugins', [RecordingPlugin::class]);
    app(Partysan::class)->renamePlugin(new RecordingPlugin('late', 990), new RecordingPlugin('never', 5, applies: false));

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--without' => 'directory'])
        ->expectsOutputToContain('recording ran')
        ->expectsOutputToContain('late ran')
        ->expectsOutputToContain('recording: remember the thing')
        ->assertSuccessful();

    expect(RecordingPlugin::$runs)->toBe(['recording:my-app->new-app', 'late:my-app->new-app']);

    $keys = array_map(fn ($plugin) => $plugin->key(), app(Partysan::class)->renamePlugins());
    expect($keys)->toBe(['never', 'env', 'recording', 'composer', 'namespace', 'kamal', '1password', 'forge', 'github', 'scan', 'caches', 'late', 'directory']);
});

it('restricts the run to --only plugins', function () {
    fakeProcesses();
    $dir = project(['README.md' => "My App\n"]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'composer'])
        ->assertSuccessful();

    expect(File::get("{$dir}/.env"))->toContain('APP_NAME="My App"')
        ->and(File::get("{$dir}/composer.json"))->toContain('acme/new-app')
        ->and(File::isDirectory($dir))->toBeTrue();
});

it('renames matching forge sites through the api', function () {
    fakeProcesses();
    project();
    config()->set('partysan.rename.forge.token', 'secret-token');
    Http::fake([
        'forge.laravel.com/api/v1/servers' => Http::response(['servers' => [['id' => 1, 'name' => 'web-1']]]),
        'forge.laravel.com/api/v1/servers/1/sites' => Http::response(['sites' => [
            ['id' => 10, 'name' => 'my-app.example', 'directory' => '/public', 'repository' => 'acme/my-app', 'repository_provider' => 'github', 'repository_branch' => 'main'],
            ['id' => 11, 'name' => 'other.example', 'directory' => '/public', 'repository' => 'acme/other'],
        ]]),
        'forge.laravel.com/api/v1/servers/1/sites/10/env' => Http::sequence()
            ->push("APP_NAME=\"My App\"\nAPP_URL=https://my-app.example\n")
            ->push(''),
        'forge.laravel.com/api/v1/servers/1/sites/10/deployment/script' => Http::sequence()
            ->push("cd /home/forge/my-app.example\ngit pull\n")
            ->push(''),
        'forge.laravel.com/api/v1/servers/1/sites/10/git' => Http::response([]),
        'forge.laravel.com/api/v1/servers/1/sites/10' => Http::response([]),
    ]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'forge'])
        ->expectsOutputToContain('domain -> new-app.example')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/sites/10') && $request['name'] === 'new-app.example' && $request->hasHeader('Authorization', 'Bearer secret-token'));
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/sites/10/git') && $request['repository'] === 'acme/new-app' && $request['branch'] === 'main');
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/sites/10/env') && $request['content'] === "APP_NAME=\"New App\"\nAPP_URL=https://new-app.example\n");
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/sites/10/deployment/script') && str_contains($request['content'], '/home/forge/new-app.example'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sites/11'));
});

it('skips forge without a token and reports a failing api', function () {
    fakeProcesses();
    project();
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'forge'])
        ->expectsOutputToContain('skipped')
        ->assertSuccessful();
    Http::assertNothingSent();

    config()->set('partysan.rename.forge.token', 'bad');

    $this->artisan('rename', ['name' => 'New App', '--force' => true, '--only' => 'forge'])
        ->expectsOutputToContain('HTTP 401')
        ->assertSuccessful();
});
