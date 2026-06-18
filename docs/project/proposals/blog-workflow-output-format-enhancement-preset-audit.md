# Content Generation Presets — Complete Audit & Enhancement Scope

> **Part of:** `docs/project/proposals/blog-workflow-output-format-enhancement.md`  
> **Date:** 2026-06-18  
> **Purpose:** Catalogues every preset that creates content to ensure the featured-image and content-format enhancements are applied systemically — not just to blog presets.

---

## Audit: All 16 Pertinent Presets

A systematic scan of all 21 schedule-preset toolkits (~80+ presets) and 10+ workflow-preset categories (~30+ presets) reveals **16 presets across 6 toolkits** that create WordPress content and are affected by the featured-image and content-format gaps.

### Schedule Presets — `assistant_run` type (10 presets)

These presets dispatch an AI assistant with a hardcoded `message` prompt. The assistant autonomously chooses which tools to call.

#### Tier 1: Direct Content Creators (must fix — Phase 1)

| # | Preset ID | What It Creates | Existing Gap |
|---|-----------|-----------------|-------------|
| 1 | `weekly_blog_post_writer` | Blog post draft via `create_post` | Prompt doesn't mention image gen; no template |
| 2 | `weekly_blog_topic_research` | Blog topic research brief | Prompt doesn't ask for image prompt recommendations; no template |

**Fix**: Update AI prompt to add `STEP 2: Generate featured image using generate_openai_image` before `create_post`. Add `template` reference key.

#### Tier 2: Research & Strategy (should fix — Phase 3)

| # | Preset ID | What It Creates | Reason |
|---|-----------|-----------------|--------|
| 3 | `blog_editorial_calendar` | 30-day editorial calendar | Calendar format would benefit from user-customisable template |
| 4 | `content_gap_analysis` | Content gap research report | Competitive analysis report would benefit from structured format template |

**Fix**: Add `template` reference key so users can control output format.

#### Tier 3: Reports & Audits (nice to have — Phase 3)

| # | Preset ID | What It Creates | Reason |
|---|-----------|-----------------|--------|
| 5 | `post_seo_audit` | SEO audit report | Audit format would benefit from user-customisable template |
| 6 | `post_performance_report` | Monthly performance report | Report format would benefit from structured output |
| 7 | `landing_page_performance` | Landing page CRO report | Report format would benefit from structured output |
| 8 | `page_meta_audit` | Metadata completeness report | Audit format would benefit from structured output |
| 9 | `media_alt_text_audit` | Alt text compliance report | Audit format would benefit from structured output |
| 10 | `image_seo_report` | Image SEO report | Report format would benefit from structured output |

**Fix**: Add optional `template` reference key (backward-compatible — null = current behaviour).

---

### Workflow Presets — `workflow` type (6 presets)

These presets define explicit node DAGs with specific tool calls.

#### Tier 1: Missing Image Generation Node (must fix — Phase 1)

| # | Preset ID | Category | Nodes | Gap |
|---|-----------|----------|-------|-----|
| 11 | `content_pipeline` | content | Input → web_search → summarise → **create_post** → seo_meta_optimizer → save_post → output | Missing `generate_openai_image` node. `create_post` missing `featured_image_id`. |
| 14 | `content_brief_generator` | seo | keyword_research → cluster_keywords → **create_post** (draft briefs) | Missing `generate_openai_image` node. |

**Fix**: Insert `generate_openai_image` node before `create_post`. Wire `{{node_X.attachment_id}}` into `create_post`'s `featured_image_id`.

#### Tier 2: Has Image Gen Node But Missing Passthrough (must fix — Phase 1)

| # | Preset ID | Category | Nodes | Gap |
|---|-----------|----------|-------|-----|
| 13 | `social_media_campaign` | content | ... → **generate_openai_image** (node_5) → **create_post** (node_6) → ... | `generate_openai_image` exists but `create_post` doesn't use its `attachment_id`. |
| 15 | `product_listing_creator` | ecommerce | ... → **generate_openai_image** → ... → **save_post** | Image gen exists but `save_post` missing `featured_image_id`. |
| 16 | `onboarding_welcome` | onboarding | ... → **generate_openai_image** → **create_post** (welcome page) | Image gen exists but `create_post` missing `featured_image_id`. |

**Fix**: Update `create_post`/`save_post` node arguments to include `'featured_image_id' => '{{node_X.attachment_id}}'`.

#### Tier 3: Already Complete (no changes needed)

| # | Preset ID | Category | Status |
|---|-----------|----------|--------|
| 12 | `content_refresh` | content | Already has `generate_openai_image` node. Already a content refresh workflow (not post creation). ✅ |

---

## Modification Summary

### By File

| File | Presets to Modify | Changes |
|------|------------------|---------|
| `class-wp-mcp-ai-pro-schedule-presets.php` | #1–10 (10 presets) | Add `template` key to `assistant_config`; update message for #1, #2 to include image gen step |
| `class-wp-mcp-ai-pro-workflow-presets.php` | #11, #13–16 (5 presets) | Insert image gen nodes where missing; wire `featured_image_id` refs where missing |
| `class-wp-mcp-ai-result-delivery-service.php` | (all presets) | Add `featured_image_id` to `send_wordpress_post()`; add auto-generate option |

### By Phase

| Phase | Presets | Effort | Risk |
|-------|---------|--------|------|
| **Phase 1** (Day 1) | #1, #2, #11–16 (7 presets) — all that create posts | ~1.5 hrs | Low — prompt + node edits |
| **Phase 3** (Days 3–4) | #3–10 (8 presets) — research & report presets | ~1 hr | Low — additive template keys |

### Variable Passthrough Map (Workflow Presets)

For each workflow preset that needs `{{node_X.attachment_id}}` → `create_post`:

| Preset | Image Gen Node | Post Node | Variable |
|--------|---------------|-----------|----------|
| `content_pipeline` | `node_3b` (new) | `node_4` (create_post) | `{{node_3b.attachment_id}}` |
| `social_media_campaign` | `node_5` (existing) | `node_6` (create_post) | `{{node_5.attachment_id}}` |
| `product_listing_creator` | existing image node | existing save_post node | variable from image node |
| `onboarding_welcome` | existing image node | existing create_post node | variable from image node |
| `content_brief_generator` | new image node | existing create_post node | variable from new node |

### Validation Checklist

After implementing Phase 1:

- [ ] `weekly_blog_post_writer` AI prompt mentions `generate_openai_image` before `create_post`
- [ ] `weekly_blog_topic_research` AI prompt asks for DALL-E prompt per topic
- [ ] `content_pipeline` workflow has `generate_openai_image` node + wired `featured_image_id`
- [ ] `social_media_campaign` `create_post` node receives `featured_image_id`
- [ ] `product_listing_creator` `save_post` node receives `featured_image_id`
- [ ] `onboarding_welcome` `create_post` node receives `featured_image_id`
- [ ] `content_brief_generator` has `generate_openai_image` node + wired `featured_image_id`
- [ ] `send_wordpress_post()` in Result Delivery Service sets `featured_image_id` on created post
- [ ] All schedule presets' hardcoded `message` strings preserved for backward compat
- [ ] New `template` keys are optional — null → current behaviour
- [ ] Cross-node variable resolution works for `attachment_id` field
