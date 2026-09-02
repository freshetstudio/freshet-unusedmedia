<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

defined('ABSPATH') || exit;

/**
 * Names the build in Plugins → Installed Plugins.
 *
 * The two archives carry the same slug and the same version, so the plugins
 * list gives no way to tell which one a site is running. This class ships in
 * the sales archive only — bin/release.conf strips it — so its presence *is*
 * the answer, and the directory build renders the row exactly as WordPress
 * builds it: nothing added, nothing that could read as an upsell.
 *
 * It names the build, not the license: a sales copy sitting on a site with no
 * key is still the separate release, and what a key is worth is the license
 * card's business, not the plugins list's. So there is no license read here to
 * share with the header pill.
 */
final class PluginRow
{
    public function hooks(): void
    {
        add_filter('all_plugins', [$this, 'nameBuild']);
    }

    /**
     * @param array<string, array<string, mixed>> $plugins
     *
     * @return array<string, array<string, mixed>>
     */
    public function nameBuild(array $plugins): array
    {
        $file = plugin_basename(FRESHET_UNUSEDMEDIA_FILE);

        if (isset($plugins[$file]['Name'])) {
            $plugins[$file]['Name'] = sprintf(
                /* translators: %s: the plugin name as read from its header */
                __('%s Pro', 'freshet-unused-media'),
                (string) $plugins[$file]['Name']
            );
        }

        return $plugins;
    }
}
