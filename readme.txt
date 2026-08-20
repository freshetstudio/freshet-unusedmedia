=== Freshet Unused Media ===
Contributors: kristoffbertram
Tags: media, unused media, media library, clean up, attachments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Determines whether media is still in use — ACF, page builders, options and raw URLs included — and safely deletes what isn't.

== Description ==

WordPress' own "Uploaded to" column only tracks where a file was first attached — it says nothing about where a file is actually *used*. Images referenced from ACF fields, featured images, galleries, widgets, the customizer logo, WooCommerce product galleries or plain URLs in content all look "unattached", and genuinely unused files look no different from files your site depends on.

Freshet Unused Media scans everywhere a reference can hide and tells you, per attachment, exactly where it is used — or that it provably isn't.

**What it detects**

* ACF fields: image, gallery, file — including serialized values and repeater/flexible sub-fields, verified via ACF's own field-key meta
* Featured images and WooCommerce product galleries
* Block editor content: image blocks, gallery blocks, `wp-image-N` classes
* Classic content: `[gallery]` shortcodes and raw file URLs, including resized variants like `photo-300x200.jpg` and `-scaled` files
* Elementor page data
* Options and theme mods: site icon, custom logo, widgets, customizer settings
* Term meta and user meta (ACF fields on categories and profiles)

**How it works**

* A **Usage column** in the Media Library (list mode) with a per-file "Check usage" action
* A **Usage meta box** on the attachment screen showing the evidence: which post, which field, which option — with edit links
* A **full-library scan** (Media → Usage) that batches through your library in the browser, resumable at any time
* **Safe deletion**: delete selected or all unused files — every file is re-checked immediately before deletion, and anything that has become used is skipped
* Ambiguous matches (a bare ID in unknown meta) are treated as **used** — the plugin errs on the side of keeping files

The "Uploaded to" relation itself is shown as informational evidence but never counts as usage — that unreliable signal is exactly what this plugin replaces.

**Extensible**

Site-specific detectors can be added via the `freshet_unusedmedia_detectors` filter; `freshet_unusedmedia_is_used` gets the final say on any status; `freshet_unusedmedia_batch_size` tunes scan batches.

Part of the Freshet plugin suite. Full documentation: [freshet.studio/docs](https://freshet.studio/docs).

== Installation ==

1. Upload the plugin and activate it.
2. Go to **Media → Usage** and run a full scan.
3. Review the unused list, then delete selected files or all unused ones.

Tip: add `define( 'MEDIA_TRASH', true );` to `wp-config.php` so deletions go to trash instead of being permanent.

== Frequently Asked Questions ==

= Can it be wrong? =

Detection is deliberately conservative: filename and structural matches are boundary-checked (attachment 123 never matches `wp-image-1234`), ambiguous ID matches count as used, and every file is re-verified right before deletion.

What it cannot see is anything outside the tables it reads — posts, postmeta, options, term meta and user meta. The blind spot most worth knowing about is inside your database, not outside it: **references stored in a plugin's own custom database tables** — form entries, slider or page-builder records, any plugin that keeps attachment IDs or file URLs in a table of its own. No query-based scanner can find a reference in a table whose shape it has never seen. The same applies to references hard-coded in theme or plugin files, references held by an external service, and references on other sites of a multisite network.

So: if a plugin on your site stores media in its own tables, check what it holds before deleting — and add `define( 'MEDIA_TRASH', true );` (see Installation) so a deletion can be undone.

= Does it work with multisite? =

Per site, yes. Cross-site references (another site embedding this site's file URL) are not detected.

= Does it delete anything by itself? =

Never. Scanning only reads and caches results. Deletion happens exclusively when you click a delete action, after re-verification.

== Changelog ==

= 1.0.0 =
* Initial release: usage scanning across postmeta (ACF, Elementor, WooCommerce, featured images), post content, options/theme mods, term meta and user meta; Media Library usage column and filter; evidence meta box; batched full-library scan; safe selected/all deletion with pre-delete re-verification.
