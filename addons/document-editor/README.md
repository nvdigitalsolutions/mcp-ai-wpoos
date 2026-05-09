# NV oOS Document Editor

React SPA addon for NV oOS, scaffolded from the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md). This is
the **Tier C** companion to the canvas and shell addons — a separate addon
for rich-text / document surfaces powered by Tiptap v3 (ProseMirror-based, MIT).

## Modes

| Mode | Status | Implementation |
|------|--------|----------------|
| `editor` (default) | ✅ shipped | Full Tiptap document editor — heading / paragraph / table / link / placeholder / bold / italic / strike / code / blockquote / list; toolbar with undo/redo; REST-persisted `nvoos_document` CPT. |
| `site-creator` | 🚧 stub | Tiptap + GrapesJS — ships in a follow-up PR. |

Unknown values fall back to `editor`.

## Quick start

```bash
cd addons/document-editor
npm ci
npm run build       # produces assets/dist/document-editor.{js,css}
```

Add the shortcode:

```
[nvoos_document_editor_app mode="editor" toolkit="document-generation" document_id="42"]
```

Or use the matching Gutenberg block (`nvoos/document-editor`). The `document_id` attribute
wires the editor to an existing `nvoos_document` post (load on mount + Save button). Omit it
for an ephemeral editor with no persistence.

## REST API

`/wp-json/nvoos-document-editor/v1/` routes — gated by `edit_posts`:

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/documents` | List documents (`per_page`, `page`, `search`) |
| GET | `/documents/{id}` | Get one document |
| POST | `/documents` | Create document (`title`, `content`) |
| PUT | `/documents/{id}` | Update document |
| DELETE | `/documents/{id}` | Trash document |
| GET | `/health` | Version / health (requires `manage_options`) |

Documents are stored as the `nvoos_document` CPT (non-public, author-scoped).
Content is sanitized with `wp_kses_post` on write; served raw (already safe HTML) on read.

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. `Version:` header in `nvoos-document-editor.php`
2. `define( 'NVOOS_DOCUMENT_EDITOR_VERSION', '…' );`
3. `"version"` in `package.json`

This forces `?ver=` query strings to invalidate browser caches.

## Credits

This addon bundles:

- [React 19 + ReactDOM](https://github.com/facebook/react) (MIT)
- [Tiptap 3 family](https://github.com/ueberdosis/tiptap) (MIT) — `@tiptap/react`, `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/extension-link`, `@tiptap/extension-placeholder`, `@tiptap/extension-table*`

When adding upstream packages, update:

- [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)
- The root [`CREDITS.md`](../../CREDITS.md)
- This Credits section

