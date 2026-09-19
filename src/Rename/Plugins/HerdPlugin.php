<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\Process;

/**
 * Carries a Laravel Herd (or Valet) site over to the new name. Linked sites are
 * unlinked and linked again from the renamed directory; parked sites follow the
 * directory name by themselves. In both cases a secured site is secured again
 * and an isolated PHP version is isolated again under the new name.
 */
class HerdPlugin extends BasePlugin
{
    protected ?string $binary = null;

    protected ?string $oldSite = null;

    protected ?string $newSite = null;

    public function key(): string
    {
        return 'herd';
    }

    public function order(): int
    {
        return 1010;
    }

    public function applies(RenameContext $context): bool
    {
        return $this->binary() !== null;
    }

    public function run(RenameContext $context): void
    {
        $binary = (string) $this->binary();
        $link = $this->link($context);
        $path = $context->currentPath();

        if ($link !== null) {
            $oldName = $link['name'];
            $newName = $context->old->replaceIn($oldName, $context->new);

            if ($newName === $oldName) {
                $newName = $context->new->kebab();
            }
        } else {
            $oldName = basename($context->basePath);
            $newName = basename($path);

            if ($oldName === $newName) {
                $context->record("{$binary}: the site is parked and the directory kept its name; nothing to do");

                return;
            }
        }

        $tld = $this->tld($oldName) ?? 'test';
        $secured = in_array("{$oldName}.{$tld}", $this->secured(), true);
        $php = $this->isolated()["{$oldName}.{$tld}"] ?? null;

        $steps = [];

        if ($link !== null) {
            $steps[] = [$binary, 'unlink', $oldName];
            $steps[] = [$binary, 'link', $newName];
        }

        if ($secured) {
            $steps[] = [$binary, 'unsecure', $oldName];
            $steps[] = [$binary, 'secure', $newName];
        }

        if ($php !== null) {
            $steps[] = [$binary, 'unisolate', "--site={$oldName}"];
            $steps[] = [$binary, 'isolate', $php, "--site={$newName}"];
        }

        if ($steps === []) {
            $context->record("{$binary}: parked, http only, default PHP; the site is now {$newName}.{$tld}");
            $this->oldSite = "{$oldName}.{$tld}";
            $this->newSite = "{$newName}.{$tld}";

            return;
        }

        $summary = implode(', ', array_filter([
            $link !== null ? 're-link' : null,
            $secured ? 'https' : null,
            $php !== null ? "PHP {$php}" : null,
        ]));

        if (! $context->confirm("{$binary}: carry [{$oldName}.{$tld}] over to [{$newName}.{$tld}] ({$summary})?")) {
            $context->remind("{$binary}: carry the site over by hand: ".implode(' && ', array_map(fn ($step) => implode(' ', $step), $steps)));

            return;
        }

        foreach ($steps as $step) {
            $result = $context->run($step, $path);

            if ($result !== null && ! $result->successful()) {
                $context->warn("{$binary}: ".implode(' ', $step).' failed: '.trim($result->errorOutput() ?: $result->output()));
                $context->remind("{$binary}: finish by hand: ".implode(' && ', array_map(fn ($s) => implode(' ', $s), array_slice($steps, (int) array_search($step, $steps, true)))));

                return;
            }
        }

        $this->oldSite = "{$oldName}.{$tld}";
        $this->newSite = "{$newName}.{$tld}";
    }

    public function checklist(RenameContext $context): array
    {
        return $this->oldSite === null ? [] : [
            "The dev URL changed from {$this->oldSite} to {$this->newSite}: update bookmarks, OAuth redirect URIs and any APP_URL that was not rewritten.",
        ];
    }

    /**
     * The `links` row for this project, matched by path or by site name.
     *
     * @return array{name: string, path: string}|null
     */
    protected function link(RenameContext $context): ?array
    {
        $paths = array_unique(array_filter([
            $context->basePath,
            realpath($context->basePath) ?: null,
            $context->movedTo,
            $context->movedTo ? (realpath($context->movedTo) ?: null) : null,
        ]));

        foreach ($this->table('links') as $row) {
            $name = $row['site'] ?? array_values($row)[0] ?? '';
            $path = rtrim($row['path'] ?? '', '/');

            if ($path === '') {
                foreach ($row as $cell) {
                    if (str_starts_with($cell, '/')) {
                        $path = rtrim($cell, '/');
                    }
                }
            }

            if (in_array($path, $paths, true) || preg_match('/^(?:'.preg_quote($context->old->kebab(), '/').'|'.preg_quote($context->old->flat(), '/').')$/i', $name)) {
                return ['name' => $name, 'path' => $path];
            }
        }

        return null;
    }

    /** @return list<string> sites like "my-app.test" */
    protected function secured(): array
    {
        return array_values(array_filter(array_map(fn ($row) => $row['site'] ?? array_values($row)[0] ?? '', $this->table('secured'))));
    }

    /** @return array<string, string> site => php version */
    protected function isolated(): array
    {
        $versions = [];

        foreach ($this->table('isolated') as $row) {
            $site = $row['path'] ?? $row['site'] ?? array_values($row)[0] ?? '';
            $version = $row['php version'] ?? array_values($row)[1] ?? '';

            if ($site !== '' && $version !== '') {
                $versions[$site] = $version;
            }
        }

        return $versions;
    }

    protected function tld(string $name): ?string
    {
        foreach (array_merge($this->secured(), array_keys($this->isolated())) as $site) {
            if (preg_match('/^'.preg_quote($name, '/').'\.([a-z0-9-]+)$/i', $site, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Parse one of the CLI's ascii tables into rows keyed by lower-cased header.
     *
     * @return list<array<string, string>>
     */
    protected function table(string $command): array
    {
        $result = Process::run([(string) $this->binary(), $command]);

        if (! $result->successful()) {
            return [];
        }

        $header = null;
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $result->output()) ?: [] as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim(trim($line), '|')));

            if ($header === null) {
                $header = array_map('strtolower', $cells);

                continue;
            }

            $row = [];

            foreach ($cells as $index => $cell) {
                $row[$header[$index] ?? (string) $index] = $cell;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    protected function binary(): ?string
    {
        if ($this->binary !== null) {
            return $this->binary ?: null;
        }

        foreach (['herd', 'valet'] as $candidate) {
            if (Process::run([$candidate, '--version'])->successful()) {
                return $this->binary = $candidate;
            }
        }

        $this->binary = '';

        return null;
    }
}
