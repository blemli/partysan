<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * IDE project files are usually gitignored, so the scan never sees them. PhpStorm
 * keeps the project name in .idea/.name and .idea/<name>.iml (referenced from
 * .idea/modules.xml); VS Code keeps folder paths in <name>.code-workspace.
 */
class IdePlugin extends BasePlugin
{
    protected bool $touched = false;

    public function key(): string
    {
        return 'ide';
    }

    public function order(): int
    {
        return 80;
    }

    public function applies(RenameContext $context): bool
    {
        return File::isDirectory($context->path('.idea')) || $this->workspaces($context) !== [];
    }

    public function run(RenameContext $context): void
    {
        $this->phpstorm($context);
        $this->vscode($context);
    }

    protected function phpstorm(RenameContext $context): void
    {
        $idea = $context->path('.idea');

        if (! File::isDirectory($idea)) {
            return;
        }

        $name = "{$idea}/.name";

        if (File::isFile($name) && $context->old->matches(trim(File::get($name)))) {
            $context->put($name, $context->old->replaceIn(trim(File::get($name)), $context->new)."\n", '.idea/.name');
            $this->touched = true;
        }

        foreach (File::glob("{$idea}/*.iml") ?: [] as $iml) {
            $base = basename($iml, '.iml');

            if (! $context->old->matches($base)) {
                continue;
            }

            $newBase = $context->old->replaceIn($base, $context->new);

            if ($newBase === $base) {
                continue;
            }

            if ($context->move($iml, "{$idea}/{$newBase}.iml")) {
                $context->replaceInFile("{$idea}/modules.xml", '/'.preg_quote("{$base}.iml", '/').'/', "{$newBase}.iml");
                $this->touched = true;
            }
        }
    }

    protected function vscode(RenameContext $context): void
    {
        foreach ($this->workspaces($context) as $workspace) {
            $count = $context->replaceInFile($workspace);
            $base = basename($workspace, '.code-workspace');
            $newBase = $context->old->replaceIn($base, $context->new);

            if ($newBase !== $base) {
                $context->move($workspace, dirname($workspace)."/{$newBase}.code-workspace");
                $this->touched = true;
            } elseif ($count > 0) {
                $this->touched = true;
            }
        }
    }

    /** @return list<string> */
    protected function workspaces(RenameContext $context): array
    {
        return array_values(array_filter(File::glob($context->path('*.code-workspace')) ?: [], fn ($path) => File::isFile($path)));
    }

    public function checklist(RenameContext $context): array
    {
        $items = [];

        if ($context->movedTo !== null) {
            $items[] = 'IDE: the recent-projects list still points at the old directory; reopen the project from '.$context->movedTo.'.';
        }

        if ($this->touched) {
            $items[] = 'IDE: run configurations, PHP interpreter paths and Xdebug path mappings may still reference the old name or path.';
        }

        return $items;
    }
}
