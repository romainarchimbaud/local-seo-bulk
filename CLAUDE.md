# CLAUDE.md — Local SEO Bulk Editor

## Plugin overview

WordPress multisite plugin for bulk editing H1, meta title and meta description across all sites in a network. Values are resolved through a three-tier hierarchy: network pattern → local override → fallback. Supports geolocalized tokens (`%%lsb_ville%%`, `%%lsb_code_postal%%`, `%%lsb_adresse%%`) and Yoast SEO integration.

## Environment

The plugin runs inside a Docker WordPress container. Always use Docker for any WP-CLI or PHP commands:

```bash
make bash   # Shell inside WordPress container
```

Access points:
- Network admin: `http://localhost:8081/wp-admin/network/`
- Site admin (example): `http://localhost:8081/bb-stores/wp-admin/`

## Architecture

### Class map

| Class | File | Role |
|---|---|---|
| `LSB_Plugin` | `class-lsb-plugin.php` | Singleton bootstrap: instantiates all classes, wires hooks |
| `LSB_Meta_Store` | `class-lsb-meta-store.php` | Read/write site-level H1/title/desc meta (Tier 1) |
| `LSB_Network_Store` | `class-lsb-network-store.php` | Read/write network-level entity values and scope configs (Tier 2) |
| `LSB_Scope_Matcher` | `class-lsb-scope-matcher.php` | Finds which scope a post/term belongs to; resolves matching objects for a scope |
| `LSB_Resolver` | `class-lsb-resolver.php` | Three-tier resolution: Tier 1 (local meta) → Tier 2 (network pattern) → Tier 3 (fallback) |
| `LSB_Token_Resolver` | `class-lsb-token-resolver.php` | Replaces `%%lsb_*%%` tokens with site address values |
| `LSB_Network_Entity_Index` | `class-lsb-network-entity-index.php` | Cached transient index of all network slugs per scope (`LSB_Network_Entity_Index::TRANSIENT`) |
| `LSB_Network_CPT_Index` | `class-lsb-network-cpt-index.php` | Cached index of all registered CPTs and taxonomies across the network |
| `LSB_Ajax` | `class-lsb-ajax.php` | All `wp_ajax_*` handlers: save row, save all, preview token, import CSV, download CSV template |
| `LSB_Admin_Menu` | `class-lsb-admin-menu.php` | Registers site-level admin menu + screen options hook |
| `LSB_Editor_Page` | `class-lsb-editor-page.php` | Site-level bulk editor page (`lsb-editor`) |
| `LSB_List_Table` | `class-lsb-list-table.php` | `WP_List_Table` subclass used by the site editor |
| `LSB_Network_Scope_Page` | `class-lsb-network-scope-page.php` | Network admin CRUD page for scopes (`lsb-network-scopes`) |
| `LSB_Network_Editor_Page` | `class-lsb-network-editor-page.php` | Network admin bulk editor for Tier 2 entity values (`lsb-network-editor`) |
| `LSB_Settings` | `class-lsb-settings.php` | Site-level settings page (address, kill switch, active types/scopes) |
| `LSB_Shortcodes` | `class-lsb-shortcodes.php` | `[lsb_h1]`, `[lsb_title]`, `[lsb_desc]` shortcodes |
| `LSB_Yoast_Integration` | `class-lsb-yoast-integration.php` | Overrides Yoast title/desc variables with resolved values |
| `LSB_H1_Replacer` | `class-lsb-h1-replacer.php` | Output-buffer filter that replaces the first `<h1>` on the page |

### Instantiation order (class-lsb-plugin.php)

Dependency-sensitive order in `instantiate()`:
1. Stores (`LSB_Meta_Store`, `LSB_Network_Store`)
2. `LSB_Scope_Matcher` (needs `LSB_Network_Store`)
3. `LSB_Resolver` (needs meta store, network store, scope matcher, token resolver)
4. `LSB_Network_CPT_Index`, `LSB_Network_Entity_Index` (needs network store + scope matcher)
5. `LSB_Ajax` (needs entity index — must come after step 4)
6. Editor pages, admin menu

## Three-tier resolution

```
Tier 1 (local meta)  →  Tier 2 (network entity value)  →  Tier 3 (scope pattern / fallback)
```

- **Tier 1**: `lsb_meta_{field}_{type}_{id}` post/term meta per site.
- **Tier 2**: Network option `lsb_entity_{scope_id}` — per-slug values set in the network editor.
- **Tier 3**: Scope `patterns` field (h1/title/desc), set on the scope form.

`LSB_Resolver::resolve_network_raw($scope_id, $slug, $field)` returns `['raw' => ..., 'tier' => int]`.

## Data storage

| Data | Storage |
|---|---|
| Site-level SEO values | Post meta / term meta (`_lsb_{field}` or similar, see `LSB_Meta_Store`) |
| Network scope configs | Site option `lsb_scopes` (array keyed by scope ID) |
| Network entity values | Site option `lsb_entity_{scope_id}` (keyed by slug) |
| Site address (tokens) | Site option `lsb_address` (`['adresse','code_postal','ville']`) |
| Kill switch | Site option `lsb_site_kill_switch` |
| Active editor types | Site option `lsb_editor_types` |
| Entity index | Site transient `LSB_Network_Entity_Index::TRANSIENT` — flush with `delete_site_transient()` |
| Per-page screen option | User meta `lsb_items_per_page` |

## Admin pages

### Site admin (`/wp-admin/admin.php`)

| Slug | Page |
|---|---|
| `lsb-editor` | Bulk editor — type/scope selector, field tabs (H1/title/desc), paginated list table |
| `lsb-settings` | Address, kill switch, active post types/scopes/taxonomies |

### Network admin (`/wp-admin/network/admin.php`)

| Slug | Page |
|---|---|
| `lsb-network-scopes` | Scope CRUD (checkbox list, bulk delete, clickable IDs) |
| `lsb-network-editor` | Network-level Tier 2 editor (per-scope, per-slug, paginated) |

## JavaScript (assets/js/admin.js)

Single file, vanilla jQuery, no build step. Key behaviors:

- **Dirty tracking**: `updateDirtyCounter()` counts all `.lsb-value-input` fields where current value differs from `data-initial-value`. Counter shows total dirty fields across all tabs.
- **Field tab switching**: JS-only, no page reload. Toggles `.lsb-field-panel[data-field]` visibility and updates `.lsb-clear-row`/`.lsb-clear-network-row` `data-field` attribute.
- **Save all**: Collects all dirty inputs, splits into site rows (`lsb_save_all`) and network rows (`lsb_save_network_all`), fires both in parallel.
- **Token preview**: Debounced AJAX call to `lsb_preview_token` on input, shows resolved value under the input.

## CSS (assets/css/admin.css)

No build step — edited directly. Key selectors:
- `.lsb-toolbar` — top bar on site editor (type selector + search + bulk actions)
- `.lsb-tabs-bar` — flex bar containing nav tabs + pagination or field meta
- `.lsb-scope-tabs-bar .lsb-scope-actions` — network editor top-right (CSV import + save all + dirty counter)
- `.lsb-dirty` — row-level class applied when any field in that row is unsaved

## AJAX actions

| Action | Handler | Auth |
|---|---|---|
| `lsb_save_row` | `LSB_Ajax::save_row` | `manage_options` |
| `lsb_save_all` | `LSB_Ajax::save_all` | `manage_options` |
| `lsb_preview_token` | `LSB_Ajax::preview_token` | `manage_options` |
| `lsb_save_network_row` | `LSB_Ajax::save_network_row` | `manage_network_options` |
| `lsb_save_network_all` | `LSB_Ajax::save_network_all` | `manage_network_options` |
| `lsb_delete_network_slug` | `LSB_Ajax::delete_network_slug` | `manage_network_options` |
| `lsb_import_csv` | `LSB_Ajax::import_csv` | `manage_options` |
| `lsb_csv_template` | `LSB_Ajax::download_csv_template` | `manage_options` |
| `lsb_import_network_csv` | `LSB_Ajax::import_network_csv` | `manage_network_options` |
| `lsb_network_csv_template` | `LSB_Ajax::download_network_csv_template` | `manage_network_options` |

All AJAX actions use nonce `lsb_ajax_nonce`.

## CSV import format

**Site-level**: `slug, h1, title, desc` — slug matches `post_name` (posts) or term slug. Empty columns ignored.

**Network-level**: `scope_id, slug, h1, title, desc` — slug is the entity slug within the scope.

The CSV template download pre-fills all known slugs with their existing saved values.

## Tokens

Available in any H1/title/desc value field:

| Token | Resolved value |
|---|---|
| `%%lsb_ville%%` | Site city (`lsb_address['ville']`) |
| `%%lsb_code_postal%%` | Site postal code |
| `%%lsb_adresse%%` | Site street address |
| `%%sitename%%` | Blog name (`get_bloginfo('name')`) |

## Screen options

All editor pages expose a "Éléments par page" screen option stored as user meta `lsb_items_per_page`. Defaults to 50. Applies to:
- Site editor (`LSB_List_Table::prepare_items`)
- Network editor entity list
- Network scopes list

## Version

Bump `LSB_VERSION` in `local-seo-bulk.php` (both the plugin header `Version:` and the `define()`) whenever JS or CSS changes to bust browser cache.
