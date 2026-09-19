<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\Process;

/**
 * Renames the GitHub repository with `gh repo rename`, which also updates the
 * local origin remote.
 */
class GitHubPlugin extends BasePlugin
{
    protected bool $renamed = false;

    public function key(): string
    {
        return 'github';
    }

    public function order(): int
    {
        return 800;
    }

    public function applies(RenameContext $context): bool
    {
        $remote = Process::path($context->basePath)->run(['git', 'remote', 'get-url', 'origin']);

        return $remote->successful() && str_contains($remote->output(), 'github.com');
    }

    public function run(RenameContext $context): void
    {
        $remote = trim(Process::path($context->basePath)->run(['git', 'remote', 'get-url', 'origin'])->output());
        $repository = preg_replace('/\.git$/', '', basename($remote)) ?? '';

        if (! $context->old->matches($repository)) {
            $context->record("github: repository [{$repository}] does not carry the old name; nothing to rename");

            return;
        }

        $new = $context->old->replaceIn($repository, $context->new);

        if (! $context->confirm("Rename GitHub repository [{$repository}] to [{$new}]?")) {
            $context->remind("GitHub repository [{$repository}] was not renamed.");

            return;
        }

        $result = $context->run(['gh', 'repo', 'rename', $new, '--yes']);

        if ($result !== null && ! $result->successful()) {
            $context->warn('gh repo rename failed: '.trim($result->errorOutput()));
            $context->remind("Rename the GitHub repository to [{$new}] manually (gh repo rename {$new}).");

            return;
        }

        $this->renamed = true;
    }

    public function checklist(RenameContext $context): array
    {
        return $this->renamed ? [
            'GitHub redirects the old repository URL, but other clones, CI secrets, deploy keys and webhooks scoped to the old name should be updated.',
        ] : [];
    }
}
