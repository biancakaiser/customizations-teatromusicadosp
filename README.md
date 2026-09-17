# Customizations - Teatro Musicado SP

[![Project Status: WIP – Initial development is in progress, but there has not yet been a stable, usable release suitable for the public.](https://www.repostatus.org/badges/latest/WIP.svg)](https://www.repostatus.org/#WIP)

A site-specific WordPress plugin that customizes [Tainacan](https://tainacan.org) to
power a digital archive of theatrical presentations ("apresentações") in São Paulo —
plays, companies, productions, venues and people, for academic and cultural-heritage
research use.

For a full architecture reference (module structure, bootstrap/load order, data
model, Tainacan coupling, known caveats) see [`CLAUDE.md`](./CLAUDE.md). This README
covers what a contributor needs to get set up and start developing.

## Requirements

- WordPress, with **Tainacan active**. This is a soft/runtime dependency (checked
  via `function_exists('tainacan_metadata'|'tainacan_items'|'tainacan_collections'|'tainacan_taxonomies')`
  in `classes/Plugin.php`) — if Tainacan isn't active, the plugin shows an admin
  notice and does nothing else.
- PHP 8.0+ (the codebase uses constructor property promotion, e.g.
  `classes/presentations/grouping/grouping-mode.php`).
- Node.js + npm, only if you're working on the Vue components under `components/`.
  No Node version is currently pinned (no `.nvmrc`/`engines` field) — any reasonably
  recent LTS works.

## Installation

Like any other WordPress plugin: place this folder at
`wp-content/plugins/customizations-teatromusicadosp`, then activate it from
wp-admin. Activate **Tainacan first** — activating this plugin before Tainacan is
harmless (you'll just get an admin notice) but nothing will register until Tainacan
is active too.

## Developing the Vue components (`components/`)

This folder holds **two independent Vue 3 apps**, built with Webpack 5 — not one
shared SPA:

- `components.js` (entry) → `PersonViewMode` / `CompanyViewMode`. These register
  into Tainacan's own component system (`tainacan-register-vuejs-component` /
  `register_vuejs_component()`) and back the custom "grouped table" view modes on
  the Pessoa and Companhia collections (`classes/view-modes/register-viewmodes.php`).
- `src/espetaculos-form-hook/espetaculos-form-hook.js` (entry) → a standalone
  `createApp` mounted into `#teatro-espetaculos-app`. It's injected into Tainacan's
  admin item-edit screen for the Montagem collection
  (`classes/form/collection-form.php`) and self-mounts via a `MutationObserver`,
  since Tainacan's admin SPA injects that container via `v-html` rather than a
  normal page load.

`assets/presentations.js` (used by the `[teatro_apresentacoes]` shortcode) is a
separate, hand-written vanilla JS file at the plugin root — it is **not** part of
this build pipeline.

### Build commands

```sh
cd components
npm install
npm run build   # webpack production build
```

This outputs `build/components.bundle.js` and `build/espetaculos-form-hook.bundle.js`,
which is exactly what the PHP side enqueues
(`classes/view-modes/register-viewmodes.php`, `classes/form/collection-form.php`).

For hot-reloading while developing:

```sh
npm run dev   # webpack serve --mode development --hot, on http://127.0.0.1:8080/
```

`npm run dev` alone doesn't change what WordPress loads. To have PHP load the bundle
straight from the dev server instead of `build/`, add this to `wp-config.php`:

```php
define('TEATRO_COMPONENTS_DEV_SERVER', true);
```

**Known inconsistency**: `components/.gitignore` ignores `/dist`, but Webpack
actually outputs to `/build`. Don't assume build output is (or isn't) tracked in
git without checking — verify directly before relying on a fresh checkout already
having a `build/` folder.

## `classes/` module structure — how to add a module

Every feature in this plugin is a **module**: a class that implements
`Contracts\Module` (a single `register()` method) and uses the `Traits\Singleton`
trait. There's no autoloader — `customizations-teatromusicadosp.php` manually
`require_once`s every class file in a fixed order, so wiring up a new module means:

1. Write your class implementing `Module::register()`, using `Singleton` (copy the
   shape of any existing module, e.g. `classes/related-items/pessoa-related-items-order.php`,
   as a template).
2. `require_once` it from `customizations-teatromusicadosp.php`, positioned after
   anything it depends on (load order is manual and matters).
3. Instantiate it and add it to the `$modules` array in `classes/Plugin.php`'s
   `boot()` — this is what actually calls `register()`, deferred to `plugins_loaded`.
   The one exception is metadata-type registration, which hooks
   `tainacan-register-metadata-type` **immediately** at file-load time (outside
   `plugins_loaded`), because Tainacan fires that hook while loading its own main
   file, before `plugins_loaded` runs.

Existing modules, for quick reference:

| Path | Purpose |
|---|---|
| `contracts/` | `Module` interface |
| `traits/` | `Singleton` trait |
| `form/collection-form.php` | Injects the Espetáculos Vue form into Tainacan's admin item-edit screen (Montagem collection, ID 3922) |
| `metadata-types/slug-id/` | Custom Tainacan metadata type ("Slug/ID") |
| `view-modes/register-viewmodes.php` | Custom Tainacan "extra view modes" (grouped table) for Pessoa/Companhia |
| `related-items/pessoa-related-items-order.php` | Reorders related-item groups on Tainacan item pages |
| `blocks/bibliographic.php` | Gutenberg block querying Tainacan items directly |
| `presentations/` | The `[teatro_apresentacoes]` shortcode — see below |

See [`CLAUDE.md`](./CLAUDE.md) for the full bootstrap load order and the reasoning
behind it.

## The presentations module (`[teatro_apresentacoes]`)

- `PresentationsSchema` / `PresentationColumn` (`classes/presentations/schema/`) is
  the single source of truth for columns and which ones are filterable — add new
  fields there first; filters, facets and renderers all read from it.
- **Data is currently mocked.** Presentations live in a dedicated table
  (`{$wpdb->prefix}teatro_presentations`), seeded from `data/flat-table.csv`
  (historical 1931 São Paulo presentation records). The table is re-seeded
  automatically on `admin_init` whenever a schema-version option is stale — to
  regenerate the data today, edit the CSV and bump (or delete) that version marker
  in `classes/presentations/presentations-repository.php`. This table is meant to
  eventually be fed by live Tainacan data, but isn't yet — don't assume it reflects
  the live archive.
- There are no `wp_ajax_*` handlers. The front end talks to a dedicated REST
  namespace, `teatromusicadosp/v1` (`/presentations`, `/presentations/grouped`),
  consumed by `assets/presentations.js` as progressive enhancement — the page must
  keep working with JS disabled.
- The results table only renders after the visitor submits the filter form once
  (the `tap_go` no-first-load gate in `PresentationsRequest`) — this is intentional
  UX, not a bug.

## Coding conventions & known gaps

- No `composer.json`, PHPCS, ESLint, Prettier or EditorConfig is set up anywhere in
  this plugin. Follow the existing style (namespaced classes under
  `TeatroMusicadoSP\Customizations`, the Module/Singleton pattern) rather than
  introducing new tooling or conventions ad hoc.
- There is no automated test suite (no PHPUnit, no CI). Verify changes manually:
  toggle Tainacan active/inactive and confirm the admin notice / module behavior,
  and exercise the shortcode with JS both on and off.
- Text domain `customizations-teatromusicadosp` is wired up
  (`load_plugin_textdomain()` in `classes/Plugin.php`), but the `languages/` folder
  it points to doesn't exist yet. To generate it (requires [WP-CLI](https://wp-cli.org/)):

  ```sh
  wp i18n make-pot . languages/customizations-teatromusicadosp.pot
  ```

## License

GPLv3 — see [`LICENSE`](./LICENSE).
