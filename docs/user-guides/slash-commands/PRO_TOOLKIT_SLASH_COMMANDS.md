# Toolkit Slash Commands — Reference

**Status**: ✅ Declarative registry (v2.2.0+)
**Applies to**: Base + Pro

## Overview

Toolkit slash commands are **declarative wrappers over the MCP tool
registry**. Every command is a small mapping entry — name, backing tool
slug, argument map, capability and documentation — registered by
`WP_MCP_AI_Slash_Command_Toolkit_Manager`. Execution is delegated through
`WP_MCP_AI_Slash_Command_Tool_Adapter` to
`WP_MCP_AI_Tool_Registry::execute_tool()`, so:

- business logic lives **in the tool, once** — capability checks, parameter
  validation, sanitisation and the canonical `{ success, message, data }`
  envelope are enforced by the tool layer;
- a command exists **only when a real tool backs it**. Placeholder
  commands ("Implementation coming soon") were removed in v2.2.0;
- commands for toolkits whose Pro addon is absent are simply not
  registered, and a missing tool surfaces as a friendly `tool_unavailable`
  error instead of a silent no-op.

New commands are added declaratively via
`wp_mcp_ai_register_tool_command()` (or `register_tool_command()` on the
handler). The same registry also feeds the MCP **prompts** bridge
(`WP_MCP_AI_Slash_Command_Prompts`), which exposes every command as a
`prompts/list` entry (`slash.<command>`) so MCP clients surface them as
user-invoked prompts.

## Command reference

### Content & Publishing (`content_publishing`)

| Command | Tool | Capability |
|---|---|---|
| `/content-draft --topic=... [--type=post]` | `create_post` (draft status) | `edit_posts` |
| `/seo-optimize --post_id=123` | `seo_meta_optimizer` | `edit_posts` |
| `/meta-generate --post_id=123` | `seo_meta_optimizer` | `edit_posts` |

### Media Processing (`media_processing`)

| Command | Tool | Capability |
|---|---|---|
| `/image-optimize --attachment_id=456` | `optimize_image_sharp` | `upload_files` |
| `/video-transcode --attachment_id=456 --output_format=mp4` | `transcode_video` | `upload_files` |
| `/watermark-add --video_id=123 --watermark_id=456` | `add_watermark_to_video` | `upload_files` |

### Data & Analytics (`data_analytics`)

| Command | Tool | Capability |
|---|---|---|
| `/chart-create --type=bar --title="Sales"` | `create_chart` | `edit_posts` |

### Developer & Technical (`developer_technical`)

| Command | Tool | Capability |
|---|---|---|
| `/code-analyze --language=php --code="..."` | `analyze_code_sequence` | `edit_posts` |

### Security & Compliance (`security_compliance`)

| Command | Tool | Capability |
|---|---|---|
| `/security-scan` | `check_site_security` | `manage_options` |

### Research & Discovery (`research_discovery`)

| Command | Tool | Capability |
|---|---|---|
| `/research-query --topic="..." --depth=standard` | `deep_research` | `edit_posts` |

### Calendar & Booking (`calendar_booking`)

| Command | Tool | Capability |
|---|---|---|
| `/booking-create --client_name=... --client_email=...` | `create_appointment` | `edit_posts` |

### CRM (`crm`)

| Command | Tool | Capability |
|---|---|---|
| `/lead-add --email=...` | `create_lead` | `edit_posts` |
| `/lead-qualify --lead_id=456` | `qualify_lead_bant` | `edit_posts` |
| `/lead-assign --lead_id=456 --owner_id=789` | `assign_lead_to_owner` | `edit_posts` |
| `/deal-create --lead_id=456 --deal_name=...` | `create_deal` | `edit_posts` |
| `/deal-move --deal_id=789 --new_stage=proposal` | `move_deal_stage` | `edit_posts` |
| `/pipeline-view [--deal_owner=789]` | `get_pipeline_view` | `edit_posts` |

### Document Generation (`document_generation`)

| Command | Tool | Capability |
|---|---|---|
| `/doc-create --title=... --description=...` | `pro_pdf_document` | `edit_posts` |

### E-Commerce Pro (`ecommerce_pro`)

| Command | Tool | Capability |
|---|---|---|
| `/abandoned-recover [--action=identify]` | `abandoned_cart_recovery` | `manage_woocommerce` |
| `/inventory-forecast --forecast_type=demand` | `inventory_forecast` | `manage_woocommerce` |
| `/create-discount-campaign --code=SAVE20 --discount_type=percent --amount=20` | `create_discount_campaign` | `manage_woocommerce` |

### Financial Planner (`financial_planner`)

| Command | Tool | Capability |
|---|---|---|
| `/budget-create --monthly_income=5000` | `budget_planner` | `edit_posts` |

### Image Production (`image_production`)

| Command | Tool | Capability |
|---|---|---|
| `/image-edit --prompt="remove background" [--attachment_id=456]` | `edit_gemini_image` | `upload_files` |

### Multilingual (`multilingual`)

| Command | Tool | Capability |
|---|---|---|
| `/content-translate --post_id=123 --target_language=es` | `auto_translate_content` | `edit_posts` |
| `/translate-content --post_id=123 --target_language=fr` | `auto_translate_content` | `edit_posts` |

### Social Media (`social_media`)

| Command | Tool | Capability |
|---|---|---|
| `/social-post --content=... --platform=twitter` | `schedule_social_post` | `edit_posts` |
| `/social-schedule --content=... --platform=linkedin --scheduled_time=...` | `schedule_social_post` | `edit_posts` |
| `/social-publish --platform=twitter --content=... [--dry_run=true]` | `publish_to_social` | `edit_posts` |
| `/social-analytics [--platforms=...]` | `get_social_analytics` | `edit_posts` |
| `/content-calendar [--platform=...]` | `get_content_calendar` | `edit_posts` |

### Video Production (`video_production`)

| Command | Tool | Capability |
|---|---|---|
| `/video-compress --video_id=123 --quality=medium` | `compress_video` | `upload_files` |
| `/video-trim --video_id=123 --start_time=10 --end_time=60` | `trim_video` | `upload_files` |
| `/video-merge --video_ids=1,2,3 [--transition=fade]` | `merge_videos` | `upload_files` |
| `/video-thumbnail --video_id=123 [--count=5]` | `generate_video_thumbnails` | `upload_files` |
| `/video-edit --edit_prompt="..." [--source_video_id=123]` | `edit_omni_video` | `upload_files` |
| `/get-video-metadata --video_id=123` | `get_video_metadata` | `upload_files` |

## Removed commands (v2.2.0)

The following placeholder commands were removed because no backing tool
existed. Their behaviour is available through the underlying MCP tools
(and the AI can be asked for the same outcome in plain chat):

`/upsell-suggest`, `/crosssell-suggest`, `/subscription-manage`,
`/wholesale-pricing`, `/marketplace-sync`, `/tax-calculate`,
`/return-process`, `/supplier-sync`, `/ecom-analytics`,
`/hashtag-suggest`, `/social-calendar`, `/social-engage`,
`/social-monitor`, `/trend-identify`, `/social-report`,
`/competitor-track`, `/post-optimize`, `/influencer-find`,
`/campaign-create`, `/customer-segment`, `/discount-optimize`,
`/bundle-create`, `/video-subtitle`, `/video-template`,
`/video-analytics`, `/video-publish`, `/video-effect`, `/video-music`,
`/video-transition`, `/video-storyboard`, `/video-render`,
`/video-voiceover`, `/prompt-library`, `/aitool-*`, `/model-deploy`,
`/funnel-analyze`, `/predict-churn`, `/metric-*`, `/goal-set`,
`/segment-advanced`, `/attribution-model`, `/cohort-analyze`, and the
other "Implementation coming soon" entries.

## Workflows

Built-in workflow definitions (see
`WP_MCP_AI_Slash_Command_Workflow_Orchestrator`) now chain only
registered, tool-backed commands. Purged command steps were replaced
with their closest live equivalent (e.g. analytics steps now use
`/abandoned-recover --action=get_analytics`; discount steps use
`/create-discount-campaign`).
