# Harmonization Architecture

The Harmonization sub-toolkit lives in
`addons/pro/includes/tools/image-production/harmonization/` and complements the
existing `product_actualization` end-to-end tool by exposing each stage of
professional AI compositing as a discrete, composable Pro tool.

## Components

```
harmonization/
├── trait-wp-mcp-ai-tool-harmonization.php          # Shared trait: input
│                                                    # resolution, working files,
│                                                    # provider auto-selection,
│                                                    # response shaping.
├── class-wp-mcp-ai-harmonization-compositor.php    # Imagick/GD layer engine:
│                                                    # alpha refinement, Reinhard
│                                                    # color transfer, shadow,
│                                                    # reflection, feathering.
├── class-wp-mcp-ai-lighting-analyzer.php           # Heuristic-first lighting
│                                                    # estimator. Escalates to
│                                                    # AI vision when confidence
│                                                    # is low.
├── class-wp-mcp-ai-tool-harmonization-base.php     # Abstract base:
│                                                    # auth/multisite gating,
│                                                    # capability flags, AI edit
│                                                    # call.
├── class-wp-mcp-ai-tool-*.php                      # 14 concrete tools.
└── harmonization-init.php                          # Registration loader.
```

## Pipeline

The headline orchestrator `harmonize_image_into_background` runs (each stage
individually toggleable):

1. **Resolve background** — load existing OR call `generate_scene_background`.
2. **Adapt background** *(optional)* — declutter / blur / vignette so the
   subject reads clearly.
3. **Clean white BG** *(optional)* — for catalog product shots.
4. **Refine subject matte** — alpha feathering, halo suppression.
5. **Suggest placement** — saliency / hint-based bounding box.
6. **Harmonize color** — Reinhard mean/std color transfer to match palette.
7. **Relight subject** *(optional, AI)* — re-illuminate to match background
   light direction, color temp, intensity.
8. **Generate shadow** *(optional)* — contact + cast shadow layer rendered
   underneath the subject.
9. **Generate reflection** *(optional)* — for glossy ground planes.
10. **Compose layers** — Imagick (preferred) or GD composite.
11. **Refine composite boundary** — edge-aware feather + optional low-strength
    AI polish on a 1-2 px boundary band.
12. **Optional AI polish** — single Gemini/OpenAI edit on the whole frame at
    `polish_strength`. Gated; when 0 (default), original product pixels are the
    source of truth.

## Provider selection

`harmonization_detect_provider()` resolves `auto` to the configured provider in
this order: Gemini (if `wp_mcp_ai_settings.gemini_api_key` is set) →
OpenAI (if `wp_mcp_ai_settings.openai_api_key` is set). Tools that never need
AI (e.g. `analyze_scene_lighting`, `suggest_placement`,
`auto_clean_white_background`) work fine without a provider.

## Lighting analyzer escalation

`WP_MCP_AI_Lighting_Analyzer::analyze()`:

1. **Cheap path (always runs):**
   - Downsample to 32×32.
   - Compute brightest-region centroid (light direction).
   - Compute warm/cool dominant color (color temperature).
   - Compute mean luminance (intensity) and std-dev (contrast).
2. **Confidence score:** `min(direction_strength, color_strength, contrast)`.
3. **Escalation:** if `allow_ai_escalation === true` and confidence < 0.45,
   issue a single Gemini/OpenAI vision call that returns a JSON lighting
   description and overrides the heuristic result.

Results are filterable via `wp_mcp_ai_harmonization_lighting`.

## Non-destructive guarantee

Every tool saves a new attachment to the media library; original product pixels
are **never** overwritten in place. The orchestrator's `polish_strength`
parameter is the single opt-in for whole-frame AI modification.

## Capability flags

All write tools declare:
`pro`, `requires-capability`, `write`, `state-changing`, `external-api`,
`requires-credentials`, `network-dependent`, `consumes-tokens`, `rate-limited`,
`gpu-accelerated`, `performance-impact`. The orchestrator and batch tools add
`async` + `long-running` (and `batch` for the batch tool). Read-only helpers
(`analyze_scene_lighting`, `suggest_placement`) declare `read-only` +
`cacheable` instead.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_before_harmonization_stage` | action | Fires before each pipeline stage. |
| `wp_mcp_ai_after_harmonization_stage` | action | Fires after each pipeline stage. |
| `wp_mcp_ai_harmonization_compositor_layers` | filter | Inject extra layers into the composite. |
| `wp_mcp_ai_harmonization_lighting` | filter | Override detected lighting metadata. |

## Future work (out of scope)

- True 3D-aware compositing / NeRF.
- Video harmonization beyond `product_actualization`'s video mode.
- Refactor `product_actualization` to delegate to the orchestrator internally
  once primitives are stable (planned phase 4 of rollout).
