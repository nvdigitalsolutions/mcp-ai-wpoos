# Model Catalog Update Process — July 2026

> **Purpose**: Repeatable checklist for monthly model configuration updates across the NV oOS plugin (base + pro).

## Quick Reference: Files Touched

| # | File | Role |
|---|---|---|
| 1 | `includes/data/model-catalog.json` | **Single source of truth** — all model metadata |
| 2 | `includes/class-wp-mcp-ai-model-catalog-migration.php` | Legacy ID → current ID rewrite map |
| 3 | `includes/class-wp-mcp-ai-model-selector.php` | Auto-routing: light/advanced model selection & fallbacks |
| 4 | `includes/class-wp-mcp-ai-language-model-router.php` | Draft & verification model tier maps (17 providers) |
| 5 | `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | All plugin setting defaults |
| 6 | `includes/admin/class-wp-mcp-ai-admin-settings.php` | `get_default_model()` fallback, hardcoded dropdown fallback |
| 7 | `includes/services/class-wp-mcp-ai-token-budget-service.php` | Context windows, TPM fallbacks, max output tokens, higher-limit suggestions |
| 8 | `includes/class-wp-mcp-ai-cost-calculator.php` | Per-model pricing (USD per 1M tokens) |
| 9 | `includes/class-wp-mcp-ai-usage-tracker.php` | Per-model fallback pricing (per 1K tokens) |
| 10 | `includes/class-wp-mcp-ai-anthropic-client.php` | Static model list, resolve_model default, test connection fallback |
| 11 | `includes/class-wp-mcp-ai-gemini-live-client.php` | `DEFAULT_MODEL` constant |
| 12 | `includes/class-wp-mcp-ai-default-assistants.php` | Pre-built assistant model assignments |
| 13 | `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` | Anthropic connection test default model |
| 14 | `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php` | Placeholder text examples |
| 15 | `includes/admin/class-wp-mcp-ai-admin-profession-settings.php` | Placeholder text examples |
| 16 | `includes/admin/class-wp-mcp-ai-admin-team-settings.php` | Placeholder text examples |
| 17 | `includes/class-wp-mcp-ai-model-rate-limits-cct.php` | Meta field description examples |
| 18 | `includes/class-wp-mcp-ai-token-tracking-database.php` | Docblock example models |
| 19 | `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | Help text examples |
| 20 | `assets/examples/model-config-capability-filtering.php` | Example code model IDs |
| 21 | `addons/pro/includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-analyze-sensory-input.php` | Tool model enum |
| 22 | `addons/pro/includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-interpret-imaging-study.php` | Tool default models |
| 23 | `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php` | Tool default models |
| 24 | `addons/pro/includes/tools/research/class-wp-mcp-ai-tool-research-product.php` | Tool default models |
| 35 | `addons/pro/includes/metaboxes/class-wp-mcp-ai-media-template-metabox-operation.php` | Docs example model |

---

## Process Checklist (Run Monthly)

### Phase 1: Research (Web)

Search each major provider for model changes:

```
"OpenAI API models deprecated discontinued [MONTH] [YEAR]"
"Anthropic Claude models latest [MONTH] [YEAR] deprecated sunset"
"Google Gemini models latest [MONTH] [YEAR] deprecated"
"DeepSeek API models latest [MONTH] [YEAR]"
```

Key sources:
- OpenAI: `https://developers.openai.com/api/docs/deprecations`
- Anthropic: `https://platform.claude.com/docs/en/about-claude/models/overview`
- Google: `https://blog.google/innovation-and-ai/models-and-research/gemini-models/`
- DeepSeek: `https://api-docs.deepseek.com/quick_start/pricing`

### Phase 2: Update the Catalog (`model-catalog.json`)

This is the **single source of truth**. Every other file derives from this.

**Actions:**
1. Add new models with full metadata (model_name, provider, tpm_limit, rpm_limit, context_window, max_output_tokens, supports_function_calling, supports_vision, cost_per_1k_input_tokens, cost_per_1k_output_tokens, fallback_model, status, sunset_date, notes)
2. Mark deprecated models: `"status": "deprecated"`, set `"sunset_date"`, set `"fallback_model"` to successor
3. Bump `"version"` and `"updated_at"` to current date
4. Validate JSON: `php -r "json_decode(file_get_contents('includes/data/model-catalog.json'), true); echo json_last_error_msg();"`

**Entry template:**
```json
{
  "model_name": "model-id",
  "provider": "openai|anthropic|gemini|deepseek|nvidia|cloudflare|huggingface|ollama|lm_studio|kimi|baseten|digitalocean|openrouter|embedded|webllm|azure",
  "tpm_limit": 0,
  "rpm_limit": 0,
  "tpd_limit": 0,
  "rpd_limit": 0,
  "context_window": 0,
  "max_output_tokens": 0,
  "tier": "free|tier-1|tier-2|paid|local|cloud",
  "supports_streaming": true,
  "supports_function_calling": true,
  "supports_vision": true,
  "cost_per_1k_input_tokens": 0.0,
  "cost_per_1k_output_tokens": 0.0,
  "fallback_model": "model-id-or-empty",
  "status": "active|deprecated|legacy",
  "sunset_date": "YYYY-MM-DD-or-empty",
  "notes": "Human-readable description."
}
```

### Phase 3: Update the Migration Map

**File**: `includes/class-wp-mcp-ai-model-catalog-migration.php`

Add entries in `get_legacy_id_map()` for any newly deprecated models. Map old model IDs → their successors.

**Rule**: Target must exist in the catalog. Never map to a model that isn't in `model-catalog.json`.

### Phase 4: Update Settings Defaults

**File**: `includes/admin/class-wp-mcp-ai-admin-settings-base.php` → `get_default_settings()`

Check these keys:
- `default_model` — Stable OpenAI model (currently `gpt-4.1`)
- `default_gemini_model` — Fast/reliable Gemini (currently `gemini-3.5-flash`)
- `nvidia_model` — Current-gen NIM model (currently `nvidia/nemotron-3-nano-30b-a3b`)
- `cloudflare_model` — Current Workers AI model
- `high_token_fallback_model` — Highest TPM model for emergency fallback (currently `gemini-2.5-flash`)
- `openai_realtime_model` — Latest realtime model (currently `gpt-realtime-2`)
- `gemini_live_model` — Latest Gemini Live model (currently `gemini-3.1-flash-live-preview`)
- `gemini_image_model` — Latest image gen model (currently `gemini-3.1-flash-image`)
- `openai_image_model` — Current image model (currently `gpt-image-1`)

**File**: `includes/admin/class-wp-mcp-ai-admin-settings.php`
- `get_default_model()` fallback: match `default_model` setting
- Hardcoded model dropdown fallback (line ~5319): add any major new models

### Phase 5: Update the Model Selector

**File**: `includes/class-wp-mcp-ai-model-selector.php`

- `get_default_light_model()` — Fast/cheap model for simple tasks (currently `gpt-4.1-mini`, filterable via `wp_mcp_ai_default_light_model`)
- `get_default_advanced_model()` — Capable model for complex tasks (currently `gpt-4.1`, filterable via `wp_mcp_ai_default_advanced_model`)
- `check_tpm_and_suggest_fallback()` — Provider-specific fallback chains for OpenAI, Gemini, Claude
- `get_high_capacity_fallback_model()` — Ultimate fallback (currently `gemini-2.5-flash`)
- Docblock comments referencing specific model names

### Phase 6: Update the Language Model Router

**File**: `includes/class-wp-mcp-ai-language-model-router.php`

- `get_draft_model_for_provider()` — Fast/cheap model per provider (17 entries)
- `get_verification_model_for_provider()` — Most capable model per provider (17 entries)
- Both have a fallback default (`gpt-4.1-nano` / `gpt-4.1`)

### Phase 7: Update Provider Clients

**File**: `includes/class-wp-mcp-ai-anthropic-client.php`
- `list_models()` — Static model list, add new entries at top, label legacy entries
- `resolve_model()` — Default fallback model
- `test_connection()` — Test connection fallback model

**File**: `includes/class-wp-mcp-ai-gemini-live-client.php`
- `DEFAULT_MODEL` constant

### Phase 8: Update Token Budget Service

**File**: `includes/services/class-wp-mcp-ai-token-budget-service.php`

Three arrays to check:
- `$model_limits` — Context window sizes for every model
- `$default_tpm_limits` — TPM fallback limits (Anthropic only; others from CCT)
- `$model_max_output_tokens` — Max output token caps per model
- `get_higher_limit_models()` — Suggested alternatives when TPM exceeded (OpenAI & Gemini arrays)
- `resolve_tiktoken_encoding()` — Remove any non-existent model mappings

### Phase 9: Update Cost Tables

**File**: `includes/class-wp-mcp-ai-cost-calculator.php` (pricing per 1M tokens)
**File**: `includes/class-wp-mcp-ai-usage-tracker.php` → `get_fallback_pricing()` (pricing per 1K tokens)

- Add entries for all new models
- Remove entries for models no longer in API
- Update prices if provider changed pricing
- Remove expired promotional pricing (e.g., DeepSeek V4-Pro promo ended 2026-05-31)

### Phase 10: Update Admin UI References

These are placeholder examples shown in admin forms. Use `sed` for bulk updates:

```bash
sed -i "s/old-model-id/new-model-id/g" \
  includes/admin/class-wp-mcp-ai-admin-profession-settings.php \
  includes/admin/class-wp-mcp-ai-admin-team-settings.php \
  includes/admin/sections/class-wp-mcp-ai-section-orchestration.php \
  includes/cli/class-wp-mcp-ai-cli-assistant-command.php \
  includes/class-wp-mcp-ai-model-rate-limits-cct.php \
  includes/class-wp-mcp-ai-token-tracking-database.php
```

Also check:
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` — test connection defaults
- `includes/class-wp-mcp-ai-default-assistants.php` — pre-built assistant `'model'` values

### Phase 11: Update Pro Tool Files

Grep for hardcoded model references in `addons/pro/includes/tools/`:

```bash
grep -rn "gpt-4o\|gemini-1.5\|claude-3-haiku\|claude-3-5-sonnet" addons/pro/includes/tools/
```

Common patterns to fix in each tool:
- `get_model()` / `get_research_model()` — default model fallback
- `get_parameters_schema()` — `'enum'` arrays listing models
- Docblock comments / parameter descriptions referencing specific models
- `get_definition()` — model parameter enums

### Phase 12: Update Example Files

- `assets/examples/model-config-capability-filtering.php` — Example model IDs
- `addons/pro/includes/metaboxes/class-wp-mcp-ai-media-template-metabox-operation.php` — Docs

### Phase 13: Validate

```bash
# JSON validity
php -r "json_decode(file_get_contents('includes/data/model-catalog.json'), true); echo json_last_error_msg();"

# PHP syntax on all changed files
php -l includes/class-wp-mcp-ai-model-selector.php
php -l includes/class-wp-mcp-ai-language-model-router.php
php -l includes/class-wp-mcp-ai-anthropic-client.php
php -l includes/class-wp-mcp-ai-cost-calculator.php
php -l includes/class-wp-mcp-ai-usage-tracker.php
php -l includes/services/class-wp-mcp-ai-token-budget-service.php
php -l includes/admin/class-wp-mcp-ai-admin-settings-base.php
php -l includes/admin/class-wp-mcp-ai-admin-settings.php
php -l includes/class-wp-mcp-ai-default-assistants.php
# ... all other changed files

# Git diff review
git --no-pager diff --stat
git --no-pager diff -- includes/ addons/pro/includes/
```

---

## This Month's Changes (July 2026)

### New Models Added to Catalog
| Model | Provider | Notes |
|---|---|---|
| `gpt-5.6` | openai | Latest flagship (July 9, 2026). $5/$30 per 1M. 1.05M context. |
| `claude-sonnet-5` | anthropic | New default Claude (June 30, 2026). $2/$10 intro through Aug 31. |
| `claude-fable-5` | anthropic | Top tier above Opus (June 2026). $10/$50 per 1M. |
| `gpt-4o` | openai | Added as deprecated (was only under Azure). Sunset 2026-06-30. |

### Status Changes
| Model | Old Status | New Status | Reason |
|---|---|---|---|
| `claude-sonnet-4-6` | active | deprecated | Replaced by claude-sonnet-5 (sunset 2026-09-30) |

### Default Changes
| Setting | Old | New |
|---|---|---|
| `default_gemini_model` | `gemini-2.5-flash` | `gemini-3.5-flash` |
| `nvidia_model` | `meta/llama-3.1-8b-instruct` | `nvidia/nemotron-3-nano-30b-a3b` |
| `gemini_live_model` | `gemini-2.5-flash-live` | `gemini-3.1-flash-live-preview` |
| Anthropic `resolve_model()` | `claude-3-5-sonnet-20241022` | `claude-sonnet-5` |

### Pricing Fixes
| Model | Old Price | New Price | Notes |
|---|---|---|---|
| `claude-opus-4-7` input | $15/1M | $5/1M | Was 3x too high |
| `claude-opus-4-6` input | $15/1M | $5/1M | Was 3x too high |
| `claude-opus-4-5` input | $15/1M | $5/1M | Was 3x too high |
| `deepseek-v4-pro` input | $0.435/1M | $1.74/1M | Promo ended May 31 |

### Deletions
- `o4-mini` from cost calculator and token budget encoding map (doesn't exist in API)
- `o4` / `o4-mini` from token budget `$model_limits` array
- `gpt-5.5-mini` from token budget `$model_limits` array (doesn't exist)

### Migration Map Fixes
| Legacy ID | Old Target | New Target |
|---|---|---|
| `gpt-4-vision-preview` | `gpt-4o` | `gpt-4.1` |
| `chatgpt-4o-latest` | `gpt-4o` | `gpt-4.1` |
| `o1` / `o1-mini` / `o1-preview` | `o4-mini` | `o3-mini` |
| `claude-mythos-preview` | `claude-opus-4-7` | `claude-opus-4-8` |
| `imagen-3` | `imagen-4` | `gemini-3.1-flash-image` |

---

## This Month's Changes (September 2026)

### New Models Added to Catalog
| Model | Provider | Notes |
|---|---|---|
| `gpt-6-astra` | openai | Next-gen flagship, limited preview (Sept 3, 2026). $10/$50 per 1M, 1M ctx, computer use. |
| `gpt-5.6-sol` | openai | GPT-5.6 flagship tier. $4/$20 promo through Nov 21, 2026 (standard $5/$30). |
| `gpt-5.6-terra` | openai | Balanced tier. $2/$12. Replacement for gpt-3.5-turbo-instruct/babbage/davinci (Sept 28). |
| `gpt-5.6-luna` | openai | Cost tier. $0.20/$1.20 after July 30 80% cut. |
| `gpt-image-2` | openai | Token-based image model replacing the gpt-image-1 family (retires Oct 23 / Dec 1). |
| `claude-opus-5` | anthropic | New default flagship (July 24, 2026). $5/$25, 1M ctx, 128K out. |
| `claude-fable-5.1` | anthropic | Top tier (Sept 1, 2026). $10/$50; cache reads cut 75% to $0.25. |
| `claude-mythos-5` | anthropic | Invitation-gated top tier (restored July 1). Successor of claude-mythos-preview. |
| `gemini-3.8-flash` | gemini | Third Flash in six weeks (Sept 2, 2026). $0.75/$3.75 intro through Dec 31. |
| `gemini-3.7-flash` | gemini | Workhorse Flash targeting coding/agents. $0.75/$3.75 intro through Dec 31. |
| `gemini-3.6-flash` | gemini | GA July 21, 2026. New default Gemini model. $0.75/$3.75 intro through Dec 31. |
| `gemini-3.5-flash-lite` | gemini | GA July 21, 2026. $0.30/$2.50 per 1M. |
| `gemini-3.1-flash-image-preview` | gemini | Migration target for gemini-2.5-flash-image (shutdown Oct 2, 2026). |
| `deepseek-v4-flash-vision-exp` | deepseek | Experimental vision V4 Flash (Aug 21, 2026). Images billed as input tokens. |
| `kimi-k3` | kimi | 2.8T MoE flagship (July 16, 2026), open weights, 1M ctx. $3/$15 per 1M. |
| `kimi-k2.7-code` | kimi | June 2026 code model; referenced by the admin Kimi default before K3. |

### Status Changes / Removals
| Model | Change | Reason |
|---|---|---|
| `gemini-3.1-flash` | Removed | Sunset 2026-09-01 passed. |
| `imagen-4` | Removed | Shut down Aug 17, 2026. |
| `deepseek-chat` / `deepseek-reasoner` / `deepseek-coder` | Removed | Retired from the DeepSeek API on July 24, 2026. |
| `gemini-2.5-flash-image` | Sunset set to 2026-10-02 | Google shutdown date; fallback now points at the preview endpoint. |
| `claude-sonnet-4-6` | Sunset cleared | Anthropic publishes retirement floors into 2027; the Sept-30 date was wrong. |

### Default Changes
| Setting | Old | New |
|---|---|---|
| `default_gemini_model` | `gemini-3.5-flash` | `gemini-3.6-flash` |
| `openai_image_model` | `gpt-image-1` | `gpt-image-2` |
| Kimi `DEFAULT_MODEL` / section default | `kimi-k2.6` / `kimi-k2.7-code` | `kimi-k3` |
| Router verification: anthropic | `claude-opus-4-8` | `claude-opus-5` |
| Router verification: deepseek | `deepseek-reasoner` | `deepseek-v4-pro` |
| Router verification: kimi | `moonshot-v1-128k` | `kimi-k3` |
| Router verification: gemini | `gemini-2.5-pro` | `gemini-3.1-pro` |
| OpenAI image tool `DEFAULT_MODEL` | `gpt-image-1` | `gpt-image-2` |
| DeepSeek research-tool fallbacks (pro ×9 + deep-research) | `deepseek-chat` | `deepseek-v4-flash` |

### Pricing Fixes
| Model | Old | New | Notes |
|---|---|---|---|
| `deepseek-v4-flash` input | $0.014/1M | $0.14/1M | Catalog entry was 10x too low. |
| `deepseek-v4-pro` | $1.74/$3.48 | $0.435/$0.87 | Official pricing per DeepSeek's pricing page (Aug 2026). |
| `gemini-2.5-flash` (usage tracker) | $0.075/$0.30 | $0.30/$2.50 | Tracker was 10x off; aligned with catalog. |
| `claude-opus-4-5/4-6/4-7` input | $15/1M | $5/1M | July fix finally applied in the cost calculator. |

### Migration Map Fixes
| Legacy ID | Old Target | New Target |
|---|---|---|
| `claude-mythos-preview` | `claude-opus-4-8` | `claude-mythos-5` |
| `gemini-3.1-flash` | — | `gemini-3.5-flash` |
| `imagen-4` | — | `gemini-3.1-flash-image` |
| `deepseek-reasoner` / `deepseek-coder` | `deepseek-v4-flash` | `deepseek-v4-pro` |
