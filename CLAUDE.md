# Customizations - Teatro Musicado SP

## What this is

A site-specific WordPress plugin (`customizations-teatromusicadosp.php`, v1.0.0,
namespace `TeatroMusicadoSP\Customizations`) built on top of **Tainacan**, a
WordPress-based digital-collections/archive platform. This plugin does not stand
alone — its whole purpose is customizing how Tainacan behaves and renders for one
specific archive.

**Application domain**: an academic/cultural-heritage digital archive of theatrical
presentations ("apresentações") in São Paulo. The data model, expressed as Tainacan
collections, links:

- **Peça** — play (name, genre, nationality)
- **Companhia** — theatrical company/troupe (name, nationality)
- **Espetáculo** / **Montagem** — a production (theater, kind, language, date,
  session count) — the "Montagem" collection has ID `3922`
- **Pessoa** — person (collection ID `3408`)
- **Apresentação** — presentation, the join entity tying the above together; this is
  the subject of the `[teatro_apresentacoes]` shortcode

The audience is researchers and academics using this as a citable archive, not a
general-public consumer site — favor correctness, data provenance, and stable
filtering/sorting semantics over UX polish when the two trade off.

**Tainacan dependency is soft, not declared.** There is no `Requires Plugins:`
header. `classes/Plugin.php` checks `is_tainacan_available()` — literally
`function_exists('tainacan_metadata') && function_exists('tainacan_items') &&
function_exists('tainacan_collections') && function_exists('tainacan_taxonomies')` —
and if Tainacan is inactive, `boot()` shows an admin notice and returns early. Every
other module in this plugin assumes Tainacan is present; don't add code that runs
before that check.

## Ecosystem — relations with other plugins/theme (one-directional)

Nothing else in the install depends on this plugin; it depends on Tainacan and,
loosely, on one Tainacan add-on. Keep these straight, since names are similar:

- **`wp-content/plugins/tainacan/`** — the real, active Tainacan core plugin (v1.2.0).
  This plugin hooks into its `Tainacan\Entities\*`, `Tainacan\Repositories\*`,
  `Tainacan\Metadata_Types\Metadata_Type` classes, and its action hooks
  `tainacan-register-metadata-type`, `tainacan-register-admin-hooks`,
  `tainacan-fetch-args`.
- **`wp-content/plugins/tainacan-repo/`** — **not an active plugin.** It's a nested
  dev/reference git checkout of the Tainacan GitHub source (two directories deep, has
  its own `.git`, `tests/`, `docs/`). Do not confuse it with `tainacan/` above, and
  don't expect WordPress to load it.
- **`wp-content/plugins/tainacan-blocksy/`** — official Tainacan↔Blocksy
  compatibility plugin. `classes/related-items/pessoa-related-items-order.php`
  explicitly relies on its template
  `template-parts/tainacan-item-single-items-related-to-this.php` for rendering
  related-item groups it reorders.
- **`wp-content/themes/teatromusicadosp-tema/`** — the active theme, a Blocksy child
  theme (`Template: blocksy`). It carries site CSS targeting Tainacan blocks (e.g.
  `.wp-block-tainacan-dynamic-items-list`) but no PHP integration logic — that lives
  in `tainacan-blocksy` instead.
- `akismet`, `wordpress-importer`, `wpforms-lite`, `blocksy-companion`,
  `stackable-ultimate-gutenberg-blocks` are generic/off-the-shelf plugins with no
  code relationship to this plugin in either direction.

## Bootstrap & load order

`customizations-teatromusicadosp.php` manually `require_once`s every class file in a
**fixed order that matters** — there's no autoloader:

1. `classes/contracts/module.php` — the `Module` interface (`register()`)
2. `classes/traits/singleton.php` — shared `Singleton` trait
3. Presentations: `schema/` → `filters/` → `grouping/` → `rendering/` →
   `PresentationsRepository` → remaining facets/renderers → `PresentationsRequest` →
   `PresentationsShortcode`/`PresentationsPage`
4. Activation hook: `register_activation_hook(__FILE__, [PresentationsRepository::class, 'install'])`
5. `classes/metadata-types/register-metadatas.php`, then immediately
   `add_action('tainacan-register-metadata-type', ...)` **outside** the
   `plugins_loaded` cycle — this is deliberate: Tainacan fires
   `tainacan-register-metadata-type` while loading its own main file, before
   `plugins_loaded` runs, so registering it later would miss the hook.
6. `view-modes/`, `related-items/`, `form/collection-form.php`, `blocks/bibliographic.php`
7. `classes/Plugin.php`, then `Plugin::get_instance()->register()` — this is what
   defers everything else to `plugins_loaded` → `boot()`.

**Convention**: every feature module implements `Module::register()` and uses the
`Singleton` trait. Follow this pattern for new modules rather than inventing a new
bootstrapping style.

## `classes/` map

| Path | Purpose |
|---|---|
| `contracts/` | `Module` interface all feature classes implement |
| `traits/` | `Singleton` trait |
| `form/collection-form.php` | Injects a custom Vue form into Tainacan's admin item-edit screen, for the "Montagem" collection (ID 3922) |
| `metadata-types/slug-id/` | Custom Tainacan metadata type ("Slug/ID"), extends `Tainacan\Metadata_Types\Metadata_Type` |
| `view-modes/register-viewmodes.php` | Custom Tainacan "extra view modes" (grouped table) for item-relationship display on Pessoa/Companhia collections |
| `related-items/pessoa-related-items-order.php` | Reorders related-item groups on Tainacan item pages via `tainacan-fetch-args`/`posts_results` filters |
| `blocks/bibliographic.php` | Gutenberg block calling `Tainacan\Repositories\Items::get_instance()->fetch()` directly |
| `presentations/` | The `[teatro_apresentacoes]` feature — largest, most actively developed part of the plugin (see below) |

## `presentations/` module — the `[teatro_apresentacoes]` shortcode

Subfolders:
- `schema/` — `PresentationsSchema`/`PresentationColumn`: **single source of truth**
  for the presentations table's columns (which are facetable, numeric, etc.)
- `filters/` — `PresentationFilters` (immutable value object), `PresentationFilterClause`,
  `PresentationsFacets`
- `grouping/` — `GroupingMode`/`GroupingModes`, `PresentationGrouper`
- `rendering/` — `FlatTableRenderer`, `GroupedTableRenderer`, `ResultsContentRenderer`,
  `FilterFormRenderer`, `ResultsViewRenderer`, `PresentationValue`

Core top-level classes: `PresentationsRepository`, `PresentationsShortcode`,
`PresentationsRequest`, `PresentationsPage`.

**No-first-load gate**: `PresentationsRequest::QV_SUBMITTED = 'tap_go'`
(`presentation-request.php`) — the results table only renders after the visitor
submits the filter form once (checked via `has_submitted()` in
`presentations-shortcode.php`), and is set client-side in `assets/presentations.js`.
This is intentional UX, not a bug — don't "fix" it into rendering results on first
load.

**Interactivity**: there are **no `wp_ajax_*` actions** in this module. A dedicated
public REST namespace `teatromusicadosp/v1` is registered in
`presentations-repository.php` (`/presentations`, `/presentations/grouped`),
consumed by `assets/presentations.js` as progressive enhancement — the page must keep
working with JS disabled.

**Data source caveat — read before changing anything here**: presentations are
**not** queried live from Tainacan today. They live in a dedicated custom table
(`{$wpdb->prefix}teatro_presentations`), created on plugin activation, but currently
**seeded from a mock CSV** (`data/flat-table.csv`). A comment in
`presentations-repository.php` states the intent is for this table to eventually be
fed by real Tainacan data; table creation is already gated on Tainacan being active,
but the actual row data is still mock. Do not assume this table reflects the live
Tainacan archive.

**Filters**: values within one column are OR'd (`col IN (...)`), different columns
are AND'd, plus a `presentationDate` range (`tap_from`/`tap_to` on the page,
`date_from`/`date_to` over REST). Only columns whitelisted via
`PresentationsSchema::facetable_columns()` are accepted. The same `PresentationFilters`
object is translated three ways — `to_query_args()` (page URL, `tap_f`),
`to_rest_params()` (REST `filters` param), and `cache_fragment()` (sorted, stable key
for transient caching) — keep all three in sync if the filter model changes.

## Testing

No automated test suite exists in this plugin. There is no PHPUnit config, no
`tests/` directory, and no CI wiring found. Treat any change here as needing manual
verification (activate/deactivate with Tainacan on and off, exercise the shortcode
with JS on and off) rather than assuming hidden test coverage.

## Known stale/misleading files

- `README.md` at the plugin root is leftover boilerplate from an earlier, narrower
  version of the plugin (titled "Teatro View Modes", describing only one Tainacan
  view mode). It does not reflect the plugin's current scope — this CLAUDE.md is the
  authoritative architecture reference, not the README.
