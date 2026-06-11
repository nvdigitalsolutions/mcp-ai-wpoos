# UI/UX & Onboarding Improvement Proposal
## NV oOS — Open Operator System WordPress Plugin

**Version:** 1.0  
**Date:** March 2026  
> **Status:** ✅ Implemented (v1.1.29) — Onboarding wizard, presets, welcome banner shipped

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Problem Analysis](#problem-analysis)
3. [Industry Research & Benchmarks](#industry-research--benchmarks)
4. [Proposed Solution Architecture](#proposed-solution-architecture)
5. [Onboarding Wizard Design](#onboarding-wizard-design)
6. [Quick-Setup Presets (Use-Case Templates)](#quick-setup-presets-use-case-templates)
7. [Admin Dashboard UX Improvements](#admin-dashboard-ux-improvements)
8. [Advanced Feature Discoverability](#advanced-feature-discoverability)
9. [Implementation Plan](#implementation-plan)
10. [Success Metrics](#success-metrics)

---

## Executive Summary

NV oOS is a feature-rich AI assistant framework, yet its depth creates a barrier for new users. Compared to mainstream AI plugins (Bertha.ai, ContentBot, AIKit), the current setup process requires domain knowledge of concepts like "professions," "assistants," tool registries, and orchestration before users can gain any value.

This proposal introduces a **multi-step onboarding wizard**, **one-click use-case presets**, a **contextual welcome notice**, and structural UX refinements that eliminate the cold-start problem—without reducing power for advanced users.

---

## Problem Analysis

### Pain Points Identified

| # | Problem | User Impact | Priority |
|---|---------|-------------|----------|
| 1 | No guided first-run experience after activation | Users are dropped into a dense settings page with no direction | Critical |
| 2 | API key entry buried inside a settings tab | Users fail to connect an AI provider and think the plugin is broken | Critical |
| 3 | No "one-click" setup for common scenarios (content writing, customer support, etc.) | High time-to-value; many users churn | High |
| 4 | Advanced features (vector memory, playbooks, orchestration) appear at the same level as basic ones | Cognitive overload; beginners feel the plugin is not for them | High |
| 5 | No in-context help or tooltip layer for complex concepts | Users must read external docs before understanding the admin | Medium |
| 6 | No completion indicator to track setup progress | Users don't know if they've finished configuring the plugin | Medium |
| 7 | Plugin action links in the WP Plugins list are minimal | Lost opportunity to guide users back to the wizard | Low |

### Root Cause

The plugin was built feature-first with expert users in mind. Each powerful feature received its own admin page or settings tab, but **no narrative thread** exists to guide a new user from zero to their first working AI chat.

---

## Industry Research & Benchmarks

### Best-in-Class WordPress Plugin Onboarding

#### WooCommerce
- **Auto-redirect** to setup wizard immediately after activation
- **5-step wizard** (Store details → Industry → Product types → Business details → Theme)
- Progress bar at the top of each step
- Contextual help text on every field
- "Skip" option on every step

#### Yoast SEO
- **"First-time Configuration"** wizard accessible from the admin notice banner
- Wizard hides the WordPress admin chrome (full-page layout)
- Plain-language step labels ("Tell us about your site", "Social Profiles", etc.)
- Completion confetti and a "Go to your dashboard" CTA

#### WPForms
- **Welcome screen** with three paths: "Create Your First Form", "Watch a Demo", "Read the Docs"
- Inline feature tooltips with dismiss buttons
- Setup checklist widget on the WordPress dashboard

#### Bertha.ai / ContentBot (SaaS AI plugins)
- API key entry is **Step 1** — nothing else is shown until it's entered
- After API key, a "Choose your first use case" screen surfaces 3–5 curated templates
- One-click template selection pre-configures the entire assistant
- A persistent "Getting Started" menu item stays until all steps are complete

### Key Patterns Extracted

1. **Activation → Redirect**: Immediately redirect new users to a purpose-built page
2. **Full-page wizard layout**: Hide the normal WordPress chrome to reduce cognitive load
3. **Progressive disclosure**: Show only what's needed for each step; defer advanced options
4. **Preset-first UX**: Let users pick a use case before asking for configuration details
5. **Persistent progress indicator**: A checklist or progress bar that survives page refreshes
6. **Graceful exit**: Every step has a "Skip" or "Finish later" option with autosave
7. **Post-wizard nudge**: After completion, show a summary and highlight the next action

---

## Proposed Solution Architecture

```
Plugin Activation
      │
      ▼
[Onboarding Wizard]  (new: class-wp-mcp-ai-onboarding-wizard.php)
  Step 1: Welcome        — Plugin intro, two paths (Quick Setup / Custom)
  Step 2: AI Provider    — Enter API key; live "Test Connection" button
  Step 3: Use-Case Preset — Choose a preset (Blog, Support, E-commerce…)
  Step 4: Finish          — Summary, next-steps links, dismiss wizard
      │
      ▼
[Main Settings Dashboard]  (existing, no structural changes)
  + Welcome Banner (dismissible, shown until wizard is complete)
  + "Setup Checklist" widget on WordPress Dashboard
  + Tooltip layer on complex concepts
  + "Getting Started" sub-menu item (links back to wizard)
```

### Non-Destructive Principle

All wizard changes are **additive**. The existing settings dashboard, menu structure, and tool registry are not modified. The wizard is a new admin page that overlays the first-run experience.

---

## Onboarding Wizard Design

### Layout

The wizard uses a **full-admin-page layout** without the standard WordPress sidebar and footer (hidden via CSS). It renders inside `<div class="wrap">` with a custom card-based container.

```
┌─────────────────────────────────────────────────────────┐
│  🤖 NV oOS — Welcome                          [Skip →]  │
├─────────────────────────────────────────────────────────┤
│  ● Step 1  ──── ○ Step 2  ──── ○ Step 3  ──── ○ Done   │
├─────────────────────────────────────────────────────────┤
│                                                         │
│   [Step content area]                                   │
│                                                         │
├─────────────────────────────────────────────────────────┤
│                          [← Back]   [Next Step →]       │
└─────────────────────────────────────────────────────────┘
```

### Step 1 — Welcome

**Headline:** "Welcome to NV oOS — Your AI Command Center"

**Body:** Short (3-sentence) value proposition. Two large buttons:
- **"Quick Setup (2 min)"** → proceeds through the wizard
- **"I'm an expert — Go to settings"** → skips the wizard and redirects to the settings dashboard; marks wizard as complete

### Step 2 — Connect an AI Provider

**Headline:** "Connect Your AI Brain"

**Fields:**
- Provider selector (OpenAI / Google Gemini / Ollama — local)
- API key input (password field with "Show" toggle)
- "Test Connection" button (AJAX — reuses existing `wp_mcp_ai_test_connection` handlers)
- Inline success/error feedback

**Logic:** The step is considered complete when a successful connection test has been returned, OR when the user clicks "Skip for now" (they can configure this later). The wizard saves the API key to the existing settings option (`wp_mcp_ai_settings`).

### Step 3 — Choose Your Use Case

**Headline:** "What will you use NV oOS for?"

Up to 6 preset cards (icon + title + brief description). Selecting a card immediately applies the corresponding seeded configuration:

| Preset | What it seeds |
|--------|---------------|
| **Content Creator / Blogger** | Blog writing assistant, content tools enabled |
| **Customer Support Bot** | Support assistant, FAQ tools, live chat persona |
| **E-commerce Assistant** | Product description writer, WooCommerce tools (if active) |
| **SEO & Research** | Research assistant, Brave/Tavily search tools |
| **Developer Copilot** | Code review assistant, GitHub/WP-CLI tools |
| **General Purpose** | Default assistant with balanced tool set |

Multiple selections are allowed. Selections are stored in `wp_mcp_ai_onboarding_presets` and the corresponding assistant seeder is called on wizard completion.

### Step 4 — You're All Set!

**Headline:** "🎉 NV oOS is ready!"

**Content:**
- Summary of what was configured (provider ✓, preset(s) applied ✓)
- "Your AI assistant is now available at [shortcode] or via Elementor"
- Three action buttons: "Open Chat", "View Settings", "Read the Docs"
- Marks `wp_mcp_ai_onboarding_complete` option as `1`

---

## Quick-Setup Presets (Use-Case Templates)

Presets are the fastest path to value. Each preset maps to a named seeder method that creates a default assistant and applies recommended tool/settings defaults.

### Preset Implementation

Presets are stored as a filterable array so that third-party addons (e.g., the Pro add-on) can register additional presets:

```php
// Filter hook for preset extensibility.
$presets = apply_filters( 'wp_mcp_ai_onboarding_presets', $default_presets );
```

### Default Preset Definitions

```php
array(
    'content_creator' => array(
        'label'       => 'Content Creator / Blogger',
        'icon'        => '✍️',
        'description' => 'Write blog posts, social media content, and email campaigns.',
        'tools'       => array( 'create_post', 'rewrite_content', 'summarize_content', 'seo_analysis' ),
        'assistant'   => 'Blog Writing Assistant',
    ),
    'customer_support' => array(
        'label'       => 'Customer Support Bot',
        'icon'        => '🎧',
        'description' => 'Answer FAQs, handle support tickets, and greet visitors.',
        'tools'       => array( 'search_posts', 'get_post', 'send_email' ),
        'assistant'   => 'Support Assistant',
    ),
    'ecommerce' => array(
        'label'       => 'E-commerce Assistant',
        'icon'        => '🛒',
        'description' => 'Write product descriptions and assist shoppers.',
        'tools'       => array( 'create_post', 'get_post', 'woocommerce_products' ),
        'assistant'   => 'E-commerce Assistant',
    ),
    'seo_research' => array(
        'label'       => 'SEO & Research',
        'icon'        => '🔍',
        'description' => 'Research topics, analyze keywords, and optimize content.',
        'tools'       => array( 'brave_search', 'tavily_search', 'seo_analysis' ),
        'assistant'   => 'Research Assistant',
    ),
    'developer' => array(
        'label'       => 'Developer Copilot',
        'icon'        => '💻',
        'description' => 'Code review, WP-CLI commands, and GitHub integration.',
        'tools'       => array( 'run_wp_cli', 'search_code', 'create_snippet' ),
        'assistant'   => 'Developer Assistant',
    ),
    'general' => array(
        'label'       => 'General Purpose',
        'icon'        => '🤖',
        'description' => 'A balanced assistant for everyday AI tasks.',
        'tools'       => array( 'create_post', 'search_posts', 'summarize_content' ),
        'assistant'   => 'General Assistant',
    ),
)
```

---

## Admin Dashboard UX Improvements

### 1. Welcome Banner (Admin Notice)

Shown on all admin pages until the wizard is completed or dismissed:

```
┌───────────────────────────────────────────────────────────────┐
│ 🤖 Welcome to NV oOS! Complete the 2-minute setup wizard to   │
│ configure your first AI assistant.  [Start Setup →]  [✕]      │
└───────────────────────────────────────────────────────────────┘
```

- Persists across page loads until `wp_mcp_ai_onboarding_complete = 1`
- Dismissal stored per-user in user meta (`wp_mcp_ai_notice_dismissed`)
- AJAX-based dismiss (no page reload)

### 2. "Getting Started" Sub-Menu Item

A new sub-menu page under **NV oOS** labeled **"Getting Started"** (with a ⭐ star prefix to stand out) that links to the wizard page. This item is removed once the wizard is complete to keep the menu clean.

### 3. WordPress Dashboard Checklist Widget

A `wp_dashboard_setup` widget titled **"NV oOS Setup Checklist"** showing:

- [ ] Connect an AI provider (API key)
- [ ] Choose a use-case preset
- [ ] Place the chat shortcode on a page

Each item links to the relevant settings tab. The widget is hidden once all items are checked.

### 4. Contextual Help Tabs

WordPress admin pages support a built-in contextual help tab (the "Help" dropdown in the top-right). The wizard page, settings dashboard, and assistant pages will each have a tab titled **"NV oOS Help"** with quick-reference information.

### 5. Setup Progress Bar

On the main settings dashboard header, a thin progress bar shows:  
`Setup: API Key ✓  |  First Assistant ✓  |  Chat Published ○`  
This bar disappears once all three milestones are met.

---

## Advanced Feature Discoverability

### Collapsible "Advanced" Sections

In the settings dashboard, complex sections (Vector Memory, Playbook Editor, Team Orchestration) are wrapped in a `<details>` disclosure widget with a "For advanced users" label. This collapses them by default for new installs.

This is a purely CSS/HTML change — no loss of functionality, just hidden by default.

### Contextual Tooltips

Small `(?)` icons next to each settings field (or group) open a tooltip with:
- Plain-language explanation of the setting
- A "Learn more →" link to the relevant docs page

Implementation uses native CSS `title` attributes for basic tooltips, with a JS upgrade for richer popovers.

### Feature Discovery Nudges

After the wizard, an optional "Discover More" panel in the NV oOS dashboard widget shows one new feature per week:
- Week 1: "Did you know? You can schedule content with the Cron Manager"
- Week 2: "Try Vector Memory to give your assistant long-term recall"

These are powered by a static array of tips (no external network calls).

---

## Implementation Plan

### Phase 1 — Core Onboarding Wizard (This PR)

| Task | File | Status |
|------|------|--------|
| Proposal document | `docs/UI_UX_ONBOARDING_PROPOSAL.md` | ✅ Done |
| Onboarding wizard class | `includes/admin/class-wp-mcp-ai-onboarding-wizard.php` | ✅ Done |
| Activation redirect transient | `mcp-ai-wpoos.php` | ✅ Done |
| Load wizard in settings init | `includes/admin/settings-dashboard-init.php` | ✅ Done |
| Welcome admin notice | Included in wizard class | ✅ Done |
| Getting Started sub-menu | Included in wizard class | ✅ Done |

### Phase 2 — Preset Seeding (Next PR)

- Wire preset selection in Step 3 to assistant seeder calls
- Add `wp_mcp_ai_apply_onboarding_preset` AJAX handler
- Extend `WP_MCP_AI_Task_Plan_Seeder` or `WP_MCP_AI_Profession_Orchestration_Seeder` to support preset configs

### Phase 3 — Dashboard UX Polish (Future)

- WordPress Dashboard checklist widget
- Contextual help tabs on all NV oOS admin pages
- Settings progress bar
- Collapsible advanced sections
- Tooltip layer

---

## Success Metrics

| Metric | Baseline | Target |
|--------|----------|--------|
| Wizard completion rate | 0% (no wizard exists) | ≥ 60% within 30 days of activation |
| Time to first chat (TTFC) | Unknown (no tracking) | < 5 minutes |
| Support tickets: "how do I set up" type | High | Reduce by 40% |
| User activation rate (API key entered within 24h) | Unknown | ≥ 70% |
| Settings page bounce rate | Unknown | Track as baseline |

---

## References

- WooCommerce Setup Wizard source: `wp-content/plugins/woocommerce/includes/admin/class-wc-admin-setup-wizard.php`
- Yoast SEO First-Time Configuration: `wp-content/plugins/wordpress-seo/src/first-time-configuration/`
- Appsero: [Improving First-Time User Experience for WordPress Plugins](https://appsero.com/user-guide/first-time-user-experience/)
- CreateIT: [WordPress Wizard in Admin — Step by Step](https://www.createit.com/blog/wordpress-wizard-in-admin-step-by-step/)
- GitHub: [tws-admin-onboarding — WordPress Admin Onboarding Wizard](https://github.com/TheWebSolver/tws-admin-onboarding)
- Nielsen Norman Group: [Progressive Disclosure](https://www.nngroup.com/articles/progressive-disclosure/)
- Nielsen Norman Group: [Wizard UX Design](https://www.nngroup.com/articles/wizards/)
