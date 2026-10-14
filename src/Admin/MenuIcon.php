<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

defined('ABSPATH') || exit;

/**
 * The plugin's own mark, in the one shape WordPress will repaint: the
 * `$icon_url` add_menu_page() takes as a base64 SVG data URI (freshet-D252).
 *
 * wp-admin/menu-header.php gives that branch its own treatment — the URI
 * becomes a CSS background-image and the element is tagged `.svg` — and
 * wp-admin/js/svg-painter.js then decodes it and rewrites the markup to the
 * admin colour scheme's icon colour, once at rest and again on hover and for
 * the current menu. Three properties of the markup below are what make that
 * work, and none of them is decoration:
 *
 *  - exactly one `fill`, and no `stroke` anywhere. The painter rewrites
 *    `fill="..."` and nothing else, so a stroked mark would stay black — and
 *    black on the admin menu is invisible.
 *  - no `style` attribute. The painter replaces the whole of one with
 *    `style="fill:<colour>"`, so anything else in it is lost.
 *  - ASCII only. The painter round-trips through atob()/btoa(), which throw
 *    on any character above U+00FF, and a throw leaves the mark unpainted.
 *
 * The geometry is this plugin's own glyph from .wordpress-org/icon.svg with
 * the blue rounded square dropped — the menu paints its own background — and
 * the glyph fitted to a 17px box centred in the 20px core draws at, which is
 * where core's own dashicons sit optically.
 *
 * assets/menu-icon.svg carries the same markup byte for byte, for anything
 * that wants the file rather than the string, and tests/menu-icon.php asserts
 * the two agree — two copies of one drawing is exactly the pair that drifts.
 */
final class MenuIcon
{
    /** Kept in step with assets/menu-icon.svg — see tests/menu-icon.php. */
    // phpcs:ignore Generic.Files.LineLength.TooLong -- one drawing, one line; wrapping it would stop it diffing against the file.
    public const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path fill="#000" d="M11.96 6.07C12.68 6.07 13.27 6.66 13.27 7.38C13.27 8.1 12.68 8.69 11.96 8.69C11.24 8.69 10.65 8.1 10.65 7.38C10.65 6.66 11.24 6.07 11.96 6.07ZM1.51 15.22L1.5 3.46C1.5 2.73 2.09 2.15 2.81 2.15H13.27C14 2.16 14.58 2.74 14.58 3.45C14.58 4.18 13.99 4.76 13.27 4.76H4.12V10.01L6.73 7.39L11.95 12.61H14.58V10.01L18.5 13.93L14.58 17.85V15.22H11.95L6.73 10.01Z"/></svg>';

    /**
     * The mark as add_menu_page() wants its sixth argument.
     */
    public static function dataUri(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::SVG);
    }
}
