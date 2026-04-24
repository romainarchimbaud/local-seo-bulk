# Local SEO Bulk Editor

Plugin WordPress d'édition en masse des champs SEO (H1, meta title, meta description) par entité, avec système de tokens géolocalisés et support multisite.

**Version :** 0.4.2 | **WordPress :** 6.0+ | **PHP :** 7.4+ | **Réseau :** oui

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
| `[lsb_h1]` | — | Valeur H1 résolue du post courant |

Les tokens Yoast natifs (`%%title%%`, `%%sitename%%`, etc.) sont également supportés dans les patterns.

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

- Adresse du site (ville, code postal, adresse)
- Types de contenu visibles dans l'éditeur
- Force H1 par type
- Kill switch

### Éditeur (par site)

Tableau d'édition inline par type de contenu avec onglets H1 / Title / Description. Sauvegarde ligne par ligne ou en masse. Vidage en masse des champs sélectionnés. Import/export CSV.

### Éditeur réseau

Même interface au niveau réseau, par scope. Valeurs par slug d'entité, vidage en masse, rafraîchissement de l'index de cache, import/export CSV réseau.

---

## CSV

**Colonnes site :** `slug, h1, title, desc`

**Colonnes réseau :** `scope_id, slug, h1, title, desc` — le slug est le chemin complet du permalink multisite (ex. `/bb-stores/produit-x/`), le `post_name` simple est accepté en fallback.

Le délimiteur (virgule ou point-virgule) est détecté automatiquement à l'import.

Les lignes commençant par `#` sont ignorées.

---

## Structure

```
local-seo-bulk/
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── class-lsb-plugin.php           # Bootstrap singleton
│   ├── class-lsb-resolver.php         # Résolution 3 niveaux
│   ├── class-lsb-token-resolver.php   # Substitution des tokens
│   ├── class-lsb-scope-matcher.php    # Correspondance scope/entité
│   ├── class-lsb-meta-store.php       # Lecture/écriture meta locale
│   ├── class-lsb-network-store.php    # Options réseau (scopes, entités)
│   ├── class-lsb-h1-replacer.php      # Remplacement H1 (output buffer)
│   ├── class-lsb-yoast-integration.php# Filtres Yoast + variables custom
│   ├── class-lsb-settings.php         # Page de réglages
│   ├── class-lsb-editor-page.php      # Éditeur site
│   ├── class-lsb-network-editor-page.php # Éditeur réseau
│   ├── class-lsb-ajax.php             # Handlers AJAX
│   ├── class-lsb-shortcodes.php       # Shortcodes [lsb_*]
│   ├── class-lsb-list-table.php       # Tableau WP_List_Table
│   ├── class-lsb-network-cpt-index.php
│   ├── class-lsb-network-entity-index.php
│   ├── class-lsb-network-scope-page.php
│   └── class-lsb-admin-menu.php
├── local-seo-bulk.php                 # Point d'entrée
└── uninstall.php
```

---

## Notes

- Plugin développé pour le réseau Decostory (intérieur/décoration)
- Non destiné à être publié sur le répertoire WordPress.org
- Testé avec Yoast SEO, ACF Pro, WordPress multisite
