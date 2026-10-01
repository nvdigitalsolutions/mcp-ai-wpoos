# Fashion Studio Bridge

## Purpose

This folder wires the Pro side of the Media Studio fashion pipeline — the
managed identity library, the batch/review queue, and their REST surface —
into the base addon's filter seams.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `WP_MCP_AI_Pro_Module_Registry` module `fashion_studio` (requires `NV_oOS_Media_Studio_AI_Service`), via `init.php` |
| **Optional dependencies** | NV oOS Media Studio addon (required); WooCommerce + Media Collection CPT (optional, used at export time) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Fashion_Model_CPT` | `class-wp-mcp-ai-fashion-model-cpt.php` | `init.php`, `fashion_*` tools, base consent/preset filters |
| `WP_MCP_AI_Fashion_Batch` | `class-wp-mcp-ai-fashion-batch.php` | `init.php`, `fashion_batch_job` tool, SPA batch routes |
| `WP_MCP_AI_Fashion_REST` | `class-wp-mcp-ai-fashion-rest.php` | `init.php` (rest_api_init) |

## Inputs / Outputs / Neighbors

- **Reads from:** Media Studio settings, `_fashion_model_*` and
  `_fashion_job_*` post meta, the `nvoos_media_studio_models|presets|identity_consent`
  filter inputs.
- **Writes to:** CPTs `mcp_ai_fashion_model` and `mcp_ai_fashion_job`, the
  seeding option `wp_mcp_ai_fashion_models_seeded`, WooCommerce product
  galleries and the Media Collection CPT (on variant approval).
- **Upstream callers:** the Pro module registry, the base addon's
  REST controllers (batch routes), the `fashion_*` tools in
  `addons/pro/includes/tools/fashion/`.
- **Downstream collaborators:** `NV_oOS_Media_Studio_AI_Service` +
  `NV_oOS_Media_Studio_Output_Pipeline` (`addons/media-studio/includes/ai/`),
  Action Scheduler (`as_enqueue_async_action`, WooCommerce-bundled) with an
  inline fallback.
- **Events fired:** the base seams `nvoos_media_studio_models`,
  `nvoos_media_studio_presets`, `nvoos_media_studio_identity_consent` are
  consumed here (fired by the addon).
- **Events listened to:** `init`, `rest_api_init`, the three seams above.

## Conventions

- The identity CPT seeds a starter library on `init` with a versioned,
  idempotent option (`SEEDED_KEY` + `SEED_VERSION`); seeded identities ship
  with consent `none` — face transforms stay blocked until an administrator
  grants consent (decisions D-1/D-2 in the parent plan).
- Batch jobs mirror the single-run gates: consent check, D-3 cost tripwires,
  and the review queue with approve/reject/reroll decisions.
- The batch dispatch prefers Action Scheduler but falls back to inline
  processing when `as_enqueue_async_action` is unavailable.

## Tests

```bash
vendor/bin/phpunit --filter "Test_Fashion_Model_CPT|Test_Fashion_Batch|Test_Fashion_REST" --no-coverage
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always)
- [`.context/rest-api.md`](../../../../.context/rest-api.md) — REST endpoint rules
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro gating rules
- [docs/project/plans/media-studio-fashion-photography-implementation.md](../../../../docs/project/plans/media-studio-fashion-photography-implementation.md) — Phase 2 spec

## See Also

- Upstream parent: [`addons/pro/includes/`](../)
- Assistant tools: `addons/pro/includes/tools/fashion/`
- Base addon: `addons/media-studio/includes/ai/`
