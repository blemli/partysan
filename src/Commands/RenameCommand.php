<?php

namespace Blemli\Partysan\Commands;

use Blemli\Partysan\Partysan;
use Blemli\Partysan\Rename\AppName;
use Blemli\Partysan\Rename\RenameContext;
use Blemli\Partysan\Rename\RenamePlugin;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'rename')]
class RenameCommand extends Command
{
    protected $signature = 'rename
                            {name? : The new app name, e.g. "New App" or new-app}
                            {--from= : The old app name (defaults to APP_NAME)}
                            {--only= : Comma-separated plugin keys to run}
                            {--without= : Comma-separated plugin keys to skip}
                            {--plugins : List the rename plugins and whether they apply}
                            {--dry-run : Show what would change without changing anything}
                            {--force : Skip confirmations and the clean-working-tree check}';

    protected $description = 'Rename the app everywhere: env files, composer, namespace, deploy config, GitHub, directory, and flag what is left';

    public function handle(Partysan $partysan): int
    {
        $old = $this->resolveOldName();

        if ($old === null) {
            return self::FAILURE;
        }

        $newInput = $this->argument('name') ?: ($this->input->isInteractive() ? $this->ask('New app name') : null);

        if (! is_string($newInput) || trim($newInput) === '') {
            $this->components->error('Pass the new app name as the first argument.');

            return self::FAILURE;
        }

        $new = new AppName(trim($newInput));

        if (! $this->validate($old, $new)) {
            return self::FAILURE;
        }

        $context = new RenameContext(
            old: $old,
            new: $new,
            basePath: base_path(),
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
            io: $this,
            isInteractive: $this->input->isInteractive(),
        );

        $plugins = $this->selectPlugins($partysan->renamePlugins());

        if ($plugins === null) {
            return self::FAILURE;
        }

        if ($this->option('plugins')) {
            $this->listPlugins($plugins, $context);

            return self::SUCCESS;
        }

        if (! $this->preflight($context)) {
            return self::FAILURE;
        }

        $this->components->info(sprintf('%s [%s] to [%s]', $context->dryRun ? 'Would rename' : 'Renaming', $old->title(), $new->title()));
        $this->components->twoColumnDetail('spellings', implode(', ', array_map(fn ($o, $n) => "{$o} → {$n}", array_keys($old->replacementsTo($new)), $old->replacementsTo($new))));

        $applied = [];

        foreach ($plugins as $plugin) {
            if (! $plugin->applies($context)) {
                $this->components->twoColumnDetail($plugin->key(), '<fg=gray>skipped (nothing to do)</>');

                continue;
            }

            $this->components->twoColumnDetail($plugin->key(), $context->dryRun ? '<fg=yellow>dry-run</>' : '<fg=green>running</>');
            $plugin->run($context);
            $applied[] = $plugin;
        }

        $this->newLine();
        $this->components->info($context->dryRun ? 'Dry run finished; nothing was changed.' : 'Rename finished.');

        $checklist = $context->checklist();

        foreach ($applied as $plugin) {
            $checklist = array_merge($checklist, $plugin->checklist($context));
        }

        $checklist = array_merge($checklist, $this->generalChecklist());

        $this->components->warn('Manual follow-ups:');
        $this->components->bulletList($checklist);

        return self::SUCCESS;
    }

    protected function resolveOldName(): ?AppName
    {
        $from = $this->option('from');

        if (is_string($from) && trim($from) !== '') {
            return new AppName(trim($from));
        }

        $appName = $this->envValue('APP_NAME') ?? config('app.name');
        $appName = is_string($appName) ? trim($appName) : '';

        $candidates = array_filter([
            'APP_NAME' => $appName,
            'directory' => basename(base_path()),
            'composer.json' => $this->composerName(),
        ]);

        if ($candidates === []) {
            $this->components->error('Could not detect the current app name. Pass it with --from.');

            return null;
        }

        $distinct = collect($candidates)->unique(fn (string $value) => (new AppName($value))->kebab());

        if ($distinct->count() === 1) {
            return new AppName((string) $distinct->first());
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('The current app name is ambiguous ('.$distinct->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ').'). Pass it with --from.');

            return null;
        }

        $choice = $this->choice(
            'Which is the current app name?',
            $distinct->map(fn ($value, $source) => "{$value} ({$source})")->values()->all(),
            0,
        );

        return new AppName((string) preg_replace('/ \([^)]*\)$/', '', (string) $choice));
    }

    protected function envValue(string $key): ?string
    {
        $path = base_path('.env');

        if (! File::isFile($path)) {
            return null;
        }

        try {
            $value = Dotenv::parse(File::get($path))[$key] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    protected function composerName(): ?string
    {
        $path = base_path('composer.json');

        if (! File::isFile($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);
        $name = is_array($data) ? ($data['name'] ?? null) : null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return str_contains($name, '/') ? substr($name, strpos($name, '/') + 1) : $name;
    }

    protected function validate(AppName $old, AppName $new): bool
    {
        if ($new->kebab() === '') {
            $this->components->error("[{$new->input}] does not contain any letters or digits.");

            return false;
        }

        if (! preg_match('/^[A-Za-z]/', $new->studly())) {
            $this->components->error("[{$new->input}] must start with a letter to be usable as a namespace.");

            return false;
        }

        if ($old->kebab() === $new->kebab()) {
            $this->components->error("The app is already called [{$old->input}]; pass a different name.");

            return false;
        }

        return true;
    }

    /**
     * @param  list<RenamePlugin>  $plugins
     * @return list<RenamePlugin>|null
     */
    protected function selectPlugins(array $plugins): ?array
    {
        $known = array_map(fn (RenamePlugin $plugin) => $plugin->key(), $plugins);

        foreach (['only', 'without'] as $option) {
            $keys = $this->keys($option);
            $unknown = array_diff($keys, $known);

            if ($unknown !== []) {
                $this->components->error(sprintf('Unknown plugin(s) [%s]. Available: %s.', implode(', ', $unknown), implode(', ', $known)));

                return null;
            }
        }

        $only = $this->keys('only');
        $without = $this->keys('without');

        return array_values(array_filter(
            $plugins,
            fn (RenamePlugin $plugin) => ($only === [] || in_array($plugin->key(), $only, true)) && ! in_array($plugin->key(), $without, true),
        ));
    }

    /** @return list<string> */
    protected function keys(string $option): array
    {
        $value = $this->option($option);

        return is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
    }

    /**
     * @param  list<RenamePlugin>  $plugins
     */
    protected function listPlugins(array $plugins, RenameContext $context): void
    {
        $this->table(
            ['Plugin', 'Order', 'Applies', 'Class'],
            array_map(fn (RenamePlugin $plugin) => [
                $plugin->key(),
                $plugin->order(),
                $plugin->applies($context) ? 'yes' : 'no',
                $plugin::class,
            ], $plugins),
        );
    }

    protected function preflight(RenameContext $context): bool
    {
        if ($context->dryRun || $context->force) {
            return true;
        }

        $status = Process::path($context->basePath)->run(['git', 'status', '--porcelain']);

        if ($status->successful() && trim($status->output()) !== '') {
            $this->components->error('The working tree has uncommitted changes. Commit or stash them first (so `git checkout .` can undo the rename), or pass --force.');

            return false;
        }

        return $context->confirm(sprintf('Rename [%s] to [%s]?', $context->old->title(), $context->new->title()));
    }

    /** @return list<string> */
    protected function generalChecklist(): array
    {
        return [
            'Cache, Redis and Horizon prefixes derive from APP_NAME: drain the queues before deploying, or jobs queued under the old prefix are never picked up.',
            'Processes started from the old directory (queue workers, Horizon, `artisan serve`, the Vite dev server, IDE terminals) keep the old cwd: restart them.',
            'Local dev domains, hosts entries, DNS records and TLS certificates for the new hostname.',
            'Hosting platforms without an API hook (e.g. Laravel Cloud): rename the application, environment, custom domain and database in their dashboard.',
            'Third parties that know the app by name: OAuth apps and callback URLs, mail sender domains, error tracking, uptime monitors, container registries.',
            'Binary assets (logos, screenshots) whose content shows the old name.',
            'Git history and branch names keep the old name.',
        ];
    }
}
