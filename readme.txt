=== Freshet Unused Media ===
Contributors: kristoffbertram
Tags: media, unused media, media library, clean up, attachments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Determines whether media is still in use — ACF, page builders, options and raw URLs included — and safely deletes what isn't.

== Description ==

WordPress' own "Uploaded to" column only tracks where a file was first attached — it says nothing about where a file is actually *used*. Files referenced from ACF fields, featured images, galleries, widgets, the customizer logo, WooCommerce product galleries or plain URLs in content all look "unattached", and genuinely unused files look no different from files your site depends on.

Freshet Unused Media scans everywhere a reference can hide and tells you, per attachment, exactly where it is used — or that it provably isn't.

**What it detects**

* ACF fields: image, gallery, file — including serialized values and repeater/flexible sub-fields, verified via ACF's own field-key meta
* ACF blocks: field values stored inside the block markup, where no URL is ever written
* Featured images and WooCommerce product galleries
* Block editor content: image blocks, gallery blocks, `wp-image-N` classes
* Classic content: `[gallery]` and `[playlist]` shortcodes, page-builder shortcodes holding an ID (`image="123"`), and raw file URLs, including resized variants like `photo-300x200.jpg`, `-scaled` files and percent-encoded filenames
* Elementor page data
* Options and theme mods: site icon, custom logo, widgets, customizer settings — serialized or JSON
* Term meta and user meta (ACF fields on categories and profiles)
* Comments and comment meta (a file linked in a reply, review photos)
* Excerpts, and unsaved edits held in autosaves

**How it works**

* A **Usage column** in the Media Library (list mode) with a per-file "Check usage" action
* A **Usage meta box** on the attachment screen showing the evidence: which post, which field, which option — with edit links
* A **full-library scan** (Media → Usage) that batches through your library in the browser, resumable at any time
* **Safe deletion**: delete selected or all unused files, each one re-checked before it goes

**Why the answer can be trusted**

Deleting media is destructive, so the scan is built to be wrong in one direction only — towards keeping the file.

* **Ambiguity counts as used.** A bare attachment ID in a meta key the plugin has never seen keeps the file. Matches are boundary-checked, so attachment 123 is never matched by `wp-image-1234`.
* **Every file is re-checked in the instant before it is deleted.** Anything that became used between the scan and your click is skipped rather than deleted, and the result tells you how many were skipped.
* **A reference in the trash still counts.** A trashed post or comment can be restored, so the files it uses are kept.
* **Files uploaded in the last 24 hours count as in use** — an editor may still be placing them — so a clean-up never removes work in progress.
* **The evidence is shown, not summarised.** Every reference is listed with the object it lives in, the field or option it was found in, and a link to go and look: you can check the reasoning before you act on it.
* **The blind spot is written down.** References kept inside a plugin's own custom database tables cannot be found by any query-based scanner, and the FAQ below names that limit rather than leaving you to discover it.

The "Uploaded to" relation itself is shown as informational evidence but never counts as usage — that unreliable signal is exactly what this plugin replaces.

Thoroughness is the point rather than speed: a first full scan of a large library can take hours rather than minutes. It runs in batches that stop before the PHP time limit and resume where they left off, so a scan always makes progress and never has to be started over.

**Extensible**

Site-specific detectors can be added via the `freshet_unusedmedia_detectors` filter; `freshet_unusedmedia_is_used` gets the final say on any status; `freshet_unusedmedia_batch_size` and `freshet_unusedmedia_batch_seconds` tune scan batches; `freshet_unusedmedia_upload_grace` sets the recent-upload window in seconds (0 disables it).

**Free, and what a licence adds**

Scanning, detection and deletion are free, and stay free — they are the plugin. Nothing in this download is locked, limited or time-barred. A licence from [freshet.studio](https://freshet.studio) adds four things. The Used view: open any file that is in use and see every place it is used, not just the first few. A WP-CLI command — `wp freshet-unusedmedia scan` and `wp freshet-unusedmedia list` — for running the same scan across many sites without a browser and reading the result as JSON. An evidence report: export the whole library from Media → Usage as CSV or JSON, one row per file with its status, its size and the places it was found referenced, so the reasoning can be checked — or handed to whoever has to approve it — before anything is deleted. And space totals on Media → Usage: how much disk the unused files are holding now, and, separately, how much has actually been freed by the deletions made here.

All four are additions rather than limits on what is here, and none of them deletes: the command scans and reports, the report exports, the totals add up, and deletion stays where it is, in the admin, behind a confirmation and the re-verification pass.

Part of the Freshet plugin suite. Full documentation: [freshet.studio/docs](https://freshet.studio/docs).

== Installation ==

1. Upload the plugin and activate it.
2. Go to **Media → Usage** and run a full scan.
3. Review the unused list, then delete selected files or all unused ones.

Tip: add `define( 'MEDIA_TRASH', true );` to `wp-config.php` so deletions go to trash instead of being permanent.

== Frequently Asked Questions ==

= Can it be wrong? =

Detection is deliberately conservative: filename and structural matches are boundary-checked (attachment 123 never matches `wp-image-1234`), ambiguous ID matches count as used, and every file is re-verified right before deletion.

What it cannot see is anything outside the tables it reads — posts, postmeta, options, term meta, user meta, comments and comment meta. The blind spot most worth knowing about is inside your database, not outside it: **references stored in a plugin's own custom database tables** — form entries, slider or page-builder records, any plugin that keeps attachment IDs or file URLs in a table of its own. No query-based scanner can find a reference in a table whose shape it has never seen. The same applies to references hard-coded in theme or plugin files, references held by an external service, references on other sites of a multisite network (network-wide options included), and the legacy Links manager.

Two deliberate choices are worth knowing too. Old post revisions are not counted as usage — a file removed from a post is meant to be found — but autosaves are, because they hold edits nobody has saved yet. And the re-check before deletion is not atomic: a reference written in the instant between the re-check and the deletion is not seen. That window is a fraction of a second; the recent-upload grace period covers the realistic case (a file placed in the editor before its post is saved), and `MEDIA_TRASH` covers the rest.

So: if a plugin on your site stores media in its own tables, check what it holds before deleting — and add `define( 'MEDIA_TRASH', true );` (see Installation) so a deletion can be undone.

= How long does a full scan take? =

Longer than a scanner that only looks at the "Uploaded to" column, because it looks everywhere else as well. On a large library the first full scan is measured in hours, not minutes. It runs in batches from the Media → Usage screen, each batch stops before the PHP time limit and the next one resumes from the last completed file, so the scan always makes progress and can be stopped and picked up later. Nothing runs on a schedule — a scan happens when you start one.

= Does a file referenced from the trash get deleted? =

No. A trashed post or comment can be restored, so a reference from one keeps the file — it is reported as a possible reference rather than a confirmed one, and possible still counts as used.

= Does it work with multisite? =

Per site, yes. Cross-site references (another site embedding this site's file URL) are not detected.

= Does it delete anything by itself? =

Never. Scanning only reads and caches results. Deletion happens exclusively when you click a delete action, after re-verification. The licensed WP-CLI command scans and reports; there is deliberately no delete verb on the command line, so nothing can be deleted non-interactively. The licensed evidence report only writes a file to your own browser — it has no delete action either, and exporting changes nothing on the site. The licensed space totals only add up sizes; opening the page deletes nothing.

== Changelog ==

= 1.0.1 =
* Detects field values stored inside ACF block markup, which carry no URL and were previously invisible.
* Detects IDs in shortcode attributes beyond `[gallery]` — `[playlist]` and page-builder shortcodes included.
* Detects IDs inside JSON stored in meta and options under any key, including JSON nested in serialized settings.
* Scans comments and comment meta, and post excerpts.
* Counts autosaves (unsaved edits) as usage; old revisions still do not count.
* Matches percent-encoded and JSON-escaped spellings of non-ASCII filenames, and alternate-format (WebP/AVIF) sources recorded in attachment metadata.
* Files uploaded in the last 24 hours count as in use (`freshet_unusedmedia_upload_grace` to tune).
* Full-scan batches stop before the PHP time limit and resume from the last completed file, so large libraries always make progress (`freshet_unusedmedia_batch_seconds` to tune).
* Text domain renamed to freshet-unused-media to match the wordpress.org plugin slug.

= 1.0.0 =
* Initial release: usage scanning across postmeta (ACF, Elementor, WooCommerce, featured images), post content, options/theme mods, term meta and user meta; Media Library usage column and filter; evidence meta box; batched full-library scan; safe selected/all deletion with pre-delete re-verification.
