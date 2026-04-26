# Multilingual Content Toolkit

> AI-powered translation and localization for WordPress and WooCommerce: 10 tools covering
> auto-translation, translation memory, RTL optimization, and multilingual SEO.

| | |
|---|---|
| **Activation setting** | `enable_multilingual_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Multilingual |
| **Tools** | 10 |
| **NPM** | `i18next`, `franc`, `google-translate-api-x`, `iso-639-1` |

---

## Tools

- `auto_translate_content` — translate posts and pages
- `translate_woocommerce_products` — translate product catalogs
- `detect_content_language` — language detection (`franc`)
- `find_untranslated_strings` — find missing translations
- `translation_memory_search` — reuse prior translations
- `translation_quality_check` — QA pass over translated content
- `localize_dates_currencies` — locale-aware formatting
- `multilingual_seo_audit` — hreflang and metadata audit
- `rtl_content_optimization` — Arabic / Hebrew / Persian RTL fixes
- `export_import_translations` — XLIFF / PO bulk export-import

Tool source: `addons/pro/includes/tools/multilingual/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Multilingual Toolkit** under **NV oOS → Settings → Pro Features**.
3. (Optional) Configure a translation provider (Google Translate API key) on the toolkit
   settings page.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/multilingual/README.md`](../../includes/tools/multilingual/README.md) — full tool reference
