<?php

namespace Blemli\Partysan\Rename\Plugins;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Renames Laravel Forge sites that carry the old name: the site domain, its git
 * repository, its environment file and its deployment script. Needs a Forge API
 * token (FORGE_API_TOKEN, or config partysan.rename.forge.token).
 */
class ForgePlugin extends BasePlugin
{
    protected bool $touched = false;

    public function key(): string
    {
        return 'forge';
    }

    public function order(): int
    {
        return 60;
    }

    public function applies(RenameContext $context): bool
    {
        return $this->token() !== null;
    }

    public function run(RenameContext $context): void
    {
        $servers = $this->client()->get('/servers');

        if ($servers->failed()) {
            $context->warn("Forge: could not list servers (HTTP {$servers->status()}); check the API token.");
            $context->remind('Forge sites were not renamed because the API request failed.');

            return;
        }

        $found = 0;

        foreach ($servers->json('servers', []) as $server) {
            $sites = $this->client()->get("/servers/{$server['id']}/sites");

            foreach ($sites->json('sites', []) as $site) {
                $carriesName = $context->old->matches((string) ($site['name'] ?? ''))
                    || $context->old->matches((string) ($site['repository'] ?? ''))
                    || $context->old->matches((string) ($site['directory'] ?? ''));

                if (! $carriesName) {
                    continue;
                }

                $found++;
                $this->renameSite($context, (int) $server['id'], (string) $server['name'], $site);
            }
        }

        if ($found === 0) {
            $context->record('Forge: no sites carry the old name');
        }
    }

    /**
     * @param  array<string, mixed>  $site
     */
    protected function renameSite(RenameContext $context, int $serverId, string $serverName, array $site): void
    {
        $siteId = (int) $site['id'];
        $domain = (string) ($site['name'] ?? '');
        $newDomain = $context->old->replaceIn($domain, $context->new);
        $label = "Forge site [{$domain}] on [{$serverName}]";

        if (! $context->confirm("{$label}: rename to [{$newDomain}] and update its repository, .env and deploy script?")) {
            $context->remind("{$label} was not renamed.");

            return;
        }

        $base = "/servers/{$serverId}/sites/{$siteId}";

        if ($newDomain !== $domain) {
            $this->request($context, 'put', $base, ['name' => $newDomain], "{$label}: domain -> {$newDomain}");
        }

        $repository = (string) ($site['repository'] ?? '');

        if ($repository !== '' && $context->old->matches($repository)) {
            $this->request($context, 'put', "{$base}/git", [
                'provider' => $site['repository_provider'] ?? 'github',
                'repository' => $context->old->replaceIn($repository, $context->new),
                'branch' => $site['repository_branch'] ?? 'main',
            ], "{$label}: repository -> ".$context->old->replaceIn($repository, $context->new));
        }

        $this->rewriteText($context, "{$base}/env", 'content', "{$label}: .env");
        $this->rewriteText($context, "{$base}/deployment/script", 'content', "{$label}: deployment script");

        $this->touched = true;
    }

    protected function rewriteText(RenameContext $context, string $path, string $field, string $label): void
    {
        $response = $this->client()->get($path);

        if ($response->failed()) {
            return;
        }

        $current = $response->body();
        $updated = $context->old->replaceIn($current, $context->new, $count);

        if ($count > 0) {
            $this->request($context, 'put', $path, [$field => $updated], "{$label}: {$count} occurrence(s)");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function request(RenameContext $context, string $method, string $path, array $payload, string $note): void
    {
        if ($context->dryRun) {
            $context->record("would update {$note}");

            return;
        }

        $response = $this->client()->{$method}($path, $payload);

        if ($response->failed()) {
            $context->warn("Forge: {$note} failed (HTTP {$response->status()}).");
            $context->remind("Forge: {$note} must be done by hand.");

            return;
        }

        $context->record("updated {$note}");
    }

    public function checklist(RenameContext $context): array
    {
        return $this->touched ? [
            'Forge: point DNS at the renamed site and issue a new TLS certificate; the web directory, daemons, scheduler entries and nginx config on the server still use the old paths.',
        ] : [];
    }

    protected function client(): PendingRequest
    {
        $url = (string) config('partysan.rename.forge.url', 'https://forge.laravel.com/api/v1');

        return Http::baseUrl($url)->withToken((string) $this->token())->acceptJson()->timeout(30);
    }

    protected function token(): ?string
    {
        $token = config('partysan.rename.forge.token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }
}
