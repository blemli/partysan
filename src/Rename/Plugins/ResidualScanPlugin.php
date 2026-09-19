<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Lists every remaining occurrence of the old name (any casing) in project files
 * and file names, and offers to replace them file by file.
 */
class ResidualScanPlugin extends BasePlugin
{
    /** @var list<string> */
    protected array $remaining = [];

    public function key(): string
    {
        return 'scan';
    }

    public function order(): int
    {
        return 900;
    }

    public function run(RenameContext $context): void
    {
        $pattern = $context->old->scanPattern();
        $hits = [];

        foreach ($context->files() as $file) {
            if (! $context->isTextFile($file)) {
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', File::get($file)) ?: [] as $index => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[$file][] = [$index + 1, trim($line)];
                }
            }
        }

        if ($hits === []) {
            $context->info('No other occurrences of the old name found.');
        } else {
            $context->warn(count($hits).' file(s) still mention the old name:');

            foreach ($hits as $file => $lines) {
                $relative = $context->relative($file);
                $context->line("  <fg=yellow>{$relative}</>");

                foreach (array_slice($lines, 0, 10) as [$number, $text]) {
                    $context->line(sprintf('    <fg=gray>%5d</>  %s', $number, e(mb_strimwidth($text, 0, 110, '…'))));
                }

                if (count($lines) > 10) {
                    $context->line('    <fg=gray>… and '.(count($lines) - 10).' more</>');
                }

                $this->offer($context, $file, count($lines));
            }
        }

        $this->renamePaths($context);
    }

    protected function offer(RenameContext $context, string $file, int $count): void
    {
        $relative = $context->relative($file);

        if ($context->dryRun || ! $context->interactive()) {
            $this->remaining[] = $relative;

            return;
        }

        if ($context->confirm("Replace {$count} occurrence(s) in [{$relative}]?", false)) {
            $context->replaceInFile($file);

            if ($context->old->matches(File::get($file))) {
                $this->remaining[] = $relative.' (spellings not in the known variants)';
            }
        } else {
            $this->remaining[] = $relative;
        }
    }

    protected function renamePaths(RenameContext $context): void
    {
        $paths = [];

        foreach ($context->files() as $file) {
            $relative = $context->relative($file);
            $parts = explode('/', $relative);

            for ($i = 0; $i < count($parts); $i++) {
                if ($context->old->matches($parts[$i])) {
                    $paths[implode('/', array_slice($parts, 0, $i + 1))] = true;
                }
            }
        }

        $paths = array_keys($paths);
        usort($paths, fn (string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($paths as $relative) {
            $newRelative = dirname($relative) === '.'
                ? $context->old->replaceIn(basename($relative), $context->new)
                : dirname($relative).'/'.$context->old->replaceIn(basename($relative), $context->new);

            if ($newRelative === $relative) {
                continue;
            }

            if ($context->dryRun || ! $context->interactive()) {
                $this->remaining[] = "{$relative} (path name)";

                continue;
            }

            if ($context->confirm("Rename [{$relative}] to [{$newRelative}]?", false)) {
                $context->move($context->path($relative), $context->path($newRelative));
            } else {
                $this->remaining[] = "{$relative} (path name)";
            }
        }
    }

    public function checklist(RenameContext $context): array
    {
        return array_map(fn (string $item) => "Still mentions the old name: {$item}", $this->remaining);
    }
}
