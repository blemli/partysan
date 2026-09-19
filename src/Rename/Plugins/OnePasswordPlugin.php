<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\Process;

/**
 * Renames 1Password items whose title (or primary URL) carries the old app name,
 * using the `op` CLI. Skipped when `op` is missing or not signed in.
 */
class OnePasswordPlugin extends BasePlugin
{
    public function key(): string
    {
        return '1password';
    }

    public function order(): int
    {
        return 50;
    }

    public function applies(RenameContext $context): bool
    {
        return Process::run(['op', '--version'])->successful();
    }

    public function run(RenameContext $context): void
    {
        if (! Process::run(['op', 'whoami'])->successful()) {
            $context->warn('1Password: not signed in (run `op signin`); skipped.');
            $context->remind('1Password items were not renamed because `op` was not signed in.');

            return;
        }

        $command = ['op', 'item', 'list', '--format', 'json'];
        $vault = config('partysan.rename.onepassword.vault');

        if (is_string($vault) && $vault !== '') {
            array_push($command, '--vault', $vault);
        }

        $result = Process::run($command);

        if (! $result->successful()) {
            $context->warn('1Password: could not list items: '.trim($result->errorOutput()));

            return;
        }

        $items = json_decode($result->output(), true);
        $renamed = 0;

        foreach (is_array($items) ? $items : [] as $item) {
            $title = (string) ($item['title'] ?? '');
            $urls = array_map(fn ($url) => (string) ($url['href'] ?? ''), $item['urls'] ?? []);
            $primary = collect($item['urls'] ?? [])->firstWhere('primary', true)['href'] ?? ($urls[0] ?? null);

            $titleMatches = $context->old->matches($title);
            $urlMatches = is_string($primary) && $context->old->matches($primary);

            if (! $titleMatches && ! $urlMatches) {
                continue;
            }

            $newTitle = $context->old->replaceIn($title, $context->new);
            $newUrl = is_string($primary) ? $context->old->replaceIn($primary, $context->new) : null;

            if (! $context->confirm("1Password: rename item [{$title}] to [{$newTitle}]".($urlMatches ? " and its URL to [{$newUrl}]" : '').'?')) {
                continue;
            }

            $edit = ['op', 'item', 'edit', (string) $item['id'], '--title', $newTitle];

            if ($urlMatches && $newUrl !== null) {
                array_push($edit, '--url', $newUrl);
            }

            $context->run($edit);
            $renamed++;
        }

        if ($renamed === 0) {
            $context->record('1Password: no items to rename');
        }
    }
}
