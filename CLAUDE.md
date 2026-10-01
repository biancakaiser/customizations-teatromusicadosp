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
- **Teatro** — venue
- **Espetáculos** — one presentation: relationships to Peça, Companhia and Teatro,
  plus language (taxonomy), kind, date and session count. **One published item =
  one row** of the `[teatro_apresentacoes]` table.
- **Pessoa**, **Membros** (company casts), **Funções**

**Tainacan IDs live in exactly one place:** `classes/references/tainacan-ids.php`
(`References\TainacanIds` — collections, taxonomies, and every metadatum the code
reads). IDs are environment-specific (a reimport renumbers them); the current
environment is the reference. Never write a collection/metadatum ID as a literal
anywhere else: PHP uses the constants; the Vue bundles read
`window.TMSP_TAINACAN_IDS` (printed by `TainacanIds::enqueue_for_js()`, wired in
`RegisterViewModes`) through `components/src/tainacan-ids.js`, lazily. Changing an
ID in PHP needs no JS rebuild.

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
2. `classes/traits/singleton.php` — shared `Singleton` trait, then
   `classes/references/tainacan-ids.php` (the ID reference above)
2b. `classes/settings/` — `Features` (which features are on) and `SettingsPage`; must
   load before step 5, which already reads the saved state
3. Presentations: `schema/` → `filters/` → `grouping/` → `rendering/` →
   `PresentationsRepository` → `sync/` (+ `sync/presentations-sync-cli.php` only under
   WP-CLI) → remaining facets/renderers → `PresentationsRequest` →
   `PresentationsShortcode`/`PresentationsPage`
4. Activation hook: `register_activation_hook(__FILE__, [Features::class, 'on_activation'])`
   (fresh install → all features off; installs the presentations table only if it's on),
   then `Features::maybe_migrate()` (see "Feature toggles" below)
5. `classes/metadata-types/register-metadatas.php`, then immediately
   `add_action('tainacan-register-metadata-type', ...)` (only if the `metadata_types`
   feature is on) **outside** the
   `plugins_loaded` cycle — this is deliberate: Tainacan fires
   `tainacan-register-metadata-type` while loading its own main file, before
   `plugins_loaded` runs, so registering it later would miss the hook.
6. `view-modes/`, `related-items/`, `blocks/bibliographic.php`
7. `classes/Plugin.php`, then `Plugin::get_instance()->register()` — this is what
   defers everything else to `plugins_loaded` → `boot()`.

**Convention**: every feature module implements `Module::register()` and uses the
`Singleton` trait. Follow this pattern for new modules rather than inventing a new
bootstrapping style.

## `classes/` map

| Path | Purpose |
|---|---|
| `contracts/` | `Module` interface all feature classes implement |
| `settings/` | `Features` (feature registry + saved state) and `SettingsPage` (Settings > Teatro Musicado SP) |
| `traits/` | `Singleton` trait |
| `references/tainacan-ids.php` | Single source of Tainacan collection/taxonomy/metadatum IDs (PHP constants + JS bridge) |
| `metadata-types/slug-id/` | Custom Tainacan metadata type ("Slug/ID"), extends `Tainacan\Metadata_Types\Metadata_Type` |
| `view-modes/register-viewmodes.php` | Custom Tainacan "extra view modes" (grouped table) for item-relationship display on Pessoa/Companhia collections |
| `related-items/pessoa-related-items-order.php` | Reorders related-item groups on Tainacan item pages via `tainacan-fetch-args`/`posts_results` filters |
| `blocks/bibliographic.php` | Gutenberg block calling `Tainacan\Repositories\Items::get_instance()->fetch()` directly |
| `presentations/` | The `[teatro_apresentacoes]` feature — largest, most actively developed part of the plugin (see below) |

## Feature toggles (`settings/`)

`Plugin::boot()` no longer hardcodes the module list: it registers `SettingsPage`
(always, even without Tainacan) and then every module class returned by
`Features::enabled_modules()`. `Features::definitions()` is the single registry — feature
key → module classes, plus `children` (sub-options). To add a feature: add a module class,
add it to `definitions()`, and add its label/description in `SettingsPage::copy()`.

- State is one option, `tmsp_customizations_features` (`key => 0|1`). Missing key = off; a
  sub-option only counts when its parent is on (`is_enabled()` already enforces this).
- `Features` must stay free of translatable strings: it's read at file load (before `init`),
  and calling `__()` that early triggers WP's just-in-time translation notice. Labels live
  in `SettingsPage`.
- **Defaults**: fresh activation → all off (`on_activation()`); an install that already had
  the plugin active but no option → all on, once (`maybe_migrate()`), so deploying doesn't
  switch the live site off. Reactivation keeps the saved choices.
- Presentations sub-options: `presentations_shortcode`, `presentations_rest`,
  `presentations_admin_page`, `presentations_table`, `presentations_sync`
  (`Features::SYNC_KEY`, registers the `Sync\PresentationsSync` hooks; also forces the
  table on). `Sync\PresentationsReconciler` is registered with the parent
  `presentations` feature, so scheduled backfills always have a handler. `PresentationsRepository::register()`
  only adds its `admin_init` install hook if the table option is on and its REST routes if
  the REST option is on; its transient caching is bypassed when the table option is off.
  `Features::sanitize()` forces the table on when shortcode or REST is on (both query it).
  With REST off, the shortcode emits an empty `data-rest`, so the JS doesn't enhance and the
  server-rendered page still works.
- Turning `presentations_table` (or all of Presentations) off deletes the `tmsp_pres_*`
  transients (`SettingsPage::on_features_updated()`); the DB table itself is never dropped.
  Turning it on runs `PresentationsRepository::install()`.

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

**Data source — read before changing anything here**: the shortcode/REST never
query Tainacan live. They read the flat, read-only projection table
`{$wpdb->prefix}teatro_presentations`, whose source is the option
`tmsp_presentations_source` (`PresentationsRepository::source()`):

- `tainacan` — one row per **published** Espetáculos item whose Peça, Companhia and
  Teatro are also published (otherwise the row is left out). Column mapping lives in
  `sync/espetaculo-projector.php` (one set-based SQL per batch over postmeta keyed by
  metadatum ID + term_relationships; `presentationKind` stores the Tainacan value as
  is). Technical columns (`espetaculo_id` UNIQUE upsert key, `legacy_id` = Slug ID,
  `play_id`/`company_id`/`theater_id`, `source_hash`, `synced_at`) come from
  `PresentationsSchema::technical_column_definitions()` and are deliberately **not**
  in `columns()` (not displayed, not LIKE-searched).
- `csv` — the mock `data/flat-table.csv` (`seed_from_csv()`), kept as the rollback path.

Sync pieces (`classes/presentations/sync/`):
- `PresentationsSync` — hooks (`tainacan-insert`, `tainacan-deleted`,
  `tainacan-deleted-Item_Metadata_Entity`, plus WP `transition_post_status`,
  `untrashed_post`, `deleted_post`, `set_object_terms`, `edited_term`,
  `pre_delete_term`) only mark dirty IDs; `shutdown` resolves Peça/Companhia/Teatro/
  term changes to espetáculos and recomputes them (>200 IDs → persistent queue +
  `tmsp_presentations_process_queue` single event). Never throws into Tainacan:
  failures go to the retry option.
- `PresentationsSyncWriter` — always recomputes from source and upserts by
  `espetaculo_id` only rows whose hash changed (idempotent); bumps
  `tmsp_presentations_data_version`, which is part of every transient key.
- `PresentationsReconciler` — full `reconcile()` (cron `tmsp_presentations_reconcile`
  on the custom `tmsp_every_three_days` schedule, "Conferir agora" button, CLI),
  `backfill()` (builds `{table}_next`, atomic `RENAME` swap, previous table kept as
  `{table}_prev`, sets source=tainacan and turns sync on), `rollback()` (sync off,
  cron cleared, CSV reinstall).
- `SyncState` — log (`tmsp_pres_sync_log`, last 50 runs), queue, retry, lock.
- CLI: `wp tmsp presentations status|backfill|reconcile|verify|rollback`. `verify`
  is a dry-run reconcile that exits non-zero on any divergence — use it after any
  change here.

A schema bump (`SCHEMA_VERSION`) with source `tainacan` never drops data in
`admin_init`: it schedules `tmsp_presentations_backfill` instead. Unpaginated queries
are capped at `PresentationsRepository::MAX_ROWS` (30000; ~25k espetáculos
expected) and results above `CACHE_MAX_ROWS` are not cached.

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
