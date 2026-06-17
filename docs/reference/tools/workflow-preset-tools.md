# Workflow Preset Tools

**Status:** Stable — v1.1.31  
**Category:** Reference — Tools  
**Tool Count:** 36 tools across 10 toolkits

## Overview

Workflow preset tools are AI-accessible operations that expose pre-configured workflow templates to assistants. Each tool represents a reusable workflow preset — a named sequence of parameterized steps that an assistant can invoke with minimal arguments.

## Tool Index

### AI Tool Builder (`oos-toolkit-ai-tool-builder`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_ai_tool_builder_create` | Create AI Tool Builder Preset | `manage_options` |
| `workflow_preset_ai_tool_builder_list` | List AI Tool Builder Presets | `manage_options` |
| `workflow_preset_ai_tool_builder_execute` | Execute AI Tool Builder Preset | `manage_options` |

### Analytics (`oos-toolkit-analytics`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_analytics_report` | Generate Analytics Report | `manage_options` |
| `workflow_preset_analytics_dashboard` | Analytics Dashboard Snapshot | `manage_options` |
| `workflow_preset_analytics_export` | Export Analytics Data | `manage_options` |

### Architect Agent (`oos-toolkit-architect-agent`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_architect_agent_plan` | Generate Architecture Plan | `manage_options` |
| `workflow_preset_architect_agent_review` | Review Architecture | `manage_options` |
| `workflow_preset_architect_agent_estimate` | Estimate Project Effort | `manage_options` |

### Architectural Design (`oos-toolkit-architectural-design`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_arch_design_generate` | Generate Design Package | `manage_options` |
| `workflow_preset_arch_design_validate` | Validate Design Compliance | `manage_options` |
| `workflow_preset_arch_design_export` | Export Design Files | `manage_options` |
| `workflow_preset_arch_design_render` | Render 3D Visualization | `manage_options` |

### Calendar Booking (`oos-toolkit-calendar-booking`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_calendar_schedule` | Schedule Booking Preset | `manage_options` |
| `workflow_preset_calendar_availability` | Check Availability | `manage_options` |
| `workflow_preset_calendar_reminder` | Set Booking Reminder | `manage_options` |

### CRM (`oos-toolkit-crm`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_crm_onboard_lead` | Onboard Lead Preset | `manage_options` |
| `workflow_preset_crm_follow_up` | Follow-Up Sequence Preset | `manage_options` |
| `workflow_preset_crm_pipeline_report` | Pipeline Report Preset | `manage_options` |
| `workflow_preset_crm_merge_duplicates` | Merge Duplicates Preset | `manage_options` |

### Document Generation (`oos-toolkit-document-generation`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_docgen_generate` | Generate Document Preset | `manage_options` |
| `workflow_preset_docgen_template` | Apply Document Template | `manage_options` |
| `workflow_preset_docgen_batch` | Batch Document Generation | `manage_options` |

### DJ Management (`oos-toolkit-dj-management`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_dj_setlist` | Generate Setlist Preset | `manage_options` |
| `workflow_preset_dj_mix_analysis` | Analyze Mix | `manage_options` |
| `workflow_preset_dj_event_planner` | Event Planning Preset | `manage_options` |
| `workflow_preset_dj_library_organize` | Organize Music Library | `manage_options` |

### Social Media (`oos-toolkit-social-media`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_social_calendar` | Content Calendar Preset | `manage_options` |
| `workflow_preset_social_cross_post` | Cross-Platform Post Preset | `manage_options` |
| `workflow_preset_social_analytics` | Social Analytics Preset | `manage_options` |
| `workflow_preset_social_engage` | Engagement Workflow Preset | `manage_options` |

### Video Production (`oos-toolkit-video-production`)

| Slug | Name | Capability |
|------|------|-----------|
| `workflow_preset_video_storyboard` | Generate Storyboard Preset | `manage_options` |
| `workflow_preset_video_edit` | Video Edit Preset | `manage_options` |
| `workflow_preset_video_export` | Export Video Preset | `manage_options` |
| `workflow_preset_video_subtitle` | Generate Subtitles Preset | `manage_options` |

## Implementation Notes

- All tools extend `WP_MCP_AI_Tool_Base` and follow the canonical return envelope (success array or `WP_Error`).
- All tools implement the two-gate sanitisation rule (sanitize `$arguments` at entry, escape every value at exit).
- Action tools default to `dry_run: true` for safety.
- All tools carry the `pro` capability flag and are `read-only` where applicable.
- A comprehensive PHPUnit test suite validates class existence, interface implementation, slug resolution, metadata, and capability flags for all 36 tools.

## Version History

- **v1.1.31** — Initial implementation of all 34 missing workflow preset tools. Full PHPCS compliance and test suite. Orphaned media tools created. Upwork tool availability fixed.
