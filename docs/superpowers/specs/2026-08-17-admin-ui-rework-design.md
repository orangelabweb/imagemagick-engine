# Admin UI/UX rework — design

Date: 2026-08-17
Status: approved design, ready for implementation planning

## Goal

Rebuild the plugin's admin page so it reads as a native part of wp-admin, drop the
duplicated jQuery/Alpine JS stacks down to one, and make image regeneration
survivable — resumable across page loads, cancellable, and honest about failures.

## Decisions

| Decision | Choice |
|---|---|
| Scope | Full rebuild of the settings page markup |
| Reactivity | Alpine.js **CSP-safe build**; jQuery and jQuery UI removed entirely |
| Visual language | Strictly WordPress-native classes and CSS custom properties |
| Tabs | Settings, Regenerate (no third Status tab) |
| Minimum WordPress | Raised from 5.3 to **6.4** |
| Regeneration | New UI **and** server-side batched, resumable queue |
| Queue storage | Option `ime_regen_queue` with `autoload = false` |
| Quality inputs | `type="number"` with `placeholder="auto"` |

## File structure

```
imagemagick-engine.php      bootstrap, hooks, engine dispatch, resize functions
includes/admin-page.php     ime_option_page() and render helpers
includes/ajax.php           ime_ajax_* handlers and queue management
js/alpine.csp.min.js        @alpinejs/csp 3.x  (replaces js/alpine.min.js)
js/ime-admin.js             all Alpine.data() components, zero jQuery
css/ime-admin.css           rewritten, WordPress custom properties only
```

The engine dispatch convention (`ime_im_{mode}_valid()` / `ime_im_{mode}_resize()`,
resolved by string concatenation) is untouched and stays in the main file. Adding a
new engine keeps working exactly as documented in CLAUDE.md.

`.distignore` must not exclude `includes/`. Verify the built SVN payload before the
release tag.

## Dependencies

Removed from `wp_register_script( 'ime-admin', ... )`: `jquery`,
`jquery-ui-progressbar`. The handle registers with an empty dependency array.

`alpinejs` handle is re-registered against `js/alpine.csp.min.js`. Both scripts stay
enqueued in the footer.

### Consequence of the CSP build

The CSP build evaluates no expressions inside attributes. Every conditional needs a
property or method on the component object:

```html
<!-- before -->
<tr x-show="mode === 'php'">
<!-- after -->
<tr x-show="isPhp">
```

Four engines means four getters. This is the accepted cost of CSP compatibility.

## Settings tab

### Page shell

```html
<div class="wrap">
  <h1>ImageMagick Engine</h1>
  <!-- admin notices -->
  <nav class="nav-tab-wrapper">Settings | Regenerate</nav>
```

Tab switching is Alpine-driven with `x-show` getters. The active tab is written to
the URL with `history.replaceState()` as
`?page=imagemagick-engine&tab=regenerate` so the link is shareable and a reload
lands on the same tab.

The master "Enable" checkbox sits above the tabs, since it gates everything below.
It uses a plain WordPress checkbox and label, not a custom toggle switch.

### Engine cards

The `<select id="ime-select-mode">` is replaced by a `<fieldset>` of radio cards —
one per engine, all four visible at once with their availability and version:

```
┌────────────────────────┐ ┌────────────────────────┐
│ ◉ Imagick PHP module   │ │ ○ Gmagick PHP module   │
│   ✔ 7.1.1-15           │ │   ✖ Module not found   │
└────────────────────────┘ └────────────────────────┘
┌────────────────────────┐ ┌────────────────────────┐
│ ○ ImageMagick CLI      │ │ ○ GraphicsMagick CLI   │
│   ✔ /usr/bin/convert   │ │   ✖ Command not found  │
└────────────────────────┘ └────────────────────────┘
```

- `<legend class="screen-reader-text">` names the group.
- Each `<label>` wraps its whole card.
- Unavailable engines render `disabled` with `aria-describedby` pointing at their
  status line. They remain visible — showing what the server actually has is the
  reason for the change.
- Status icons are `<span class="dashicons dashicons-yes-alt">` and
  `dashicons-dismiss`, plus `.screen-reader-text` with "Available" / "Not
  available".
- Layout is CSS grid over WordPress's `.card` base.

The POST handler is unaffected: the field is still named `mode` and still validated
against `array_key_exists( $posted_mode, ime_get_available_modes() )`.

`ime_option_status_icon()` and `ime_option_display()` become dead code once the
`yes.png` / `no.png` / inline-`style="display:none"` pattern is gone.
`ime_option_admin_images_url()` has only those two callers plus the media-page
`wpspin_light.gif`, all of which are removed. Delete all three functions.

### Binary path fields

The CLI and GraphicsMagick path inputs move *inside* their engine card instead of
living in separate `form-table` rows, and are shown only when that card is
selected. The "Test path" button stays, but its result renders as a
`notice notice-error inline` / `notice-success inline` element instead of toggling
three `<img>` tags.

The existing `ime_ajax_test_im_path` endpoint is reused unchanged apart from
switching its response to `wp_send_json_success()` / `wp_send_json_error()`.

### Quality

Two `type="number"` inputs, `min="0" max="100"`, `placeholder="auto"`:

- Optimize for quality
- Optimize for size

An empty field means the dynamically computed default, matching current behaviour
(`-1` stored). Help text below shows the value the dynamic computation currently
produces, so "auto" is not opaque.

### Checkboxes

Interlace, Preserve Exif, and Disable client-side media processing keep their
`form-table` rows and `.ime-description` help text. They work; they are not
rewritten. The client-side processing row stays behind
`ime_client_side_processing_available()` as today.

### Image size table

Becomes `<table class="wp-list-table widefat striped">` with columns Quality /
Size / None. Each column header carries a `<button class="button-link">Select
all</button>` that sets every row in that column. Each row's radios are wrapped in
a `<fieldset>` with a screen-reader legend naming the size.

Field names stay `handle-mode-{$size}` with values `quality` / `size` / `skip`, so
the POST handler is unchanged.

## Regenerate tab

### Idle state

```
Regenerate images

Sizes    ☑ Thumbnail  ☑ Medium  ☐ Medium Large  ☑ Large
         [All] [None] [Match settings]

☐ Also regenerate images already handled by ImageMagick Engine

                                       [ Start regeneration ]
```

When the plugin is disabled, the "Resize will use standard WordPress functions"
warning renders as `notice notice-warning inline`, not a bare `<p class="howto">`.

### Running state

Same Alpine component, swapped by `x-show` getters:

```
Regenerating images
████████████████░░░░░░  62 %
1,240 / 2,000 · ~3 min remaining
✖ 4 failed                             [Cancel]

▾ Failed images
   IMG_2201.jpg — memory limit reached
   panorama.png — file missing
```

- Progress uses the native `<progress class="ime-progress" max="100">` element.
  No `jquery-ui-progressbar`.
- Styled with `--wp-admin-theme-color`. The hardcoded `#FF6600` is removed.
- The count and percentage live in an `aria-live="polite"` region throttled to at
  most one update every 2 seconds. Announcing once per image would flood screen
  readers.
- Time remaining is a moving average over the last 5 batches, not a cumulative
  average, and is hidden until roughly 10 images have completed.

### Completion

`notice notice-success` with the processed count, plus a `notice notice-warning`
listing failures when there are any. No `alert()` remains anywhere in the codebase.

## Queue architecture

### Why

`ime_ajax_regeneration_get_images()` currently returns every attachment ID to the
browser in one response (50,000 images is roughly 350 kB of JSON) and the queue
lives in a JavaScript global. A page reload loses everything; a single failure
produces an `alert()` and a silent stop.

### Storage

Option `ime_regen_queue`, `autoload = false`:

```php
[
    'id'      => 'run identifier',
    'sizes'   => [ 'thumbnail', 'medium' ],
    'force'   => false,
    'offset'  => 1240,
    'total'   => 2000,
    'failed'  => [ [ 'id' => 221, 'err' => 'memory' ], ... ],
    'batch'   => 5,
    'started' => 1755400000,
]
```

An option rather than a transient: an object cache running
`maxmemory-policy allkeys-lru` can evict a transient mid-run, losing the user's
position. The cost is that expiry is our responsibility — the batch endpoint
discards any queue whose `started` is more than 12 hours old.

`ime_uninstall()` gains `delete_option( 'ime_regen_queue' )`, per the CLAUDE.md rule
that new persistent storage is wired into uninstall.

### Endpoints

| Action | Behaviour |
|---|---|
| `ime_regen_start` | Counts the total with `COUNT(*)` (not an ID list), writes the queue option, returns `{ run_id, total }` |
| `ime_regen_batch` | Selects the next N IDs with `LIMIT N OFFSET offset`, processes them, updates `offset` / `failed` / `batch`, returns `{ done, total, failed_count, batch, finished }` |
| `ime_regen_cancel` | Deletes the queue option |

On page load the Alpine component asks for the queue state. If a run is in
progress it renders "Regeneration in progress, 62 % — [Resume] [Cancel]". This
resumability is the point of moving the queue server-side.

### Adaptive batch size

Batch size is not fixed. The server starts at 5 and measures its own wall-clock per
batch: under ~5 s it increases the next batch (ceiling 25), over ~15 s it decreases
it (floor 1). The chosen size is returned in the response and the client simply
follows. A fixed batch of 20 times out on shared hosting, where a single image can
take anywhere from 0.2 s to 30 s.

### Ordering

`ORDER BY ID ASC` with `LIMIT` / `OFFSET`. Stable ordering is required for
correctness — without it `OFFSET` skips rows. Images uploaded *during* a run sort
to the end and may be missed. This is accepted and not surfaced in the UI.

### Failure list

Capped at 100 entries plus a total counter. Uncapped, a broken library could grow
the option to megabytes inside `wp_options`.

### Security

All three endpoints require `current_user_can( 'manage_options' )` and
`wp_verify_nonce( $_REQUEST['ime_nonce'], 'ime-admin-nonce' )`, matching the
existing handlers. Responses go through `wp_send_json_success()` /
`wp_send_json_error()` rather than `wp_die()` with a raw string — the current
protocol is why the client has to `parseInt()` the response and `alert()` whatever
it cannot parse.

`ime_ajax_process_image` remains, because the media page's single-image button uses
it. It changes only to emit JSON.

## JavaScript architecture

```
Alpine.data( 'imeSettings',   ... )   tabs, enabled, engine selection, path test
Alpine.data( 'imeRegen',      ... )   queue polling, batch loop, cancel, resume
Alpine.data( 'imeMediaRegen', ... )   single image on the media page
```

A shared `imeRequest( action, data )` helper wraps `fetch`, the nonce, and error
handling, replacing every `jQuery.get` / `jQuery.post`.

The media page's regenerate button (`ime_filter_media_meta`) is rewritten as an
Alpine component. Its `wpspin_light.gif` spinner is replaced by WordPress's
`.spinner` class.

### Bug fixed along the way

`imagemagick-engine.php:1205` filters `handle_sizes` with `if ( ! $h ) continue;`,
but the stored values are the strings `'skip'`, `'quality'`, and `'size'`. `'skip'`
is truthy, so the media page's regenerate button submits sizes the user explicitly
opted out of. The correct test is `if ( ! $h || $h === 'skip' ) continue;`.

## CSS

`css/ime-admin.css` is rewritten against WordPress custom properties only:
`--wp-admin-theme-color` and friends. No hardcoded colours. The vendor-prefixed
`-moz-` / `-khtml-` border-radius declarations and every `#ime-regenbar.ui-*` rule
go away with jQuery UI.

Verified against all eight admin colour schemes and dark mode.

## Compatibility and housekeeping

- `readme.txt`: `Requires at least: 5.3` → `6.4`.
- `IME_VERSION` and `readme.txt` `Stable tag` bump together to **2.0.0** — breaking
  requirement change plus a full UI rebuild.
- Work continues on `develop`. 1.9.0 is committed but not yet tagged, pending the
  WordPress 7.1 final release; it is tagged from commit `d61c3a0`, whose tree still
  carries `Stable tag: 1.9.0`. The release workflow deploys the tagged tree, so
  landing 2.0.0 work on `develop` first does not block that tag. Do not tag 1.9.0
  from `develop`'s HEAD once the version bump has landed.
- Stored options are unchanged in shape. `IME_OPTION_VERSION` does not need a bump
  and no migration block is added.
- `wp_localize_script`'s `ime_admin` object gains the new strings. `alert`-era
  strings (`noimg`, `failed`) are re-used or replaced as the new UI requires.
- `languages/imagemagick-engine.pot` is regenerated, existing `.po` files updated,
  `.mo` recompiled — per CLAUDE.md.
- `wp_admin_notice()` (6.4+) is now safe to use for notices.

## Testing

No PHP test suite exists in the repository and none is added here. Verification is
manual, in wp-env, which installs both `imagemagick` and `graphicsmagick` in the
container so all four engine modes are exercisable.

1. Each of the four engines: select, save, upload an image, confirm the sub-sizes
   carry `metadata['image-converter'][$size] === 'IME'`.
2. An engine that is unavailable renders disabled and cannot be submitted.
3. Path test for both CLI engines: valid path, invalid path, and an
   `open_basedir`-restricted path.
4. Quality fields: empty (auto), 0, 100, and out-of-range input.
5. Regeneration: start, watch progress, cancel mid-run, reload the page mid-run and
   resume, and let a run complete.
6. Regeneration with a deliberately broken image to confirm the failure list and
   the cap.
7. Media page single-image regenerate, with and without `force`.
8. Keyboard-only traversal of the whole page, and a screen-reader pass over the
   engine cards and progress region.
9. All eight admin colour schemes plus dark mode.
10. Exif orientation behaviour is unchanged — spot-check a rotated JPEG through one
    PHP-module engine and one CLI engine, since that logic is order-sensitive.

## Out of scope

- Replacing the hand-rolled settings POST with the Settings API.
- A Status/diagnostics tab.
- Any change to resize logic, engine dispatch, or Exif orientation handling.
- Refactoring `ime_filter_attachment_metadata` and the `$ime_image_sizes` /
  `$ime_image_file` globals. CLAUDE.md documents these as load-bearing across three
  filters; they stay.

## Risks

- Splitting into `includes/` changes packaging. If `.distignore` or the deploy
  action drops the directory, the plugin fatals on activation for every user. Check
  the built payload before tagging.
- The CSP Alpine build is a different distribution from the one currently bundled.
  Any expression accidentally left in an attribute fails silently rather than
  erroring loudly.
- Raising the WordPress minimum to 6.4 strands installations on older versions at
  1.9.0. Intentional, but it is a one-way door for those users.
- Adaptive batch sizing is timing-dependent and therefore hard to test
  deterministically. The floor of 1 is the safety valve.
