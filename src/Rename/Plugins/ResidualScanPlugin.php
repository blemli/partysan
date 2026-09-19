<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Console\CodePreview;
use Blemli\Partysan\Console\SnippetHighlighter;
use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;

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

        $sources = [];

        foreach ($context->files() as $file) {
            if (! $context->isTextFile($file)) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', File::get($file)) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[$file][] = [$index + 1, trim($line)];
                    $sources[$file] = $lines;
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
                    $context->line(sprintf('    <fg=gray>%5d</>  %s', $number, OutputFormatter::escape(mb_strimwidth($text, 0, 110, '…'))));
                }

                if (count($lines) > 10) {
                    $context->line('    <fg=gray>… and '.(count($lines) - 10).' more</>');
                }

            }

            $this->offer($context, $hits, $sources);
        }

        $this->renamePaths($context);
    }

    /**
     * @param  array<string, list<array{int, string}>>  $hits
     */
    protected function listHits(RenameContext $context, array $hits): void
    {
        foreach ($hits as $file => $lines) {
            $context->line('  <fg=yellow>'.$context->relative($file).'</>');

            foreach (array_slice($lines, 0, 10) as [$number, $text]) {
                $context->line(sprintf('    <fg=gray>%5d</>  %s', $number, OutputFormatter::escape(mb_strimwidth($text, 0, 110, '…'))));
            }

            if (count($lines) > 10) {
                $context->line('    <fg=gray>… and '.(count($lines) - 10).' more</>');
            }
        }
    }

    /**
     * @param  array<string, list<array{int, string}>>  $hits
     * @param  array<string, list<string>>  $sources  full file contents, by line
     */
    protected function offer(RenameContext $context, array $hits, array $sources): void
    {
        $options = [];

        foreach ($hits as $file => $lines) {
            $options[$file] = sprintf('%s  (%d)', $context->relative($file), count($lines));
        }

        $width = max(40, ($context->io->getOutput()->isDecorated() ? (new Terminal)->getWidth() : 120) - 16);
        $preview = new CodePreview(new SnippetHighlighter($context->old->scanPattern()), $width);

        $snippet = fn (int|string|null $file): array => $preview->lines(
            array_map(fn ($hit) => $hit[0], $hits[$file] ?? []),
            $sources[$file] ?? [],
            (bool) preg_match('/\.(php|blade\.php|stub)$/', (string) $file),
        );

        // Everything is ticked; interactively you untick what must keep the old
        // name, --force and --no-interaction take the default and replace all.
        $selected = $context->choose(
            'Replace the old name in these files? Untick files whose old name must stay (public APIs, database keys, …).',
            $options,
            array_keys($options),
            $snippet,
        );

        foreach ($hits as $file => $lines) {
            $relative = $context->relative($file);

            if (! in_array($file, $selected, true)) {
                $this->remaining[] = $relative;

                continue;
            }

            $context->replaceInFile($file);

            if ($context->old->matches(File::get($file))) {
                $this->remaining[] = $relative.' (spellings not in the known variants)';
            }
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

        $renames = [];

        foreach ($paths as $relative) {
            $newRelative = dirname($relative) === '.'
                ? $context->old->replaceIn(basename($relative), $context->new)
                : dirname($relative).'/'.$context->old->replaceIn(basename($relative), $context->new);

            if ($newRelative !== $relative) {
                $renames[$relative] = $newRelative;
            }
        }

        if ($renames === []) {
            return;
        }

        $options = [];

        foreach ($renames as $from => $to) {
            $options[$from] = "{$from} → {$to}";
        }

        $selected = $context->choose('Rename these files and directories?', $options, array_keys($options));

        foreach ($renames as $from => $to) {
            if (in_array($from, $selected, true)) {
                $context->move($context->path($from), $context->path($to));
            } else {
                $this->remaining[] = "{$from} (path name)";
            }
        }
    }

    public function checklist(RenameContext $context): array
    {
        return array_map(fn (string $item) => "Still mentions the old name: {$item}", $this->remaining);
    }
}
