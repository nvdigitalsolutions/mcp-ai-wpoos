# Proposal 141 — Generative-media capability registry & prompt craft

**Status:** Implementing
**Surfaces:** `includes/data/media-model-catalog.json` (new), `includes/class-wp-mcp-ai-media-model-catalog.php` (new), `includes/class-wp-mcp-ai-prompt-craft.php` (new), `includes/tools/class-wp-mcp-ai-tool-media-model-capabilities.php` (new), `includes/tools/class-wp-mcp-ai-tool-enhance-media-prompt.php` (new), `includes/class-wp-mcp-ai-tool-registry.php`
**Related:** [140](./140-forge-performance-budget-proposal.md) (media optimisation, unrelated surface), [`includes/data/model-catalog.json`](../../../includes/data/model-catalog.json) (the chat/LLM catalog this does **not** replace)

## 1. Problem

NV oOS ships ~1,745 tools across ~30 generative-media models, but every media tool re-declares its own capability constraints inline:

| Tool | Where the constraints live | Shape |
|---|---|---|
| `generate_higgsfield_image` | `const IMAGE_MODELS`, `const ALLOWED_ASPECT_RATIOS` | `resolutions[]`, `has_style` |
| `generate_higgsfield_video` | `const VIDEO_MODELS` | `duration => array(4,30)` (min/max), `resolutions[]`, `aspects[]`, `refs{image,video,audio}` |
| `generate_veo_video` | `get_parameters_schema()` | `minimum: 5`, `maximum: 8`, `enum[]` |
| `generate_omni_video` | `get_parameters_schema()` | `minimum: 4`, `maximum: 10`, `maxItems: 5` |
| `generate_sora_video` | `get_parameters_schema()` | `minimum: 5`, `maximum: 60`, `enum[]` |

Three consequences:

1. **No cross-provider answer to "which model should I use?"** An assistant asked for a product video has no way to know that Seedance 2.0 takes 9 reference photos while Kling 3.0 takes none, or that Veo can only produce 8 seconds. It picks arbitrarily and burns a paid generation.
2. **Enum/limit drift.** `includes/data/model-catalog.json` (251 models) is a *chat* catalog: `context_window`, `tpm_limit`, `supports_vision`, `supports_streaming`. It carries **zero** media-generation metadata, so media constraints have no shared source of truth and no drift check.
3. **No deterministic prompt craft.** Grepping `includes/` for `focal_length|anamorphic|aperture` returns nothing. Identity-preserving multi-reference prompting — the difference between a usable product shot and a hallucinated one — is left entirely to the LLM's improvisation, every call, at full token cost.

## 2. Standards researched

**Async job contract (how the industry describes generative media).** fal's Queue API and Replicate's Predictions API both converge on the same four-part contract: submit returns a `request_id` plus operation URLs; status is an enumerated machine state (`IN_QUEUE`/`IN_PROGRESS`/`COMPLETED`, or `starting`/`processing`/`succeeded`/`failed`); the result is fetched separately; cancel is a first-class operation with defined status codes. Both also expose a server-side deadline (`X-Fal-Request-Start-Timeout`, `Cancel-After: 5s–24h`).

NV oOS **already matches this** — `WP_MCP_AI_Higgsfield_Client` returns `{request_id, status_url, cancel_url}`, declares `TERMINAL_STATUSES` / `ACTIVE_STATUSES`, and `wait_for_completion()` classifies transient (network/5xx/429 → retry with ×1.5 backoff + jitter) versus terminal (400/401/404 → abort). So the *lifecycle* is not the gap. The gap is the **input contract**.

**Declarative model registry (how the industry describes capabilities).** LiteLLM ships one checked-in JSON registry (`model_prices_and_context_window.json`) covering every provider, plus `get_supported_openai_params( model, provider )` which returns the per-model list of accepted params. Its `drop_params=True` mode **drops** unsupported params instead of raising — because a hard failure on an optional arg is a worse product outcome than proceeding without it.

**Per-model serialisation quirks are real.** The reference open-source studio studied for this proposal (`Autom8AI/Open-Higgsfield-AI`, an MIT-licensed BYOK front-end over a model gateway) carries an explicit `durationType: 'string' | 'number'` per model, because some providers accept `duration: 5` and others `duration: "5"` for the same semantic field. It also gates per-model advanced fields with `when: 't2v' | 'i2v'` — a parameter's validity depends on whether reference media is present — and applies `fixed` params the caller never sees.

**Declarative capability → UI.** The same project's `models.js` declares `styles[]`, `tier` (`premium|standard|budget`), and `refs` (max reference images) per model, then filters candidates: budget/draft models are excluded whenever a specific style is requested, so a "realistic" brief never silently lands on a cheap draft. This is the pattern worth adopting: **capability metadata is not just validation, it is model selection.**

**Prompt craft.** Their `lockInstruction()` emits ordinal identity instructions ("Reference images 1-2 show Sarah; keep this person's face, hair, skin tone and build exactly the same"), and camera/lens/focal/aperture choice maps to prompt fragments. Both are deterministic string assembly, so they belong in PHP, not in a per-call LLM round trip.

## 3. Design

Five pieces. The first two are the library; the last three are the surfaces.

### 3.1 The registry file (§1)

`includes/data/media-model-catalog.json`, versioned, sibling to `model-catalog.json` but **never** merged into it — that file is chat-only and auto-generated from a migration fixture.

Entry shape:

```json
{
  "id": "seedance-2.0",
  "provider": "higgsfield",
  "tool": "generate_higgsfield_video",
  "kind": "video",
  "label": "Seedance 2.0",
  "modes": ["text_to_video", "reference_to_video"],
  "aspect_ratios": ["16:9", "4:3", "1:1", "3:4", "9:16", "21:9"],
  "resolutions": ["480p", "720p", "1080p", "4k"],
  "duration": { "min": 4, "max": 15, "default": 5, "type": "number" },
  "defaults": { "aspect_ratio": "16:9", "resolution": "720p" },
  "max_reference_images": 9,
  "max_reference_videos": 3,
  "max_reference_audio": 3,
  "audio": "optional",
  "supports_seed": true,
  "tier": "premium",
  "styles": ["realistic", "cinematic", "ugc", "cartoon", "product"],
  "notes": "…"
}
```

Every value is **transcribed from a constraint already declared in the codebase** — `VIDEO_MODELS` for the Higgsfield video models, `get_parameters_schema()` for Veo/Omni/Sora, `IMAGE_MODELS` for the Higgsfield images. The catalog is a *consolidation*, not a set of new vendor claims. That is enforced by a drift test (§3.6).

`duration.type` exists because providers disagree: Kling's text-to-video endpoint in the reference project wants `"5"`, Seedance wants `5`. Recording it per model removes a whole class of silent 422s.

### 3.2 The catalog service (§3.1)

`WP_MCP_AI_Media_Model_Catalog` — static, no singleton, no DB, no network, no options. It reads the JSON once per request into a static cache, with `reset_cache()` for tests.

```php
WP_MCP_AI_Media_Model_Catalog::get_models( array( 'kind' => 'video', 'provider' => 'higgsfield' ) );
WP_MCP_AI_Media_Model_Catalog::get_model( 'seedance-2.0' );
WP_MCP_AI_Media_Model_Catalog::get_enum( 'seedance-2.0', 'resolutions' );     // → array
WP_MCP_AI_Media_Model_Catalog::get_defaults( 'seedance-2.0' );                 // → array
WP_MCP_AI_Media_Model_Catalog::max_reference_images( 'seedance-2.0' );         // → 9
WP_MCP_AI_Media_Model_Catalog::validate( 'seedance-2.0', $args );              // → { params, dropped[], errors[] }
WP_MCP_AI_Media_Model_Catalog::build_payload( 'seedance-2.0', $args );         // → provider-ready array
WP_MCP_AI_Media_Model_Catalog::recommend( $intent );                           // → ranked candidates
```

`validate()` follows LiteLLM's `drop_params` semantics deliberately: an unsupported *optional* param is dropped and reported in `dropped[]`, not fatal. A value outside an enum or range is clamped when the field is numeric-with-bounds, and reported in `errors[]` when it is a closed enum with no sensible nearest value. The tool surfaces both so the agent learns the constraint without losing the generation.

`build_payload()` is the `fixed` + coercion + mode-gating layer:

- `fixed` params are injected and are **not** model-facing schema (the caller never sees them).
- `duration` is cast to `duration.type`.
- Params declared `when: 'i2v'` are dropped when the request carries no reference media, and vice versa.

`recommend()` is the fit layer. Score = tier preference + style match + reference capacity + audio requirement, with two hard filters that mirror the reference implementation's intent:

- a budget-tier model is excluded when a specific `style` is requested;
- a model whose `max_reference_images` is below the caller's reference count is excluded (not merely down-ranked — sending 12 photos to a 2-photo model is a hard provider error).

Each result carries `reasons[]` so the agent can explain the choice to the user instead of asserting it.

**One model, two entry points.** `tool` names the model's *owner* — the tool whose capability unions it contributes to. An optional `tools[]` array lists every tool that can *select* it. Only `omni` uses this today: it is owned by `generate_omni_video`, but `generate_veo_video` also accepts it as a model choice. `get_models( array( 'tool' => $slug ) )` matches either field, so both tools see it, while keeping `omni`'s capabilities out of the Veo schema's unions (see §3.6).

### 3.3 Prompt craft (§3.5)

`WP_MCP_AI_Prompt_Craft` — pure static string assembly, no I/O.

- `CAMERAS`, `LENSES`, `FOCAL_LENGTHS` (8/14/24/35/50/85 mm), `APERTURES` (f/1.4, f/4, f/11) — the photographic vocabulary as data, each mapping to a prompt fragment.
- `STYLE_MODIFIERS` — the six styles already used as enums by Veo/Omni (`cinematic`, `realistic`, `anime`, `documentary`, `artistic`) plus `product`, `ugc`, `design`, so the tool accepts what the video tools already accept.
- `build_identity_lock( $characters, $items )` — ordinal reference instructions with correct grammar for one reference (`"Reference image 1 shows…"`) versus many (`"Reference images 1-3 show…"`), and continuous numbering across characters then items so the ordinals match send order.
- `compose( $base, $options )` — joins non-empty fragments with `", "`, de-duplicates repeated fragments, and never emits a dangling comma.

This is a *deterministic* function. It costs zero tokens and is fully unit-testable, which is the point: the LLM decides *what* to shoot, PHP decides how to say it consistently.

### 3.4 Tool: `media_model_capabilities` (§3.2, §3.4)

Read-only, offline, credential-free. `action` enum:

| `action` | Input | Output |
|---|---|---|
| `list` | `kind`, `provider`, `tier`, `style` | matching models, compact |
| `describe` | `model` | the full capability record |
| `recommend` | `kind`, `style`, `reference_images`, `audio`, `tier` | ranked candidates with 1-3 `reasons` each |
| `validate` | `model`, `arguments` | `params` / `dropped` / `errors` — a pre-flight, no generation |

`validate` is the operationally important one: it lets an agent check a payload before spending money, and it makes the `drop_params` behaviour inspectable rather than surprising.

### 3.5 Tool: `enhance_media_prompt` (§3.3)

Deterministic prompt assembly. Inputs: `prompt`, `style`, `camera`, `lens`, `focal_length`, `aperture`, `characters[]`, `items[]`, `include_quality_tags`, `extra_tags[]`. Returns `enhanced_prompt`, plus `applied[]` (what was appended) and `reference_instructions` separately, so the caller can inspect the diff instead of trusting an opaque rewrite.

Explicitly **not** an LLM call. There is no provider dependency, no cost, and no non-determinism.

### 3.6 Drift tests

Two suites, with different jobs.

`tests/test-media-model-catalog.php` checks the data itself:

- every `VIDEO_MODELS` id exists in the catalog, with matching `duration.min`/`max`, `resolutions`, `aspect_ratios`, and `max_reference_images`;
- every enum field in the catalog is non-empty (a typo'd field name would otherwise silently read as "no constraint", the worst failure mode);
- every id survives `normalize_id()` unchanged, so a dotted id cannot silently become a lookup miss.

`includes/data/media-model-catalog.json` and each converted tool are pinned to each other by a parity suite — `tests/test-higgsfield-video-catalog-parity.php`, `tests/test-higgsfield-image-catalog-parity.php` and `tests/test-media-tool-catalog-parity.php` (Veo / Omni / Sora) — which check the **relationship**, in both directions:

| Direction | Assertion |
|---|---|
| const → registry | every model id in the tool's const is registered for that tool |
| registry → const | the registry advertises no model the tool cannot build an endpoint for |
| per-model fields | endpoint, resolutions, batch sizes and duration agree model by model (not just as unions) |
| derivation | the derived unions/bounds reproduce the tool's `ALLOWED_*` constants and the const's span **exactly** |
| schema | the schema enum is the const list, in const order, and the enums are the derived values rather than separate literals |
| fallback | with no registry file, every schema value is byte-for-byte the pre-141 literal |

Where a tool *routes* to a model it does not own, the suite pins the split explicitly. `generate_veo_video` selects `omni` but must not inherit Omni's aspect ratios, because it forwards `aspect_ratio` to the service unclamped — an over-wide enum becomes a provider-side error rather than a graceful fallback. So the tool exposes `get_supported_models()` (selectable: omni, veo-3.1, veo-2.0) and `get_capability_models()` (native: veo-3.1, veo-2.0), and the suite asserts that `16:9` / `9:16` are **absent** from the Veo schema.

Every direction was **watched failing** before being accepted:

| Suite | Probe | Failed |
|---|---|---|
| video | registry-only `ghost-model` | registry→const only — the schema enum stayed correct (intended containment) |
| video | `kling-3.0` deleted | const→registry **and** schema enum |
| image | registry-only `ghost-image` | registry→const only |
| image | `soul-2` endpoint rewritten | per-model fields |
| image | `soul-cinema` deleted | const→registry, per-model fields, schema enum |
| Veo/Omni/Sora | `omni.tools` removed | tool→registry, Veo model enum |
| Veo/Omni/Sora | `veo-3.1` gains `16:9` | the widening guard **and** the Veo aspect enum |
| Veo/Omni/Sora | `sora-2-pro` deleted | Sora model enum |
| Veo/Omni/Sora | `omni.max_reference_images` 5→3 | Omni `maxItems` |

Together these make the registry safe to own. Without them it becomes a second stale copy — the exact problem §1 describes.

## 4. Deliberate non-goals

- **Not** a merge into `includes/data/model-catalog.json`. That file is auto-generated by `WP_MCP_AI_Model_Catalog_Migration` and consumed by rate-limit logic; adding media rows would make the migration fixture and the runtime file disagree.
- **Not** a rewrite of media-tool **payload construction**. The converted tools now *derive their schema enums* from the registry (§3.6), but the request each submits is still built from its own declarations, which carry data the registry does not model (`audio_field`, `audio_values`, `creative` and the per-kind `refs` map for Higgsfield video; `has_style` for images). Routing payload construction through the registry is a separate, riskier change.
- **No provider calls, no new credentials, no new options, no new tables.** Everything here is static data and pure functions, so it is safe on every site including Base-only installs.

## 5. Validation matrix

| Gate | Command | Expected |
|---|---|---|
| Catalog unit | `vendor/bin/phpunit tests/test-media-model-catalog.php` | all green |
| Prompt craft unit | `vendor/bin/phpunit tests/test-prompt-craft.php` | all green |
| Capabilities tool | `vendor/bin/phpunit tests/test-tool-media-model-capabilities.php` | all green |
| Enhance tool | `vendor/bin/phpunit tests/test-tool-enhance-media-prompt.php` | all green |
| Registry loads | `vendor/bin/phpunit tests/test-tool-registry.php` | both slugs present |
| PHP compat | `composer run lint:compat` | clean (7.4+ target) |
| WPCS | `composer run lint` on the changed files | clean |

## 6. Risks

| Risk | Mitigation |
|---|---|
| Catalog drifts from the tool consts | §3.6 drift test asserts parity for every Higgsfield video model |
| Reading JSON on every request is slow | One static read per request; ~14 entries; no autoload, no option, no transient |
| Agents treat `recommend` as authoritative | Every result carries `reasons[]` and the tool description says it is advisory; `tier: 'any'` is expressible |
| `validate` gives false confidence | It validates the *catalog's* declared constraints only, and says so in `notes`; provider-side rejection is still possible |
