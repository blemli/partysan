<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Rewrites the README title when it is nothing but the old app name. Other
 * mentions in the README are left to the residual scan.
 */
class ReadmePlugin extends BasePlugin
{
    public function key(): string
    {
        return 'readme';
    }

    public function order(): int
    {
        return 25;
    }

    public function applies(RenameContext $context): bool
    {
        $readme = $this->readme($context);

        return $readme !== null && $this->title($context, File::get($readme)) !== null;
    }

    public function run(RenameContext $context): void
    {
        $readme = (string) $this->readme($context);
        $contents = File::get($readme);
        $title = $this->title($context, $contents);

        if ($title === null) {
            return;
        }

        [$line, $prefix, $name, $suffix] = $title;

        $new = $context->old->replaceIn($name, $context->new);

        if ($new === $name) {
            $new = $context->new->title();
        }

        $lines = preg_split('/(?<=\n)/', $contents) ?: [];
        $lines[$line] = $prefix.$new.$suffix;

        $context->put($readme, implode('', $lines), 'title of '.$context->relative($readme).": {$name} -> {$new}");
    }

    protected function readme(RenameContext $context): ?string
    {
        foreach (File::glob($context->path('README*')) ?: [] as $path) {
            if (File::isFile($path) && $context->isTextFile($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The first markdown H1 when it consists of the old name only.
     *
     * @return array{int, string, string, string}|null [line index, prefix, name, suffix]
     */
    protected function title(RenameContext $context, string $contents): ?array
    {
        foreach (preg_split('/(?<=\n)/', $contents) ?: [] as $index => $line) {
            if (! preg_match('/^(#[ \t]+)(.*?)([ \t]*#*[ \t]*\r?\n?)$/', $line, $match)) {
                continue;
            }

            $name = $match[2];
            $pattern = str_replace('(?<![A-Za-z0-9])', '^', str_replace('(?![A-Za-z0-9])', '$', $context->old->scanPattern()));

            return preg_match($pattern, $name) === 1 ? [$index, $match[1], $name, $match[3]] : null;
        }

        return null;
    }
}
