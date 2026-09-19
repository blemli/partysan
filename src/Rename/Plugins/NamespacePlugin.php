<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Renames the application's root PHP namespace when it is not the default `App`.
 */
class NamespacePlugin extends BasePlugin
{
    /** @var list<string> */
    public const DIRECTORIES = ['app', 'tests', 'config', 'routes', 'bootstrap', 'database', 'resources'];

    public function key(): string
    {
        return 'namespace';
    }

    public function order(): int
    {
        return 30;
    }

    public function applies(RenameContext $context): bool
    {
        $namespace = $this->rootNamespace($context);

        return $namespace !== null && $namespace !== 'App' && $context->old->matches($namespace);
    }

    public function run(RenameContext $context): void
    {
        $old = (string) $this->rootNamespace($context);
        $new = $context->old->replaceIn($old, $context->new);

        if ($new === $old) {
            return;
        }

        $context->info("Renaming namespace [{$old}] to [{$new}].");

        $pattern = '/(?<![A-Za-z0-9_\\\\])'.preg_quote($old, '/').'(?=\\\\|;|::)/';

        $context->replaceInFile($context->path('composer.json'), '/"'.preg_quote($old, '/').'\\\\\\\\"/', '"'.$new.'\\\\\\\\"');

        foreach (self::DIRECTORIES as $directory) {
            $path = $context->path($directory);

            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if (in_array($file->getExtension(), ['php', 'stub'], true)) {
                    $context->replaceInFile($file->getPathname(), $pattern, $new);
                }
            }
        }

        $result = $context->run(['composer', 'dump-autoload']);

        if ($result !== null && ! $result->successful()) {
            $context->warn('composer dump-autoload failed: '.trim($result->errorOutput()));
        }
    }

    protected function rootNamespace(RenameContext $context): ?string
    {
        $path = $context->path('composer.json');

        if (! File::isFile($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);
        $psr4 = is_array($data) ? ($data['autoload']['psr-4'] ?? []) : [];

        foreach ($psr4 as $namespace => $directories) {
            foreach ((array) $directories as $directory) {
                if (in_array(rtrim((string) $directory, '/'), ['app', 'app/'], true)) {
                    return rtrim((string) $namespace, '\\');
                }
            }
        }

        return null;
    }
}
