<?php

namespace Blemli\Partysan\Rename;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/**
 * Everything a rename plugin needs: the old and new name, the project path,
 * dry-run aware file and process helpers, and the console for output and prompts.
 */
class RenameContext
{
    /** @var list<string> */
    protected array $changes = [];

    /** @var list<string> */
    protected array $checklist = [];

    /** @var list<string>|null */
    protected ?array $files = null;

    /** @var list<string> */
    public const IGNORED_DIRECTORIES = ['.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'public/hot', '.idea', '.vscode'];

    public function __construct(
        public readonly AppName $old,
        public readonly AppName $new,
        public readonly string $basePath,
        public readonly bool $dryRun,
        public readonly bool $force,
        public readonly Command $io,
        public readonly bool $isInteractive = true,
    ) {}

    public function components(): Factory
    {
        return $this->io->outputComponents();
    }

    public function info(string $message): void
    {
        $this->components()->info($message);
    }

    public function warn(string $message): void
    {
        $this->components()->warn($message);
    }

    public function line(string $message): void
    {
        $this->io->line($message);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : $this->basePath.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    public function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), '/\\') ?: '.';
    }

    /**
     * Whether to ask the user. Respects --force and --no-interaction.
     */
    public function confirm(string $question, bool $default = true): bool
    {
        if ($this->force || $this->dryRun || ! $this->isInteractive) {
            return $default;
        }

        return (bool) $this->io->confirm($question, $default);
    }

    public function interactive(): bool
    {
        return ! $this->force && ! $this->dryRun && $this->isInteractive;
    }

    /**
     * Replace every spelling of the old name in a text file. Returns the number of replacements.
     */
    public function replaceInFile(string $path, ?string $pattern = null, ?string $replacement = null): int
    {
        if (! File::isFile($path) || ! $this->isTextFile($path)) {
            return 0;
        }

        $contents = File::get($path);
        $count = 0;

        $updated = $pattern === null
            ? $this->old->replaceIn($contents, $this->new, $count)
            : (preg_replace($pattern, (string) $replacement, $contents, -1, $count) ?? $contents);

        if ($count > 0) {
            $this->put($path, $updated, "{$count} occurrence(s) in ".$this->relative($path));
        }

        return $count;
    }

    public function put(string $path, string $contents, ?string $note = null): void
    {
        $note ??= 'wrote '.$this->relative($path);

        if (! $this->dryRun) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        $this->record($note);
    }

    public function move(string $from, string $to): bool
    {
        if (File::exists($to)) {
            $this->warn('Cannot rename ['.$this->relative($from).']: ['.$this->relative($to).'] already exists.');

            return false;
        }

        if (! $this->dryRun && ! File::move($from, $to)) {
            $this->warn('Could not rename ['.$this->relative($from).'].');

            return false;
        }

        $this->record('renamed '.$this->relative($from).' to '.$this->relative($to));

        return true;
    }

    /**
     * Run a command that changes something. Skipped in dry-run mode.
     *
     * @param  string|list<string>  $command
     */
    public function run(string|array $command, ?string $path = null): ?ProcessResult
    {
        $display = is_array($command) ? implode(' ', $command) : $command;

        if ($this->dryRun) {
            $this->record("would run: {$display}");

            return null;
        }

        $result = Process::path($path ?? $this->basePath)->run($command);

        $this->record(($result->successful() ? 'ran: ' : 'failed: ').$display);

        return $result;
    }

    public function record(string $change): void
    {
        $this->changes[] = ($this->dryRun ? '[dry-run] ' : '').$change;
        $this->line('  <fg=gray>'.end($this->changes).'</>');
    }

    public function remind(string $item): void
    {
        $this->checklist[] = $item;
    }

    /** @return list<string> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** @return list<string> */
    public function checklist(): array
    {
        return $this->checklist;
    }

    /**
     * All project files worth scanning: git-tracked plus untracked-but-not-ignored,
     * falling back to a directory walk outside a git checkout.
     *
     * @return list<string> absolute paths
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $tracked = Process::path($this->basePath)->run(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
        $list = $tracked->successful() ? array_filter(explode("\0", $tracked->output())) : [];

        if ($list === []) {
            $finder = (new Finder)->files()->in($this->basePath)->ignoreDotFiles(false)->ignoreVCS(true)
                ->exclude(self::IGNORED_DIRECTORIES);
            $list = array_map(fn ($file) => $file->getRelativePathname(), iterator_to_array($finder, false));
        }

        $files = [];

        foreach ($list as $relative) {
            $absolute = $this->path($relative);

            if (File::isFile($absolute) && ! $this->isIgnored($relative)) {
                $files[] = $absolute;
            }
        }

        sort($files);

        return $this->files = $files;
    }

    public function isIgnored(string $relative): bool
    {
        foreach (self::IGNORED_DIRECTORIES as $directory) {
            if ($relative === $directory || str_starts_with($relative, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    public function isTextFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $chunk = fread($handle, 8192);
        fclose($handle);

        return $chunk !== false && ! str_contains($chunk, "\0");
    }
}
