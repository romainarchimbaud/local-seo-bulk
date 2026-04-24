# CLAUDE.md — Local SEO Bulk Editor

## Plugin overview

WordPress multisite plugin for bulk editing H1, meta title, and meta description across all sites in a network. Values resolve through a three-tier hierarchy: local meta → network entity value → network scope pattern. Supports geolocalized tokens (`%%lsb_ville%%`, `%%lsb_code_postal%%`, `%%lsb_adresse%%`) and Yoast SEO integration.

## Architecture

### Class map

| Class | File | Role |
|---|---|---|
| `LSB_Plugin` | `class-lsb-plugin.php` | Singleton bootstrap |
| `LSB_Meta_Store` | `class-lsb-meta-store.php` | Site-level H1/title/desc meta (Tier 1) |
| `LSB_Network_Store` | `class-lsb-network-store.php` | Network entity values and scope configs (Tier 2) |
| `LSB_Scope_Matcher` | `class-lsb-scope-matcher.php` | Matches posts/terms to scopes |
| `LSB_Resolver` | `class-lsb-resolver.php` | Three-tier resolution logic |
| `LSB_Token_Resolver` | `class-lsb-token-resolver.php` | Replaces `%%lsb_*%%` tokens with site address values |
| `LSB_Network_Entity_Index` | `class-lsb-network-entity-index.php` | Transient index of all network slugs per scope |
| `LSB_Network_CPT_Index` | `class-lsb-network-cpt-index.php` | Transient index of CPTs/taxonomies across network |
| `LSB_Ajax` | `class-lsb-ajax.php` | All `wp_ajax_*` handlers |
| `LSB_Admin_Menu` | `class-lsb-admin-menu.php` | Site-level admin menu + screen options |
| `LSB_Editor_Page` | `class-lsb-editor-page.php` | Site-level bulk editor (`lsb-editor`) |
| `LSB_List_Table` | `class-lsb-list-table.php` | `WP_List_Table` subclass for site editor |
| `LSB_Network_Scope_Page` | `class-lsb-network-scope-page.php` | Network scope CRUD (`lsb-network-scopes`) |
| `LSB_Network_Editor_Page` | `class-lsb-network-editor-page.php` | Network Tier 2 bulk editor (`lsb-network-editor`) |
| `LSB_Settings` | `class-lsb-settings.php` | Site settings (address, kill switch, active types) |
| `LSB_Shortcodes` | `class-lsb-shortcodes.php` | `[lsb_h1]`, `[lsb_title]`, `[lsb_desc]` |
| `LSB_Yoast_Integration` | `class-lsb-yoast-integration.php` | Overrides Yoast title/desc with resolved values |
| `LSB_H1_Replacer` | `class-lsb-h1-replacer.php` | Output-buffer filter replacing first `<h1>` |

## Three-tier resolution

```
Tier 1 (local meta)  →  Tier 2 (network entity value)  →  Tier 3 (scope pattern / fallback)
```

- **Tier 1**: post/term meta per site (`LSB_Meta_Store`)
- **Tier 2**: network option `lsb_entity_{scope_id}` keyed by slug (`LSB_Network_Store`)
- **Tier 3**: scope `patterns` field (h1/title/desc)

`LSB_Resolver::resolve_network_raw($scope_id, $slug, $field)` returns `['raw' => ..., 'tier' => int]`.

## Data storage

| Data | Storage |
|---|---|
| Site-level SEO values | Post/term meta (see `LSB_Meta_Store`) |
| Network scope configs | Site option `lsb_scopes` (array keyed by scope ID) |
| Network entity values | Site option `lsb_entity_{scope_id}` (keyed by slug) |
| Site address (tokens) | Site option `lsb_address` (`['adresse','code_postal','ville']`) |
| Kill switch | Site option `lsb_site_kill_switch` |
| Active editor types | Site option `lsb_editor_types` |
| Entity index | Site transient `LSB_Network_Entity_Index::TRANSIENT` |
| Per-page screen option | User meta `lsb_items_per_page` (default 50) |

## Admin pages

**Site** (`/wp-admin/admin.php`): `lsb-editor` (bulk editor), `lsb-settings` (address, kill switch, types).

**Network** (`/wp-admin/network/admin.php`): `lsb-network-scopes` (scope CRUD), `lsb-network-editor` (Tier 2 per-scope editor).

Both editors use a three-part flexbox toolbar: bulk actions | field tabs (H1/Title/Desc) | pagination.

## JavaScript (assets/js/admin.js)

Single file, vanilla jQuery, no build step. Key behaviors:

- **Dirty tracking**: `updateDirtyCounter()` counts `.lsb-value-input` fields where value differs from `data-initial-value`.
- **Field tab switching**: JS-only toggle of `.lsb-field-panel[data-field]` visibility; updates `data-field` on clear buttons.
- **Save all**: Collects dirty inputs, splits into site rows (`lsb_save_all`) and network rows (`lsb_save_network_all`), fires both in parallel.
- **Bulk clear (site)**: Intercepts `#lsb-editor-form` submit for `lsb_bulk_clear` action; resets checked rows client-side via `lsb_save_row`.
- **Bulk clear (network)**: Intercepts `#lsb-network-editor-form` submit; resets checked network rows via `lsb_save_network_row`.
- **Token preview**: Debounced AJAX to `lsb_preview_token` on input change.

## CSS (assets/css/admin.css)

No build step. Key selectors:

- `.lsb-toolbar` — site editor top bar (type selector, search, bulk actions)
- `.lsb-tabs-bar` — three-part flex bar (bulk actions / tabs / pagination)
- `.lsb-tabs-bar .lsb-bulk-bar` — bulk actions zone (left, aligned to bottom)
- `.lsb-tabs-bar .lsb-tab-header.nav-tab-wrapper .nav-tab` — compact tab padding (8px 12px)
- `.lsb-scope-tabs-bar .lsb-scope-actions` — network editor top-right (CSV + save all + counter)
- `.lsb-dirty` — row with unsaved changes
- `.wp-list-table .check-column input[type=checkbox]` — consistent checkbox margin

## AJAX actions

| Action | Handler | Auth |
|---|---|---|
| `lsb_save_row` | `LSB_Ajax::save_row` | `manage_options` |
| `lsb_save_all` | `LSB_Ajax::save_all` | `manage_options` |
| `lsb_preview_token` | `LSB_Ajax::preview_token` | `manage_options` |
| `lsb_import_csv` | `LSB_Ajax::import_csv` | `manage_options` |
| `lsb_csv_template` | `LSB_Ajax::download_csv_template` | `manage_options` |
| `lsb_save_network_row` | `LSB_Ajax::save_network_row` | `manage_network_options` |
| `lsb_save_network_all` | `LSB_Ajax::save_network_all` | `manage_network_options` |
| `lsb_delete_network_slug` | `LSB_Ajax::delete_network_slug` | `manage_network_options` |
| `lsb_import_network_csv` | `LSB_Ajax::import_network_csv` | `manage_network_options` |
| `lsb_network_csv_template` | `LSB_Ajax::download_network_csv_template` | `manage_network_options` |

All actions use nonce `lsb_ajax_nonce`.

## CSV

**Format — site**: `slug, h1, title, desc`. Slug matches `post_name` (posts) or term slug. Empty columns ignored.

**Format — network**: `scope_id, slug, h1, title, desc`. Slug is full permalink path on multisite (e.g. `/bb-stores/produit-x/`); plain `post_name` also accepted as fallback.

**Delimiter**: comma or semicolon, auto-detected on import. Template download pre-fills all known slugs with current saved values.

## Tokens

| Token | Resolved value |
|---|---|
| `%%lsb_ville%%` | Site city |
| `%%lsb_code_postal%%` | Site postal code |
| `%%lsb_adresse%%` | Site street address |
| `%%sitename%%` | Blog name |

## Version

Bump `LSB_VERSION` in `local-seo-bulk.php` (both plugin header `Version:` and the `define()`) whenever JS or CSS changes to bust browser cache.
