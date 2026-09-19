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

`browse`, `dependency:switch`, `db:create`, `open`, `rename`

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

### rename

Rename the app everywhere at once and get told about everything that is left:

```bash
php artisan rename "New App"                  # old name is read from APP_NAME
php artisan rename new-app --from="Old App"   # when APP_NAME, directory and composer name disagree
php artisan rename "New App" --dry-run        # show what would happen
php artisan rename "New App" --only=env,composer
php artisan rename "New App" --without=github,directory
php artisan rename --plugins                  # list plugins and whether they apply here
```

Every spelling is handled: `My App`, `MyApp`, `my-app`, `my_app`, `myapp`, `MY_APP` and `my app` each become the matching spelling of the new name, matched as whole words only (`art` never touches `artisan`, but `MyAppServiceProvider` becomes `NewAppServiceProvider`).

The command requires a clean git working tree (so `git checkout .` undoes the file changes) unless `--force` is given, and runs these plugins in order:

| plugin | what it does |
|---|---|
| `env` | rewrites the name in every `.env*` file: `APP_NAME` and derived values like `DB_DATABASE`, `APP_URL`, `SESSION_COOKIE`, `VITE_APP_NAME` |
| `composer` | `name`, `description` and repository URLs (`homepage`, `support.*`, `repository`, `bugs`) in `composer.json`, `package.json` and `package-lock.json`, without reformatting them |
| `readme` | rewrites the README title when it is nothing but the old name; other mentions are left to `scan` |
| `namespace` | renames the root PHP namespace when it is not the default `App`, then runs `composer dump-autoload` |
| `kamal` | `config/deploy*.yml` and everything under `.kamal/` (service, image, hosts, volumes, secrets) |
| `compose` | docker compose files; warns that compose derives container and volume names from the directory, with the commands to pin the project name or copy the volumes |
| `1password` | lists items whose title or URL carries the name as a checkbox list (all ticked; untick what should keep its name) and renames the selected ones via the `op` CLI; skipped when `op` is missing or signed out |
| `forge` | with a `FORGE_API_TOKEN`: renames matching Forge sites (domain, git repository, remote `.env`, deployment script) |
| `ide` | PhpStorm `.idea/.name`, `.idea/<name>.iml` plus `modules.xml`, and VS Code `<name>.code-workspace` files, which are gitignored and therefore invisible to `scan` |
| `github` | `gh repo rename`, then makes sure the local `origin` remote points at the new URL |
| `scan` | lists every remaining occurrence in tracked and untracked files (any casing) with line numbers, then shows the files as a checkbox list (all ticked; untick what must keep the old name, ctrl+a toggles all) with a syntax-coloured preview of the highlighted file's matching lines plus one line of context underneath; `--force` and `--no-interaction` take the default and replace everything, `--dry-run` only reports; same for files and directories whose name carries the old name |
| `caches` | `config:clear`, `route:clear`, `view:clear`, `event:clear`, `clear-compiled` so `bootstrap/cache` and compiled views forget the old name and path |
| `directory` | renames the project directory and prints the `cd` to run |
| `herd` | with Herd or Valet installed: a linked site is unlinked and linked again from the renamed directory; a parked site follows the directory name by itself. Either way, if `herd secured` listed the old site it is secured again, and an isolated PHP version (`herd isolated`) is isolated again under the new name |

Finally a checklist of things it cannot do safely is printed: the database itself (mysql has no `RENAME DATABASE`; run `db:create` and migrate), kamal containers and volumes on the servers, the session cookie name (everyone is logged out), cache and queue prefixes (drain queues first), processes still running from the old directory, DNS and TLS, OAuth callback URLs, monitors, registries, IDE project files, binary assets and hosting dashboards such as Laravel Cloud.

#### Rename plugins

Anything else that knows your app by name can be hooked in as a plugin. Implement `Blemli\Partysan\Rename\RenamePlugin` (or extend `BasePlugin`) and register it either in `config/partysan.php` under `rename.plugins`, or from a service provider:

```php
app(\Blemli\Partysan\Partysan::class)->renamePlugin(MyRegistryPlugin::class);
```

A plugin gets a `RenameContext` with the old and new `AppName` (all spellings, `replaceIn()`, `matches()`), dry-run aware helpers (`replaceInFile()`, `put()`, `move()`, `run()`), `confirm()` and `choose()` (a checkbox list) that honour `--force`/`--no-interaction`/`--dry-run`, `record()` for the change log and `remind()` for the final checklist. `order()` decides when it runs (built-ins: 10 env, 20 composer, 25 readme … 900 scan, 950 caches, 1000 directory, 1010 herd; plugins after the move read `$context->currentPath()`) and `applies()` lets it skip projects it does not concern. Plugins are addressed by `key()` in `--only`/`--without`.

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
