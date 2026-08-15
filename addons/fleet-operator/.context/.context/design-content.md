# Design Stack & Marketing Content Skills

Load when the task is marketing / content / design for the NV oOS Pro Toolkit (Sophie / The Parfumerie ecosystem). All 30 `design-*` skills live in `~/.hermes/skills/`; a curated subset is in `.agents/skills/`. **Load the full `SKILL.md` only when the task matches** (progressive disclosure).

## Load by task type

| Task mentions… | Load skill(s) |
|----------------|---------------|
| campaign, monthly plan, SOP, theme | `design-campaign-orchestration` |
| calendar, schedule, pillars | `design-content-calendar` |
| brand, logo, identity | `design-brand-kit`, `design-color-systems`, `design-typography` |
| SEO, meta, schema, keywords | `design-seo-content` |
| captions, hashtags, hooks | `design-social-content` |
| publishing, platform posting | `design-social-publishing` |
| research → blog post | `design-content-research`, `design-deep-research`, `design-web-research` |
| product copy, stock, WooCommerce | `design-product-research`, `design-product-photography` |
| email / newsletter / SMS | `design-email-marketing`, `design-communications` |
| reports, ROI, KPIs | `design-analytics-reporting`, `design-document-generation` |
| images / video | `design-image-generation`, `design-image-optimization`, `design-media-workflow`, `design-video-creation` |
| CRM / projects / teams | `design-crm`, `design-project-management`, `design-team-management` |
| workflows, schedules | `design-pro-workflow-builder`, `design-pro-schedule-manager` |
| assistant / mesh admin | `design-ai-assistant-admin` |
| vault / secrets | `design-vault`, `design-security-ops` |

## Default operating rules (Sophie SOP)

- **Product data priority:** live WooCommerce via `remote_wp_connection` → `Products_Export_Converted.json` → brand/category cross-check.
- Only recommend **in-stock** products; link with `Permalink` values; no cash on delivery; MintPay/KOKO 3-payment option.
- Monthly cadence: 1 reel/week, 2–3 static posts/week, stories daily or alternate days; final plan presented by the 1st week of the month.
- Recurring monthly posts: islandwide delivery, complimentary gift wrapping, e-gift vouchers.
- The `sophie` / `ask-sophie` prompts on the remote site (via MCP `get_prompt`) hold the full persona rules — fetch them for brand-specific work.
