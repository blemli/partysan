<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;

/**
 * Clears cached config, routes and views so the old name does not survive in bootstrap/cache.
 */
class ClearCachesPlugin extends BasePlugin
{
    public function key(): string
    {
        return 'caches';
    }

    public function order(): int
    {
        return 950;
    }

    public function run(RenameContext $context): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear', 'clear-compiled'] as $command) {
            if ($context->dryRun) {
                $context->record("would run: artisan {$command}");

                continue;
            }

            try {
                $context->io->callSilently($command);
                $context->record("ran: artisan {$command}");
            } catch (\Throwable $e) {
                $context->warn("artisan {$command} failed: {$e->getMessage()}");
            }
        }
    }
}
