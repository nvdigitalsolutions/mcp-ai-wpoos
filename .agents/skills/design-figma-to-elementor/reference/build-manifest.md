# Build Manifest — Spec

The **build manifest** is the plan artifact of the Figma → Elementor
pipeline: the single document the agent emits at the **Plan** stage and the
human approves before any write. It is the contract the **Build** stage
executes and the **Verify** stage checks against.

JSON Schema: `build-manifest.schema.json` (draft-07). If the manifest does
not validate against the schema, the pipeline stops — no build without a
valid manifest.

## Rules

1. **One manifest per page.** A multi-page site is multiple manifests
   (or one manifest per page run), never one giant document.
2. **Every color/font/spacing value must be a token reference**, not a raw
   value. Raw hex in a widget mapping is a validation error at Tokenize.
3. **Every section maps to one Elementor section** and lists its widgets in
   render order.
4. **Approvals are recorded.** The manifest carries who approved and when;
   the Build stage refuses to start without `approval.status: "approved"`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| `manifest_version` | string | Schema version this manifest conforms to (`1.0`) |
| `source` | object | Figma file key, page name, node id, and the `get_design_context` call that produced the plan |
| `target` | object | Site (Remote Sites connection label/host), page title, slug, template |
| `tokens` | array | Variable → token mapping: Figma variable name, resolved value, target (Elementor global class/variable or `--nds-*` token), fallback value |
| `assets` | array | Images/illustrations: Figma node/export reference, filename, intended widget/field, alt text |
| `sections` | array | Build order: section name, layout (columns/stack), background token, widgets with their property maps, content copy, responsive notes |
| `responsive` | object | Tablet/mobile adjustments per section (visibility, stacking, sizes) |
| `approval` | object | `status` (`pending`/`approved`/`rejected`), reviewer, timestamp, notes |
| `verification` | object | Post-build report (added by Verify): per-section status, deviations, token violations |

## Example (minimal — one hero + one features section)

```json
{
  "manifest_version": "1.0",
  "source": {
    "file_key": "abc123DEF",
    "page": "Home — Desktop",
    "node_id": "1234:5678",
    "design_context_call": "get_design_context(node=1234:5678)"
  },
  "target": {
    "site": "client-site (Remote Sites)",
    "page_title": "Home",
    "page_slug": "home",
    "template": "default"
  },
  "tokens": [
    {
      "figma_variable": "color/primary/500",
      "resolved_value": "#2563EB",
      "target": "elementor_global_color",
      "target_name": "Primary",
      "fallback": "#2271b1"
    },
    {
      "figma_variable": "font/heading",
      "resolved_value": "Inter",
      "target": "elementor_global_font",
      "target_name": "Heading"
    }
  ],
  "assets": [
    {
      "figma_node": "2345:6789",
      "filename": "hero-product.png",
      "used_in": "hero_image",
      "alt": "Product on a clean desk"
    }
  ],
  "sections": [
    {
      "name": "Hero",
      "layout": "single",
      "background": "color/surface/primary",
      "widgets": [
        {
          "widget": "heading",
          "content": "Build faster with NV oOS",
          "color": "text/on-primary",
          "typography": "font/heading"
        },
        {
          "widget": "image",
          "asset": "hero-product.png",
          "size": "medium"
        },
        {
          "widget": "button",
          "text": "Get started",
          "link": "#features"
        }
      ]
    },
    {
      "name": "Features",
      "layout": "three-column",
      "widgets": [
        { "widget": "icon-box", "icon": "bolt", "title": "Fast", "text": "SSE streaming." },
        { "widget": "icon-box", "icon": "shield", "title": "Safe", "text": "Draft-first." },
        { "widget": "icon-box", "icon": "refresh", "title": "Reversible", "text": "Undo anytime." }
      ]
    }
  ],
  "responsive": {
    "Hero": { "tablet": { "stack_columns": true }, "mobile": { "hide_image": false } },
    "Features": { "mobile": { "stack_columns": true } }
  },
  "approval": {
    "status": "approved",
    "reviewer": "admin",
    "timestamp": "2026-10-06T12:00:00Z",
    "notes": "Approved with three-column features."
  }
}
```

## What a good manifest is NOT

- Not a screenshot description. It references the design, it does not
  re-describe it.
- Not freeform. It validates against the schema or the pipeline stops.
- Not a promise to figure things out during Build. Ambiguity in the
  manifest is a Plan defect; fix it before approval.
