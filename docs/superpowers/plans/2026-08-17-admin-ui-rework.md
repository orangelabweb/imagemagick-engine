# Admin UI/UX Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the ImageMagick Engine admin page as a native-looking, two-tab WordPress settings screen driven entirely by Alpine.js, with a server-side batched and resumable image regeneration queue.

**Architecture:** The single 1,623-line plugin file is split so admin rendering and AJAX handlers live in `includes/`. jQuery and jQuery UI are removed and every interactive behaviour moves to Alpine components using the CSP-safe build. Image regeneration stops shipping the whole attachment ID list to the browser and instead keeps its position in a non-autoloaded option, processed in adaptively sized batches.

**Tech Stack:** PHP 7.4+, WordPress 6.4+, Alpine.js 3.x CSP build (vendored, no build step), native WordPress admin CSS.

**Spec:** `docs/superpowers/specs/2026-08-17-admin-ui-rework-design.md`

## Global Constraints

- **No build step.** No bundler, no npm dependency at runtime. Vendored libraries are committed as-is.
- **Minimum WordPress: 6.4.** `wp_admin_notice()` is available. Anything newer than 6.4 must be feature-detected.
- **Version: 2.0.0.** `IME_VERSION`, the plugin file header `Version:`, and `readme.txt` `Stable tag` must all read `2.0.0`.
- **Branch: `develop`.** 1.9.0 is tagged from commit `d61c3a0`, not from `develop` HEAD.
- **Text domain: `imagemagick-engine`.** Every user-facing string is translated. New strings mean the POT is regenerated and `.po`/`.mo` updated.
- **Engine dispatch is untouchable.** `ime_im_{mode}_valid()` / `ime_im_{mode}_resize()` are resolved by string concatenation from the stored `mode` option. Do not rename, do not wrap.
- **Shell-out safety is untouchable.** All `proc_open` calls use array argv. Never concatenate user-controlled strings into a single argv element.
- **AJAX authorization.** Every `ime_ajax_*` handler requires `current_user_can( 'manage_options' )` AND `wp_verify_nonce( $_REQUEST['ime_nonce'], 'ime-admin-nonce' )`, checked before anything else.
- **Globals stay global.** `$ime_image_sizes` and `$ime_image_file` carry state across three filters. Do not make them local.
- **Orientation ordering is untouchable.** Exif orientation is applied to pixels before any dimension math. Do not reorder `autoOrient()`, the manual Gmagick rotate/flip/flop block, or the position of `-auto-orient` in CLI argv.
- **No `alert()`.** The finished code contains zero `alert()` calls.

### Verification model

This repository has no PHP test suite and `.wp-env.json` sets `"testsEnvironment": false`. The spec explicitly does not add one. So the TDD cycle in this plan is: **write the verification procedure, run it and watch it fail, implement, run it and watch it pass.** Verification is `php -l` plus a scripted manual check in wp-env. Every task states the exact commands and the exact expected output.

### Environment setup (needed from Task 3 onward)

`npx wp-env start` exits silently after the mysql container on this machine. Start the containers directly:

```bash
cd ~/.wp-env/a097a3e41fa027825740caecb4c7e12c
docker compose build wordpress cli
docker compose up -d
```

The wordpress container is Debian, the cli container is Alpine. If the engines are missing:

```bash
docker exec -u 0 $(docker ps -q --filter 'name=wordpress' | head -1) \
  bash -c 'apt-get update -qq && apt-get install -y -qq imagemagick graphicsmagick'
docker exec -u 0 $(docker ps -q --filter 'name=cli' | head -1) \
  apk add imagemagick graphicsmagick
```

Admin URL: `http://localhost:8888/wp-admin/options-general.php?page=imagemagick-engine`
Login: `admin` / `password`

---

## File Structure

| File | Responsibility |
|---|---|
| `imagemagick-engine.php` | Bootstrap, constants, globals, hook registration, engine dispatch, resize functions, metadata filters. Requires the two `includes/` files. |
| `includes/admin-page.php` | `ime_option_page()` and all markup-rendering helpers. Nothing else. |
| `includes/ajax.php` | Every `ime_ajax_*` handler, the regeneration queue functions, and `ime_process_attachment()`. |
| `js/alpine.csp.min.js` | Vendored `@alpinejs/csp` 3.x. Replaces `js/alpine.min.js`. |
| `js/ime-admin.js` | `imeRequest()` plus three `Alpine.data()` components. Zero jQuery. |
| `css/ime-admin.css` | Rewritten against WordPress custom properties. |
| `.distignore` | Must exclude `docs/`, `.claude`, `.idea`, `.DS_Store`. |

---

### Task 1: Packaging and version bump

Ships first because `.distignore` currently does **not** exclude `docs/`, `.claude`, or `.idea` — the spec and plan documents written in this session would be published to wordpress.org on the next deploy. This must be fixed before anything else lands.

**Files:**
- Modify: `.distignore`
- Modify: `readme.txt:4-6`
- Modify: `imagemagick-engine.php:8` (header `Version:`)
- Modify: `imagemagick-engine.php:39` (`IME_VERSION`)

**Interfaces:**
- Consumes: nothing
- Produces: `IME_VERSION === '2.0.0'`, used as the script/style cache-buster in later tasks.

- [ ] **Step 1: Confirm the packaging leak exists**

```bash
cd /Users/Rickard/Dropbox/Hemsidor/imagemagick-engine
grep -c 'docs' .distignore
```

Expected: `0` — proving `docs/` is not excluded and would ship.

- [ ] **Step 2: Fix `.distignore`**

Replace the whole file with:

```
/.wordpress-org
/.git
/.github
/.idea
/.claude
/docs
/node_modules

.distignore
.gitignore
.gitattributes
package.json
package-lock.json
.wp-env.json
.wp-env.override.json
.DS_Store
CLAUDE.md
```

- [ ] **Step 3: Verify the fix**

```bash
grep -c 'docs' .distignore
```

Expected: `1`

- [ ] **Step 4: Bump the readme header**

In `readme.txt`, change these three lines:

```
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 2.0.0
```

- [ ] **Step 5: Bump the plugin header and constant**

In `imagemagick-engine.php`, line 8 becomes:

```php
	Version: 2.0.0
	Requires at least: 6.4
	Requires PHP: 7.4
```

And line 39:

```php
define( 'IME_VERSION', '2.0.0' );
```

Leave `IME_OPTION_VERSION` at `2` — the spec adds no option-shape migration.

- [ ] **Step 6: Verify versions agree**

```bash
grep -n "Version: 2.0.0" imagemagick-engine.php
grep -n "IME_VERSION, '2.0.0'" imagemagick-engine.php
grep -n "Stable tag: 2.0.0" readme.txt
php -l imagemagick-engine.php
```

Expected: one hit from each grep, and `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add .distignore readme.txt imagemagick-engine.php
git commit -m "Bump to 2.0.0, require WordPress 6.4, exclude docs from the package"
```

---

### Task 2: Split admin and AJAX code into includes/

A pure move. No behaviour changes, no renames. Doing this separately means the next tasks' diffs show real changes instead of thousands of moved lines.

**Files:**
- Create: `includes/admin-page.php`
- Create: `includes/ajax.php`
- Modify: `imagemagick-engine.php` (remove moved functions, add requires)

**Interfaces:**
- Consumes: `IME_VERSION` from Task 1.
- Produces: two include files loaded unconditionally at the top of the main plugin file, before any `add_action()` call that references their functions.

- [ ] **Step 1: Record the current behaviour as a baseline**

With wp-env running, load the settings page and save the following to compare against after the move:

```bash
curl -s -o /tmp/ime-before.html -b /tmp/ime-cookies \
  "http://localhost:8888/wp-admin/options-general.php?page=imagemagick-engine"
wc -c /tmp/ime-before.html
```

If you have no session cookie jar yet, log in through the browser and export it, or simply screenshot the page. The point is a before/after comparison.

- [ ] **Step 2: Create `includes/admin-page.php`**

Move these functions verbatim out of `imagemagick-engine.php`, in this order:

- `ime_admin_menu()`
- `ime_admin_print_scripts()`
- `ime_admin_print_styles()`
- `ime_filter_plugin_actions()`
- `ime_filter_media_meta()`
- `ime_option_admin_images_url()`
- `ime_option_status_icon()`
- `ime_get_available_modes()`
- `ime_option_display()`
- `ime_option_page()`

File header:

```php
<?php
/**
 * Admin page rendering.
 *
 * @package imagemagick-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}
```

- [ ] **Step 3: Create `includes/ajax.php`**

Move these functions verbatim:

- `ime_ajax_test_im_path()`
- `ime_ajax_regeneration_get_images()`
- `ime_ajax_process_image()`

Same file header as Step 2, with the docblock reading `AJAX handlers.`

- [ ] **Step 4: Wire the requires**

In `imagemagick-engine.php`, immediately after the closing `}` of the constants block (after line 39's `define`), add:

```php
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/ajax.php';
```

- [ ] **Step 5: Verify**

```bash
php -l imagemagick-engine.php
php -l includes/admin-page.php
php -l includes/ajax.php
grep -c 'function ime_option_page' imagemagick-engine.php
```

Expected: three `No syntax errors detected`, then `0` — proving the function moved rather than being duplicated.

Then reload the settings page in the browser. It must render identically to the baseline: same fields, same values, no PHP notices. Save settings once and confirm "Settings updated" still appears. Click "Test path" and confirm the icon still toggles.

- [ ] **Step 6: Commit**

```bash
git add imagemagick-engine.php includes/
git commit -m "Move admin page and AJAX handlers into includes/"
```

---

### Task 3: Vendor the Alpine CSP build and add the page shell with tabs

At the end of this task the page has an `<h1>`, a tab bar, and both tabs render — but the tab contents are still the old markup, moved wholesale. jQuery is still enqueued. This keeps the page working while the foundation lands.

**Files:**
- Create: `js/alpine.csp.min.js`
- Delete: `js/alpine.min.js`
- Modify: `imagemagick-engine.php:113-114` (script registration)
- Modify: `includes/admin-page.php` (`ime_admin_menu()`, `ime_admin_print_scripts()`, `ime_option_page()`)
- Modify: `js/ime-admin.js`

**Interfaces:**
- Consumes: `IME_VERSION`.
- Produces:
  - JS global `imeRequest( action, data )` → `Promise<object>`, resolving with the `data` payload of `wp_send_json_success()`, rejecting with an `Error` whose `message` is the server's error message.
  - `Alpine.data( 'imeSettings' )` exposing `tab` (string), `isTabSettings` (getter, boolean), `isTabRegenerate` (getter, boolean), `selectTab( name )`.
  - Localized object `ime_admin` gains `ajaxurl` (string) and keeps `ime_nonce`.

- [ ] **Step 1: Write the verification procedure and watch it fail**

Open the settings page. The checks that must eventually pass:

1. Page shows `<h1>ImageMagick Engine</h1>`, not `<h2>ImageMagick Engine Settings</h2>`.
2. A `nav-tab-wrapper` with two tabs is present.
3. Clicking "Regenerate" hides the settings fields and shows the regenerate panel, with no page reload.
4. The URL becomes `...&tab=regenerate`.
5. Reloading that URL lands on the Regenerate tab.
6. Browser console shows zero errors.

Run through them now. Expected: all six fail — there is no tab bar.

- [ ] **Step 2: Vendor the CSP build**

Download `@alpinejs/csp` 3.x minified, place it at `js/alpine.csp.min.js`, and remove the old file:

```bash
curl -sL "https://cdn.jsdelivr.net/npm/@alpinejs/csp@3.15.9/dist/cdn.min.js" \
  -o js/alpine.csp.min.js
git rm js/alpine.min.js
head -c 200 js/alpine.csp.min.js
```

Expected: a minified bundle, non-empty. Confirm it is the CSP build and not the standard one — the CSP build's banner/contents differ; a quick check is that evaluating an expression in an attribute will throw at runtime, which Step 6 exercises.

- [ ] **Step 3: Re-register the scripts**

In `imagemagick-engine.php`, replace lines 113-114 with:

```php
        wp_register_script( 'alpinejs', plugins_url( '/js/alpine.csp.min.js', __FILE__ ), [], '3.15.9', true );
        wp_register_script( 'ime-admin', plugins_url( '/js/ime-admin.js', __FILE__ ), [], constant( 'IME_VERSION' ), true );
```

Note both changes: the CSP filename, and `[]` replacing `[ 'jquery', 'jquery-ui-progressbar' ]`.

Alpine must initialise *after* `ime-admin.js` has registered its components, so in `includes/admin-page.php`'s `ime_admin_menu()`, keep the existing separate enqueue but make the ordering explicit:

```php
    add_action( 'admin_print_scripts-' . $ime_page, function() {
        wp_enqueue_script( 'ime-admin' );
        wp_enqueue_script( 'alpinejs' );
    } );
```

- [ ] **Step 4: Add `ajaxurl` to the localized data**

In `ime_admin_print_scripts()`, add to the `$data` array:

```php
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
```

`ajaxurl` is a global on admin pages, but `ime-admin.js` no longer depends on any WordPress script that guarantees it, and the media page needs it too.

- [ ] **Step 5: Add the request helper and the settings component**

At the top of `js/ime-admin.js`, above everything else:

```js
/**
 * POST to admin-ajax.php with the plugin nonce.
 *
 * Resolves with the `data` payload of wp_send_json_success().
 * Rejects with an Error carrying the server's message.
 */
function imeRequest( action, data ) {
	var body = new URLSearchParams();
	body.append( 'action', action );
	body.append( 'ime_nonce', ime_admin.ime_nonce );

	Object.keys( data || {} ).forEach( function( key ) {
		body.append( key, data[ key ] );
	} );

	return window.fetch( ime_admin.ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: body.toString()
	} ).then( function( response ) {
		if ( ! response.ok ) {
			throw new Error( ime_admin.request_failed );
		}
		return response.json();
	} ).then( function( json ) {
		if ( ! json || ! json.success ) {
			throw new Error(
				( json && json.data && json.data.message ) || ime_admin.request_failed
			);
		}
		return json.data;
	} );
}

document.addEventListener( 'alpine:init', function() {
	Alpine.data( 'imeSettings', function() {
		return {
			tab: ime_admin.initial_tab,

			get isTabSettings() {
				return this.tab === 'settings';
			},

			get isTabRegenerate() {
				return this.tab === 'regenerate';
			},

			selectTab: function( name ) {
				this.tab = name;

				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', name );
				window.history.replaceState( {}, '', url.toString() );
			},

			selectSettingsTab: function() {
				this.selectTab( 'settings' );
			},

			selectRegenerateTab: function() {
				this.selectTab( 'regenerate' );
			}
		};
	} );
} );
```

`selectSettingsTab` and `selectRegenerateTab` exist because the CSP build cannot evaluate `selectTab('settings')` inside an attribute — only bare method references are allowed.

- [ ] **Step 6: Add the page shell**

In `includes/admin-page.php`, in `ime_option_page()`, compute the initial tab before the markup:

```php
    $initial_tab = 'settings';
    if ( isset( $_GET['tab'] ) && 'regenerate' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
        $initial_tab = 'regenerate';
    }
```

Pass it to JS by adding to `$data` in `ime_admin_print_scripts()`:

```php
        'initial_tab'    => ( isset( $_GET['tab'] ) && 'regenerate' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'regenerate' : 'settings',
        'request_failed' => __( 'The request failed. Please try again.', 'imagemagick-engine' ),
```

Replace the opening of the `.wrap` markup. The old `<h2>`, `#poststuff`, `.inner-sidebar` and `#post-body` wrappers all go away:

```php
    ?>
    <div class="wrap" x-data="imeSettings">
        <h1><?php esc_html_e( 'ImageMagick Engine', 'imagemagick-engine' ); ?></h1>

        <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'imagemagick-engine' ); ?>">
            <button type="button" class="nav-tab" :class="settingsTabClass" x-on:click="selectSettingsTab">
                <?php esc_html_e( 'Settings', 'imagemagick-engine' ); ?>
            </button>
            <button type="button" class="nav-tab" :class="regenerateTabClass" x-on:click="selectRegenerateTab">
                <?php esc_html_e( 'Regenerate', 'imagemagick-engine' ); ?>
            </button>
        </nav>

        <div x-show="isTabSettings" x-cloak>
            <!-- existing settings form moves here unchanged -->
        </div>

        <div x-show="isTabRegenerate" x-cloak>
            <!-- existing regenerate metabox contents move here unchanged -->
        </div>
    </div>
    <?php
```

Add the two class getters to `imeSettings` in `js/ime-admin.js`:

```js
			get settingsTabClass() {
				return this.tab === 'settings' ? 'nav-tab-active' : '';
			},

			get regenerateTabClass() {
				return this.tab === 'regenerate' ? 'nav-tab-active' : '';
			},
```

Move the existing `<form>` (with its nonce field, all `form-table` rows, and the submit button) inside the first tab div, and the existing `#regenerate-images-metabox` contents inside the second. Delete the `x-data="ime"` attribute from the `<form>` — `imeSettings` on the `.wrap` div now covers it — and delete the inline `<script>` block at the end of `ime_option_page()` that defined the old `Alpine.data( 'ime', ... )`.

That old component held `enabled` and `mode`, and the surviving markup still binds them, so `imeSettings` must take over both **now**. The CSP build forces this: the existing rows use `x-show="mode === 'php'"`, an inline expression the CSP build rejects outright, so leaving them as-is breaks the page. Add to the component:

```js
			enabled: ime_admin.enabled,
			mode: ime_admin.mode,

			get isPhp() { return this.mode === 'php'; },
			get isGmagick() { return this.mode === 'gmagick'; },
			get isCli() { return this.mode === 'cli'; },
			get isGraphicsmagick() { return this.mode === 'graphicsmagick'; },
```

and to `$data` in `ime_admin_print_scripts()`:

```php
        'enabled' => (bool) ( ime_get_option( 'enabled' ) && ime_mode_valid() ),
        'mode'    => (string) ime_get_option( 'mode' ),
```

Then rewrite the four surviving engine rows' attributes to use the bare getters — `x-show="isPhp"`, `x-show="isGmagick"`, `x-show="isCli"`, `x-show="isGraphicsmagick"` — and leave `x-show="enabled"` on the `<tbody>` as it is, since that is already a bare property reference. Task 4 deletes this markup entirely; these four edits keep the page working for one commit.

- [ ] **Step 7: Run the verification from Step 1**

All six checks must now pass. Pay particular attention to check 6: if the console shows a CSP-build error about an unsupported expression, an attribute still contains an inline expression — find it and move it to a getter.

- [ ] **Step 8: Commit**

```bash
git add js/ imagemagick-engine.php includes/admin-page.php
git commit -m "Replace Alpine with the CSP build and add a tabbed page shell"
```

---

### Task 4: Engine cards, dashicons, and JSON path testing

**Files:**
- Modify: `includes/admin-page.php` (`ime_option_page()`, delete three helpers)
- Modify: `includes/ajax.php` (`ime_ajax_test_im_path()`)
- Modify: `js/ime-admin.js`
- Modify: `css/ime-admin.css`

**Interfaces:**
- Consumes: `imeRequest()`, `Alpine.data( 'imeSettings' )` from Task 3.
- Produces:
  - PHP: `ime_render_engine_card( string $mode, string $label, bool $valid, string $detail, string $current_mode, string $path_field_html = '' ): void`
  - PHP: `ime_render_path_field( string $prefix, string $path ): void` where `$prefix` is `'cli'` or `'gm'`
  - PHP: `ime_ajax_test_im_path()` responds with `wp_send_json_success( [ 'found' => bool, 'version' => string, 'engine' => string ] )` or `wp_send_json_error( [ 'message' => string, 'open_basedir' => bool ] )`.
  - JS: `imeSettings` gains `enabled` (bool), `mode` (string), `isPhp` / `isGmagick` / `isCli` / `isGraphicsmagick` (getters), `cliPathState` / `gmPathState` (`'unknown' | 'testing' | 'ok' | 'error'`), `cliPathMessage` / `gmPathMessage` (string), `testCliPath()`, `testGmPath()`.
- Removed: `ime_option_status_icon()`, `ime_option_display()`, `ime_option_admin_images_url()`.

- [ ] **Step 1: Write the verification procedure and watch it fail**

1. All four engines render as cards, visible simultaneously.
2. Each card shows a green `dashicons-yes-alt` or a red `dashicons-dismiss`.
3. Available engines' cards show a version string or resolved binary path.
4. Unavailable engines' radios are `disabled` and cannot be selected by keyboard or mouse.
5. Selecting the ImageMagick CLI card reveals its path input inside that card; selecting another card hides it.
6. Clicking "Test path" with a bad path shows a red inline notice with the error text; with a good path, a green one.
7. `grep -rn 'yes.png\|no.png\|wpspin_light' includes/ imagemagick-engine.php` in the settings-page code returns nothing (the media page still has one; Task 8 removes it).
8. Saving settings still persists the chosen engine.

Run them. Expected: 1-7 fail, 8 passes.

- [ ] **Step 2: Convert the path-test endpoint to JSON**

In `includes/ajax.php`, rewrite the response portion of `ime_ajax_test_im_path()`. Keep the existing capability and nonce checks and the existing path-probing logic exactly as they are; only the output changes:

```php
    if ( $found ) {
        wp_send_json_success(
            [
                'found'   => true,
                'engine'  => $engine,
                'version' => $version,
            ]
        );
    }

    wp_send_json_error(
        [
            'found'        => false,
            'engine'       => $engine,
            'open_basedir' => $open_basedir,
            'message'      => $open_basedir
                /* translators: %s: engine name, e.g. ImageMagick */
                ? sprintf( __( '%s not found. Your PHP open_basedir setting is restricting access to this path. Add the path to your open_basedir configuration.', 'imagemagick-engine' ), $engine )
                /* translators: %s: engine name, e.g. ImageMagick */
                : sprintf( __( '%s not found at this path.', 'imagemagick-engine' ), $engine ),
        ]
    );
```

Because the message is now built server-side, delete `path_not_found` and `path_open_basedir` from the `$data` array in `ime_admin_print_scripts()`.

- [ ] **Step 3: Add the card renderer**

In `includes/admin-page.php`:

```php
/**
 * Render one engine choice as a selectable card.
 *
 * @param string $mode            Engine key, e.g. 'php'.
 * @param string $label           Human-readable engine name.
 * @param bool   $valid           Whether the engine is usable on this server.
 * @param string $detail          Version string or reason it is unavailable.
 * @param string $current_mode    Currently selected engine key.
 * @param string $path_field_html Optional markup rendered inside the card when selected.
 */
function ime_render_engine_card( $mode, $label, $valid, $detail, $current_mode, $path_field_html = '' ) {
    $id     = 'ime-engine-' . $mode;
    $desc   = $id . '-status';
    $icon   = $valid ? 'dashicons-yes-alt' : 'dashicons-dismiss';
    $state  = $valid
        ? __( 'Available', 'imagemagick-engine' )
        : __( 'Not available', 'imagemagick-engine' );
    $classes = 'ime-engine-card' . ( $valid ? '' : ' ime-engine-card--unavailable' );
    ?>
    <div class="<?php echo esc_attr( $classes ); ?>">
        <label for="<?php echo esc_attr( $id ); ?>">
            <input type="radio" name="mode" id="<?php echo esc_attr( $id ); ?>"
                value="<?php echo esc_attr( $mode ); ?>"
                x-model="mode"
                aria-describedby="<?php echo esc_attr( $desc ); ?>"
                <?php checked( $mode, $current_mode ); ?>
                <?php disabled( ! $valid ); ?> />
            <span class="ime-engine-card__label"><?php echo esc_html( $label ); ?></span>
        </label>
        <p class="ime-engine-card__status" id="<?php echo esc_attr( $desc ); ?>">
            <span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php echo esc_html( $state ); ?></span>
            <?php echo esc_html( $detail ); ?>
        </p>
        <?php
        if ( '' !== $path_field_html ) {
            echo $path_field_html; // Already escaped by the caller.
        }
        ?>
    </div>
    <?php
}
```

- [ ] **Step 4: Replace the select and the four `form-table` engine rows**

Delete the `<tr>` containing `<select id="ime-select-mode">` and the four `<tr id="ime-row-*">` rows. In their place:

```php
    <fieldset class="ime-engine-grid" x-show="enabled" x-cloak>
        <legend class="screen-reader-text"><?php esc_html_e( 'Image engine', 'imagemagick-engine' ); ?></legend>
        <?php
        ime_render_engine_card(
            'php',
            __( 'Imagick PHP module', 'imagemagick-engine' ),
            $modes_valid['php'],
            $modes_valid['php']
                ? __( 'Module loaded', 'imagemagick-engine' )
                : __( 'Module not found', 'imagemagick-engine' ),
            $current_mode
        );

        ime_render_engine_card(
            'gmagick',
            __( 'Gmagick PHP module', 'imagemagick-engine' ),
            $modes_valid['gmagick'],
            $modes_valid['gmagick']
                ? __( 'Module loaded', 'imagemagick-engine' )
                : __( 'Module not found', 'imagemagick-engine' ),
            $current_mode
        );

        ob_start();
        ime_render_path_field( 'cli', $cli_path );
        $cli_field = ob_get_clean();

        ime_render_engine_card(
            'cli',
            __( 'ImageMagick command-line', 'imagemagick-engine' ),
            $modes_valid['cli'],
            $cli_path_ok
                ? ime_get_option( 'imagemagick_version' )
                : __( 'Command not found', 'imagemagick-engine' ),
            $current_mode,
            $cli_field
        );

        ob_start();
        ime_render_path_field( 'gm', $gm_path );
        $gm_field = ob_get_clean();

        ime_render_engine_card(
            'graphicsmagick',
            __( 'GraphicsMagick command-line', 'imagemagick-engine' ),
            $modes_valid['graphicsmagick'],
            $gm_path_ok
                ? ime_get_option( 'graphicsmagick_version' )
                : __( 'Command not found', 'imagemagick-engine' ),
            $current_mode,
            $gm_field
        );
        ?>
    </fieldset>
```

- [ ] **Step 5: Add the path field renderer**

```php
/**
 * Render the binary path input for a command-line engine.
 *
 * The pass/fail indicator lives on the card's status line, so this renders
 * only the input, its test button, and the test result.
 *
 * @param string $prefix 'cli' or 'gm'.
 * @param string $path   Current stored path.
 */
function ime_render_path_field( $prefix, $path ) {
    $field    = 'cli' === $prefix ? 'cli_path' : 'gm_path';
    $show     = 'cli' === $prefix ? 'isCli' : 'isGraphicsmagick';
    $state    = 'cli' === $prefix ? 'cliPathState' : 'gmPathState';
    $message  = 'cli' === $prefix ? 'cliPathMessage' : 'gmPathMessage';
    $test     = 'cli' === $prefix ? 'testCliPath' : 'testGmPath';
    $describe = $prefix . '-path-help';
    ?>
    <div class="ime-engine-card__path" x-show="<?php echo esc_attr( $show ); ?>" x-cloak>
        <label class="screen-reader-text" for="<?php echo esc_attr( $field ); ?>">
            <?php esc_html_e( 'Path to the binary', 'imagemagick-engine' ); ?>
        </label>
        <input type="text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
            class="regular-text code" value="<?php echo esc_attr( $path ); ?>"
            aria-describedby="<?php echo esc_attr( $describe ); ?>" />
        <button type="button" class="button button-secondary" x-on:click="<?php echo esc_attr( $test ); ?>">
            <?php esc_html_e( 'Test path', 'imagemagick-engine' ); ?>
        </button>
        <span class="spinner" x-show="<?php echo esc_attr( $state ); ?> === 'testing'"></span>
        <p class="notice notice-error inline" x-show="<?php echo esc_attr( $state ); ?> === 'error'" x-cloak
            x-text="<?php echo esc_attr( $message ); ?>"></p>
        <p class="notice notice-success inline" x-show="<?php echo esc_attr( $state ); ?> === 'ok'" x-cloak
            x-text="<?php echo esc_attr( $message ); ?>"></p>
        <p class="description" id="<?php echo esc_attr( $describe ); ?>">
            <?php esc_html_e( 'Enter the path where the binary is installed on your server. This is usually /usr/bin or /usr/local/bin.', 'imagemagick-engine' ); ?>
        </p>
    </div>
    <?php
}
```

**CSP warning:** `x-show="cliPathState === 'testing'"` is an expression and the CSP build will reject it. Replace each of those three `x-show` values with dedicated getters — `cliPathTesting`, `cliPathError`, `cliPathOk`, and the `gm` equivalents — and use the bare getter name in the attribute. Twelve getters total. Write them in Step 6.

- [ ] **Step 6: Extend the Alpine component**

`enabled`, `mode`, and the four `is*` engine getters were already added to `imeSettings` in Task 3. Add the path-testing state alongside them:

```js
			cliPathState: 'unknown',
			cliPathMessage: '',
			gmPathState: 'unknown',
			gmPathMessage: '',

			get cliPathTesting() { return this.cliPathState === 'testing'; },
			get cliPathError() { return this.cliPathState === 'error'; },
			get cliPathOk() { return this.cliPathState === 'ok'; },
			get gmPathTesting() { return this.gmPathState === 'testing'; },
			get gmPathError() { return this.gmPathState === 'error'; },
			get gmPathOk() { return this.gmPathState === 'ok'; },

			testCliPath: function() {
				this.testPath( 'cli', 'cli_path', 'cliPath' );
			},

			testGmPath: function() {
				this.testPath( 'graphicsmagick', 'gm_path', 'gmPath' );
			},

			testPath: function( engineMode, field, prefix ) {
				var self = this;
				var payload = { mode: engineMode };
				payload[ field ] = document.getElementById( field ).value;

				self[ prefix + 'State' ] = 'testing';
				self[ prefix + 'Message' ] = '';

				imeRequest( 'ime_test_im_path', payload ).then( function( data ) {
					self[ prefix + 'State' ] = 'ok';
					self[ prefix + 'Message' ] = data.version
						? data.engine + ' ' + data.version
						: ime_admin.path_found;
				} ).catch( function( error ) {
					self[ prefix + 'State' ] = 'error';
					self[ prefix + 'Message' ] = error.message;
				} );
			},
```

Add one more string to `$data` in `ime_admin_print_scripts()`:

```php
        'path_found' => __( 'Command found.', 'imagemagick-engine' ),
```

The Enable checkbox keeps its `x-model="enabled"` binding from Task 3.

- [ ] **Step 7: Delete the dead helpers**

```bash
grep -rn 'ime_option_status_icon\|ime_option_display\|ime_option_admin_images_url' includes/ imagemagick-engine.php
```

Delete `ime_option_status_icon()` and `ime_option_display()`. Both must have zero remaining callers first — if the grep shows any, the markup replacement in Step 4 was incomplete.

`ime_option_admin_images_url()` stays for now: `ime_filter_media_meta()` still uses it for the `wpspin_light.gif` spinner. Task 8 removes that caller and deletes the function. Leave it untouched and unmarked.

- [ ] **Step 8: Add the card CSS**

Append to `css/ime-admin.css`:

```css
.ime-engine-grid {
	display: grid;
	grid-template-columns: repeat( auto-fit, minmax( 320px, 1fr ) );
	gap: 12px;
	margin: 16px 0 24px;
	border: 0;
	padding: 0;
}

.ime-engine-card {
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 12px 16px;
	background: #fff;
}

.ime-engine-card--unavailable {
	opacity: 0.6;
}

.ime-engine-card__label {
	font-weight: 600;
}

.ime-engine-card__status {
	margin: 6px 0 0;
	color: #50575e;
}

.ime-engine-card__status .dashicons-yes-alt {
	color: #00a32a;
}

.ime-engine-card__status .dashicons-dismiss {
	color: #d63638;
}

.ime-engine-card__path {
	margin-top: 12px;
}
```

Task 9 replaces the hardcoded colours with WordPress custom properties; this is deliberately the plain version so the layout can be verified first.

- [ ] **Step 9: Run the verification from Step 1**

Checks 1-8 must all pass. Additionally run:

```bash
php -l includes/admin-page.php && php -l includes/ajax.php
```

Expected: `No syntax errors detected` twice.

- [ ] **Step 10: Commit**

```bash
git add includes/ js/ime-admin.js css/ime-admin.css
git commit -m "Replace the engine dropdown with status cards and JSON path testing"
```

---

### Task 5: Quality inputs and the image size table

**Files:**
- Modify: `includes/admin-page.php` (`ime_option_page()`)
- Modify: `js/ime-admin.js`
- Modify: `css/ime-admin.css`

**Interfaces:**
- Consumes: `imeSettings` from Task 4.
- Produces: `imeSettings` gains `setAllQuality()`, `setAllSize()`, `setAllSkip()`. Field names are unchanged: `quality-quality`, `quality-size`, `handle-mode-{$size}`.

- [ ] **Step 1: Write the verification procedure and watch it fail**

1. Both quality fields are `type="number"` with `min="0" max="100"` and `placeholder` reading `auto`.
2. Leaving a quality field empty and saving stores `-1`, and the field re-renders empty.
3. Entering `150` and saving stores `100`; entering `-5` stores `0`.
4. Help text under the quality fields states the value used when the field is empty.
5. The size table renders as a striped `wp-list-table` with a header row.
6. Each column header has a "Select all" control that sets every row in that column.
7. Saving after using "Select all" under *None* results in every size stored as `skip`.
8. Each row's radios are inside a `<fieldset>` with a screen-reader legend naming the size.
9. Saving settings renders a dismissible success notice; the "no valid mode" and "not enabled" warnings render as standard admin notices. `grep -n 'class="updated fade"\|id="warning"' includes/admin-page.php` returns nothing.

Run them. Expected: all fail except 2 and 3, which already work server-side.

- [ ] **Step 2: Replace the quality row**

The clamping in `ime_option_page()`'s POST handler already does the right thing (`min( 100, max( 0, intval(...) ) )` and `-1` for empty) — do not change it. Replace only the markup:

```php
    <tr>
        <th scope="row"><label for="quality-quality"><?php esc_html_e( 'Optimize for quality', 'imagemagick-engine' ); ?></label></th>
        <td>
            <input type="number" id="quality-quality" name="quality-quality" min="0" max="100" step="1"
                class="small-text" placeholder="<?php esc_attr_e( 'auto', 'imagemagick-engine' ); ?>"
                value="<?php echo esc_attr( ( isset( $quality['quality'] ) && $quality['quality'] > 0 ) ? $quality['quality'] : '' ); ?>"
                aria-describedby="ime-quality-help" />
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="quality-size"><?php esc_html_e( 'Optimize for size', 'imagemagick-engine' ); ?></label></th>
        <td>
            <input type="number" id="quality-size" name="quality-size" min="0" max="100" step="1"
                class="small-text" placeholder="<?php esc_attr_e( 'auto', 'imagemagick-engine' ); ?>"
                value="<?php echo esc_attr( ( isset( $quality['size'] ) && $quality['size'] > 0 ) ? $quality['size'] : '' ); ?>"
                aria-describedby="ime-quality-help" />
            <p class="description" id="ime-quality-help">
                <?php
                printf(
                    /* translators: 1: computed quality value, 2: computed size value */
                    esc_html__( 'Set to 0-100. A higher value means better image quality and a larger file. Leave empty to compute the value dynamically, which currently gives %1$d when optimizing for quality and %2$d when optimizing for size.', 'imagemagick-engine' ),
                    absint( ime_get_quality( 'quality' ) ),
                    absint( ime_get_quality( 'size' ) )
                );
                ?>
            </p>
        </td>
    </tr>
```

`ime_get_quality()` already returns the effective value including the dynamic computation, so check 4 needs no new logic.

- [ ] **Step 3: Replace the size table**

Delete the `<tr>` wrapping `#ime-handle-table` and its nested table. Place this **after** the closing `</table>` of the `form-table`, still inside the form:

```php
    <h2><?php esc_html_e( 'Image sizes', 'imagemagick-engine' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Choose how each image size is generated. Sizes set to None are left to WordPress.', 'imagemagick-engine' ); ?></p>

    <table class="wp-list-table widefat striped ime-sizes-table">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'Image size', 'imagemagick-engine' ); ?></th>
                <th scope="col">
                    <?php esc_html_e( 'Quality', 'imagemagick-engine' ); ?><br />
                    <button type="button" class="button-link" x-on:click="setAllQuality"><?php esc_html_e( 'Select all', 'imagemagick-engine' ); ?></button>
                </th>
                <th scope="col">
                    <?php esc_html_e( 'Size', 'imagemagick-engine' ); ?><br />
                    <button type="button" class="button-link" x-on:click="setAllSize"><?php esc_html_e( 'Select all', 'imagemagick-engine' ); ?></button>
                </th>
                <th scope="col">
                    <?php esc_html_e( 'None', 'imagemagick-engine' ); ?><br />
                    <button type="button" class="button-link" x-on:click="setAllSkip"><?php esc_html_e( 'Select all', 'imagemagick-engine' ); ?></button>
                </th>
            </tr>
        </thead>
        <tbody>
        <?php
        foreach ( $sizes as $s => $name ) {
            // Fixup for options stored before 1.5.0.
            if ( ! isset( $handle_sizes[ $s ] ) || ! $handle_sizes[ $s ] ) {
                $handle_sizes[ $s ] = 'skip';
            } elseif ( true === $handle_sizes[ $s ] ) {
                $handle_sizes[ $s ] = 'quality';
            }

            $group = 'handle-mode-' . $s;
            ?>
            <tr>
                <th scope="row"><?php echo esc_html( $name ); ?></th>
                <?php foreach ( [ 'quality', 'size', 'skip' ] as $value ) { ?>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <?php
                                printf(
                                    /* translators: %s: image size name */
                                    esc_html__( 'Handling for %s', 'imagemagick-engine' ),
                                    esc_html( $name )
                                );
                                ?>
                            </legend>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( $group ); ?>"
                                    class="ime-handle-mode ime-handle-mode--<?php echo esc_attr( $value ); ?>"
                                    value="<?php echo esc_attr( $value ); ?>"
                                    <?php checked( $value, $handle_sizes[ $s ] ); ?> />
                                <span class="screen-reader-text"><?php echo esc_html( $value ); ?></span>
                            </label>
                        </fieldset>
                    </td>
                <?php } ?>
            </tr>
            <?php
        }
        ?>
        </tbody>
    </table>
```

The `<fieldset>`/`<legend>` repeats per cell, which is verbose but keeps each radio's accessible name complete without relying on table-header association, which screen readers handle inconsistently for form controls.

- [ ] **Step 4: Add the select-all methods**

```js
			setAllQuality: function() { this.setAllHandleModes( 'quality' ); },
			setAllSize: function() { this.setAllHandleModes( 'size' ); },
			setAllSkip: function() { this.setAllHandleModes( 'skip' ); },

			setAllHandleModes: function( value ) {
				var inputs = document.querySelectorAll( '.ime-handle-mode--' + value );
				Array.prototype.forEach.call( inputs, function( input ) {
					input.checked = true;
				} );
			},
```

- [ ] **Step 5: Add the table CSS**

Replace the `#ime-handle-table` rules in `css/ime-admin.css` with:

```css
.ime-sizes-table td,
.ime-sizes-table th {
	vertical-align: middle;
}

.ime-sizes-table fieldset {
	margin: 0;
	padding: 0;
	border: 0;
}
```

Delete the now-unused `.form-table td.ime-handle-table-wrapper`, `#ime-handle-table th`, `#ime-handle-table td`, `#ime-handle-table .ime-headline`, and `#ime-handle-table .ime-fixed-width` rules.

- [ ] **Step 6: Convert the three hand-rolled notices to `wp_admin_notice()`**

WordPress 6.4 is now the floor, so the function is always available. In `ime_option_page()`, replace the settings-saved echo:

```php
        wp_admin_notice(
            __( 'Settings updated', 'imagemagick-engine' ),
            [
                'type'               => 'success',
                'dismissible'        => true,
                'additional_classes' => [ 'is-dismissible' ],
            ]
        );
```

and the two warnings:

```php
    if ( ! $any_valid ) {
        wp_admin_notice(
            __( 'No valid ImageMagick mode was found on this server.', 'imagemagick-engine' ),
            [ 'type' => 'error' ]
        );
    } elseif ( ! $enabled ) {
        wp_admin_notice(
            __( 'ImageMagick Engine is not enabled.', 'imagemagick-engine' ),
            [ 'type' => 'warning' ]
        );
    }
```

`wp_admin_notice()` escapes and wraps the message itself, so drop the surrounding `<div id="message">` / `<div id="warning">` markup and the `fade` class along with it.

- [ ] **Step 7: Run the verification from Step 1**

All nine checks must pass. Then verify the round trip explicitly:

```bash
docker exec $(docker ps -q --filter 'name=cli' | head -1) \
  wp option get ime_options --format=json --allow-root
```

Expected: `handle_sizes` reflects exactly what the form shows, and `quality` holds `-1` for any field left empty.

- [ ] **Step 8: Commit**

```bash
git add includes/admin-page.php js/ime-admin.js css/ime-admin.css
git commit -m "Use number inputs for quality, a list table for image sizes, and core admin notices"
```

---

### Task 6: Regeneration queue backend

No UI in this task. It is verified entirely through wp-cli and direct AJAX calls, which is what makes it independently reviewable.

**Files:**
- Modify: `includes/ajax.php`
- Modify: `imagemagick-engine.php` (constants, `ime_init()` hook registration, `ime_uninstall()`)

**Interfaces:**
- Consumes: `ime_filter_attachment_metadata()`, `ime_mode_valid()`, `$ime_image_sizes`, `$ime_image_file` from the main file.
- Produces:
  - Constants `IME_REGEN_OPTION`, `IME_REGEN_TTL`, `IME_REGEN_BATCH_START`, `IME_REGEN_BATCH_MIN`, `IME_REGEN_BATCH_MAX`, `IME_REGEN_FAILED_CAP`.
  - `ime_regen_queue_get(): ?array` — returns the queue array, or `null` if absent or expired (expired queues are deleted as a side effect).
  - `ime_regen_queue_save( array $queue ): void`
  - `ime_regen_queue_clear(): void`
  - `ime_regen_count_images(): int`
  - `ime_regen_next_ids( int $offset, int $limit ): int[]`
  - `ime_regen_next_batch_size( int $current, float $elapsed ): int`
  - `ime_process_attachment( int $id, string[] $size_names, bool $force ): true|WP_Error`
  - AJAX actions `ime_regen_start`, `ime_regen_batch`, `ime_regen_cancel`, `ime_regen_state`.

- [ ] **Step 1: Write the verification script and watch it fail**

Create `/tmp/ime-queue-check.sh` (not committed):

```bash
#!/bin/bash
# Drives the regeneration queue endpoints directly.
# Usage: ime-queue-check.sh <cookie-file> <nonce>
set -e
COOKIES="$1"
NONCE="$2"
URL="http://localhost:8888/wp-admin/admin-ajax.php"

echo "--- state (expect no queue) ---"
curl -s -b "$COOKIES" -d "action=ime_regen_state&ime_nonce=$NONCE" "$URL"; echo

echo "--- start ---"
curl -s -b "$COOKIES" -d "action=ime_regen_start&ime_nonce=$NONCE&sizes=thumbnail|medium&force=0" "$URL"; echo

echo "--- batch ---"
curl -s -b "$COOKIES" -d "action=ime_regen_batch&ime_nonce=$NONCE" "$URL"; echo

echo "--- state (expect a queue with offset > 0) ---"
curl -s -b "$COOKIES" -d "action=ime_regen_state&ime_nonce=$NONCE" "$URL"; echo

echo "--- cancel ---"
curl -s -b "$COOKIES" -d "action=ime_regen_cancel&ime_nonce=$NONCE" "$URL"; echo

echo "--- state (expect no queue) ---"
curl -s -b "$COOKIES" -d "action=ime_regen_state&ime_nonce=$NONCE" "$URL"; echo
```

Get the nonce by loading the settings page and reading `ime_admin.ime_nonce` from the browser console. Run the script.

Expected: every call returns `0` (WordPress's response for an unregistered action).

- [ ] **Step 2: Add the constants**

In `imagemagick-engine.php`, after the `IME_VERSION` define:

```php
define( 'IME_REGEN_OPTION', 'ime_regen_queue' );
define( 'IME_REGEN_TTL', 12 * HOUR_IN_SECONDS );
define( 'IME_REGEN_BATCH_START', 5 );
define( 'IME_REGEN_BATCH_MIN', 1 );
define( 'IME_REGEN_BATCH_MAX', 25 );
define( 'IME_REGEN_FAILED_CAP', 100 );
```

- [ ] **Step 3: Register the actions and wire uninstall**

In `ime_init()`, alongside the existing `wp_ajax_` registrations:

```php
        add_action( 'wp_ajax_ime_regen_start', 'ime_ajax_regen_start' );
        add_action( 'wp_ajax_ime_regen_batch', 'ime_ajax_regen_batch' );
        add_action( 'wp_ajax_ime_regen_cancel', 'ime_ajax_regen_cancel' );
        add_action( 'wp_ajax_ime_regen_state', 'ime_ajax_regen_state' );
```

Remove the `wp_ajax_ime_regeneration_get_images` registration and delete `ime_ajax_regeneration_get_images()` — the new `ime_regen_start` replaces it, and nothing else calls it.

In `ime_uninstall()`, add one line alongside the existing `delete_option` and `delete_transient` calls:

```php
    delete_option( IME_REGEN_OPTION );
```

CLAUDE.md requires every new persistent store to be wired into uninstall. This is that wiring.

- [ ] **Step 4: Add the queue storage functions**

In `includes/ajax.php`:

```php
/**
 * Read the current regeneration queue.
 *
 * Deletes and reports absent any queue older than IME_REGEN_TTL.
 *
 * @return array|null
 */
function ime_regen_queue_get() {
    $queue = get_option( IME_REGEN_OPTION );

    if ( ! is_array( $queue ) || ! isset( $queue['started'] ) ) {
        return null;
    }

    if ( ( time() - (int) $queue['started'] ) > IME_REGEN_TTL ) {
        ime_regen_queue_clear();
        return null;
    }

    return $queue;
}

/**
 * Write the regeneration queue.
 *
 * @param array $queue Queue state.
 */
function ime_regen_queue_save( $queue ) {
    update_option( IME_REGEN_OPTION, $queue, false );
}

/** Remove the regeneration queue. */
function ime_regen_queue_clear() {
    delete_option( IME_REGEN_OPTION );
}
```

`update_option`'s third argument `false` is what keeps the row out of the autoloaded set. This is the whole reason the spec chose an option over a transient — do not drop it.

- [ ] **Step 5: Add the query helpers**

```php
/**
 * Count attachments eligible for regeneration.
 *
 * @return int
 */
function ime_regen_count_images() {
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $wpdb->posts
         WHERE post_type = 'attachment'
           AND post_mime_type LIKE 'image/%'
           AND post_mime_type != 'image/svg+xml'"
    );
}

/**
 * Fetch the next page of attachment IDs.
 *
 * Ordering by ID is required for correctness: without a stable sort,
 * OFFSET silently skips rows between batches.
 *
 * @param int $offset Rows already processed.
 * @param int $limit  Rows to fetch.
 * @return int[]
 */
function ime_regen_next_ids( $offset, $limit ) {
    global $wpdb;

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%%'
               AND post_mime_type != 'image/svg+xml'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        )
    );

    return array_map( 'intval', (array) $rows );
}

/**
 * Choose the next batch size from how long the last batch took.
 *
 * A single image can take 0.2s or 30s depending on its dimensions, so a
 * fixed batch size times out on shared hosting.
 *
 * @param int   $current Batch size just used.
 * @param float $elapsed Seconds the batch took.
 * @return int
 */
function ime_regen_next_batch_size( $current, $elapsed ) {
    if ( $elapsed < 5.0 ) {
        $next = $current * 2;
    } elseif ( $elapsed > 15.0 ) {
        $next = (int) floor( $current / 2 );
    } else {
        $next = $current;
    }

    return max( IME_REGEN_BATCH_MIN, min( IME_REGEN_BATCH_MAX, $next ) );
}
```

Note the doubled `%%` in the prepared statement — `LIKE 'image/%'` must survive `wpdb::prepare`'s own placeholder parsing.

- [ ] **Step 6: Extract the per-attachment work**

Move the body of `ime_ajax_process_image()` — everything after its argument parsing — into a reusable function, and have the AJAX handler call it. The logic is unchanged; only the error reporting differs, returning `WP_Error` instead of `wp_die( '-1' )`:

```php
/**
 * Regenerate one attachment's sub-sizes with the configured engine.
 *
 * @param int      $id         Attachment ID.
 * @param string[] $size_names Size slugs to generate.
 * @param bool     $force      Regenerate sizes already produced by this plugin.
 * @return true|WP_Error
 */
function ime_process_attachment( $id, $size_names, $force ) {
    global $ime_image_sizes, $ime_image_file;

    $size_names = apply_filters( 'intermediate_image_sizes', $size_names );

    $additional_sizes = wp_get_additional_image_sizes();
    $sizes            = [];

    foreach ( $size_names as $s ) {
        $sizes[ $s ] = [
            'width'  => isset( $additional_sizes[ $s ]['width'] )
                ? intval( $additional_sizes[ $s ]['width'] )
                : get_option( "{$s}_size_w" ),
            'height' => isset( $additional_sizes[ $s ]['height'] )
                ? intval( $additional_sizes[ $s ]['height'] )
                : get_option( "{$s}_size_h" ),
            'crop'   => isset( $additional_sizes[ $s ]['crop'] )
                ? intval( $additional_sizes[ $s ]['crop'] )
                : get_option( "{$s}_crop" ),
        ];
    }

    remove_filter( 'intermediate_image_sizes_advanced', 'ime_filter_image_sizes', 99 );
    $sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

    $ime_image_file = function_exists( 'wp_get_original_image_path' )
        ? wp_get_original_image_path( $id )
        : get_attached_file( $id );

    if ( false === $ime_image_file || ! file_exists( $ime_image_file ) ) {
        return new WP_Error( 'ime_missing_file', __( 'The source file is missing.', 'imagemagick-engine' ) );
    }

    $metadata = wp_get_attachment_metadata( $id );

    // Do not re-encode images this plugin already produced, unless forced.
    if ( ! $force && isset( $metadata['image-converter'] ) && is_array( $metadata['image-converter'] ) ) {
        foreach ( $sizes as $s => $ignore ) {
            if ( isset( $metadata['image-converter'][ $s ] ) && 'IME' === $metadata['image-converter'][ $s ] ) {
                unset( $sizes[ $s ] );
            }
        }
        if ( count( $sizes ) < 1 ) {
            return true;
        }
    }

    $ime_image_sizes = $sizes;

    set_time_limit( 60 );

    $new_meta = ime_filter_attachment_metadata( $metadata, $id );
    if ( is_wp_error( $new_meta ) ) {
        return $new_meta;
    }
    wp_update_attachment_metadata( $id, $new_meta );

    /*
     * Resized files are normally overwritten in place. If the size
     * definitions changed, the new files get different names, so the old
     * ones must be deleted explicitly.
     */
    if ( empty( $metadata['sizes'] ) ) {
        return true;
    }

    $dir = trailingslashit( dirname( $ime_image_file ) );

    foreach ( $metadata['sizes'] as $size => $sizeinfo ) {
        $old_file = $sizeinfo['file'];
        $exists   = false;

        foreach ( $new_meta['sizes'] as $ignore => $new_sizeinfo ) {
            if ( $old_file === $new_sizeinfo['file'] ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            wp_delete_file( $dir . $old_file );
        }
    }

    return true;
}
```

`wp_delete_file()` replaces the bare `@unlink()`; it is the WordPress-sanctioned wrapper and fires the `wp_delete_file` filter.

- [ ] **Step 7: Add the shared authorization guard**

```php
/**
 * Reject the request unless the caller may manage options and the nonce is valid.
 *
 * Sends a JSON error and exits on failure.
 */
function ime_ajax_require_admin() {
    $nonce = isset( $_REQUEST['ime_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ime_nonce'] ) ) : '';

    if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ime-admin-nonce' ) ) {
        wp_send_json_error(
            [ 'message' => __( 'You do not have permission to perform this action.', 'imagemagick-engine' ) ],
            403
        );
    }
}
```

Call it as the first statement of every `ime_ajax_*` handler, including the existing `ime_ajax_test_im_path()` and `ime_ajax_process_image()`, replacing their inline checks. Do not weaken either condition.

- [ ] **Step 8: Add the four handlers**

```php
/** Begin a regeneration run. */
function ime_ajax_regen_start() {
    ime_ajax_require_admin();

    if ( ! ime_mode_valid() ) {
        wp_send_json_error( [ 'message' => __( 'No valid image engine is configured.', 'imagemagick-engine' ) ] );
    }

    $raw_sizes = sanitize_text_field( wp_unslash( $_REQUEST['sizes'] ?? '' ) );
    $sizes     = array_values( array_filter( array_map( 'sanitize_key', explode( '|', $raw_sizes ) ) ) );

    if ( empty( $sizes ) ) {
        wp_send_json_error( [ 'message' => __( 'Select at least one image size.', 'imagemagick-engine' ) ] );
    }

    $total = ime_regen_count_images();
    if ( $total < 1 ) {
        wp_send_json_error( [ 'message' => __( 'There are no images to regenerate.', 'imagemagick-engine' ) ] );
    }

    $queue = [
        'id'      => wp_generate_uuid4(),
        'sizes'   => $sizes,
        'force'   => ! empty( $_REQUEST['force'] ),
        'offset'  => 0,
        'total'   => $total,
        'failed'  => [],
        'failed_count' => 0,
        'batch'   => IME_REGEN_BATCH_START,
        'started' => time(),
    ];

    ime_regen_queue_save( $queue );

    wp_send_json_success(
        [
            'run_id' => $queue['id'],
            'total'  => $total,
            'done'   => 0,
            'batch'  => $queue['batch'],
        ]
    );
}

/** Process the next batch. */
function ime_ajax_regen_batch() {
    ime_ajax_require_admin();

    $queue = ime_regen_queue_get();
    if ( null === $queue ) {
        wp_send_json_error( [ 'message' => __( 'No regeneration is in progress.', 'imagemagick-engine' ) ] );
    }

    $ids = ime_regen_next_ids( $queue['offset'], $queue['batch'] );

    if ( empty( $ids ) ) {
        ime_regen_queue_clear();
        wp_send_json_success(
            [
                'done'         => $queue['offset'],
                'total'        => $queue['total'],
                'failed'       => $queue['failed'],
                'failed_count' => $queue['failed_count'],
                'batch'        => $queue['batch'],
                'finished'     => true,
            ]
        );
    }

    $started = microtime( true );

    foreach ( $ids as $id ) {
        $result = ime_process_attachment( $id, $queue['sizes'], $queue['force'] );

        if ( is_wp_error( $result ) ) {
            ++$queue['failed_count'];

            if ( count( $queue['failed'] ) < IME_REGEN_FAILED_CAP ) {
                $queue['failed'][] = [
                    'id'    => $id,
                    'title' => get_the_title( $id ),
                    'error' => $result->get_error_message(),
                ];
            }
        }

        ++$queue['offset'];
    }

    $queue['batch'] = ime_regen_next_batch_size( $queue['batch'], microtime( true ) - $started );

    $finished = $queue['offset'] >= $queue['total'];

    if ( $finished ) {
        ime_regen_queue_clear();
    } else {
        ime_regen_queue_save( $queue );
    }

    wp_send_json_success(
        [
            'done'         => $queue['offset'],
            'total'        => $queue['total'],
            'failed'       => $queue['failed'],
            'failed_count' => $queue['failed_count'],
            'batch'        => $queue['batch'],
            'finished'     => $finished,
        ]
    );
}

/** Abandon the current run. */
function ime_ajax_regen_cancel() {
    ime_ajax_require_admin();
    ime_regen_queue_clear();
    wp_send_json_success( [ 'cancelled' => true ] );
}

/** Report whether a run is in progress, for resuming after a page load. */
function ime_ajax_regen_state() {
    ime_ajax_require_admin();

    $queue = ime_regen_queue_get();

    if ( null === $queue ) {
        wp_send_json_success( [ 'running' => false ] );
    }

    wp_send_json_success(
        [
            'running'      => true,
            'run_id'       => $queue['id'],
            'done'         => $queue['offset'],
            'total'        => $queue['total'],
            'failed'       => $queue['failed'],
            'failed_count' => $queue['failed_count'],
            'sizes'        => $queue['sizes'],
            'force'        => (bool) $queue['force'],
        ]
    );
}
```

- [ ] **Step 9: Run the verification script from Step 1**

Expected output, in order: `{"success":true,"data":{"running":false}}`; a start response with `total` matching the media library count; a batch response with `done` greater than 0; a state response with `running: true`; a cancel response; and `running: false` again.

Then confirm the option is not autoloaded. Start a run, then:

```bash
docker exec $(docker ps -q --filter 'name=cli' | head -1) \
  wp db query "SELECT option_name, autoload FROM wp_options WHERE option_name = 'ime_regen_queue'" --allow-root
```

Expected: one row with `autoload` set to `no` (or `off` on WordPress 6.6+).

Then confirm expiry. With a run in progress:

```bash
docker exec $(docker ps -q --filter 'name=cli' | head -1) \
  wp eval '$q = get_option("ime_regen_queue"); $q["started"] = time() - 60000; update_option("ime_regen_queue", $q, false); var_dump( ime_regen_queue_get() );' --allow-root
```

Expected: `NULL`, and the option gone afterwards.

Finally confirm authorization, logged out:

```bash
curl -s -d "action=ime_regen_batch&ime_nonce=bogus" http://localhost:8888/wp-admin/admin-ajax.php
```

Expected: a JSON error, not a batch result.

- [ ] **Step 10: Commit**

```bash
git add imagemagick-engine.php includes/ajax.php
git commit -m "Add a resumable server-side queue for image regeneration"
```

---

### Task 7: Regenerate tab UI

**Files:**
- Modify: `includes/admin-page.php` (`ime_option_page()`, `ime_admin_print_scripts()`)
- Modify: `js/ime-admin.js`
- Modify: `css/ime-admin.css`

**Interfaces:**
- Consumes: the four AJAX actions and `imeRequest()`.
- Produces: `Alpine.data( 'imeRegen' )` with `state` (`'idle' | 'running' | 'done'`), `done`, `total`, `failed` (array), `failedCount`, `percent` (getter), `etaText` (getter), `isIdle` / `isRunning` / `isDone` / `hasFailures` (getters), `start()`, `resume()`, `cancel()`.

- [ ] **Step 1: Write the verification procedure and watch it fail**

1. The Regenerate tab shows size checkboxes, All / None / Match settings buttons, a force checkbox, and a Start button.
2. Starting a run swaps the panel to a `<progress>` bar with `done / total` and a Cancel button.
3. Cancel stops the run; the panel returns to idle and no further batches are requested (verify in the Network panel).
4. Reloading the page mid-run shows "Regeneration in progress" with Resume and Cancel.
5. Resume continues from the stored offset, not from zero.
6. A completed run shows a success notice with the processed count.
7. A run containing a deliberately broken image shows a warning notice and lists it.
8. `grep -rn 'alert(' js/` returns nothing.
9. `grep -rn 'jquery' imagemagick-engine.php includes/` returns nothing.
10. Time remaining appears only after roughly ten images.

Run them. Expected: 1-7 and 10 fail; 8 and 9 also fail, since the old regen code still uses both.

- [ ] **Step 2: Replace the Regenerate tab markup**

Inside the regenerate tab div created in Task 3, replace everything with:

```php
    <div x-data="imeRegen">
        <h2><?php esc_html_e( 'Regenerate images', 'imagemagick-engine' ); ?></h2>

        <?php if ( ! ime_active() ) { ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e( 'ImageMagick Engine is not active, so resizing will use standard WordPress functions.', 'imagemagick-engine' ); ?></p>
            </div>
        <?php } ?>

        <div x-show="isIdle" x-cloak>
            <fieldset class="ime-regen-sizes">
                <legend><?php esc_html_e( 'Sizes', 'imagemagick-engine' ); ?></legend>
                <?php
                foreach ( $sizes as $s => $name ) {
                    $checked = isset( $handle_sizes[ $s ] ) && 'skip' !== $handle_sizes[ $s ] && $handle_sizes[ $s ];
                    ?>
                    <label>
                        <input type="checkbox" class="ime-regen-size" value="<?php echo esc_attr( $s ); ?>"
                            data-default="<?php echo $checked ? '1' : '0'; ?>"
                            <?php checked( $checked ); ?> />
                        <?php echo esc_html( $name ); ?>
                    </label>
                <?php } ?>
                <p>
                    <button type="button" class="button-link" x-on:click="selectAllSizes"><?php esc_html_e( 'All', 'imagemagick-engine' ); ?></button> ·
                    <button type="button" class="button-link" x-on:click="selectNoSizes"><?php esc_html_e( 'None', 'imagemagick-engine' ); ?></button> ·
                    <button type="button" class="button-link" x-on:click="selectDefaultSizes"><?php esc_html_e( 'Match settings', 'imagemagick-engine' ); ?></button>
                </p>
            </fieldset>

            <p>
                <label>
                    <input type="checkbox" id="ime-regen-force" x-model="force" />
                    <?php esc_html_e( 'Also regenerate images already handled by ImageMagick Engine', 'imagemagick-engine' ); ?>
                </label>
            </p>

            <p>
                <button type="button" class="button button-primary" x-on:click="start"><?php esc_html_e( 'Start regeneration', 'imagemagick-engine' ); ?></button>
            </p>
            <p class="description"><?php esc_html_e( 'This can take a long time.', 'imagemagick-engine' ); ?></p>

            <div class="notice notice-error inline" x-show="hasError" x-cloak>
                <p x-text="errorMessage"></p>
            </div>
        </div>

        <div x-show="isRunning" x-cloak>
            <p><strong x-text="headingText"></strong></p>
            <progress class="ime-progress" max="100" :value="percent"></progress>
            <p class="ime-regen-status" aria-live="polite" x-text="statusText"></p>
            <p>
                <button type="button" class="button button-primary" x-show="isPaused" x-cloak x-on:click="resume"><?php esc_html_e( 'Resume', 'imagemagick-engine' ); ?></button>
                <button type="button" class="button" x-on:click="cancel"><?php esc_html_e( 'Cancel', 'imagemagick-engine' ); ?></button>
            </p>
        </div>

        <div x-show="isDone" x-cloak>
            <div class="notice notice-success inline"><p x-text="doneText"></p></div>
        </div>

        <div x-show="hasFailures" x-cloak>
            <div class="notice notice-warning inline">
                <p x-text="failedText"></p>
                <ul class="ime-regen-failures">
                    <template x-for="item in failed" :key="item.id">
                        <li><span x-text="item.title"></span> — <span x-text="item.error"></span></li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
```

- [ ] **Step 3: Write the component**

```js
	Alpine.data( 'imeRegen', function() {
		return {
			state: 'idle',
			paused: false,
			done: 0,
			total: 0,
			failed: [],
			failedCount: 0,
			force: false,
			errorMessage: '',
			cancelRequested: false,
			batchTimes: [],

			// Alpine calls init() automatically on the component root, so the
			// markup needs no x-init attribute — which the CSP build would
			// reject anyway if it carried arguments.
			init: function() {
				this.loadState();
			},

			get isIdle() { return this.state === 'idle'; },
			get isRunning() { return this.state === 'running'; },
			get isDone() { return this.state === 'done'; },
			get isPaused() { return this.paused; },
			get hasFailures() { return this.failed.length > 0; },
			get hasError() { return this.errorMessage !== ''; },

			get percent() {
				if ( ! this.total ) {
					return 0;
				}
				return Math.min( 100, ( this.done / this.total ) * 100 );
			},

			get headingText() {
				return this.paused ? ime_admin.regen_paused : ime_admin.regen_running;
			},

			get statusText() {
				var text = this.done.toLocaleString() + ' / ' + this.total.toLocaleString();
				var eta = this.etaText;

				if ( eta ) {
					text += ' · ' + eta;
				}
				if ( this.failedCount ) {
					text += ' · ' + ime_admin.regen_failed_fmt.replace( '%d', this.failedCount );
				}
				return text;
			},

			get etaText() {
				// Under ten images the average is noise, so say nothing.
				if ( this.done < 10 || this.batchTimes.length < 2 ) {
					return '';
				}

				// Moving average over the last five batches only: throughput
				// changes as image sizes vary, and a cumulative mean lags badly.
				var recent = this.batchTimes.slice( -5 );
				var totalSeconds = 0;
				var totalImages = 0;

				recent.forEach( function( entry ) {
					totalSeconds += entry.seconds;
					totalImages += entry.images;
				} );

				if ( ! totalImages ) {
					return '';
				}

				var remaining = ( this.total - this.done ) * ( totalSeconds / totalImages );
				var minutes = Math.max( 1, Math.round( remaining / 60 ) );

				return ime_admin.regen_eta_fmt.replace( '%d', minutes );
			},

			get doneText() {
				return ime_admin.regen_done_fmt.replace( '%d', this.total.toLocaleString() );
			},

			get failedText() {
				return ime_admin.regen_failed_fmt.replace( '%d', this.failedCount );
			},

			loadState: function() {
				var self = this;

				imeRequest( 'ime_regen_state', {} ).then( function( data ) {
					if ( ! data.running ) {
						return;
					}
					self.state = 'running';
					self.paused = true;
					self.done = data.done;
					self.total = data.total;
					self.failed = data.failed || [];
					self.failedCount = data.failed_count || 0;
				} ).catch( function() {
					// A missing queue is not an error worth showing.
				} );
			},

			selectedSizes: function() {
				var values = [];
				var inputs = document.querySelectorAll( '.ime-regen-size:checked' );

				Array.prototype.forEach.call( inputs, function( input ) {
					values.push( input.value );
				} );

				return values.join( '|' );
			},

			selectAllSizes: function() { this.setSizes( 'all' ); },
			selectNoSizes: function() { this.setSizes( 'none' ); },
			selectDefaultSizes: function() { this.setSizes( 'default' ); },

			setSizes: function( which ) {
				var inputs = document.querySelectorAll( '.ime-regen-size' );

				Array.prototype.forEach.call( inputs, function( input ) {
					if ( which === 'all' ) {
						input.checked = true;
					} else if ( which === 'none' ) {
						input.checked = false;
					} else {
						input.checked = input.dataset.default === '1';
					}
				} );
			},

			start: function() {
				var self = this;
				var sizes = this.selectedSizes();

				self.errorMessage = '';
				self.failed = [];
				self.failedCount = 0;
				self.batchTimes = [];
				self.cancelRequested = false;

				imeRequest( 'ime_regen_start', {
					sizes: sizes,
					force: self.force ? 1 : 0
				} ).then( function( data ) {
					self.state = 'running';
					self.paused = false;
					self.done = 0;
					self.total = data.total;
					self.runBatch();
				} ).catch( function( error ) {
					self.errorMessage = error.message;
				} );
			},

			resume: function() {
				this.paused = false;
				this.cancelRequested = false;
				this.runBatch();
			},

			cancel: function() {
				var self = this;

				self.cancelRequested = true;

				imeRequest( 'ime_regen_cancel', {} ).then( function() {
					self.state = 'idle';
					self.paused = false;
					self.done = 0;
					self.total = 0;
				} ).catch( function( error ) {
					self.errorMessage = error.message;
				} );
			},

			runBatch: function() {
				var self = this;
				var startedAt = Date.now();
				var before = self.done;

				if ( self.cancelRequested ) {
					return;
				}

				imeRequest( 'ime_regen_batch', {} ).then( function( data ) {
					if ( self.cancelRequested ) {
						return;
					}

					self.batchTimes.push( {
						seconds: ( Date.now() - startedAt ) / 1000,
						images: Math.max( 1, data.done - before )
					} );

					self.done = data.done;
					self.total = data.total;
					self.failed = data.failed || [];
					self.failedCount = data.failed_count || 0;

					if ( data.finished ) {
						self.state = 'done';
						return;
					}

					self.runBatch();
				} ).catch( function( error ) {
					self.errorMessage = error.message;
					self.state = 'idle';
				} );
			}
		};
	} );
```

The `aria-live` region reads `statusText`, which changes once per batch rather than once per image. A batch that has shrunk to the floor of 1 still takes seconds on the slow server that caused the shrink, so announcements stay several seconds apart without an explicit throttle. The spec's "at most one update every 2 seconds" is satisfied structurally; Task 11's screen-reader pass confirms it rather than a timer enforcing it.

- [ ] **Step 4: Add the new strings**

To `$data` in `ime_admin_print_scripts()`:

```php
        'regen_running'    => __( 'Regenerating images', 'imagemagick-engine' ),
        'regen_paused'     => __( 'Regeneration in progress', 'imagemagick-engine' ),
        /* translators: %d: number of minutes */
        'regen_eta_fmt'    => __( 'about %d min remaining', 'imagemagick-engine' ),
        /* translators: %d: number of images */
        'regen_done_fmt'   => __( 'Finished. Processed %d images.', 'imagemagick-engine' ),
        /* translators: %d: number of images */
        'regen_failed_fmt' => __( '%d failed', 'imagemagick-engine' ),
```

Remove `noimg`, `done`, and `processed_fmt`, which the old code used for `alert()` and the inline message.

- [ ] **Step 5: Delete the old regeneration code**

From `js/ime-admin.js`, delete the module-level `rt_*` variables, `imeStartResize()`, `imeRegenImages()`, `imeTestPath()`, `imeTestGmPath()`, and the entire `jQuery( document ).ready()` block. From `includes/admin-page.php`, delete the `#regen-message` div, the `#ime-regeneration` div, and the `#regenerate-images-metabox` remnants.

From `imagemagick-engine.php`, nothing further is needed — the jQuery dependencies were already dropped in Task 3.

- [ ] **Step 6: Add the progress CSS**

```css
.ime-progress {
	width: 100%;
	max-width: 600px;
	height: 24px;
}

.ime-regen-status {
	font-weight: 600;
}

.ime-regen-sizes label {
	display: inline-block;
	margin-right: 16px;
}

.ime-regen-failures {
	margin: 8px 0 0 20px;
	list-style: disc;
}
```

- [ ] **Step 7: Create a broken image to exercise the failure path**

```bash
docker exec $(docker ps -q --filter 'name=cli' | head -1) \
  wp media import https://s.w.org/style/images/about/WordPress-logotype-wmark.png --allow-root
```

Then truncate the imported file inside the container so the engine fails on it:

```bash
docker exec $(docker ps -q --filter 'name=wordpress' | head -1) \
  bash -c 'f=$(ls -t /var/www/html/wp-content/uploads/*/*/WordPress-logotype-wmark*.png | head -1); : > "$f"'
```

- [ ] **Step 8: Run the verification from Step 1**

All ten checks must pass. Check 9's grep should return nothing:

```bash
grep -rni 'jquery' imagemagick-engine.php includes/ js/ime-admin.js
grep -rn 'alert(' js/ime-admin.js
```

Expected: no output from either.

- [ ] **Step 9: Commit**

```bash
git add includes/admin-page.php js/ime-admin.js css/ime-admin.css
git commit -m "Rebuild the regenerate tab on the batched queue with resume and cancel"
```

---

### Task 8: Media page button and the handle_sizes bug

**Files:**
- Modify: `includes/admin-page.php` (`ime_filter_media_meta()`, delete `ime_option_admin_images_url()`)
- Modify: `includes/ajax.php` (`ime_ajax_process_image()`)
- Modify: `js/ime-admin.js`
- Modify: `css/ime-admin.css`

**Interfaces:**
- Consumes: `ime_process_attachment()`, `ime_ajax_require_admin()`, `imeRequest()`.
- Produces: `Alpine.data( 'imeMediaRegen' )` with `busy`, `message`, `regenerate()`; reads `data-post-id`, `data-sizes`, `data-force` from its root element.

- [ ] **Step 1: Write the verification procedure and watch it fail**

Set every image size to *None* except Thumbnail on the settings page and save. Then:

1. Open an image at `wp-admin/post.php?post=<id>&action=edit`.
2. The regenerate button shows the WordPress `.spinner` while working, not `wpspin_light.gif`.
3. On success the message reads "Resized using ImageMagick Engine"; on failure it reads the server's error text.
4. Inspect the button's `data-sizes` attribute. It must contain **only** `thumbnail`.
5. `grep -rn 'wpspin_light\|ime_option_admin_images_url' includes/` returns nothing.

Run them. Expected: 2, 3, and 5 fail, and 4 fails with every size listed — this is the `'skip'`-is-truthy bug.

- [ ] **Step 2: Fix the size filter bug**

In `ime_filter_media_meta()`, the loop currently reads:

```php
    foreach ( $handle_sizes as $s => $h ) {
        if ( ! $h ) {
            continue;
        }
        $sizes[] = $s;
    }
```

`$h` holds the strings `'quality'`, `'size'`, or `'skip'`, and `'skip'` is truthy, so opted-out sizes are submitted. Replace with:

```php
    foreach ( $handle_sizes as $s => $h ) {
        if ( ! $h || 'skip' === $h ) {
            continue;
        }
        $sizes[] = $s;
    }
```

- [ ] **Step 3: Rewrite the media meta markup**

Replace the `$content .=` block that builds the link, message, and spinner with:

```php
    $content .= '</p><p>';
    $content .= sprintf(
        '<span class="ime-media-regen" x-data="imeMediaRegen" data-post-id="%1$d" data-sizes="%2$s" data-force="%3$s" data-message="%4$s">'
            . '<button type="button" class="button ime-regen-button" x-on:click="regenerate" :disabled="busy">%5$s</button>'
            . '<span class="spinner" x-show="busy" x-cloak></span>'
            . '<span class="ime-media-message" x-text="message"></span>'
            . '</span>',
        absint( $post->ID ),
        esc_attr( implode( '|', $sizes ) ),
        esc_attr( $force ),
        esc_attr( $initial_message ),
        esc_html( $resize )
    );
```

The `$message` variable above it held markup. Replace it with a plain string that the component reads from `data-message` in its `init()`:

```php
    if ( $ime ) {
        $initial_message = __( 'Resized using ImageMagick Engine', 'imagemagick-engine' );
        $resize          = __( 'Resize image', 'imagemagick-engine' );
        $force           = '1';
    } else {
        $initial_message = '';
        $resize          = __( 'Resize using ImageMagick Engine', 'imagemagick-engine' );
        $force           = '0';
    }
```

Delete the old `$message` assignment entirely — the `#ime-message-{$id}` and `#ime-spinner-{$id}` elements it produced are gone, and nothing references those IDs after Task 7 removed the jQuery.

The media page must also enqueue Alpine. In `ime_admin_menu()` the Alpine enqueue is currently attached only to the settings page hook. Move it into `ime_admin_print_scripts()` so every page in `$script_pages` gets it:

```php
function ime_admin_print_scripts() {
    wp_enqueue_script( 'ime-admin' );
    wp_enqueue_script( 'alpinejs' );
    // ... existing $data and wp_localize_script
}
```

and delete the separate `add_action( 'admin_print_scripts-' . $ime_page, ... )` closure.

- [ ] **Step 4: Convert `ime_ajax_process_image()` to JSON**

```php
/** Regenerate a single attachment from the media screens. */
function ime_ajax_process_image() {
    ime_ajax_require_admin();

    if ( ! ime_mode_valid() ) {
        wp_send_json_error( [ 'message' => __( 'No valid image engine is configured.', 'imagemagick-engine' ) ] );
    }

    $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
    if ( $id <= 0 ) {
        wp_send_json_error( [ 'message' => __( 'Invalid attachment.', 'imagemagick-engine' ) ] );
    }

    $raw_sizes = sanitize_text_field( wp_unslash( $_REQUEST['sizes'] ?? '' ) );
    $sizes     = array_values( array_filter( array_map( 'sanitize_key', explode( '|', $raw_sizes ) ) ) );

    if ( empty( $sizes ) ) {
        wp_send_json_error( [ 'message' => __( 'Select at least one image size.', 'imagemagick-engine' ) ] );
    }

    $result = ime_process_attachment( $id, $sizes, ! empty( $_REQUEST['force'] ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [ 'message' => __( 'Resized using ImageMagick Engine', 'imagemagick-engine' ) ] );
}
```

- [ ] **Step 5: Add the media component**

```js
	Alpine.data( 'imeMediaRegen', function() {
		return {
			busy: false,
			message: '',

			init: function() {
				this.message = this.$el.dataset.message || '';
			},

			regenerate: function() {
				var self = this;
				var el = this.$el;

				if ( self.busy ) {
					return;
				}

				self.busy = true;

				imeRequest( 'ime_process_image', {
					id: el.dataset.postId,
					sizes: el.dataset.sizes,
					force: el.dataset.force
				} ).then( function( data ) {
					self.busy = false;
					self.message = data.message;
				} ).catch( function( error ) {
					self.busy = false;
					self.message = error.message;
				} );
			}
		};
	} );
```

- [ ] **Step 6: Delete the last legacy helper**

```bash
grep -rn 'ime_option_admin_images_url' includes/ imagemagick-engine.php js/
```

Expected: only its own definition. Delete the function.

- [ ] **Step 7: Update the media CSS**

Replace the `.ime-spinner` rules with nothing — the WordPress `.spinner` class handles it. Keep `.ime-media-message` but drop the float:

```css
.ime-media-regen {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.ime-media-message {
	font-style: italic;
}
```

Delete `.ime-regen-button { float: left; }`, `.ime-spinner`, and `.ime-spinner img`.

- [ ] **Step 8: Run the verification from Step 1**

All five checks must pass, including check 4 now listing only `thumbnail`. Restore your size settings afterwards.

- [ ] **Step 9: Commit**

```bash
git add includes/ js/ime-admin.js css/ime-admin.css
git commit -m "Rebuild the media page regenerate button and stop submitting skipped sizes"
```

---

### Task 9: CSS pass against WordPress custom properties

**Files:**
- Modify: `css/ime-admin.css`

**Interfaces:**
- Consumes: the class names introduced in Tasks 4, 5, 7, 8.
- Produces: no new interfaces.

- [ ] **Step 1: Write the verification procedure and watch it fail**

1. `grep -nE '#[0-9a-fA-F]{3,6}' css/ime-admin.css` returns no hardcoded colours other than inside a `var(..., fallback)` position.
2. `grep -n 'ui-progressbar\|-moz-border-radius\|-khtml-border-radius\|FF6600' css/ime-admin.css` returns nothing.
3. The progress bar fill uses the active admin colour scheme. Switch schemes at `profile.php` and confirm the bar changes colour.
4. Engine cards remain readable in the Modern, Light, Blue, Coffee, Ectoplasm, Midnight, Ocean, and Sunrise schemes.
5. The page has no horizontal scrollbar at a 782px viewport width.

Run them. Expected: 1, 2, and 3 fail.

- [ ] **Step 2: Rewrite the stylesheet**

```css
[x-cloak] {
	display: none !important;
}

/* Engine cards */

.ime-engine-grid {
	display: grid;
	grid-template-columns: repeat( auto-fit, minmax( 320px, 1fr ) );
	gap: 12px;
	margin: 16px 0 24px;
	border: 0;
	padding: 0;
}

.ime-engine-card {
	border: 1px solid var( --wp-admin-border-color, #c3c4c7 );
	border-radius: 4px;
	padding: 12px 16px;
	background: var( --wp-admin-theme-color-background, #fff );
}

.ime-engine-card--unavailable {
	opacity: 0.6;
}

.ime-engine-card__label {
	font-weight: 600;
}

.ime-engine-card__status {
	margin: 6px 0 0;
}

.ime-engine-card__status .dashicons-yes-alt {
	color: #00a32a;
}

.ime-engine-card__status .dashicons-dismiss {
	color: #d63638;
}

.ime-engine-card__path {
	margin-top: 12px;
}

/* Image size table */

.ime-sizes-table td,
.ime-sizes-table th {
	vertical-align: middle;
}

.ime-sizes-table fieldset {
	margin: 0;
	padding: 0;
	border: 0;
}

/* Regeneration */

.ime-progress {
	width: 100%;
	max-width: 600px;
	height: 24px;
	accent-color: var( --wp-admin-theme-color, #2271b1 );
}

.ime-regen-status {
	font-weight: 600;
}

.ime-regen-sizes label {
	display: inline-block;
	margin-right: 16px;
}

.ime-regen-failures {
	margin: 8px 0 0 20px;
	list-style: disc;
}

/* Media screens */

.ime-media-regen {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.ime-media-message {
	font-style: italic;
}

@media screen and ( max-width: 782px ) {
	.ime-engine-grid {
		grid-template-columns: 1fr;
	}
}
```

`accent-color` on `<progress>` is the whole reason the native element was chosen — one declaration and the bar follows the admin scheme. The green and red status colours stay literal: they are WordPress's own semantic status colours and are not scheme-dependent.

- [ ] **Step 3: Verify the stylesheet URL (no change expected)**

Task 2 already fixed this: `plugins_url( ..., __FILE__ )` inside `includes/admin-page.php` resolves one directory too deep, so the call reads:

```php
    wp_enqueue_style( 'ime-admin-style', plugins_url( '/css/ime-admin.css', dirname( __DIR__ ) . '/imagemagick-engine.php' ), [], constant( 'IME_VERSION' ) );
```

`__DIR__` inside `includes/admin-page.php` is the `includes` directory, so `dirname( __DIR__ )` is the plugin root — **not** `dirname( __FILE__ )`, which would still point inside `includes/`.

Confirm the fix survived the intervening tasks:

```bash
grep -rn 'plugins_url' includes/ imagemagick-engine.php
curl -s -b .superpowers/sdd/2026-08-17-admin-ui-rework/cookies.txt \
  "http://localhost:8888/wp-admin/options-general.php?page=imagemagick-engine" \
  | grep -o 'ime-admin[^"]*\.css[^"]*'
```

Every call inside `includes/` must pass the main plugin file path, not `__FILE__`. The rendered URL must contain no `includes` segment and must carry `?ver=2.0.0`. If either check fails, fix it here.

- [ ] **Step 4: Run the verification from Step 1**

All five checks must pass. For check 4, switch schemes at `wp-admin/profile.php` and reload the settings page for each.

- [ ] **Step 5: Commit**

```bash
git add css/ime-admin.css includes/admin-page.php
git commit -m "Style the admin page with WordPress custom properties"
```

---

### Task 10: Translations

**Files:**
- Modify: `languages/imagemagick-engine.pot`
- Modify: every `languages/*.po`
- Modify: every `languages/*.mo`

**Interfaces:**
- Consumes: all strings added in Tasks 1-9.
- Produces: no code interfaces.

- [ ] **Step 1: Inventory the existing catalogue**

```bash
ls languages/
grep -c 'msgid' languages/imagemagick-engine.pot
```

Record the count so the regeneration can be compared against it.

- [ ] **Step 2: Confirm the POT is stale**

```bash
grep -c 'Regenerating images' languages/imagemagick-engine.pot
grep -c 'about %d min remaining' languages/imagemagick-engine.pot
```

Expected: `1` then `0` — the new strings are absent.

- [ ] **Step 3: Regenerate the POT**

```bash
docker exec -w /var/www/html/wp-content/plugins/imagemagick-engine \
  $(docker ps -q --filter 'name=cli' | head -1) \
  wp i18n make-pot . languages/imagemagick-engine.pot \
  --slug=imagemagick-engine --domain=imagemagick-engine --allow-root
```

- [ ] **Step 4: Merge into each locale**

```bash
for po in languages/*.po; do
  msgmerge --update --backup=none "$po" languages/imagemagick-engine.pot
done
```

- [ ] **Step 5: Recompile**

```bash
for po in languages/*.po; do
  msgfmt -o "${po%.po}.mo" "$po"
done
```

- [ ] **Step 6: Verify**

```bash
grep -c 'about %d min remaining' languages/imagemagick-engine.pot
ls -la languages/*.mo
```

Expected: `1`, and every `.mo` newer than its `.po`.

Then switch the site language to one of the translated locales at `wp-admin/options-general.php` and confirm the settings page still renders — untranslated new strings falling back to English is correct and expected.

- [ ] **Step 7: Commit**

```bash
git add languages/
git commit -m "Regenerate translation templates for the reworked admin page"
```

---

### Task 11: Full verification pass and documentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `readme.txt` (changelog)

**Interfaces:**
- Consumes: everything.
- Produces: nothing.

- [ ] **Step 1: Run the complete spec verification matrix**

Work through all ten items from the spec's Testing section, recording pass or fail for each:

1. Each of the four engines: select, save, upload an image, confirm sub-sizes carry `metadata['image-converter'][$size] === 'IME'`. Check with:

```bash
docker exec $(docker ps -q --filter 'name=cli' | head -1) \
  wp post meta get <attachment-id> _wp_attachment_metadata --format=json --allow-root
```

2. An unavailable engine renders disabled and cannot be submitted. Try forcing it:

```bash
curl -s -b cookies.txt -d "update_settings=1&mode=gmagick&_wpnonce=<nonce>" \
  "http://localhost:8888/wp-admin/options-general.php?page=imagemagick-engine" | grep -c 'gmagick'
```

The stored option must not change to an unavailable engine — the existing `array_key_exists` check permits it, so confirm the *saved* value and, if an unavailable engine can be stored, note it as a finding rather than fixing it here.

3. Path test for both CLI engines: a valid path, an invalid path, and an `open_basedir`-restricted path.
4. Quality fields: empty, `0`, `100`, `150`, `-5`.
5. Regeneration: start, watch, cancel mid-run, reload mid-run and resume, complete a run.
6. Regeneration with the truncated image from Task 7 to confirm the failure list and the 100-entry cap.
7. Media page single-image regenerate, with and without force.
8. Keyboard-only traversal of the whole page; screen-reader pass over the engine cards and the progress region.
9. All eight admin colour schemes.
10. Exif orientation unchanged — upload a JPEG with an orientation tag of 6 through the Imagick engine and again through the CLI engine, and confirm both produce upright thumbnails.

- [ ] **Step 2: Confirm the package contents**

```bash
git archive --format=tar HEAD | tar -t | grep -E '^(docs|\.claude|\.idea)/' | head
```

Expected: no output would be wrong here, because `git archive` ignores `.distignore`. Instead confirm the deploy exclusion list directly:

```bash
cat .distignore
```

Expected: `/docs`, `/.claude`, `/.idea`, and `.DS_Store` all present.

- [ ] **Step 3: Update CLAUDE.md**

The architecture description is now wrong in three places. Update:

- "Single-file plugin (`imagemagick-engine.php`) plus admin JS/CSS" → describe the `includes/` split and what each file owns.
- The `.distignore` sentence, which claims `.idea`, `node_modules`, and `.claude` are excluded. Before Task 1 they were not. State the current list.
- Add a short subsection under Architecture describing the regeneration queue: the option name, that it is not autoloaded, the 12-hour TTL, the adaptive batch sizing, and that `ime_uninstall()` must delete it.
- Add a line under Security noting that every `ime_ajax_*` handler calls `ime_ajax_require_admin()` first.
- Note that Alpine is the CSP build and inline expressions in attributes are not permitted.

- [ ] **Step 4: Write the changelog entry**

In `readme.txt`, under `== Changelog ==`:

```
= 2.0.0 =
* Rebuilt the settings page: tabbed layout, engine status cards, and a native WordPress look that follows your admin colour scheme
* Image regeneration now runs in adaptive batches, survives a page reload, and can be cancelled and resumed
* Regeneration reports which images failed instead of stopping at a browser alert
* Removed jQuery and jQuery UI from the admin page
* Fixed the media page regenerate button submitting image sizes that were set to None
* Requires WordPress 6.4 or later
```

- [ ] **Step 5: Final lint and grep sweep**

```bash
php -l imagemagick-engine.php
php -l includes/admin-page.php
php -l includes/ajax.php
grep -rni 'jquery' imagemagick-engine.php includes/ js/ime-admin.js
grep -rn 'alert(' js/ime-admin.js
grep -rn 'wpspin_light\|yes.png\|no.png' imagemagick-engine.php includes/
grep -rn 'escapeshellcmd\|shell_exec\|`' imagemagick-engine.php | grep -v '^\s*\*'
```

Expected: three `No syntax errors detected`, and no output from any grep. The last one is a standing check that no shell-interpreted execution crept in.

- [ ] **Step 6: Commit**

```bash
git add CLAUDE.md readme.txt
git commit -m "Document the reworked admin page and add the 2.0.0 changelog"
```

---

## Notes for the reviewer

- **Task 1 is not cosmetic.** Without it, the design spec and this plan are published to wordpress.org on the next tag.
- **Task 6 can be reviewed without any UI.** The verification script drives the endpoints directly, which is the point of separating it from Task 7.
- **The `plugins_url()` hazard in Task 9 Step 3 applies to anything moved into `includes/`.** `__FILE__` inside `includes/admin-page.php` resolves one directory too deep. Task 2 moves `ime_admin_print_styles()`, so this bug is introduced in Task 2 and fixed in Task 9. If Task 2's verification shows missing CSS, that is why — fix it there and drop the Task 9 step.
- **Deliberately not fixed:** `ime_option_page()` accepts an unavailable engine in `mode` if POSTed directly, because it validates against `ime_get_available_modes()` (the full list) rather than validity. Task 11 Step 1 item 2 surfaces it. It is pre-existing and out of this spec's scope.
