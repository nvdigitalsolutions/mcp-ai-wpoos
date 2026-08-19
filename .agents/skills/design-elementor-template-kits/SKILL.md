---
name: design-elementor-template-kits
description: Designs, generates, and packages Elementor template kits that import correctly — Envato Template Kit Import format, Elementor's native Kit format, and raw _elementor_data formats. Use when building/exporting template kits, fixing kit import errors ("newer Elementor Kit" warning, "nothing imports", broken images after import), migrating a site design into Elementor Pro, or packaging zips for any Elementor import pipeline. Covers verified manifest schemas, doc_type/template_type values, image URL sideloading rules, zip packaging constraints, and kit site-settings.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
---

# Elementor Template Kits

Use this skill when creating Elementor template kits, debugging why a kit ZIP
fails to import, or migrating an existing site design into Elementor Pro.

All format contracts in this skill were verified against source:

- Envato `common-repository/template-kit-import` → `vendor/template-kit-import/inc/`
  (`class-importer.php`, `class-builder.php`, `class-builder-elementor.php`,
  `class-required-plugin.php`)
- Elementor `elementor/elementor` → `app/modules/import-export/**` (processes,
  runners, `compatibility/envato.php`), `core/base/document.php`,
  `includes/template-library/sources/{local,base}.php`,
  `includes/template-library/classes/class-import-images.php`,
  `includes/controls/media.php`

Re-verify against those files when a format breaks after a plugin update.

## When to use this skill

Trigger when ANY of the following is true:

- Building or exporting an Elementor template kit (for the Envato Template Kit
  Import plugin, Elementor's Import Kit tool, or a custom import tool).
- A kit ZIP fails to import, imports nothing, or shows "This kit is in the
  newer 'Elementor Kit' format".
- Images in imported templates are broken or replaced with placeholders.
- The task mentions "template kit", "kit zip", "Envato kit", "Elementor kit
  import", "manifest_version", or "templates[] manifest".
- Migrating an existing site (React, HTML, another builder) into Elementor Pro.

## The three import pipelines — never mix their formats

| Pipeline | Format key | Manifest shape | Template file shape |
|---|---|---|---|
| Envato Template Kit Import plugin | `manifest_version` + `page_builder` (NO `version` key) | `templates[]` list with `name`/`type`/`source`/`metadata.template_type` | Elementor library JSON `{content, page_settings, type, title, version}` |
| Elementor native Import Kit tool | none needed (`version: "2.0"` written by exporter) | `content.<post_type>.<id>` + `templates.<id>` maps with `doc_type` | `{content, settings, metadata}` |
| Custom import tools (e.g. NV oOS `import_elementor_template_kit`) | tool-specific | tool-specific | bare **array** of elements stored verbatim as `_elementor_data` |

Elementor's native import tool contains an **Envato compatibility adapter**
(`compatibility/envato.php`, triggered by `manifest_version`), so a correct
Envato-format ZIP imports through BOTH the Envato plugin and Elementor's own
tool. Prefer the Envato format unless the goal is real page creation with
front-page assignment (native) or verbatim meta injection (custom tool).

## Envato kit contract

`manifest.json` at ZIP root (the plugin scans the extraction root for it):

```json
{
  "manifest_version": "1.0.0",
  "page_builder": "elementor",
  "title": "Kit Name",
  "name": "Kit Name",
  "templates": [
    {
      "name": "Home",
      "type": "page",
      "source": "templates/home.json",
      "screenshot": "media/thumb.jpg",
      "thumbnail": "",
      "metadata": { "template_type": "single-home" }
    },
    {
      "name": "Site Header",
      "type": "header",
      "source": "templates/site-header.json",
      "thumbnail": "",
      "elementor_pro_required": true,
      "metadata": { "template_type": "section-header", "elementor_pro_required": true }
    },
    {
      "name": "Kit Styles",
      "type": "kit",
      "source": "templates/global.json",
      "thumbnail": "",
      "metadata": { "template_type": "global-styles" }
    }
  ],
  "required_plugins": [
    { "name": "Elementor", "file": "elementor/elementor.php", "min_version": "3.25.0" },
    { "name": "Elementor Pro", "file": "elementor-pro/elementor-pro.php" }
  ],
  "required_css": [
    { "name": "Design System", "file": "assets/kit.css", "description": "..." }
  ]
}
```

Rules:

- **`version` key is poison.** `Importer::install_template_kit_zip_to_db`
  checks `! empty( $manifest_data['version'] )` AFTER the `manifest_version`
  check and overwrites the builder type to "elementor-kit" — the kit is then
  refused with the "newer Elementor Kit" message. Envato manifests must never
  contain `version`.
- `metadata.template_type` values the UI groups on: `single-page`,
  `single-home`, `landing-page`, `single-post`, `single-404`,
  `archive-*`, `section-header`, `section-footer`, `section-popup`,
  `section-*` (hero/about/faq/contact/cta/...), `global-styles`.
- `elementor_pro_required: true` goes BOTH at the top level of the manifest
  template entry (the UI checks `$template['elementor_pro_required']`) and in
  the file's `metadata` (the importer checks
  `$local_json_data['metadata']['elementor_pro_required']` to downgrade the
  type to `page` when Pro is absent).
- The first template's `screenshot` becomes the kit-level thumbnail.
- `required_plugins` entries need `file` (e.g. `elementor/elementor.php`) and
  optional `min_version`; `required_css` entries need `file` (path in ZIP).
- `global.json` (`metadata.template_type: "global-styles"`) carries the kit
  site settings in `page_settings`; the plugin sets `_elementor_template_type:
  kit` and `elementor_active_kit` after importing it.
- ZIP may only contain extensions `json`, `jpg`, `png`, `css`, `html` — the
  plugin filters every entry by extension before extracting.

Template files (Envato) are Elementor library JSON — include BOTH
`page_settings` (Envato plugin path via `Source_Local::import_template`) and
`settings` (Elementor native path via `Document::import`, which reads only
`settings`), so the same file works in both pipelines:

```json
{
  "content": [ ...elements... ],
  "page_settings": { "hide_title": "yes" },
  "settings": { "hide_title": "yes" },
  "version": "0.4",
  "title": "Home",
  "type": "page",
  "metadata": { "template_type": "single-home" }
}
```

`type` values: `page`, `header`, `footer`, `popup`, `section`, `container`,
`kit` (global styles file). Extra keys are ignored by both importers.

## Elementor native kit contract

ZIP layout (JSON files ONLY — the extractor is called with
`[ 'json', 'xml' ]`):

```
manifest.json
site-settings.json
content/page/<id>.json        (one per post, id = manifest key)
templates/<template-id>.json  (Theme Builder parts; manifest key = basename)
```

`manifest.json` (import side reads `title`/`name` for the session, `content`
and `templates` maps, `plugins`):

```json
{
  "name": "kit-slug",
  "title": "Kit Name",
  "description": "...",
  "version": "2.0",
  "content": {
    "page": {
      "home": { "title": "Home", "doc_type": "wp-page", "show_on_front": true, "thumbnail": "" },
      "about": { "title": "About", "doc_type": "wp-page", "show_on_front": false, "thumbnail": "" }
    }
  },
  "templates": {
    "site-header": { "title": "Site Header", "doc_type": "header", "thumbnail": "" },
    "site-footer": { "title": "Site Footer", "doc_type": "footer", "thumbnail": "" },
    "site-mobile-menu": { "title": "Mobile Menu Popup", "doc_type": "popup", "thumbnail": "" }
  },
  "plugins": []
}
```

Rules:

- Every content/template manifest entry needs a `thumbnail` key (even empty) —
  `Document::import()` reads `$data['import_settings']['thumbnail']` directly
  and PHP 8 warns on missing keys.
- `doc_type`: `wp-page` for WP pages under `content.page`; `header`, `footer`,
  `popup` (Pro) for `templates`.
- The `templates` runner only runs when `Utils::has_pro()` — header/footer/
  popup parts need Elementor Pro.
- Set `plugins: []`. The Plugins runner installs/activates every entry from
  wp.org — Elementor Pro is NOT hosted there, so listing it produces a failed
  install; an empty array skips the runner entirely.
- Do NOT put a `theme` object in `site-settings.json` unless you want the
  import to download and SWITCH the active theme. Omit it; recommend Hello
  Elementor in docs instead.

`site-settings.json` (Site_Settings runner requires non-empty `settings`):

```json
{
  "settings": {
    "custom_colors": [
      { "_id": "ink", "title": "Ink", "color": "#252522" }
    ],
    "custom_typography": [
      {
        "_id": "primary",
        "title": "Primary",
        "typography_typography": "custom",
        "typography_font_family": "Manrope",
        "typography_font_weight": "400",
        "typography_line_height": { "unit": "em", "size": 1.6, "sizes": [] }
      }
    ],
    "space_between_widgets": { "column": "24", "row": "0", "isLinked": false, "unit": "px" }
  }
}
```

Kit typography repeaters have NO `typography_font_size` (size is per-widget);
include family/weight/transform/decoration/style/line-height/letter-spacing/
word-spacing. `space_between_widgets` must use `column`/`row`/`isLinked`.

Document files (`content/**` and `templates/**`) use
`{ "content": [...], "settings": {...}, "metadata": {} }` — exactly
`Document::get_export_data()`'s shape. `Document::import()` reads `content`
and `settings` (NOT `page_settings`), then saves any `metadata` keys as post
meta.

## Images — the #1 kit-breaking issue

Elementor runs `on_import` on every media control during any import.
`Control_Media::on_import` → `Import_Images::import` → `wp_safe_remote_get`:

- **Absolute URLs** are downloaded into the Media Library, the attachment id
  is set, and the URL is replaced with the local one. This is the intended
  flow (how Envato kits work) and needs a publicly reachable URL at import time.
- **Relative URLs** (`/wp-content/uploads/...`) fail `wp_http_validate_url` →
  the image is replaced with Elementor's placeholder. Kit images "break".

Strategy:

1. Keep relative URLs in formats stored verbatim (custom tools that write
   `_elementor_data` without `on_import` processing — those survive).
2. For Envato/native formats, make image URLs absolute and point them at a
   location that resolves during import — typically the target site itself
   after pre-uploading media to the referenced path (e.g.
   `https://site.com/wp-content/uploads/sascha/x.jpg`).
3. Parametrize the generator with a `--media-base` flag and print a warning
   when it is missing.
4. `Media_Mapper` (transient `elementor_media_mapping` + a
   `media-mapping.json` media ZIP) only exists in the Kit Library flow — a
   locally uploaded ZIP does NOT get media mapping, so do not rely on it.

## Element/document rules

- Flexbox-first: `elType: "container"` roots, widgets with `widgetType`
  (`heading`, `text-editor`, `image`, `button`, `icon-list`, `nav-menu`).
  Layout in Elementor settings; typography/character in a companion CSS file
  under a `.kit-name` scope class.
- Element `id`s must be unique within a file; any string works (`c_001`).
  `Source_Local` replaces ids on import anyway.
- Page setting `hide_title: "yes"` suppresses the theme title.
- The Pro `nav-menu` widget needs `menu: ""` plus a manual WP menu assignment
  after import — menu data is site-specific, not template data.
- Popup `page_settings` (width/height/position/entrance_animation/
  exit_animation/overlay_background_color/prevent_scroll/triggers/timing) ride
  along as document settings; the click trigger on a custom selector
  (`.mobile-menu-toggle`) may need re-saving in Popup Settings after import.
- `elementor_pro_conditions` (array of `type/name/sub_name/sub_id` strings in
  file `metadata`) is understood by the Envato adapter, but instructing users
  to set display conditions manually is more reliable.

## Packaging and verification

- Write Zips with a dependency-free Node writer (deflate + CRC-32, UTF-8 flag
  bit 0x0800, forward-slash names, no directory entries). See
  `elementor-kit/scripts/lib/zip.mjs` in this project for the implementation.
- `manifest.json` must sit at the ZIP root for the Envato plugin.
- Verify with an independent parser before shipping:
  `python -c "import zipfile; z=zipfile.ZipFile('kit.zip'); print(z.testzip()); print(z.namelist())"`
- Validate generated JSON: manifests parse, every `source`/`content`/`templates`
  reference resolves to a file, element ids unique per file, Envato manifest
  has `manifest_version` + `page_builder` + `title` and NO `version`.

## This project's tooling

The Sascha Torres kit generator demonstrates all three formats from one set of
page definitions (`elementor-kit/scripts/`):

- `generate-kit.mjs` — element builders (`container`, `row`, `heading`,
  `text`, `image`, `button`, `eyebrow`, `editorialList`, `numberedList`,
  `section`, `splitHero`, `siteHeader`, `siteFooter`, `siteMobileMenu`),
  exported `pages` map and `PAGE_TITLES`; writes the custom-tool format
  (bare element arrays) + its manifest.
- `generate-formats.mjs` — imports those builders and emits `dist/native/`,
  `dist/envato/`, `dist/plugin/` plus the three ZIPs; `--media-base=` rewrites
  `/wp-content/uploads/` URLs to absolute for envato/native; self-validates.
- `lib/zip.mjs` — dependency-free ZIP writer.
- `validate-kit.mjs` — validates the custom-tool format.

Commands:

```bash
node elementor-kit/scripts/generate-kit.mjs
node elementor-kit/scripts/validate-kit.mjs
node elementor-kit/scripts/generate-formats.mjs
node elementor-kit/scripts/generate-formats.mjs --media-base=https://your-site.com
```

Reuse this structure when building kits for other sites: keep page
definitions in one file, generate each format separately, never share a
manifest between formats.

## Post-import checklist to hand to the user

1. Install the kit CSS (Envato: accept `required_css` into the Customizer;
   native: copy into Elementor → Site Settings → Custom CSS).
2. Upload media to the referenced uploads path BEFORE importing envato/native
   zips (see Images above).
3. Set header/footer display conditions (Entire Site) in Theme Builder.
4. Create the WP menu and assign it in the nav-menu widget.
5. Re-save popup trigger + set popup display conditions.
6. Swap static contact forms for Elementor Pro Forms (or another form system).
