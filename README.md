# partysan

**stop wishing and start building**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blemli/partysan.svg?style=flat-square)](https://packagist.org/packages/blemli/partysan)[![Total Downloads](https://img.shields.io/packagist/dt/blemli/partysan.svg?style=flat-square)](https://packagist.org/packages/blemli/partysan)

![partysan](assets/partysan.png)

Frequently I find myself thinking *"it would be nice if an artisan command existed for this".* This package solves that problem by adding hundreds of missing artisan commands. More in sight: **Keep the PR's coming!** 

## Installation

```bash
composer require blemli/partysan --dev
```

## Usage

just run `php artisan browse` and have fun.

## Commands

`browse`, `dependency:switch`, `db:create`, `open`

### db:create

Create the database for a connection if it does not exist yet. Supports all first-party Laravel drivers: `sqlite`, `mysql`, `mariadb`, `pgsql` and `sqlsrv`.

```bash
# create the database of the default connection
php artisan db:create

# create a specific database on a specific connection
php artisan db:create my_database --connection=pgsql

# mysql/mariadb charset & collation (defaults to the connection config)
php artisan db:create --charset=utf8mb4 --collation=utf8mb4_unicode_ci

# use a privileged account when the app user may not create databases
php artisan db:create --admin-user=root --admin-password=secret

# skip the confirmation prompt in production
php artisan db:create --force
```

For `sqlite` the database file (including missing parent directories) is created. For the server drivers the command connects to the server's maintenance database (`postgres` for pgsql, `master` for sqlsrv) with the connection's credentials — or the given admin credentials — and issues a `CREATE DATABASE` if it is missing.

### open

Open the app in your browser (also available as `visit`):

```bash
# open the current APP_URL (config('app.url'))
php artisan open

# open the APP_URL from .env.production
php artisan open --env=production
```

With `--env=<environment>` the URL is read directly from `.env.<environment>` in the project root, so it works even when the config is cached. Fails with a clear error if the file does not exist or has no `APP_URL`.

### dependency:switch

Switch a composer dependency between local symlink, VCS (git) and Packagist sources:

```bash
php artisan dependency:switch                          # interactive
php artisan dependency:switch vendor/package --local   # symlink a local checkout
php artisan dependency:switch vendor/package --vcs     # use a git repo/branch
php artisan dependency:switch vendor/package --packagist  # reset to Packagist
php artisan dependency:switch --list                   # list dependencies and their source
```

`--list` prints every dependency with its current mode, branch and PR status. The PR column is looked up via `gh` for switched packages only, so the listing stays fast; it is left empty when `gh` is unavailable. Combine `--list` with `--local`, `--vcs` or `--packagist` to filter by source, and with `--transitive` to include transitive dependencies.
