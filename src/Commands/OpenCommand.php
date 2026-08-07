<?php

namespace Blemli\Partysan\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'open', aliases: ['visit'])]
class OpenCommand extends Command
{
    // --env is not declared here on purpose: artisan already defines it as a
    // global option, and re-declaring it would clash with that definition.
    protected $signature = 'open';

    protected $description = 'Open the app in the browser, using the APP_URL of the given environment (--env=production reads .env.production)';

    public function __construct()
    {
        parent::__construct();

        // The alias has to be declared twice: the attribute is what artisan reads
        // to lazily map "visit" to this class without instantiating it, while the
        // instance itself only learns about the alias here (a $signature makes
        // Symfony skip the attribute).
        $this->setAliases(['visit']);
    }

    public function handle(): int
    {
        $environment = $this->input->hasOption('env') ? $this->option('env') : null;

        $url = $environment
            ? $this->urlFromEnvironmentFile($environment)
            : config('app.url');

        if (! is_string($url) || trim($url) === '') {
            if (! $environment) {
                $this->components->error('The app URL is not configured. Set APP_URL in your .env file.');
            }

            return self::FAILURE;
        }

        $url = trim($url);

        $result = Process::run($this->openCommand($url));

        if (! $result->successful()) {
            $this->components->error("Could not open [{$url}]: ".trim($result->errorOutput()));

            return self::FAILURE;
        }

        $this->components->info("Opened [{$url}] in the browser.");

        return self::SUCCESS;
    }

    /**
     * Read APP_URL from the base path's .env file for the given environment.
     */
    protected function urlFromEnvironmentFile(string $environment): ?string
    {
        $file = ".env.{$environment}";
        $path = base_path($file);

        if (! File::exists($path)) {
            $this->components->error("Environment file [{$file}] does not exist at [{$path}].");

            return null;
        }

        try {
            $variables = Dotenv::parse(File::get($path));
        } catch (\Throwable $e) {
            $this->components->error("Could not parse [{$file}]: {$e->getMessage()}");

            return null;
        }

        $url = $variables['APP_URL'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            $this->components->error("No APP_URL found in [{$file}].");

            return null;
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    protected function openCommand(string $url): array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };
    }
}
