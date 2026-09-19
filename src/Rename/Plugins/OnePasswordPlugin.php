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
        $candidates = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $title = (string) ($item['title'] ?? '');
            $urls = array_map(fn ($url) => (string) ($url['href'] ?? ''), $item['urls'] ?? []);
            $primary = collect($item['urls'] ?? [])->firstWhere('primary', true)['href'] ?? ($urls[0] ?? null);

            $primary = is_string($primary) ? $primary : null;
            $newUrl = $primary !== null && $context->old->matches($primary) ? $context->old->replaceIn($primary, $context->new) : null;

            if (! $context->old->matches($title) && $newUrl === null) {
                continue;
            }

            $newTitle = $context->old->replaceIn($title, $context->new);

            $candidates[(string) $item['id']] = [
                'edit' => array_merge(['op', 'item', 'edit', (string) $item['id'], '--title', $newTitle], $newUrl !== null ? ['--url', $newUrl] : []),
                'label' => "{$title} → {$newTitle}".($newUrl !== null ? "  ({$primary} → {$newUrl})" : ''),
            ];
        }

        if ($candidates === []) {
            $context->record('1Password: no items to rename');

            return;
        }

        $selected = $context->choose(
            '1Password: rename these items?',
            array_map(fn ($candidate) => $candidate['label'], $candidates),
            array_keys($candidates),
        );

        foreach ($candidates as $id => $candidate) {
            if (in_array($id, $selected, true)) {
                $context->run($candidate['edit']);
            } else {
                $context->record("1Password: kept [{$candidate['label']}]");
            }
        }
    }
}
