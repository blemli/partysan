# partysan

A collection of missing artisan commands, installed as a dev dependency in Laravel apps.

## Hard rules

- **Nothing app-specific, ever.** No app names, database file names, hostnames, domain terms or conventions of a single app anywhere in `src`, `config`, `tests` or the README. Test fixtures use generic names (`myapp`, `second.sqlite`, `users`). App-specific choices belong in the consuming app's own config file.
- **Works in every Laravel app.** Not just the blemli/verwalti apps: any Laravel project, with any database driver (sqlite, mysql, mariadb, pgsql, sqlsrv), any filesystem driver and any queue driver, in any combination. A command must either support the combination or fail with a clear message, never assume one.
- Infrastructure defaults (kamal, s6, `/data`, `/var/www/html`) may exist as configurable defaults only, and every command must still behave sensibly without them.

## Working here

- Before finishing a change, grep `src config tests README.md` for app names and app-only assumptions.
- Tests: `vendor/bin/pest`. Static analysis: `vendor/bin/phpstan analyse`. Formatting: `vendor/bin/pint`.
