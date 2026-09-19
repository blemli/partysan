<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Rewrites the package name, description and repository URLs (homepage, support,
 * repository, bugs) in composer.json, package.json and package-lock.json without
 * reformatting the files.
 */
class ComposerPlugin extends BasePlugin
{
    public function key(): string
    {
        return 'composer';
    }

    public function order(): int
    {
        return 20;
    }

    public function applies(RenameContext $context): bool
    {
        return File::isFile($context->path('composer.json'));
    }

    public function run(RenameContext $context): void
    {
        $this->rewrite($context, 'composer.json', ['name', 'description', 'homepage', 'support.source', 'support.issues', 'support.docs', 'support.wiki']);
        $this->rewrite($context, 'package.json', ['name', 'description', 'homepage', 'repository', 'repository.url', 'bugs', 'bugs.url']);
        $this->rewrite($context, 'package-lock.json', ['name']);
    }

    /**
     * @param  list<string>  $keys
     */
    protected function rewrite(RenameContext $context, string $file, array $keys): void
    {
        $path = $context->path($file);

        if (! File::isFile($path)) {
            return;
        }

        $contents = File::get($path);
        $data = json_decode($contents, true);

        if (! is_array($data)) {
            $context->warn("Could not parse [{$file}]; skipped.");

            return;
        }

        $updated = $contents;

        foreach ($keys as $key) {
            $value = data_get($data, $key);

            if (! is_string($value) || ! $context->old->matches($value)) {
                continue;
            }

            $key = substr($key, (int) strrpos('.'.$key, '.'));

            $new = $context->old->replaceIn($value, $context->new);

            if ($new !== $value) {
                $updated = str_replace(
                    $this->encoded($key, $value),
                    $this->encoded($key, $new),
                    $updated,
                );
            }
        }

        if ($updated !== $contents) {
            $context->put($path, $updated, "updated {$file}");
        }
    }

    /**
     * @return list<string>
     */
    protected function encoded(string $key, string $value): array
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return [
            json_encode($key, $flags).': '.json_encode($value, $flags),
            json_encode($key, $flags).':'.json_encode($value, $flags),
        ];
    }
}
