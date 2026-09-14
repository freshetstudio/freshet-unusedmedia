=== Freshet Unused Media ===
Contributors: kristoffbertram
Tags: media, unused media, media library, clean up, attachments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.5
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
* Term descriptions — an image or an ID in a category, tag or product-category description
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

What it cannot see is anything outside the tables it reads — posts, postmeta, options, term descriptions, term meta, user meta, comments and comment meta. The blind spot most worth knowing about is inside your database, not outside it: **references stored in a plugin's own custom database tables** — form entries, slider or page-builder records, any plugin that keeps attachment IDs or file URLs in a table of its own. No query-based scanner can find a reference in a table whose shape it has never seen. The same applies to references hard-coded in theme or plugin files, references held by an external service, references on other sites of a multisite network (network-wide options included), and the legacy Links manager.

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

= 1.0.5 =
* **A folder whose name is written in a different case is now read once.** The claims index looked a directory up case-sensitively while the database folded case, so on a server that treats `2026/09` and `2026/09` as one folder the same files could be read under two scopes — and a file's own claim could be missed. One scope per folder now, however it is spelled.
* **A file's question is asked once per batch rather than once per row standing on it.** Where several library entries share one file on disk, the scan repeated the same reads for each of them. The answer is shared across the batch now: the same verdict, measurably less work on libraries where copies are common.
* **Housekeeping.** A translator note on the Unused label's placeholder, and the wordpress.org listing artwork.

= 1.0.4 =
* **A reference written in a different case now counts.** Rows naming a file or an ID are fetched case-insensitively by the database and were then thrown away by a case-sensitive check — so a link written `HERO.JPG` against a file stored `hero.jpg`, or markup carrying `DATA-ID="123"`, kept nothing in use and the file was offered for deletion. Both halves agree now, accented filenames included.
* **Two spellings of one filename are no longer counted as one library entry.** The database folds case where the plugin did not, so `Hero-Banner.jpg` and `HERO-BANNER.jpg` were one group in the count and two in the list. Each is its own file now, and where the server's filesystem would treat them as the same file a deletion refuses it rather than removing a file the other entry still stands on.
* **A count that could not be read now says so instead of reading zero.** Where a query failed, the Scan tab reported "Unused: 0" for a library it had not managed to look at. The figures now stand at a dash under an error notice, a listing that could not be read is not drawn at all — so there is no empty table and no Delete all button naming a set nobody counted — and `wp freshet-unusedmedia scan` ends on an error instead of a success line, leaving `--resume` to carry on from the last completed batch.
* **Delete all now reports as much as the checkbox form.** Its progress bar sat at 0% for the whole run and rendered below the table, out of sight of the button that started it, so a long deletion looked like a screen that had stopped responding. The bar now sits under the button and shows real progress, and the completion message names what was deleted, skipped and failed, and how many files the run did not reach.
* **A file another library entry stands on is now held back at the scan, not only at the delete.** Where one entry's resized copy or pre-scale original was itself uploaded separately, the file read as unused, was listed, and was then quietly skipped when you tried to delete it. It now comes back used, with the reason on its own row and the entry that would lose a file named in the evidence panel.
* **An attachment's own description and caption are searched.** WordPress keeps both on the attachment itself, and the scan excluded those rows outright — so a file referenced only from another upload's description or caption was reported unused.
* A file named only by an unsaved draft's custom fields now counts as in use, the way one named in that draft's text already did. Old revisions still do not count.
* An image pasted into a comment out of the block editor is recognised by the ID the editor writes (`class="wp-image-123"`), where only a plain file URL or an attachment-page link was read before.
* **The Scan tab leads with its figures.** The three numbers the tab exists for sat at body size between two paragraphs of helper text; they now open the section, with the scan's timestamp and everything that qualifies them underneath. The explanatory copy on both tabs is shorter and reads as points rather than paragraphs, with nothing it said dropped.
* Deleting from a library where several entries share one file is substantially faster: each file's checks now run once for the whole group instead of once per entry. On a large library a five-file request fell from about 102 seconds to 70.
* A scan whose attachments hop between upload folders no longer re-reads a folder for every attachment, so scanning a library whose uploads are not in folder order is faster.

= 1.0.3 =
* **A failed database read no longer counts as "nothing references this file".** A query that errored used to be indistinguishable from one that honestly found nothing, so a file could be recorded unused because the check never actually ran. A file whose check failed is now left unscanned instead of judged, the scan reports how many checks failed, and a deletion refuses any file it could not re-check.
* **Deleting one library entry can no longer remove a file another entry still stands on.** Where an entry's resized copy or its pre-scale original was itself uploaded separately, the two entries are separate groups that can honestly reach opposite verdicts. The delete pass now asks who else stands on each file and skips it rather than unlinking it.
* **A deletion now finishes inside the time PHP gives it.** Every file is re-checked immediately before it goes, and on a large library that could run a batch past the host's time limit — leaving you with no report of what was and was not removed. A batch now stops on a file boundary, says how many files it did not reach, and the next batch carries on from there. Nothing is half-deleted and no check is skipped to make it fit.
* **The scan says when it cannot read a library's files from disk**, on the Scan tab and in the WP-CLI summary. Where media is offloaded to remote storage, the leftover-thumbnail protection cannot run: those files are judged on what the library records about them and nothing else, so an image whose old thumbnail is still on a page can be reported as unused.
* Files that an in-admin image edit replaced, and old sizes a theme change stopped generating, still count as names of the image they came from — so a page still pointing at one keeps that image in use.
* A new "sizes with no original" section lists resized files left on disk with no library entry behind them. They are not counted, not judged and not offered for deletion — there is no entry to judge them with.
* That list no longer includes an upload whose own filename happens to end in dimensions (`logo-300x200.png`, uploaded as it is), nor a size the entry's own metadata still names.
* A block that stores its attachment as a plain ID is detected wherever the block markup is kept — a block widget, a theme mod, a stored template in an option, or a custom field — and not only in post content.
* A link to a file's own attachment page is read as a reference in custom fields, widgets, theme mods, term descriptions and comments, where before only post content read it.
* Two uploads cut from the same image no longer answer for each other's replaced files, so the unused count and the Usage badge stop reporting a file as used with nothing on the site showing it.

= 1.0.2 =
* **Deletion now works on the file, not the attachment row.** Where two library entries point at the same file on disk, deleting one no longer removes a file the other still uses. The unused count, the unused list and the delete pass all group by file.
* A Media Library row is judged by its file for the same reason, so the Usage column and the unused list can no longer disagree about one file.
* A file uploaded in the last 24 hours now says it is held back on the library badge and in the evidence panel, instead of reading as used or showing nothing at all.
* The evidence panel says why a file is being held back, instead of leaving the reason blank.
* Both listings sort from their own column headers.
* The unused figure is sent back with every delete batch, so the count on screen stays right through a long deletion.
* Detects an ID inside JSON however it is spaced — pretty-printed with spaces around the colon, or written as a member of a compact array like `[12,34]` — in post content, meta and options alike.
* Detects an ID stored as a quoted string where only a bare number was recognised before.
* Searches a namespaced block's whole attribute object rather than only its `data` key: a file referenced as `{"imageId":123}` by a custom block used to scan as unused.
* Scans term descriptions — an image pasted into a category, tag or product-category description used to read as unused.
* Reads a link to a file's own attachment page as a reference.
* A value that refers back to itself can no longer hang a scan — some multilingual plugins store one, and the scan used to run until it exhausted memory.
* The WP-CLI scan releases each batch's caches, so memory stays flat across a large library.
* A page of the unused list resolves its files in one query instead of one per row.

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
