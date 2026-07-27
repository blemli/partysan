<?php

use Blemli\Partysan\Commands\DbCreateCommand;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FakePdoStatement extends PDOStatement
{
    public function __construct(public bool $exists) {}

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->exists ? 1 : false;
    }
}

class FakePdo extends PDO
{
    /** @var array<int, string> */
    public array $executed = [];

    public function __construct(public bool $exists = false) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakePdoStatement($this->exists);
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        return 1;
    }
}

function fakeDbCreateCommand(PDO $pdo): DbCreateCommand
{
    $command = new class($pdo) extends DbCreateCommand
    {
        public function __construct(private PDO $pdo)
        {
            parent::__construct();
        }

        protected function createPdo(string $driver, array $config): PDO
        {
            return $this->pdo;
        }
    };

    $command->setLaravel(app());

    return $command;
}

/**
 * @param  array<string, mixed>  $parameters
 */
function runDbCreate(DbCreateCommand $command, array $parameters = []): array
{
    $input = new ArrayInput($parameters);
    $input->setInteractive(false);
    $output = new BufferedOutput;

    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

it('creates a missing sqlite database file including parent directories', function () {
    $path = sys_get_temp_dir().'/partysan-db-create-test/nested/dir/database.sqlite';
    File::deleteDirectory(sys_get_temp_dir().'/partysan-db-create-test');

    config()->set('database.connections.sqlite_file', [
        'driver' => 'sqlite',
        'database' => $path,
    ]);

    $this->artisan('db:create', ['--connection' => 'sqlite_file'])
        ->assertSuccessful();

    expect(File::exists($path))->toBeTrue();

    File::deleteDirectory(sys_get_temp_dir().'/partysan-db-create-test');
});

it('reports an existing sqlite database without touching it', function () {
    $path = sys_get_temp_dir().'/partysan-db-create-test/existing.sqlite';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'not empty');

    config()->set('database.connections.sqlite_file', [
        'driver' => 'sqlite',
        'database' => $path,
    ]);

    $this->artisan('db:create', ['--connection' => 'sqlite_file'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get($path))->toBe('not empty');

    File::deleteDirectory(sys_get_temp_dir().'/partysan-db-create-test');
});

it('does nothing for an in-memory sqlite database', function () {
    $this->artisan('db:create', ['--connection' => 'testing'])
        ->expectsOutputToContain('in-memory')
        ->assertSuccessful();
});

it('fails for an unknown connection', function () {
    $this->artisan('db:create', ['--connection' => 'nope'])
        ->assertFailed();
});

it('creates a mysql database with charset and collation from the connection config', function () {
    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $pdo = new FakePdo(exists: false);
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), ['--connection' => 'mysql_test']);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe([
            'create database if not exists `app` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`',
        ]);
});

it('creates a mariadb database and lets options override the configured charset and collation', function () {
    config()->set('database.connections.mariadb_test', [
        'driver' => 'mariadb',
        'host' => '127.0.0.1',
        'database' => 'app',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $pdo = new FakePdo(exists: false);
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), [
        '--connection' => 'mariadb_test',
        '--charset' => 'latin1',
        '--collation' => 'latin1_swedish_ci',
    ]);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe([
            'create database if not exists `app` default character set `latin1` default collate `latin1_swedish_ci`',
        ]);
});

it('creates a postgres database with the configured encoding', function () {
    config()->set('database.connections.pgsql_test', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'database' => 'app',
        'charset' => 'UTF8',
    ]);

    $pdo = new FakePdo(exists: false);
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), ['--connection' => 'pgsql_test']);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe(["create database \"app\" encoding 'UTF8'"]);
});

it('creates a sql server database with an optional collation', function () {
    config()->set('database.connections.sqlsrv_test', [
        'driver' => 'sqlsrv',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo(exists: false);
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), [
        '--connection' => 'sqlsrv_test',
        '--collation' => 'SQL_Latin1_General_CP1_CI_AS',
    ]);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe(['create database [app] collate SQL_Latin1_General_CP1_CI_AS']);
});

it('accepts an explicit database name argument', function () {
    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo(exists: false);
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), [
        'name' => 'other_db',
        '--connection' => 'mysql_test',
    ]);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe(['create database if not exists `other_db`']);
});

it('does not create anything when the database already exists on the server', function () {
    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo(exists: true);
    [$exitCode, $output] = runDbCreate(fakeDbCreateCommand($pdo), ['--connection' => 'mysql_test']);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe([])
        ->and($output)->toContain('already exists');
});

it('rejects database names with unsafe characters', function () {
    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo;
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), [
        'name' => 'bad`name',
        '--connection' => 'mysql_test',
    ]);

    expect($exitCode)->toBe(1)
        ->and($pdo->executed)->toBe([]);
});

it('rejects unsafe charset and collation values', function () {
    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo;
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), [
        '--connection' => 'mysql_test',
        '--charset' => "utf8'; drop database app; --",
    ]);

    expect($exitCode)->toBe(1)
        ->and($pdo->executed)->toBe([]);
});

it('asks for confirmation in production and aborts without --force', function () {
    $this->app['env'] = 'production';

    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo;
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), ['--connection' => 'mysql_test']);

    expect($exitCode)->toBe(1)
        ->and($pdo->executed)->toBe([]);

    $this->app['env'] = 'testing';
});

it('runs in production when --force is given', function () {
    $this->app['env'] = 'production';

    config()->set('database.connections.mysql_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ]);

    $pdo = new FakePdo(exists: false);
    [$exitCode] = runDbCreate(fakeDbCreateCommand($pdo), [
        '--connection' => 'mysql_test',
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and($pdo->executed)->toBe(['create database if not exists `app`']);

    $this->app['env'] = 'testing';
});

it('builds a maintenance dsn per driver that avoids the target database', function (string $driver, array $config, string $expected) {
    $command = new DbCreateCommand;

    $method = new ReflectionMethod(DbCreateCommand::class, 'maintenanceDsn');

    expect($method->invoke($command, $driver, $config))->toBe($expected);
})->with([
    'mysql' => ['mysql', ['host' => 'db.example.com', 'port' => 3306], 'mysql:host=db.example.com;port=3306'],
    'mysql socket' => ['mysql', ['unix_socket' => '/tmp/mysql.sock'], 'mysql:unix_socket=/tmp/mysql.sock'],
    'mariadb' => ['mariadb', ['host' => 'localhost'], 'mysql:host=localhost'],
    'pgsql' => ['pgsql', ['host' => 'localhost', 'port' => 5432], 'pgsql:host=localhost;port=5432;dbname=postgres'],
    'sqlsrv' => ['sqlsrv', ['host' => 'localhost', 'port' => 1433], 'sqlsrv:Server=localhost,1433;Database=master'],
]);
