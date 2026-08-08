<?php

namespace Blemli\Partysan\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;

#[AsCommand(name: 'dependency:switch')]
class DependencySwitchCommand extends Command
{
    protected $signature = 'dependency:switch
                            {package? : The package name (e.g., awcodes/overlook)}
                            {--local : Switch to local symlink}
                            {--vcs : Switch to VCS (git) repository}
                            {--packagist : Reset to Packagist}
                            {--branch= : Branch name for local/vcs}
                            {--org= : Override GitHub org for VCS}
                            {--upstream : Clone/use upstream repo instead of fork}
                            {--transitive : Include transitive dependencies}
                            {--list : List dependencies and their current source}';

    protected $description = 'Switch a dependency between local, VCS, and Packagist sources';

    /** @var array<string, mixed> */
    private array $stateData = [];

    private string $stateFile;

    public function handle(): int
    {
        $this->stateFile = base_path('.depswitch.json');
        $this->loadState();

        if ($this->option('list')) {
            return $this->listPackages();
        }

        $this->checkPrStatuses();

        $package = $this->argument('package');

        if ($package && ! $this->packageExists($package)) {
            $this->components->error("Package not found: {$package}");
            $package = null;
        }

        if (! $package) {
            $package = $this->selectPackageInteractively();
            if (! $package) {
                return self::SUCCESS;
            }
        }

        $mode = $this->determineMode();

        return match ($mode) {
            'local' => $this->switchToLocal($package),
            'vcs' => $this->switchToVcs($package),
            'packagist' => $this->switchToPackagist($package),
            default => self::FAILURE,
        };
    }

    private function checkPrStatuses(): void
    {
        $packages = $this->stateData['packages'] ?? [];

        foreach ($packages as $package => $data) {
            $mode = $data['mode'] ?? null;
            $branch = $data['branch'] ?? null;
            $org = $data['org'] ?? $this->getGitHubOrg();

            if (! in_array($mode, ['local', 'vcs']) || ! $branch) {
                continue;
            }

            $pr = $this->fetchPrStatus($package, $org, $branch);

            if (! $pr) {
                continue;
            }

            $this->displayPrNotification($package, $pr, $mode);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPrStatus(string $package, string $org, string $branch): ?array
    {
        $result = Process::run(
            "gh pr list --repo {$package} --author @me --json number,title,state,url,reviewRequests,reviewDecision,headRefName --limit 20"
        );

        if ($result->failed()) {
            return null;
        }

        $prs = json_decode($result->output(), true) ?: [];

        foreach ($prs as $pr) {
            if (($pr['headRefName'] ?? '') === $branch) {
                return $pr;
            }
        }

        $result = Process::run(
            "gh pr list --repo {$package} --author @me --state merged --json number,title,state,url,headRefName --limit 20"
        );

        if ($result->failed()) {
            return null;
        }

        $prs = json_decode($result->output(), true) ?: [];

        foreach ($prs as $pr) {
            if (($pr['headRefName'] ?? '') === $branch) {
                return $pr;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pr
     */
    private function displayPrNotification(string $package, array $pr, string $mode): void
    {
        $number = $pr['number'] ?? '?';
        $title = $pr['title'] ?? 'Unknown';
        $state = strtolower($pr['state'] ?? 'unknown');
        $url = $pr['url'] ?? '';
        $reviewRequests = $pr['reviewRequests'] ?? [];
        $reviewDecision = $pr['reviewDecision'] ?? null;

        if ($state === 'merged') {
            $this->components->info("🎉 PR #{$number} merged: {$package}");
            $this->line("   <fg=gray>{$title}</>");

            if ($mode !== 'packagist') {
                if (confirm("Switch {$package} back to Packagist?", true)) {
                    $this->switchToPackagist($package);
                }
            }

            return;
        }

        if ($state === 'open') {
            if ($reviewDecision === 'CHANGES_REQUESTED') {
                $this->components->warn("👀 PR #{$number} changes requested: {$package}");
                $this->line("   <fg=gray>{$title}</>");
                $this->line("   <fg=blue;options=underscore>{$url}</>");

                return;
            }

            $status = match (true) {
                ! empty($reviewRequests) => 'awaiting review',
                $reviewDecision === 'APPROVED' => 'approved',
                default => 'open',
            };

            $this->components->info("PR #{$number} {$status}: {$package}");
            $this->line("   <fg=gray>{$title}</>");
            $this->line("   <fg=blue;options=underscore>{$url}</>");
        }
    }

    private function listPackages(): int
    {
        $filter = match (true) {
            (bool) $this->option('local') => 'local',
            (bool) $this->option('vcs') => 'vcs',
            (bool) $this->option('packagist') => 'packagist',
            default => 'all',
        };

        $packages = $this->getPackages($filter);

        if (empty($packages)) {
            $this->components->warn('No packages found for filter: '.$filter);

            return self::SUCCESS;
        }

        ksort($packages);

        $rows = [];

        foreach (array_keys($packages) as $name) {
            $rows[] = [
                $name,
                $this->detectCurrentMode($name),
                $this->stateData['packages'][$name]['branch'] ?? '',
                $this->formatPrStatus($name),
            ];
        }

        $this->table(['Package', 'Mode', 'Branch', 'PR'], $rows);

        return self::SUCCESS;
    }

    private function formatPrStatus(string $package): string
    {
        $data = $this->stateData['packages'][$package] ?? [];
        $mode = $data['mode'] ?? null;
        $branch = $data['branch'] ?? null;
        $org = $data['org'] ?? $this->getGitHubOrg();

        if (! in_array($mode, ['local', 'vcs']) || ! $branch) {
            return '';
        }

        $pr = $this->fetchPrStatus($package, $org, $branch);

        if (! $pr) {
            return '';
        }

        $number = $pr['number'] ?? '?';
        $state = strtolower($pr['state'] ?? '');

        $status = match (true) {
            $state === 'merged' => 'merged',
            ($pr['reviewDecision'] ?? null) === 'CHANGES_REQUESTED' => 'changes requested',
            ! empty($pr['reviewRequests']) => 'awaiting review',
            ($pr['reviewDecision'] ?? null) === 'APPROVED' => 'approved',
            default => $state,
        };

        return "#{$number} {$status}";
    }

    private function selectPackageInteractively(): ?string
    {
        $filter = select(
            label: 'Filter by mode',
            options: [
                'all' => 'All packages',
                'local' => 'Local only',
                'vcs' => 'VCS only',
                'packagist' => 'Packagist only',
            ],
            default: 'all',
        );

        $packages = $this->getPackages($filter);

        if (empty($packages)) {
            $this->warn('No packages found for filter: '.$filter);

            return null;
        }

        $package = search(
            label: 'Select package',
            options: function (string $search) use ($packages): array {
                if (empty($search)) {
                    return $packages;
                }

                return array_filter(
                    $packages,
                    fn (string $label, string $name): bool => str_contains(strtolower($name), strtolower($search))
                        || str_contains(strtolower($label), strtolower($search)),
                    ARRAY_FILTER_USE_BOTH
                );
            },
            placeholder: 'Type to search...',
        );

        if (! $package) {
            return null;
        }

        $mode = select(
            label: "Switch {$package} to:",
            options: [
                'local' => 'Local fork (symlink for development)',
                'local-up' => 'Local upstream (symlink, no fork)',
                'vcs' => 'VCS fork (git repo for staging/PR)',
                'vcs-up' => 'VCS upstream (track upstream branch)',
                'packagist' => 'Packagist (reset to upstream)',
            ],
        );

        if ($mode === 'local-up') {
            $this->input->setOption('local', true);
            $this->input->setOption('upstream', true);
        } elseif ($mode === 'vcs-up') {
            $this->input->setOption('vcs', true);
            $this->input->setOption('upstream', true);
        } else {
            $this->input->setOption($mode, true);
        }

        return $package;
    }

    /**
     * @return array<string, string>
     */
    private function getPackages(string $filter = 'all'): array
    {
        $composerJson = $this->getComposerJson();
        $require = $composerJson['require'] ?? [];
        $requireDev = $composerJson['require-dev'] ?? [];

        $packages = [];

        $allDeps = array_merge($require, $requireDev);

        if ($this->option('transitive')) {
            $lockFile = base_path('composer.lock');
            if (File::exists($lockFile)) {
                $lock = json_decode(File::get($lockFile), true);
                foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
                    $name = $pkg['name'] ?? '';
                    if ($name && ! isset($allDeps[$name])) {
                        $allDeps[$name] = $pkg['version'] ?? '*';
                    }
                }
            }
        }

        $allDeps = array_reverse($allDeps, true);

        foreach ($allDeps as $name => $version) {
            if (! str_contains($name, '/')) {
                continue;
            }

            if (str_starts_with($name, 'php') || str_starts_with($name, 'ext-')) {
                continue;
            }

            $mode = $this->detectCurrentMode($name);
            $baseMode = str_replace('-fork', '', $mode);

            if ($filter !== 'all' && $baseMode !== $filter) {
                continue;
            }

            $modeLabel = match ($mode) {
                'local' => '[local]',
                'local-fork' => '[local fork]',
                'vcs' => '[vcs]',
                'vcs-fork' => '[vcs fork]',
                default => '[packagist]',
            };

            $packages[$name] = "{$name}  {$modeLabel}";
        }

        return $packages;
    }

    private function determineMode(): string
    {
        if ($this->option('local')) {
            return 'local';
        }
        if ($this->option('vcs')) {
            return 'vcs';
        }
        if ($this->option('packagist')) {
            return 'packagist';
        }

        $mode = select(
            label: 'Select mode:',
            options: [
                'local' => 'Local fork (symlink for development)',
                'local-up' => 'Local upstream (symlink, no fork)',
                'vcs' => 'VCS fork (git repo for staging/PR)',
                'vcs-up' => 'VCS upstream (track upstream branch)',
                'packagist' => 'Packagist (reset to upstream)',
            ],
        );

        if ($mode === 'local-up') {
            $this->input->setOption('upstream', true);

            return 'local';
        }

        if ($mode === 'vcs-up') {
            $this->input->setOption('upstream', true);

            return 'vcs';
        }

        return $mode;
    }

    private function switchToLocal(string $package): int
    {
        $packageName = $this->getPackageName($package);
        $localPath = $this->getLocalPath($packageName);
        $expandedPath = $this->expandPath($localPath);
        $useUpstream = (bool) $this->option('upstream');

        $this->saveOriginalConstraint($package);

        if (! is_dir($expandedPath)) {
            $this->warn("Local directory not found: {$localPath}");

            if (! $this->tryCloneOrFork($package, $packageName, $expandedPath, $useUpstream)) {
                return self::FAILURE;
            }
        } else {
            // Directory exists - determine if it's a fork based on remotes
            $isFork = $this->detectIfLocalIsFork($expandedPath, $package);
            $this->updateState($package, ['is_fork' => $isFork]);
        }

        $branches = $this->getGitBranches($expandedPath);
        $branch = $this->option('branch') ?: suggest(
            label: 'Branch (existing or new)',
            options: $branches,
            placeholder: 'fix-dropdown-overflow',
        );

        if ($branch) {
            $this->info("Trying to create branch: {$branch}");
            $result = Process::path($expandedPath)->run("git switch -c {$branch}");

            if ($result->failed()) {
                $checkoutResult = Process::path($expandedPath)->run("git switch {$branch}");
                if ($checkoutResult->failed()) {
                    $this->error("Failed to create/switch to branch: {$branch}");
                    $this->line($result->errorOutput());

                    return self::FAILURE;
                }
                $this->info("Switched to existing branch: {$branch}");
            } else {
                $this->info("Created branch: {$branch}");
                $pushResult = Process::path($expandedPath)->run("git push -u origin {$branch}");
                if ($pushResult->successful()) {
                    $this->info("Pushed branch to origin: {$branch}");
                }
            }

            $this->updateState($package, ['branch' => $branch]);
        }

        $repoKey = $this->getRepoKey($package);
        $isDirect = $this->isDirectDependency($package);

        $this->removeRepository($repoKey, $package);
        $this->configurePathRepository($repoKey, $localPath);

        $this->info('Running composer update...');

        if ($isDirect) {
            $success = $this->runComposer("require {$package}:@dev --with-all-dependencies");
        } else {
            // Transitive dep: just update, composer will use the path repo
            $success = $this->runComposer('update --with-all-dependencies');
        }

        if (! $success) {
            $this->error('Composer failed. Rolling back...');
            $this->removeRepository($repoKey, $package);

            return self::FAILURE;
        }

        $this->updateState($package, ['mode' => 'local']);
        $this->saveState();

        $this->info("Switched {$package} to local: {$localPath}");

        return self::SUCCESS;
    }

    private function configurePathRepository(string $repoKey, string $localPath): void
    {
        $composerPath = base_path('composer.json');
        $composerJson = json_decode(File::get($composerPath), true);

        $newRepo = [
            'name' => $repoKey,
            'type' => 'path',
            'url' => $localPath,
            'options' => [
                'symlink' => true,
            ],
        ];

        $composerJson['repositories'] = self::upsertRepo($composerJson['repositories'] ?? [], $repoKey, $newRepo);

        File::put(
            $composerPath,
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        $this->line("<fg=gray>Added path repository: {$repoKey} -> {$localPath}</>");
    }

    private function tryCloneOrFork(string $package, string $packageName, string $targetPath, bool $useUpstream = false): bool
    {
        $parentDir = dirname($targetPath);

        if (! is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        if ($useUpstream) {
            $this->info("Cloning upstream {$package}...");
            $cloneResult = Process::path($parentDir)->run(
                "gh repo clone {$package} {$targetPath}"
            );

            if ($cloneResult->successful()) {
                $this->info("Cloned upstream {$package}");
                $this->updateState($package, ['is_fork' => false]);

                return true;
            }

            $this->error("Failed to clone upstream {$package}");
            $this->line($cloneResult->errorOutput());

            return false;
        }

        $org = $this->getGitHubOrg();

        $this->info("Trying to clone from {$org}/{$packageName}...");
        $cloneResult = Process::path($parentDir)->run(
            "gh repo clone {$org}/{$packageName} {$targetPath}"
        );

        if ($cloneResult->successful()) {
            $this->info("Cloned from {$org}/{$packageName}");
            $this->updateState($package, ['is_fork' => true, 'org' => $org]);

            return true;
        }

        $this->warn("Clone failed. Trying to fork {$package}...");

        $forkResult = Process::path($parentDir)->run(
            "gh repo fork {$package} --remote=true --clone --org {$org}"
        );

        if ($forkResult->failed()) {
            $this->error('Fork failed. Check: gh auth status');
            $this->line($forkResult->errorOutput());

            return false;
        }

        $clonedDir = $parentDir.'/'.$packageName;
        if ($clonedDir !== $targetPath && is_dir($clonedDir)) {
            rename($clonedDir, $targetPath);
        }

        $this->info("Forked and cloned {$package} to {$org}/{$packageName}");
        $this->updateState($package, ['is_fork' => true, 'org' => $org]);

        return true;
    }

    private function switchToVcs(string $package): int
    {
        $packageName = $this->getPackageName($package);
        $useUpstream = (bool) $this->option('upstream');
        $org = $this->option('org') ?: $this->getGitHubOrg();

        $this->saveOriginalConstraint($package);

        $repoKey = $this->getRepoKey($package);

        if ($useUpstream) {
            $repoUrl = "https://github.com/{$package}";
        } else {
            $repoUrl = "https://github.com/{$org}/{$packageName}";
        }

        $defaultBranch = $this->stateData['packages'][$package]['branch']
            ?? config('depswitch.default_branch', 'main');

        $branch = $this->option('branch') ?: suggest(
            label: 'Branch name',
            options: $this->getRemoteBranches($repoUrl),
            default: $defaultBranch,
            required: true,
        );

        $isDirect = $this->isDirectDependency($package);

        $this->removeRepository($repoKey, $package);
        $this->configureVcsRepository($repoKey, $repoUrl);

        $this->info('Running composer update...');

        if ($isDirect) {
            $constraint = $this->branchToConstraint($branch);
            $success = $this->runComposer("require {$package}:{$constraint} --with-all-dependencies");
        } else {
            $success = $this->runComposer('update --with-all-dependencies');
        }

        if (! $success) {
            $this->error('Composer failed. Rolling back...');
            $this->removeRepository($repoKey, $package);

            return self::FAILURE;
        }

        $this->updateState($package, [
            'mode' => 'vcs',
            'org' => $useUpstream ? null : $org,
            'branch' => $branch,
            'is_fork' => ! $useUpstream,
        ]);
        $this->saveState();

        $this->info("Switched {$package} to VCS: {$repoUrl} (branch: {$branch})");

        return self::SUCCESS;
    }

    private function configureVcsRepository(string $repoKey, string $repoUrl): void
    {
        $composerPath = base_path('composer.json');
        $composerJson = json_decode(File::get($composerPath), true);

        $newRepo = [
            'name' => $repoKey,
            'type' => 'vcs',
            'url' => $repoUrl,
        ];

        $composerJson['repositories'] = self::upsertRepo($composerJson['repositories'] ?? [], $repoKey, $newRepo);

        File::put(
            $composerPath,
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        $this->line("<fg=gray>Added VCS repository: {$repoKey} -> {$repoUrl}</>");
    }

    private function switchToPackagist(string $package): int
    {
        $repoKey = $this->getRepoKey($package);
        $originalConstraint = $this->stateData['packages'][$package]['original_constraint'] ?? null;
        $isDirect = $this->isDirectDependency($package);

        $this->removeRepository($repoKey, $package);

        if ($isDirect && $originalConstraint) {
            $this->info("Restoring original constraint: {$originalConstraint}");
            $success = $this->runComposer("require {$package}:{$originalConstraint} --with-all-dependencies");
        } else {
            $this->info('Running composer update...');
            $success = $this->runComposer('update --with-all-dependencies');
        }

        if (! $success) {
            $this->error('Composer failed.');

            return self::FAILURE;
        }

        unset($this->stateData['packages'][$package]);
        $this->saveState();

        $this->info("Switched {$package} to Packagist");

        return self::SUCCESS;
    }

    private function removeRepository(string $repoKey, ?string $package = null): void
    {
        $composerPath = base_path('composer.json');
        $composerJson = json_decode(File::get($composerPath), true);
        $repositories = $composerJson['repositories'] ?? [];
        $packageName = $package ? $this->getPackageName($package) : null;

        $found = false;
        $composerJson['repositories'] = array_values(array_filter($repositories, function (array $repo) use ($repoKey, $package, $packageName, &$found): bool {
            $nameMatch = ($repo['name'] ?? '') === $repoKey;
            $url = $repo['url'] ?? '';
            $urlMatch = $packageName && (str_ends_with($url, '/'.$packageName) || str_ends_with($url, '/'.$package));

            if ($nameMatch || $urlMatch) {
                $found = true;

                return false;
            }

            return true;
        }));

        if ($found) {
            File::put(
                $composerPath,
                json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );

            $this->line("<fg=gray>Removed repository: {$repoKey}</>");
        }
    }

    private function detectCurrentMode(string $package): string
    {
        $composerJson = $this->getComposerJson();
        $repositories = $composerJson['repositories'] ?? [];
        $repoKey = $this->getRepoKey($package);
        $packageName = $this->getPackageName($package);

        foreach ($repositories as $repo) {
            $url = $repo['url'] ?? '';
            $matchesName = ($repo['name'] ?? '') === $repoKey;
            $matchesUrl = str_ends_with($url, '/'.$packageName) || str_ends_with($url, '/'.$package);

            if ($matchesName || $matchesUrl) {
                if (($repo['type'] ?? '') === 'path') {
                    $isFork = $this->stateData['packages'][$package]['is_fork'] ?? false;

                    return $isFork ? 'local-fork' : 'local';
                }
                if (in_array($repo['type'] ?? '', ['vcs', 'git'])) {
                    $isFork = $this->stateData['packages'][$package]['is_fork'] ?? $this->detectVcsIsFork($url, $package);

                    return $isFork ? 'vcs-fork' : 'vcs';
                }
            }
        }

        return 'packagist';
    }

    /**
     * @param  array<int, array<string, mixed>>  $repositories
     * @param  array<string, mixed>  $newRepo
     * @return array<int, array<string, mixed>>
     */
    private static function upsertRepo(array $repositories, string $repoKey, array $newRepo): array
    {
        foreach ($repositories as $index => $repo) {
            if (($repo['name'] ?? '') === $repoKey) {
                $repositories[$index] = $newRepo;

                return $repositories;
            }
        }

        $repositories[] = $newRepo;

        return $repositories;
    }

    private function branchToConstraint(string $branch): string
    {
        // Version-like branches (e.g. "5.x", "2.0", "v3.1") use suffix: 5.x-dev
        // All other branches (e.g. "main", "fix-bug") use prefix: dev-main
        if (preg_match('/^v?\d+(\.\d+)*\.?x?$/i', $branch)) {
            return "{$branch}-dev";
        }

        return "dev-{$branch}";
    }

    private function detectVcsIsFork(string $repoUrl, string $package): bool
    {
        // Check if the VCS URL points to a fork or the original package
        // Original: https://github.com/vendor/name
        // Fork: https://github.com/myorg/name
        if (preg_match('#github\.com/([^/]+/[^/]+)#', $repoUrl, $matches)) {
            $urlPackage = $matches[1];

            return strtolower($urlPackage) !== strtolower($package);
        }

        return false;
    }

    private function getPackageName(string $package): string
    {
        $parts = explode('/', $package);

        return end($parts);
    }

    private function getRepoKey(string $package): string
    {
        return str_replace('/', '-', $package);
    }

    /**
     * @return array<string>
     */
    private function getGitBranches(string $path): array
    {
        if (! is_dir($path.'/.git')) {
            return [];
        }

        $result = Process::path($path)->run('git branch -a --format="%(refname:short)"');

        if ($result->failed()) {
            return [];
        }

        $branches = array_filter(
            array_map('trim', explode("\n", $result->output()))
        );

        // Clean up remote branch names (origin/branch -> branch)
        return array_values(array_unique(array_map(
            fn ($branch) => preg_replace('#^origin/#', '', $branch),
            $branches
        )));
    }

    /**
     * List branches of a remote repository without cloning it.
     *
     * @return array<string>
     */
    private function getRemoteBranches(string $repoUrl): array
    {
        $result = Process::env(['GIT_TERMINAL_PROMPT' => '0'])
            ->timeout(10)
            ->run('git ls-remote --heads '.escapeshellarg($repoUrl));

        if ($result->failed()) {
            return [];
        }

        $branches = [];

        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('#refs/heads/(.+)$#', trim($line), $matches)) {
                $branches[] = $matches[1];
            }
        }

        sort($branches);

        return $branches;
    }

    private function detectIfLocalIsFork(string $path, string $package): bool
    {
        if (! is_dir($path.'/.git')) {
            return false;
        }

        $result = Process::path($path)->run('git remote get-url origin');

        if ($result->failed()) {
            return false;
        }

        $originUrl = trim($result->output());

        // Extract vendor/name from the origin URL
        // Handles: git@github.com:vendor/name.git or https://github.com/vendor/name.git
        if (preg_match('#[:/]([^/]+/[^/]+?)(?:\.git)?$#', $originUrl, $matches)) {
            $originPackage = $matches[1];

            // If origin doesn't match the package vendor, it's a fork
            return strtolower($originPackage) !== strtolower($package);
        }

        return false;
    }

    private function getLocalPath(string $packageName): string
    {
        $directory = config('depswitch.directory', '~/git');

        return "{$directory}/{$packageName}";
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';

            return $home.'/'.substr($path, 2);
        }

        return $path;
    }

    private function getGitHubOrg(): string
    {
        return $this->option('org') ?: config('depswitch.github_org', 'blemli');
    }

    /**
     * @return array<string, mixed>
     */
    private function getComposerJson(): array
    {
        static $cache = null;

        if ($cache === null) {
            $path = base_path('composer.json');
            $cache = json_decode(File::get($path), true) ?: [];
        }

        return $cache;
    }

    private function saveOriginalConstraint(string $package): void
    {
        if (isset($this->stateData['packages'][$package]['original_constraint'])) {
            return;
        }

        $composerJson = $this->getComposerJson();
        $constraint = $composerJson['require'][$package]
            ?? $composerJson['require-dev'][$package]
            ?? null;

        if ($constraint && ! str_starts_with($constraint, 'dev-') && $constraint !== '*') {
            $this->updateState($package, ['original_constraint' => $constraint]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateState(string $package, array $data): void
    {
        if (! isset($this->stateData['packages'])) {
            $this->stateData['packages'] = [];
        }

        if (! isset($this->stateData['packages'][$package])) {
            $this->stateData['packages'][$package] = [];
        }

        $this->stateData['packages'][$package] = array_merge(
            $this->stateData['packages'][$package],
            $data
        );
    }

    private function loadState(): void
    {
        if (File::exists($this->stateFile)) {
            $this->stateData = json_decode(File::get($this->stateFile), true) ?: [];
        }
    }

    private function saveState(): void
    {
        File::put(
            $this->stateFile,
            json_encode($this->stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    private function runComposer(string $command): bool
    {
        $this->line("<fg=gray>$ composer {$command}</>");
        $result = Process::path(base_path())->run("composer {$command}", function (string $type, string $output): void {
            $this->output->write($output);
        });

        return $result->successful();
    }

    private function isDirectDependency(string $package): bool
    {
        $composerJson = $this->getComposerJson();

        return isset($composerJson['require'][$package])
            || isset($composerJson['require-dev'][$package]);
    }

    private function packageExists(string $package): bool
    {
        if ($this->isDirectDependency($package)) {
            return true;
        }

        $lockFile = base_path('composer.lock');

        if (! File::exists($lockFile)) {
            return false;
        }

        $lock = json_decode(File::get($lockFile), true);
        $allPackages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        foreach ($allPackages as $pkg) {
            if (($pkg['name'] ?? '') === $package) {
                return true;
            }
        }

        return false;
    }
}
