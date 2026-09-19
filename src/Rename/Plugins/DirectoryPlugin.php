<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;

/**
 * Renames the project directory itself. Runs last, because the running process'
 * working directory goes stale afterwards.
 */
class DirectoryPlugin extends BasePlugin
{
    protected ?string $target = null;

    public function key(): string
    {
        return 'directory';
    }

    public function order(): int
    {
        return 1000;
    }

    public function applies(RenameContext $context): bool
    {
        return $context->old->matches(basename($context->basePath));
    }

    public function run(RenameContext $context): void
    {
        $current = basename($context->basePath);
        $new = $context->old->replaceIn($current, $context->new);

        if ($new === $current) {
            return;
        }

        $target = dirname($context->basePath).DIRECTORY_SEPARATOR.$new;

        if (! $context->confirm("Rename directory [{$current}] to [{$new}]?")) {
            $context->remind("Directory [{$current}] was not renamed.");

            return;
        }

        if ($context->move($context->basePath, $target)) {
            $this->target = $target;
            $context->movedTo = $target;
        }
    }

    public function checklist(RenameContext $context): array
    {
        return $this->target === null ? [] : [
            "The project now lives in [{$this->target}]. Your shell is still in the old path: run `cd {$this->target}`.",
            'IDE project files, local dev domains (Herd/Valet) and shell bookmarks still point at the old directory.',
        ];
    }
}
