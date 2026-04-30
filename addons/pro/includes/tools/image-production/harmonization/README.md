# Harmonization Sub-Toolkit

A Pro-only sub-toolkit inside the Image Production Toolkit that exposes the
individual stages of professional AI compositing as discrete, composable tools,
plus a higher-level orchestrator. Aligns with industry standards (Adobe Sensei,
RunwayML, NVIDIA Canvas, Foundry Nuke AI nodes, DoveNet/Harmonizer++ research).

## Tools

### Background-handling

| Tool | Purpose |
|---|---|
| `generate_scene_background` | Text-to-background scene generator (Gemini/OpenAI). |
| `adapt_background_for_subject` | Modify an existing background — declutter, blur, dim, vignette, AI inpaint a "landing zone" — so a subject reads clearly. |
| `outpaint_background` | Extend a background's canvas to a target aspect ratio without cropping the subject. |

### Foreground / matte

| Tool | Purpose |
|---|---|
| `refine_subject_matte` | Clean up alpha edges; halo/fringe suppression with optional AI polish. |
| `auto_clean_white_background` | Convert white-background product photos into clean transparent PNG with smart edge anti-aliasing. |

### Harmonization primitives

| Tool | Purpose |
|---|---|
| `harmonize_color` | Match foreground color statistics to background (Reinhard mean/std, histogram, AI neural). |
| `relight_subject` | Re-illuminate the foreground based on detected background lighting. |
| `generate_shadow` | Render physically plausible contact + cast shadow layer. |
| `generate_reflection` | Synthesize ground/surface reflections. |
| `refine_composite_boundary` | Edge-aware blending + optional low-strength AI polish on a boundary band. |

### Helpers

| Tool | Purpose |
|---|---|
| `analyze_scene_lighting` | Vision helper returning structured lighting estimates. |
| `suggest_placement` | Top-3 placement candidates via saliency heuristic. |

### Orchestrator

| Tool | Purpose |
|---|---|
| `harmonize_image_into_background` | End-to-end pipeline: each stage individually toggleable. |
| `harmonize_batch` | Run the orchestrator over a list of subjects sharing one background. |

## Inputs

Every tool accepts the same flexible image input — attachment ID, public URL,
or `file-xxx` chat upload ID — and returns the same shape used by
`product_actualization`:

```json
{
  "success": true,
  "stage": "<tool slug>",
  "attachment_id": 123,
  "url": "https://.../uploads/.../image.png",
  "report": { ... },
  "text": "Human-readable summary."
}
```

## Non-destructive guarantee

Every tool saves a new attachment; original product pixels are never overwritten
except where the user opts in via the orchestrator's `polish_strength` parameter.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_before_harmonization_stage` | action | Fires before each pipeline stage. |
| `wp_mcp_ai_after_harmonization_stage` | action | Fires after each pipeline stage. |
| `wp_mcp_ai_harmonization_compositor_layers` | filter | Inject extra layers into the final composite. |
| `wp_mcp_ai_harmonization_lighting` | filter | Override detected lighting metadata. |

## Example LLM prompts

- *"Place this product photo on an AI-generated kitchen counter, soft window light from the left."*
- *"Drop the attached subject onto this background photo, lower-center, with a soft contact shadow."*
- *"Rebuild this catalog page with consistent harmonization: same warm lighting and matching shadows across all 8 products."*

## Architecture

See [`docs/harmonization-architecture.md`](../../../../../../docs/harmonization-architecture.md)
for the full pipeline, provider-selection policy, and lighting-analyzer
escalation rules.
