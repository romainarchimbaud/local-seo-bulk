# Local SEO Bulk Editor

Plugin WordPress d'édition en masse des champs SEO (H1, meta title, meta description) par entité, avec système de tokens géolocalisés et support multisite.

**Version :** 0.6.0 | **WordPress :** 6.0+ | **PHP :** 7.4+ | **Réseau :** oui

---

## Fonctionnement

Le plugin résout les champs SEO en suivant une hiérarchie à 3 niveaux :

1. **Local** — valeur saisie manuellement pour une page/terme spécifique
2. **Entité réseau** — valeur définie par slug au niveau réseau
3. **Pattern réseau** — modèle avec tokens défini dans un scope

Si aucun niveau ne retourne de valeur, Yoast SEO prend le relais normalement.

---

## Tokens disponibles

| Token | Shortcode | Description |
|---|---|---|
| `%%lsb_ville%%` | `[lsb_ville]` | Ville du site |
| `%%lsb_code_postal%%` | `[lsb_code_postal]` | Code postal |
| `%%lsb_adresse%%` | `[lsb_adresse]` | Adresse complète |
| `%%lsb_departement%%` | `[lsb_departement]` | Département du site |
| `[lsb_h1]` | — | Valeur H1 résolue du post courant |

Les tokens Yoast natifs (`%%title%%`, `%%sitename%%`, `%%sep%%`, etc.) sont également supportés dans les patterns.

---

## Scopes (multisite)

Les scopes sont des règles réseau qui associent un pattern SEO à un type de contenu (CPT ou taxonomie). Chaque scope peut filtrer :

- `all` — tous les éléments du type
- `parents` — éléments de premier niveau uniquement
- `children` — éléments enfants uniquement
- `custom_meta` — filtre par clé/valeur de meta

Les scopes se configurent dans l'administration réseau.

---

## Remplacement automatique du H1

Le plugin peut remplacer le premier `<h1>` de la page par la valeur résolue. Ce comportement est contrôlé par :

- Le toggle `replace_h1` du scope correspondant
- Le réglage "Force H1" par type de contenu (dans les réglages du site)
- Le kill switch global (désactive tout le plugin sur un site)

---

## Interface d'administration

### Réglages (par site)

- Adresse du site (ville, code postal, adresse, département)
- Types de contenu visibles dans l'éditeur
- Force H1 par type
- Kill switch

### Éditeur (par site)

Tableau d'édition inline par type de contenu avec onglets H1 / Title / Description. Sauvegarde ligne par ligne ou en masse. Vidage en masse des champs sélectionnés. Import/export CSV avec patch DOM inline (sans rechargement).

### Pages réseau

Trois pages dédiées dans l'administration réseau :

- **Scopes** (`lsb-network-scopes`) — CRUD des scopes : création, édition, suppression, filtres (all / parents / children / custom_meta), toggle replace_h1.
- **Éditeur réseau** (`lsb-network-editor`) — Édition Tier 2 par scope : valeurs par slug d'entité, vidage en masse, rafraîchissement de l'index de cache, import/export CSV réseau avec patch DOM inline.
- **Adresses SEO** (`lsb-network-addresses`) — Adresses centralisées par site : ville, code postal, adresse, département. Import/export CSV. Prefill depuis un champ ACF réseau (`lsb_network_address_acf_field`).

---

## Adresses réseau

Chaque site du réseau peut avoir une adresse géolocalisée stockée dans l'option réseau `lsb_network_seo_addresses`. Ces valeurs alimentent les tokens `%%lsb_*%%` et les shortcodes `[lsb_*]`.

La page **Adresses SEO** permet :
- D'éditer les adresses site par site en ligne
- D'importer un CSV en masse (patch DOM sans rechargement)
- D'exporter le CSV courant
- De pré-remplir depuis un champ ACF réseau

**Colonnes CSV adresses :** `blog_id, ville, code_postal, adresse, departement`

---

## CSV

**Colonnes site :** `slug, h1, title, desc`

**Colonnes réseau :** `scope_id, slug, h1, title, desc` — le slug est le chemin complet du permalink multisite (ex. `/bb-stores/produit-x/`), le `post_name` simple est accepté en fallback.

**Colonnes adresses réseau :** `blog_id, ville, code_postal, adresse, departement`

Le délimiteur (virgule ou point-virgule) est détecté automatiquement à l'import.

Les lignes commençant par `#` sont ignorées.

Les templates CSV (téléchargeables depuis chaque page) sont pré-remplis avec les slugs, blog_ids et valeurs actuellement enregistrées.

---

## Actions AJAX

| Action | Portée | Capability |
|---|---|---|
| `lsb_save_row` | Site | `manage_options` |
| `lsb_save_all` | Site | `manage_options` |
| `lsb_preview_token` | Site | `manage_options` |
| `lsb_import_csv` | Site | `manage_options` |
| `lsb_csv_template` | Site | `manage_options` |
| `lsb_save_network_row` | Réseau | `manage_network_options` |
| `lsb_save_network_all` | Réseau | `manage_network_options` |
| `lsb_delete_network_slug` | Réseau | `manage_network_options` |
| `lsb_import_network_csv` | Réseau | `manage_network_options` |
| `lsb_network_csv_template` | Réseau | `manage_network_options` |
| `lsb_save_network_address_row` | Réseau | `manage_network_options` |
| `lsb_save_network_address_all` | Réseau | `manage_network_options` |
| `lsb_import_network_addresses_csv` | Réseau | `manage_network_options` |
| `lsb_export_network_addresses_csv` | Réseau | `manage_network_options` |
| `lsb_network_address_csv_template` | Réseau | `manage_network_options` |
| `lsb_prefill_network_addresses` | Réseau | `manage_network_options` |
| `lsb_refresh_entity_index` | Réseau | `manage_network_options` |
| `lsb_create_scope` | Réseau | `manage_network_options` |
| `lsb_delete_scope` | Réseau | `manage_network_options` |

Toutes les actions utilisent le nonce `lsb_ajax_nonce`.

---

## Stockage des données

| Donnée | Stockage |
|---|---|
| Valeurs SEO site (H1/title/desc) | Meta post/terme par site (`LSB_Meta_Store`) |
| Configs des scopes | Option réseau `lsb_scopes` (tableau indexé par scope ID) |
| Valeurs entités réseau | Option réseau `lsb_entity_{scope_id}` (indexé par slug) |
| Adresses géolocalisées | Option réseau `lsb_network_seo_addresses` (indexé par blog_id) |
| Champ ACF adresse | Option réseau `lsb_network_address_acf_field` |
| Kill switch | Option site `lsb_site_kill_switch` |
| Types actifs dans l'éditeur | Option site `lsb_editor_types` |
| Index d'entités réseau | Transient site `lsb_entity_index_{scope_id}` |
| Écran : items par page | User meta `lsb_items_per_page` (défaut : 50) |

---

## Structure

```
local-seo-bulk/
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── class-lsb-plugin.php              # Bootstrap singleton
│   ├── class-lsb-resolver.php            # Résolution 3 niveaux
│   ├── class-lsb-token-resolver.php      # Substitution des tokens %%lsb_*%%
│   ├── class-lsb-scope-matcher.php       # Correspondance scope/entité
│   ├── class-lsb-meta-store.php          # Lecture/écriture meta locale
│   ├── class-lsb-network-store.php       # Options réseau (scopes, entités)
│   ├── class-lsb-h1-replacer.php         # Remplacement H1 (output buffer)
│   ├── class-lsb-yoast-integration.php   # Filtres Yoast + variables custom
│   ├── class-lsb-settings.php            # Page de réglages
│   ├── class-lsb-editor-page.php         # Éditeur site
│   ├── class-lsb-network-editor-page.php # Éditeur réseau Tier 2
│   ├── class-lsb-network-scope-page.php  # CRUD scopes réseau
│   ├── class-lsb-network-address-page.php# Page adresses SEO réseau
│   ├── class-lsb-ajax.php                # Handlers AJAX
│   ├── class-lsb-csv-handler.php         # Import/export CSV (site + réseau)
│   ├── class-lsb-shortcodes.php          # Shortcodes [lsb_*]
│   ├── class-lsb-list-table.php          # Tableau WP_List_Table
│   ├── class-lsb-network-cpt-index.php
│   ├── class-lsb-network-entity-index.php
│   └── class-lsb-admin-menu.php
├── local-seo-bulk.php                    # Point d'entrée
└── uninstall.php
```

---

## Notes

- Plugin développé pour multisite WP
- Non destiné à être publié sur le répertoire WordPress.org
- Testé avec Yoast SEO, ACF Pro, WordPress multisite
