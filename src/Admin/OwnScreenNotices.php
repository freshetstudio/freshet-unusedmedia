<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

defined('ABSPATH') || exit;

/**
 * On this plugin's OWN admin screens, other plugins' admin notices do not
 * render (freshet-D337). Everywhere else in wp-admin they appear exactly as
 * they always did, so nothing is hidden from the administrator overall — the
 * Dashboard, the Plugins screen and every core list still carry them.
 *
 * The reported case: one of our own screens, on a site running WPML and WP
 * Offload Media, showed three foreign notices stacked inside our panel —
 * WPML's *"enable automatic detection for image texts"*, WPML's *"taxonomy
 * terms are out of sync"* and *"Finished offloading media"*. A notice
 * belongs where it can be acted on; none of those three can be acted on from
 * a screen of ours, and WordPress relocates every `.notice` to just after
 * `.wp-header-end`, which puts them *inside* our screen rather than above it.
 *
 * **One shared implementation.** This file is the suite's copy: the body is
 * identical in every Freshet plugin that registers screens of its own and the
 * only line that differs is the namespace, the way `bin/build-release.sh` is
 * byte-identical across the repos with `bin/release.conf` holding what
 * differs. Nothing in it names the plugin it is installed in — the page-slug
 * prefix and the version both arrive from the caller, and the plugin's own
 * directory is read off `__DIR__`. It is free code, and it travels with the
 * screens it quiets: whether a given plugin's directory build keeps this file
 * is that plugin's own `bin/release.conf` to answer, and this file assumes
 * nothing either way.
 *
 * Two layers, because one does not reach everything:
 *
 * 1. **The hooks.** On `in_admin_header` — the last hook before
 *    `admin-header.php` fires the four notice actions, and after
 *    `admin_enqueue_scripts`, `admin_head` and every other hook a plugin
 *    registers a notice from — every callback on `admin_notices`,
 *    `all_admin_notices`, `user_admin_notices` and `network_admin_notices`
 *    that can be attributed to a third party is removed.
 * 2. **A scoped stylesheet**, for notices that never pass through those
 *    hooks. WPML prints one into a `.wpml-notice-container` and moves it
 *    after `.wp-header-end` from `DOMContentLoaded`
 *    (`PostListingPage::renderNotice()` in its own tree), which no amount of
 *    hook removal touches. The rule is loaded on our screens only and exempts
 *    our own notices by class.
 *
 * **What is kept: ours, and WordPress's own.** The update nag is core's, it
 * is trivially separable — core's notice callbacks are declared under
 * `wp-admin/` or `wp-includes/`, which is one `realpath()` comparison — and
 * suppressing a WordPress security nag is a different act from declining
 * another plugin's marketing. So core's notices stay. A callback whose
 * origin cannot be read at all is also left alone: this removes what it can
 * positively attribute to a third party and nothing else.
 */
final class OwnScreenNotices
{
    /**
     * The class one of our own notices carries so the stylesheet below
     * leaves it alone. The hook layer does not need it — it attributes by
     * file — but a notice printed inside the page body never passes through
     * a hook, and this is what tells the two apart.
     */
    public const NOTICE_CLASS = 'frst-notice';

    /** The four actions wp-admin/admin-header.php prints notices from. */
    private const HOOKS = [
        'admin_notices',
        'all_admin_notices',
        'user_admin_notices',
        'network_admin_notices',
    ];

    /**
     * Notice containers that carry none of core's notice classes, so the
     * rule above cannot see them. Verified against the WPML tree on a site
     * that shows the problem (`sitepress-multilingual-cms`): `.otgs-notice`
     * is printed both with and without `.notice`, and
     * `.wpml-notice-container` is the wrapper its JavaScript relocates.
     */
    private const FOREIGN_CONTAINERS = [
        '.otgs-notice',
        '.wpml-notice',
        '.wpml-notice-container',
        '.wpml-admin-notice',
    ];

    /**
     * Classes the stylesheet never hides: our own notices, core's update and
     * maintenance nags, and the Settings API's own output — `settings_errors()`
     * prints *our* "Settings saved." under core's markup, so it is ours
     * however core spells it.
     */
    private const EXEMPT = [
        self::NOTICE_CLASS,
        'update-nag',
        'settings-error',
    ];

    /**
     * @param string $pageSlugPrefix The `admin.php?page=` prefix every screen of this plugin is addressed by.
     * @param string $version        This plugin's own version, for the stylesheet handle below.
     */
    public function __construct(
        private readonly string $pageSlugPrefix,
        private readonly string $version,
    ) {
    }

    public function hooks(): void
    {
        // Last on in_admin_header: a plugin that registers its notice from
        // this very hook is still caught, whatever priority it chose.
        add_action('in_admin_header', [$this, 'stripForeignNotices'], PHP_INT_MAX);
        add_action('admin_enqueue_scripts', [$this, 'enqueueFallback'], PHP_INT_MAX);
    }

    /**
     * Whether the request is for one of this plugin's own screens.
     *
     * The `page` query var rather than the screen id, because that var is
     * what WordPress itself resolved the screen from: a submenu page's id is
     * built out of the *parent menu's sanitised title*
     * (get_plugin_page_hookname()), so it changes if the menu is ever
     * relabelled or translated, while the slug is the address. Every screen
     * this plugin registers is `<prefix>` or `<prefix>-something`, so one
     * prefix answers for all of them and a screen added later is covered
     * without this file knowing about it.
     */
    public function isOwnScreen(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen is being rendered; nothing changes.
        $page = sanitize_key(wp_unslash((string) ($_GET['page'] ?? '')));

        return $page === $this->pageSlugPrefix || str_starts_with($page, $this->pageSlugPrefix . '-');
    }

    /**
     * Removes every third-party notice callback from the four hooks, on our
     * screens only.
     *
     * The registry is read rather than guessed, and each removal goes
     * through remove_action() at the priority the callback was registered
     * under — which is what makes a closure removable, since
     * _wp_filter_build_unique_id() hands back the same id for the same
     * object. Collected first and removed after, so the array is not
     * rewritten while it is being walked.
     */
    public function stripForeignNotices(): void
    {
        if (!$this->isOwnScreen()) {
            return;
        }

        global $wp_filter;

        $own  = self::ownDirectory();
        $core = self::coreDirectories();

        foreach (self::HOOKS as $hook) {
            if (!isset($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || !isset($wp_filter[$hook]->callbacks)) {
                continue;
            }

            $foreign = [];
            foreach ((array) $wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ((array) $callbacks as $callback) {
                    $function = $callback['function'] ?? null;
                    if (null !== $function && self::isForeign($function, $own, $core)) {
                        $foreign[] = [$function, (int) $priority];
                    }
                }
            }

            foreach ($foreign as [$function, $priority]) {
                remove_action($hook, $function, $priority);
            }
        }
    }

    /**
     * The scoped fallback, on our screens only: a stylesheet with no file
     * behind it, so it costs one `<style>` block and no request. The handle
     * is derived from the page prefix, which keeps it unique per plugin
     * without this file naming one.
     *
     * The version is passed although nothing is fetched: with no `$src` there
     * is no URL for `?ver=` to land on, but Plugin Check's
     * EnqueuedResourceParameters sniff reads the argument rather than the
     * registration, and a directory build has to come back with no errors.
     * It arrives through the constructor so that the copies of this file in
     * the other plugins still differ by their namespace and nothing else.
     */
    public function enqueueFallback(): void
    {
        if (!$this->isOwnScreen()) {
            return;
        }

        $handle = $this->pageSlugPrefix . '-own-screen';
        wp_register_style($handle, false, [], $this->version);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, self::css());
    }

    /**
     * The rule itself. Scoped to `#wpbody-content` — the screen's own
     * column, so the admin bar, the menu and anything outside the page are
     * untouched — and loaded nowhere else.
     *
     * `!important` is here for a reason rather than out of habit: WPML
     * prints its container with `style="display: none"` and then sets
     * `notice.style.display = 'block'` from its own script. An inline style
     * beats any class rule, so without it the one case this fallback exists
     * for is the one case it would lose.
     */
    public static function css(): string
    {
        $exempt = '';
        foreach (self::EXEMPT as $class) {
            $exempt .= ':not(.' . $class . ')';
        }

        $selectors = [];
        foreach (['.notice', '.updated', '.error'] as $class) {
            $selectors[] = '#wpbody-content ' . $class . $exempt;
        }
        foreach (self::FOREIGN_CONTAINERS as $container) {
            $selectors[] = '#wpbody-content ' . $container;
        }

        return implode(",\n", $selectors) . "{display:none !important;}\n";
    }

    /**
     * Whether a notice callback belongs to someone else. True only when the
     * file it was declared in is known and is neither this plugin's nor
     * WordPress's own; an origin that cannot be read is left alone.
     *
     * @param string|array{0: object|string, 1: string}|object $callback
     * @param array<string>                                    $core
     */
    private static function isForeign(mixed $callback, string $own, array $core): bool
    {
        $file = self::originFile($callback);
        if ('' === $file || '' === $own) {
            return false;
        }

        if (str_starts_with($file, $own . '/')) {
            return false;
        }
        foreach ($core as $dir) {
            if ('' !== $dir && str_starts_with($file, $dir . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The file a callable was declared in, in any of the four shapes
     * `add_action()` takes — a function name, `Class::method`, an object or
     * static array pair, and a closure, which is the shape most notices are
     * registered as. '' when reflection cannot answer.
     *
     * @param string|array{0: object|string, 1: string}|object $callback
     */
    private static function originFile(mixed $callback): string
    {
        try {
            if (is_string($callback) && str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
                $file = (new \ReflectionMethod($class, $method))->getFileName();
            } elseif (is_string($callback)) {
                $file = (new \ReflectionFunction($callback))->getFileName();
            } elseif ($callback instanceof \Closure) {
                $file = (new \ReflectionFunction($callback))->getFileName();
            } elseif (is_array($callback) && 2 === count($callback)) {
                $file = (new \ReflectionMethod($callback[0], (string) $callback[1]))->getFileName();
            } elseif (is_object($callback)) {
                $file = (new \ReflectionMethod($callback, '__invoke'))->getFileName();
            } else {
                return '';
            }
        } catch (\ReflectionException) {
            return '';
        }

        if (!is_string($file) || '' === $file) {
            return '';
        }

        return self::normalize($file);
    }

    /** This plugin's directory: src/Admin/ is two levels under it. */
    private static function ownDirectory(): string
    {
        return self::normalize(dirname(__DIR__, 2));
    }

    /**
     * Where WordPress itself declares its notice callbacks — `update_nag()`
     * and `maintenance_nag()` live in `wp-admin/includes/update.php`. Kept
     * as a list because a site may have moved wp-content, which is not one
     * of these.
     *
     * @return array<string>
     */
    private static function coreDirectories(): array
    {
        return [
            self::normalize(ABSPATH . 'wp-admin'),
            self::normalize(ABSPATH . 'wp-includes'),
        ];
    }

    /**
     * A path both sides of a comparison can be made of: symlinks resolved
     * where the file exists, separators normalised either way. A plugin
     * installed through a symlinked directory — every local working copy of
     * this one — otherwise reads as foreign to itself.
     */
    private static function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', (string) ($real ?: $path)), '/');
    }
}
