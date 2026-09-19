<?php

namespace Blemli\Partysan;

use Blemli\Partysan\Rename\Plugins\ClearCachesPlugin;
use Blemli\Partysan\Rename\Plugins\ComposePlugin;
use Blemli\Partysan\Rename\Plugins\ComposerPlugin;
use Blemli\Partysan\Rename\Plugins\DirectoryPlugin;
use Blemli\Partysan\Rename\Plugins\EnvFilesPlugin;
use Blemli\Partysan\Rename\Plugins\ForgePlugin;
use Blemli\Partysan\Rename\Plugins\GitHubPlugin;
use Blemli\Partysan\Rename\Plugins\HerdPlugin;
use Blemli\Partysan\Rename\Plugins\IdePlugin;
use Blemli\Partysan\Rename\Plugins\KamalPlugin;
use Blemli\Partysan\Rename\Plugins\NamespacePlugin;
use Blemli\Partysan\Rename\Plugins\OnePasswordPlugin;
use Blemli\Partysan\Rename\Plugins\ReadmePlugin;
use Blemli\Partysan\Rename\Plugins\ResidualScanPlugin;
use Blemli\Partysan\Rename\RenamePlugin;

class Partysan
{
    /** @var list<class-string<RenamePlugin>> */
    public const RENAME_PLUGINS = [
        EnvFilesPlugin::class,
        ComposerPlugin::class,
        ReadmePlugin::class,
        NamespacePlugin::class,
        KamalPlugin::class,
        ComposePlugin::class,
        OnePasswordPlugin::class,
        ForgePlugin::class,
        IdePlugin::class,
        GitHubPlugin::class,
        ResidualScanPlugin::class,
        ClearCachesPlugin::class,
        DirectoryPlugin::class,
        HerdPlugin::class,
    ];

    /** @var list<class-string<RenamePlugin>|RenamePlugin> */
    protected array $renamePlugins = [];

    /**
     * Register additional rename plugins, e.g. from another package's service provider.
     *
     * @param  class-string<RenamePlugin>|RenamePlugin  ...$plugins
     */
    public function renamePlugin(string|RenamePlugin ...$plugins): static
    {
        foreach ($plugins as $plugin) {
            $this->renamePlugins[] = $plugin;
        }

        return $this;
    }

    /**
     * Built-in plugins, plus those from config('partysan.rename.plugins') and
     * renamePlugin(), instantiated and sorted by order.
     *
     * @return list<RenamePlugin>
     */
    public function renamePlugins(): array
    {
        $configured = config('partysan.rename.plugins', []);

        $all = array_merge(self::RENAME_PLUGINS, is_array($configured) ? $configured : [], $this->renamePlugins);
        $plugins = [];

        foreach ($all as $plugin) {
            $instance = is_string($plugin) ? app($plugin) : $plugin;

            if (! $instance instanceof RenamePlugin) {
                throw new \InvalidArgumentException(sprintf('[%s] is not a rename plugin.', is_string($plugin) ? $plugin : get_debug_type($plugin)));
            }

            $plugins[$instance->key()] = $instance;
        }

        $plugins = array_values($plugins);

        usort($plugins, fn (RenamePlugin $a, RenamePlugin $b) => $a->order() <=> $b->order());

        return $plugins;
    }
}
