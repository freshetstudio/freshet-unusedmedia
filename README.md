# Freshet Unused Media

Determines whether a WordPress media file is still in use — ACF fields, page builders, options, galleries and raw URLs included — and safely deletes what isn't.

WordPress' "Uploaded to" column only records where a file was first attached. It misses ACF image/gallery/repeater fields, featured images, block and shortcode galleries, Elementor data, WooCommerce product galleries, the customizer logo/site icon, widgets, term/user meta and plain URLs in content (including resized `-300x200` / `-scaled` variants). This plugin scans all of it.

## Features

- **Usage column** in the Media Library (list mode) with per-file "Check usage"
- **Evidence meta box** on the attachment screen: exactly where the file is used, with edit links
- **Batched full-library scan** (Media → Usage), resumable, runs in the browser
- **Progress you can read**: attachments done / total, time spent, an estimate of what is left and the check the time is going into; a finished scan says how long it took and how the time split across the checks
- **Safe deletion** of unused files: every file is re-verified immediately before deletion; anything that became used is skipped
- Conservative by design: ambiguous matches count as *used*; ID matches are digit-boundary-checked (123 never matches 1234)
- **WP-CLI** (licensed): `wp freshet-unusedmedia scan` / `list` — the same scan and the same results across many installs, in `--format=json`. It reports; it has no delete verb.
- **Evidence report** (licensed): export the library from Media → Usage as CSV or JSON — one row per file, with the references behind each verdict. It exports; it does not delete.
- **Space totals** (licensed): what the unused files are holding now, and — kept separate — what deletions made here have actually freed.

## Dev environment

Symlink or copy the plugin into a local WordPress install and activate it:

```bash
ln -s "$(pwd)" /path/to/wp/wp-content/plugins/freshet-unusedmedia
```

No build step — plain PHP (8.2+, autoloaded from `src/`) and plain assets in `assets/`.

Lint: `find . -name '*.php' -exec php -l {} \;`

## WP-CLI (licensed)

The scan the Media → Usage screen runs, without a browser — for operators who
have more sites than browser tabs and want the answer piped somewhere.

```bash
wp freshet-unusedmedia scan [--resume] [--format=table|json|csv|yaml]
wp freshet-unusedmedia list [--fields=<fields>] [--limit=<n>] [--format=table|json|csv|yaml|ids|count]
```

- **Licensed.** Without a valid key both subcommands exit 1 and do nothing. With
  no key stored the check never leaves the site — no request is made.
- **`scan` completes in one invocation.** There is no request time limit to work
  around, so it does not batch the way the browser scan has to; attachment IDs
  are still fetched in chunks of `freshet_unusedmedia_batch_size` and the cursor
  is written after every chunk, so `--resume` picks up an interrupted run. It
  shares its Scanner, detectors, results and cursor with the admin screen — the
  two read the same numbers by construction.
- **It prints a progress line per chunk** — the same sentence the Usage screen
  paints under its bar, from the same `ScanProgress`, and the same breakdown
  when it finishes. Suppressed in the machine formats (`--format=json|csv|yaml`).
- **`list` reads the stored results**; it does not re-scan. Trashed files are
  excluded, exactly as on Media → Usage.
- **Neither subcommand deletes anything, and there is deliberately no CLI delete
  verb.** Deletion stays in the admin, behind a confirmation and the
  re-verification pass.
- **Nothing is scheduled.** A scan happens when someone runs one.
- Stripped from the wordpress.org build along with the rest of the paid tier
  (`bin/release.conf`), so the directory archive has nothing locked in it.

## Evidence report (licensed)

A card on Media → Usage that exports the last scan's results — the file an
agency hands a client before it deletes anything.

- **CSV or JSON, from the same rows.** CSV is the deliverable: stable English
  column keys (`id,file,title,url,mime,uploaded,status,bytes,size,scanned_at,
  references,evidence`), a UTF-8 BOM so a spreadsheet reads non-ASCII filenames,
  and the references packed into one readable `evidence` cell. JSON carries the
  references as structured objects plus a `summary` block of the library totals.
- **Used files as well as unused**, unused first, with a scope select for
  unused-only. The working is half the point: "why we kept these" is what makes
  a deletion defensible. Files that have never been scanned are not rows — they
  have no evidence — and their count is stated on the card and in the JSON
  summary.
- **Serialisation, not detection.** Every value comes from the stored results
  (`ResultStore::byStatus()`, `refs()`, `status()`, `scannedAt()`, `counts()`)
  and the same byte computation the unused table uses. Nothing is re-scanned,
  so the report cannot disagree with the screen. Where a file has more
  references than a scan stores, the row says `…and N more (not stored)` rather
  than implying the list is complete.
- **It exports, and that is all it does.** No delete action, nothing scheduled,
  nothing emailed; the file streams to the browser that asked for it.
- Stripped from the wordpress.org build with the rest of the paid tier
  (`bin/release.conf`).

## Space totals (licensed)

A card on Media → Usage carrying two figures that are deliberately not merged.

- **Reclaimable now** — bytes held by the files currently on the unused list.
  The figure to quote before touching anything; nothing has been freed. It is
  summed live from the same `FileSize::bytes()` the table shows per file, in
  pages of 200, so it reconciles against rows anyone can check.
- **Reclaimed so far** — bytes actually freed by deletions this plugin made,
  accumulated in `ReclaimedLedger` at deletion time because after
  `wp_delete_attachment()` there is nothing left to measure. **It is never the
  reclaimable number relabelled.** A file sent to the media trash frees no disk,
  so it is counted separately as pending rather than as reclaimed.
- **Unknown sizes are said, not zeroed.** A file with no local copy and no
  recorded size — an offloaded library — is counted and reported as un-sizeable
  rather than folded into the total as 0.
- **Original file only.** Deleting also removes the generated thumbnail sizes,
  so the real saving is larger than either figure. Both understate.
- **No scan, no store of its own, nothing scheduled.** It adds up results that
  already exist, when someone opens the page. Recording at deletion time lives
  in the free build (`ReclaimedLedger`, four integers in one option) so the
  deletion path never consults the licence; only the card is gated.
- Stripped from the wordpress.org build with the rest of the paid tier
  (`bin/release.conf`).

## Filters

| Filter | Purpose |
| --- | --- |
| `freshet_unusedmedia_detectors` | Add/remove detectors (`DetectorInterface[]`, receives `AttachmentContext`) |
| `freshet_unusedmedia_is_used` | Final say on the computed status (`bool $used, Reference[] $refs, AttachmentContext $ctx`) |
| `freshet_unusedmedia_batch_size` | Scan batch size (default 10 in the browser, 100 over WP-CLI) |

## License

GPL-2.0-or-later. Part of the [Freshet Studio](https://freshet.studio) plugin suite.
