<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Support\Facades\File;

/**
 * Rewrites the name in docker compose files and warns about the volume trap:
 * compose derives its project name (and therefore container and volume names)
 * from the directory, so renaming the directory orphans the existing volumes.
 */
class ComposePlugin extends BasePlugin
{
    /** @var list<string> */
    public const FILES = ['compose.yml', 'compose.yaml', 'docker-compose.yml', 'docker-compose.yaml', 'compose.override.yml', 'docker-compose.override.yml'];

    protected bool $pinned = false;

    public function key(): string
    {
        return 'compose';
    }

    public function order(): int
    {
        return 45;
    }

    public function applies(RenameContext $context): bool
    {
        return $this->files($context) !== [];
    }

    public function run(RenameContext $context): void
    {
        $contents = '';

        foreach ($this->files($context) as $file) {
            $contents .= File::get($file);
            $context->replaceInFile($file);
        }

        $env = File::isFile($context->path('.env')) ? File::get($context->path('.env')) : '';

        $this->pinned = preg_match('/^name:\s*\S+/m', $contents) === 1
            || preg_match('/^COMPOSE_PROJECT_NAME=/m', $env) === 1;
    }

    public function checklist(RenameContext $context): array
    {
        $old = strtolower($context->old->kebab());
        $new = strtolower($context->new->kebab());

        if ($this->pinned) {
            return ['docker compose: the project name is pinned (name: or COMPOSE_PROJECT_NAME); if you changed it, rename the volumes too.'];
        }

        return [
            "docker compose: the project name follows the directory, so after the move the containers and named volumes of [{$old}] are orphaned and fresh empty ones are created. Either pin `name: {$old}` in the compose file (or COMPOSE_PROJECT_NAME in .env), or copy each volume: `docker volume ls --filter name={$old}_` then `docker run --rm -v {$old}_X:/from -v {$new}_X:/to alpine cp -a /from/. /to/`.",
        ];
    }

    /** @return list<string> */
    protected function files(RenameContext $context): array
    {
        return array_values(array_filter(array_map(fn ($file) => $context->path($file), self::FILES), fn ($path) => File::isFile($path)));
    }
}
