<?php

namespace Blemli\Partysan\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'db:create')]
class DbCreateCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'db:create
                            {name? : Database name (defaults to the connection\'s configured database)}
                            {--connection= : Database connection to use (defaults to the default connection)}
                            {--charset= : Character set (mysql/mariadb) or encoding (pgsql)}
                            {--collation= : Collation (mysql/mariadb/sqlsrv)}
                            {--admin-user= : Privileged user allowed to create databases}
                            {--admin-password= : Password for the admin user}
                            {--force : Force the operation to run when in production}';

    protected $description = 'Create the database for the given connection if it does not exist';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            $this->components->error("Database connection [{$connection}] is not configured.");

            return self::FAILURE;
        }

        $driver = $config['driver'] ?? null;
        $database = $this->argument('name') ?: ($config['database'] ?? null);

        if (! $database) {
            $this->components->error("No database name given and none configured for connection [{$connection}].");

            return self::FAILURE;
        }

        if ($driver === 'sqlite') {
            return $this->createSqliteDatabase($database);
        }

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            $this->components->error("Unsupported database driver [{$driver}].");

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9_\-]+$/', $database)) {
            $this->components->error("Invalid database name [{$database}]. Only letters, numbers, underscores and dashes are allowed.");

            return self::FAILURE;
        }

        foreach (['charset', 'collation'] as $option) {
            $value = $this->option($option);

            if ($value && ! preg_match('/^[A-Za-z0-9_]+$/', $value)) {
                $this->components->error("Invalid {$option} [{$value}].");

                return self::FAILURE;
            }
        }

        try {
            $pdo = $this->createPdo($driver, $config);
        } catch (\PDOException $e) {
            $this->components->error("Could not connect to the {$driver} server: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            if ($this->databaseExists($pdo, $driver, $database)) {
                $this->components->info("Database [{$database}] already exists on connection [{$connection}].");

                return self::SUCCESS;
            }

            $pdo->exec($this->createStatement($driver, $database, $config));
        } catch (\PDOException $e) {
            $this->components->error("Failed to create database [{$database}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Database [{$database}] created on connection [{$connection}].");

        return self::SUCCESS;
    }

    protected function createSqliteDatabase(string $path): int
    {
        if ($path === ':memory:') {
            $this->components->info('The sqlite connection uses an in-memory database; nothing to create.');

            return self::SUCCESS;
        }

        if (File::exists($path)) {
            $this->components->info("Database [{$path}] already exists.");

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($path));

        if (File::put($path, '') === false) {
            $this->components->error("Could not create database file [{$path}].");

            return self::FAILURE;
        }

        $this->components->info("Database [{$path}] created.");

        return self::SUCCESS;
    }

    /**
     * Connect to the server without selecting the target database.
     *
     * @param  'mysql'|'mariadb'|'pgsql'|'sqlsrv'  $driver
     * @param  array<string, mixed>  $config
     */
    protected function createPdo(string $driver, array $config): PDO
    {
        $username = $this->option('admin-user') ?: ($config['username'] ?? null);
        $password = $this->option('admin-user')
            ? $this->option('admin-password')
            : ($config['password'] ?? null);

        return new PDO(
            $this->maintenanceDsn($driver, $config),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Build a DSN pointing at the server-level maintenance database.
     *
     * @param  'mysql'|'mariadb'|'pgsql'|'sqlsrv'  $driver
     * @param  array<string, mixed>  $config
     */
    protected function maintenanceDsn(string $driver, array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? null;

        return match ($driver) {
            'mysql', 'mariadb' => isset($config['unix_socket']) && $config['unix_socket'] !== ''
                ? "mysql:unix_socket={$config['unix_socket']}"
                : 'mysql:host='.$host.($port ? ";port={$port}" : ''),
            'pgsql' => 'pgsql:host='.$host.($port ? ";port={$port}" : '').';dbname=postgres',
            'sqlsrv' => 'sqlsrv:Server='.$host.($port ? ",{$port}" : '').';Database=master',
        };
    }

    /**
     * @param  'mysql'|'mariadb'|'pgsql'|'sqlsrv'  $driver
     */
    protected function databaseExists(PDO $pdo, string $driver, string $database): bool
    {
        $statement = $pdo->prepare($this->existsQuery($driver));
        $statement->execute([$database]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * @param  'mysql'|'mariadb'|'pgsql'|'sqlsrv'  $driver
     */
    protected function existsQuery(string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => 'select 1 from information_schema.schemata where schema_name = ?',
            'pgsql' => 'select 1 from pg_database where datname = ?',
            'sqlsrv' => 'select 1 from sys.databases where name = ?',
        };
    }

    /**
     * @param  'mysql'|'mariadb'|'pgsql'|'sqlsrv'  $driver
     * @param  array<string, mixed>  $config
     */
    protected function createStatement(string $driver, string $database, array $config): string
    {
        $charset = $this->option('charset') ?: ($config['charset'] ?? null);
        $collation = $this->option('collation') ?: ($config['collation'] ?? null);

        return match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'create database if not exists `%s`%s%s',
                $database,
                $charset ? " default character set `{$charset}`" : '',
                $collation ? " default collate `{$collation}`" : '',
            ),
            'pgsql' => sprintf(
                'create database "%s"%s',
                $database,
                $charset ? " encoding '{$charset}'" : '',
            ),
            'sqlsrv' => sprintf(
                'create database [%s]%s',
                $database,
                $collation ? " collate {$collation}" : '',
            ),
        };
    }
}
