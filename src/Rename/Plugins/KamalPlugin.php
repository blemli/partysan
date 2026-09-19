<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Rewrites the old name in the kamal deploy config (service, image, hosts,
 * accessories, volumes) and everything under .kamal/.
 */
class KamalPlugin extends BasePlugin
{
    public function key(): string
    {
        return 'kamal';
    }

    public function order(): int
    {
        return 40;
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
            'kamal: a renamed service gets fresh containers and volumes on the servers; move persistent data (e.g. the /data volume) before the first deploy and remove the old containers afterwards.',
            'kamal: image tags under the old name stay in the registry; the proxy host and TLS certificate for the new hostname must exist.',
        ];
    }

    /** @return list<string> */
    protected function files(RenameContext $context): array
    {
        $config = (string) config('partysan.rename.kamal.config', 'config/deploy.yml');
        $files = File::glob($context->path(preg_replace('/\.ya?ml$/', '*.yml', $config) ?? $config)) ?: [];
        $files = array_merge($files, File::glob($context->path(preg_replace('/\.ya?ml$/', '*.yaml', $config) ?? $config)) ?: []);

        $kamalDirectory = $context->path('.kamal');

        if (File::isDirectory($kamalDirectory)) {
            foreach (File::allFiles($kamalDirectory) as $file) {
                $files[] = $file->getPathname();
            }
        }

        return array_values(array_unique(array_filter($files, fn ($path) => File::isFile($path))));
    }
}
