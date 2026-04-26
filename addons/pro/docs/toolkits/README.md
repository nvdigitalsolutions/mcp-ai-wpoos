# Pro Toolkit Documentation Index

The NV oOS Pro add-on bundles **25+ specialized toolkits**. Each toolkit is independently
toggleable from **WordPress Admin → NV oOS → Settings → Pro Features** and is loaded only
when both the Pro add-on is active and the toolkit's `enable_*` flag is set.

Each page below covers:

- What the toolkit is for and who it's for
- The activation setting key and admin location
- Custom post types (CPTs), CCTs, REST controllers, and admin pages it registers
- A summary of the tools it ships and a link to the canonical tool reference
- Common configuration, permissions, and troubleshooting notes

> **Tool-level READMEs** that ship inside `addons/pro/includes/tools/<toolkit>/` remain the
> canonical reference for individual tool slugs and parameters. The pages here are the
> user/operator-level overview that ties tools, CPTs, settings and admin UI together.

---

## Document & Content Management

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Document Generation | `enable_document_generation_toolkit` | 14 | [document-generation.md](document-generation.md) |
| Multilingual Content | `enable_multilingual_toolkit` | 10 | [multilingual.md](multilingual.md) |

## Media & Creative Production

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Media (Sharp) | `enable_media_toolkit` | 30+ | [media.md](media.md) |
| Image Production | `enable_image_production_toolkit` | 15 | [image-production.md](image-production.md) |
| Video Production | `enable_video_production_toolkit` | 13 | [video-production.md](video-production.md) |

## Business Management

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Project Management | `enable_project_management` | CPTs + tools | [project-management.md](project-management.md) |
| CRM & Email Marketing | `enable_crm_toolkit` | 10 | [crm.md](crm.md) |
| Financial Planner | `enable_financial_planner_toolkit` | 30+ | [financial-planner.md](financial-planner.md) |

## E-commerce & Sales

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| E-commerce (WooCommerce) | `enable_ecommerce_toolkit` | 20 | [ecommerce.md](ecommerce.md) |

## Social Media & Analytics

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Social Media Management | `enable_social_media_toolkit` | 15 | [social-media.md](social-media.md) |
| Advanced Analytics | `enable_analytics_toolkit` | 12 | [analytics.md](analytics.md) |
| Chat Channels Integration | `enable_chat_channels_toolkit` | varies | [chat-channels.md](chat-channels.md) |

## Development & Architecture

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Site Creator | `enable_site_creator_toolkit` | 27 | [site-creator.md](site-creator.md) |
| Architect Agent | `enable_architect_agent_toolkit` | 4 | [architect-agent.md](architect-agent.md) |
| Architectural Design | `enable_architectural_design_toolkit` | 17 | [architectural-design.md](architectural-design.md) |
| AI Tool Builder | `enable_ai_tool_builder_toolkit` | 11 | [ai-tool-builder.md](ai-tool-builder.md) |
| Skills Manager | (always-on with Pro) | n/a | [skills-manager.md](skills-manager.md) |

## Scheduling & Events

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Calendar Booking | `enable_calendar_booking_toolkit` | 15 | [calendar-booking.md](calendar-booking.md) |
| DJ Management | `enable_dj_management_toolkit` | 18 | [dj-management.md](dj-management.md) |

## Healthcare & Compliance

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Health & Wellness Management | `enable_health_wellness_management` | 15+ | [health-wellness.md](health-wellness.md) |
| Healthcare Imaging | `enable_healthcare_imaging` | viewer + REST | [healthcare-imaging.md](healthcare-imaging.md) |
| Regulatory Registration | `enable_regulatory_registration_toolkit` | 59 | [regulatory-registration.md](regulatory-registration.md) |

## Legal & Financial Services

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Law Firm | `enable_law_firm_toolkit` | 64 | [law-firm.md](law-firm.md) |
| CRE Debt & Securitization | `enable_cre_debt_toolkit` | 57 | [cre-debt.md](cre-debt.md) |

## Location, Education & Data

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Places Management | `enable_places_management` | CPT + tools | [places-management.md](places-management.md) |
| Quiz System | `enable_quiz_system` | CPT + tools | [quiz-system.md](quiz-system.md) |
| ECA Management | `enable_eca_management` | 20+ | [eca-management.md](eca-management.md) |
| Vector Storage Pro Tools | (auto with Pro) | 1+ | [vector-storage.md](vector-storage.md) |

## Security & Cognition

| Toolkit | Setting | Tools | Doc |
|---|---|---|---|
| Password Vault Manager | (always-on with Pro) | 2 + UI | [password-vault.md](password-vault.md) |
| Extended Cognition | `enable_extended_cognition_toolkit` | 7 | [extended-cognition.md](extended-cognition.md) |

---

## Conventions used in toolkit pages

- **Activation setting** — option key in `wp_mcp_ai_settings` (saved by **NV oOS → Settings → Pro Features**).
- **Toolkit-specific settings** — separate `wp_mcp_ai_*_settings` option for per-toolkit options where applicable (e.g. `wp_mcp_ai_law_firm_settings`).
- **Custom Post Types** — registered only when the toolkit is enabled and the plugin is **not** running in Base mode.
- **Capability** — defaults to `manage_options`. Individual tools may declare more granular capabilities; check the tool's PHP class for `get_required_capability()`.
- **Base vs. Pro** — Pro toolkits never load when `WP_MCP_AI_BASE_VERSION` is `true`. See [`addons/pro/README.md`](../../README.md) for the full Base vs. Pro comparison.

For implementation details, see [`TOOLKIT_ARCHITECTURE.md`](../TOOLKIT_ARCHITECTURE.md) and
[`TOOLKIT_ARCHITECTURE_PATTERNS.md`](../TOOLKIT_ARCHITECTURE_PATTERNS.md).
