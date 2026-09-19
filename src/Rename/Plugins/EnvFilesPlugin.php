<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Rewrites the old name in every .env* file: APP_NAME, and derived values such
 * as DB_DATABASE, APP_URL, SESSION_COOKIE or VITE_APP_NAME when they contain it.
 */
class EnvFilesPlugin extends BasePlugin
{
    public function key(): string
    {
        return 'env';
    }

    public function order(): int
    {
        return 10;
    }

    public function applies(RenameContext $context): bool
    {
        return $this->files($context) !== [];
    }

    public function run(RenameContext $context): void
    {
        foreach ($this->files($context) as $file) {
            $context->replaceInFile($file);
        }
    }

    public function checklist(RenameContext $context): array
    {
        return [
            'The session cookie name derives from APP_NAME, so every user is logged out after deploying.',
            'If DB_DATABASE changed, the database itself was not renamed: create it (db:create) and migrate the data, or keep the old value.',
        ];
    }

    /** @return list<string> */
    protected function files(RenameContext $context): array
    {
        return array_values(array_filter(File::glob($context->path('.env*')) ?: [], fn ($path) => File::isFile($path)));
    }
}
