# Freshet Unused Media

Truly determine whether a WordPress media file is still in use — ACF fields, page builders, options, galleries and raw URLs included — and safely delete what isn't.

WordPress' "Uploaded to" column only records where a file was first attached. It misses ACF image/gallery/repeater fields, featured images, block and shortcode galleries, Elementor data, WooCommerce product galleries, the customizer logo/site icon, widgets, term/user meta and plain URLs in content (including resized `-300x200` / `-scaled` variants). This plugin scans all of it.

## Features

- **Usage column** in the Media Library (list mode) with per-file "Check usage"
- **Evidence meta box** on the attachment screen: exactly where the file is used, with edit links
- **Batched full-library scan** (Media → Usage), resumable, runs in the browser
- **Safe deletion** of unused files: every file is re-verified immediately before deletion; anything that became used is skipped
- Conservative by design: ambiguous matches count as *used*; ID matches are digit-boundary-checked (123 never matches 1234)

## Dev environment

After cloning, arm the content guard once — `core.hooksPath` lives in
`.git/config` and so is never cloned:

```bash
bash .freshet/install-hooks.sh
```

The same check runs in CI on every push, where it cannot be skipped. See
`.freshet/README.md`.

Symlink or copy the plugin into a local WordPress install and activate it:

```bash
ln -s "$(pwd)" /path/to/wp/wp-content/plugins/freshet-unusedmedia
```

No build step — plain PHP (8.2+, autoloaded from `src/`) and plain assets in `assets/`.

Lint: `find . -name '*.php' -exec php -l {} \;`

## Filters

| Filter | Purpose |
| --- | --- |
| `freshet_unusedmedia_detectors` | Add/remove detectors (`DetectorInterface[]`, receives `AttachmentContext`) |
| `freshet_unusedmedia_is_used` | Final say on the computed status (`bool $used, Reference[] $refs, AttachmentContext $ctx`) |
| `freshet_unusedmedia_batch_size` | Scan batch size (default 10) |

## License

GPL-2.0-or-later. Part of the [Freshet Studio](https://freshet.studio) plugin suite.
