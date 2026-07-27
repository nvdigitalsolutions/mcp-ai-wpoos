# Agent Skills

> **Specification:** [agentskills.io](https://agentskills.io/specification)  
> **Since:** 1.7.0  
> **Available in:** Base plugin (skill registry + 45 bundled skills) and Pro add-on (Skill Manager UI + extra plugin-ecosystem skills)

Agent Skills are portable, reusable behaviour packages that teach an assistant how to handle a specific class of task. Each skill is a single `SKILL.md` file — standard Markdown with a small YAML frontmatter block — stored under `wp-content/uploads/mcp-ai-skills/{skill-name}/SKILL.md`. When an assistant runs, any skills assigned to it are automatically injected into the system prompt so the model knows exactly when and how to use them.

> **OKF v0.1 Conformance (July 2026):** All bundled skills now include `type: Skill` in their YAML frontmatter, making them fully conformant with Google's [Open Knowledge Format v0.1](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) specification. This means skills are navigable as OKF concepts via the 6 OKF MCP tools (`okf_read_concept`, `okf_browse`, `okf_traverse`, `okf_search`, `okf_write_concept`, `okf_delete_concept`). See [`docs/features/okf-integration.md`](okf-integration.md) for details.

---

## Pre-Built Skills (Base Plugin)

The base plugin ships with **45 pre-built skills** inside `includes/bundled-skills/`. They are copied to `wp-content/uploads/mcp-ai-skills/` automatically on first plugin activation. **No Pro add-on is required** — all base skills are available on every install.

### General-purpose skills (Anthropic-authored)

| Skill slug | Description |
|---|---|
| `algorithmic-art` | Generates algorithmic art using p5.js with seeded randomness and interactive parameters |
| `brand-guidelines` | Applies Anthropic's official brand colours and typography to any artifact |
| `browser-use` | Drives a browser via Playwright to research, scrape, or interact with web apps |
| `canvas-design` | Creates beautiful visual art in PNG/PDF documents using design philosophy |
| `code-reviewer` | Reviews code for quality, simplicity, and maintainability before presenting it |
| `doc-coauthoring` | Guides users through a structured co-authoring workflow for documentation |
| `docx` | Creates, reads, edits, and manipulates Word `.docx` files |
| `excalidraw-diagram` | Creates Excalidraw-style diagrams via the JSON spec |
| `frontend-design` | Produces distinctive, production-grade frontend interfaces with high design quality |
| `internal-comms` | Drafts all kinds of internal communications (memos, announcements, updates) |
| `karpathy-coding-principles` | Coding behaviour guidelines distilled from Andrej Karpathy's observations |
| `mcp-builder` | Guides creation of high-quality MCP (Model Context Protocol) servers |
| `pdf` | Handles any PDF task — creation, reading, editing, and form filling |
| `planetscale` | PlanetScale-specific schema, branching, and deploy-request workflow |
| `pptx` | Handles any `.pptx` PowerPoint file as input or output |
| `remotion` | Builds React-driven programmatic video using Remotion |
| `shannon` | Authorisation, capability, and access-control reasoning helper |
| `skill-creator` | Creates, modifies, and measures the performance of other skills |
| `slack-gif-creator` | Creates animated GIFs optimised for Slack with design best practices |
| `theme-factory` | Applies consistent visual themes to slides, docs, and other artifacts |
| `ui-ux-pro-max` | Comprehensive UI/UX design system with component libraries, color palettes, typography scales, and stack-specific guidelines (React, Vue, Angular, Laravel, Flutter, SwiftUI, etc.) |
| `valyu` | Valyu-API research and retrieval skill |
| `web-artifacts-builder` | Builds elaborate multi-component HTML artifacts for Claude.ai |
| `webapp-testing` | Tests local web applications using Playwright browser automation |
| `xlsx` | Handles any spreadsheet file as primary input or output |

### WordPress-developer skills

Curated from the MIT-licensed [`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills) catalogue (see [`includes/bundled-skills/THIRD_PARTY_NOTICES.md`](../../includes/bundled-skills/THIRD_PARTY_NOTICES.md) for attribution and license text).

**Security & quality**

| Skill slug | Description |
|---|---|
| `wp-security-audit` | Audits PHP code for the most common WP plugin/theme security mistakes (nonces, caps, sanitization, XSS, SQL, AJAX nopriv, redirects) |
| `wp-security-deep` | Deeper second-pass review for object injection, SSRF, CSRF on GET, mass assignment, file include, mail/zip injection, timing comparison, and TOCTOU races |
| `wp-security-secrets` | Reviews secret handling — hardcoded credentials, weak token randomness, password storage, cookie flags, secrets in logs |
| `wp-i18n-audit` | Audits translation correctness — text domain, escape order with placeholders, translator comments, JS i18n |

**Core WordPress APIs**

| Skill slug | Description |
|---|---|
| `wp-rest-api` | Idiomatic REST controllers, schema, permission callbacks, sanitize/validate args |
| `wp-abilities-api` | Building abilities exposed to AI tools and the WP Abilities Manager |
| `wp-html-api` | Safe HTML manipulation with the WP HTML API processor |
| `wp-utf8-text` | UTF-8-safe text manipulation, length and slicing without `mb_*` pitfalls |
| `wp-query-cache` | `WP_Query` caching, `cache_results`, `update_post_meta_cache`, and object-cache patterns |

**Plugin scaffolding (`plugin-scaffold/*`)**

| Skill slug | Description |
|---|---|
| `wp-action-scheduler` | Scheduling background work with Action Scheduler (vs `wp_schedule_event`) |
| `wp-plugin-architecture` | Plugin architecture patterns — namespaces, autoloaders, service container |
| `wp-plugin-assets-loading` | `wp_enqueue_script`/`wp_enqueue_style` patterns, conditional loading, dependencies |
| `wp-plugin-bootstrap` | The mainfile pattern — entry point, constants, dependency check, init hook |
| `wp-plugin-cron` | `wp_schedule_event`, `wp_schedule_single_event`, hook registration timing |
| `wp-plugin-dto` | Data-transfer-object pattern for plugin internals |
| `wp-plugin-hooks` | Hook design — naming, parameter design, applying vs doing, removal patterns |
| `wp-plugin-lifecycle` | Activation, deactivation, uninstall, upgrade routines and DB versioning |
| `wp-plugin-options-storage` | `get_option`/`update_option` patterns, autoload flags, schema migration |
| `wp-plugin-presenter` | Presenter pattern for separating template logic from data |
| `wp-plugin-rewrite-rules` | `add_rewrite_rule`, `add_rewrite_endpoint`, query var registration, flush timing |

### Re-installing Bundled Skills

If a bundled skill is accidentally deleted or you want to reset customisations:

1. Go to **Settings → Advanced → Skill Management**
2. Click **Install Bundled Skills** to add any missing skills without touching existing ones, or
3. Click **Force Reinstall Bundled Skills** to reset all bundled skills to their shipped versions (custom edits to bundled skills will be overwritten).

---

## Pre-Built Skills (Pro Add-on)

When the Pro add-on is active it contributes additional bundled skills under `addons/pro/includes/bundled-skills/`. These are installed alongside the base skills on Pro activation and use the same registry and uploads directory as base skills.

**Google Workspace CLI (Pro-exclusive, in-house)**

`gws-calendar`, `gws-docs`, `gws-drive`, `gws-gmail`, `gws-gmail-send`, `gws-meet`, `gws-shared`, `gws-sheets`, `gws-tasks`, `gws-workflow`, `gws-workflow-standup-report`.

**Plugin-ecosystem skills** (curated from MIT-licensed [`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills) — see [`addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md`](../../addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md)):

- **WooCommerce** — `wc-coupon-dynamic`, `wc-customer-and-sessions`, `wc-emails-classic`, `wc-hpos-compatibility`, `wc-payment-gateway`, `wc-product-search-select`, `wc-rest-api-v4`, `wc-shipping-method`, `wc-shipping-providers`, `wc-stripe-add-payment-method`, `wc-variations-data`, `wc-variations-pricing-filters`
- **WooCommerce Memberships / Subscriptions** — `wcm-access-discounts`, `wcm-data-model-subscriptions-link`, `wcm-membership-hooks`, `wcs-data-model-switching-gifting`, `wcs-renewal-scheduler`, `wcs-subscription-hooks`
- **JetEngine** — `je-dynamic-visibility-condition`, `je-listings-callback`, `je-query-builder-custom-type`
- **JetFormBuilder** — `jfb-action-events`, `jfb-action-external-api`, `jfb-action-item-decorator`, `jfb-action-messages`, `jfb-form-action`, `jfb-form-sidebar-panel`, `jfb-settings-tab`
- **WP Rocket** — `wp-rocket-cache-invalidation`, `wp-rocket-cache-rejection-and-filters`

These plugin-ecosystem skills don't require the matching plugin to be installed in order to *load* them — assistants can still produce code referencing them. They're shipped in Pro because they pair naturally with the Pro tools that operate against the same plugins.

---

## SKILL.md Format

Every skill is a single `SKILL.md` file following this format:

```yaml
---
name: my-skill
description: One-line description shown in the admin UI (max 1024 chars).
compatibility: claude-3-5-sonnet, claude-3-opus   # optional
---

# My Skill

Detailed instructions for the model go here in standard Markdown.
Use headings, lists, and code blocks to convey the expected behaviour clearly.
```

| Frontmatter field | Required | Max length | Notes |
|---|---|---|---|
| `name` | ✅ | 64 chars | Becomes the skill's slug; must be filesystem-safe |
| `description` | ✅ | 1 024 chars | Shown in the Skills meta box and Skill Manager list |
| `compatibility` | ❌ | 500 chars | Informational only; not enforced at runtime |
| `license` | ❌ | — | SPDX identifier for the skill (e.g. `MIT`, `GPL-3.0-or-later`) |
| `source` | ❌ | — | URL pointing to the upstream source for re-distributed skills |
| `source-license` | ❌ | — | License of the upstream source if different from `license` |

> **Parser note.** NV oOS ships a deliberately minimal YAML reader so it doesn't pull in a full YAML library. It supports flat `key: value` pairs and one-level indented maps. **Multi-line folded scalars and YAML lists (`- item` syntax) are not supported** — keep `description` on a single line and avoid list-valued frontmatter fields. Skills curated from upstream catalogues are pre-normalised to this format when bundled.

---

## Assigning Skills to an Assistant

1. Open an assistant post in **Assistants → Edit**.
2. Find the **Skills** meta box (below the system prompt).
3. Check any skills you want active for this assistant.
4. Save the post.

At inference time, the selected skills are combined into a single block prepended to the system prompt:

```
# Active Skills

You have the following specialized skills loaded. Use them when relevant to the user's request:

## algorithmic-art

<contents of SKILL.md body>

---

## pdf

<contents of SKILL.md body>
```

Skills are composable — you can assign as many as you need to a single assistant.

### Progressive disclosure (opt-in)

The default mode above is **eager**: every assigned skill's full body is injected into the system prompt on every turn. That keeps things simple, but it also fills the context window even when no assigned skill is relevant.

Each assistant has a **"Use progressive disclosure"** checkbox in its Skills meta box. When enabled:

1. The system prompt only receives a short catalogue (skill name + description) under an `# Available Skills` heading — full instructions are NOT included.
2. The base plugin auto-registers a `load_skill` tool. When the model decides a skill applies, it calls `load_skill({ name: "<skill-name>" })` and the full SKILL.md instructions are returned in the tool result.
3. The model uses those instructions for the rest of the turn.

This matches the pattern described at [agentskills.io](https://agentskills.io/specification) and is recommended whenever an assistant has more than a handful of assigned skills.

**Security model for `load_skill`:**

- The skill must be installed in the registry.
- The skill must be assigned to the current assistant via the Skills meta box. The model cannot load skills outside the administrator-approved list, even if it knows the slug.
- When called outside an assistant context (direct `/tools` REST call), the calling user must be authenticated (`read` capability).
- Loads are observable through the `wp_mcp_ai_skill_loaded` action, which receives `( string $skill_name, int $assistant_id )`.

---

## Skill Packs

A **skill pack** is a curated, named collection of related skills that ships as a single addressable unit. Use them when you want to install several related skills with one click and describe them as a coherent capability ("WordPress Developer", "Document Authoring", etc.).

The base plugin ships three packs out of the box:

| Pack slug | Members |
|---|---|
| `wordpress-developer` | `wp-abilities-api`, `wp-action-scheduler`, `wp-html-api`, `wp-i18n-audit`, `wp-plugin-architecture`, `wp-plugin-assets-loading`, `wp-rest-api`, `wp-security-audit`, `wp-security-deep` |
| `document-authoring`  | `docx`, `pdf`, `pptx`, `doc-coauthoring`, `internal-comms` |
| `ui-ux-design`        | `ui-ux-pro-max`, `frontend-design`, `canvas-design` |

**Install a pack** via *Settings → NV oOS → Advanced → Skill Management → Skill Packs*: each row shows the pack name, member skills, the installed-vs-total count, and an *Install Pack* button. Members already present in `wp-content/uploads/mcp-ai-skills/` are skipped (existing customisations are preserved).

**Register your own pack** with the `wp_mcp_ai_skill_packs` filter:

```php
add_filter(
    'wp_mcp_ai_skill_packs',
    function ( $packs ) {
        $packs['my-team-pack'] = array(
            'slug'        => 'my-team-pack',
            'name'        => __( 'My Team Pack', 'my-textdomain' ),
            'description' => __( 'Skills my team always wants on every assistant.', 'my-textdomain' ),
            'skills'      => array( 'wp-rest-api', 'wp-security-audit', 'docx' ),
        );
        return $packs;
    }
);
```

Pack installation runs through the existing bundled-skills pipeline, so SKILL.md companion files (reference docs, examples, JSON, images) are copied alongside the body. Each install attempt fires the `wp_mcp_ai_skill_pack_installed` action with `( $slug, $installed, $skipped, $errors )` for observability.

> Skill packs only handle *installation*. Assignment to an assistant continues to use the per-skill checkbox UI in the Skills meta box.

---

## Managing Skills

### Base Plugin (Settings → Advanced → Skill Management)

- View all installed skills with name, description, and slug
- Refresh the skill index (rescans `wp-content/uploads/mcp-ai-skills/`)
- Install bundled skills not yet present in uploads
- Force-reinstall all bundled skills to their shipped versions

### Pro Add-On (Assistants → Skill Manager)

The Pro add-on's dedicated **Skill Manager** page adds:

- **Upload SKILL.md** — drag-and-drop a `SKILL.md` file directly
- **Upload ZIP** — upload a ZIP archive containing a `{skill-name}/SKILL.md` directory structure
- **Install from URL** — fetch a `SKILL.md` from any public HTTPS URL
- **Browse Catalogues** — install skills one-click from registered remote catalogue sources (see below)
- **Inline editor** — create or edit `SKILL.md` content in a browser-based CodeMirror editor with Markdown syntax highlighting
- **Delete** — uninstall a skill and remove it from all assigned assistants

### Skill Catalogues (Pro)

A *catalogue* is a public Git repository (currently GitHub-only) containing one or more `SKILL.md` files. Pre-seeded with:

- **`Lonsdale201/wp-agent-skills`** — MIT-licensed WordPress-developer catalogue (security audits, REST/HTML/i18n APIs, plugin scaffold, WooCommerce, JetEngine, JetFormBuilder, WP Rocket).
- **`anthropics/skills`** — Anthropic's own catalogue of general-purpose skills.

Manage sources at **Assistants → Skill Settings → Catalogues**. Each source carries an `id`, `owner`, `repo`, and `ref` (branch, tag, or commit SHA — pin to a SHA for reproducibility).

Each source is read in this order:

1. If a top-level `catalogue.json` exists in the repo at the registered ref, its `skills[]` list is used directly.
2. Otherwise the **GitHub Git Tree API** is walked to discover every `SKILL.md` and the manifest is built on-the-fly.

Manifests are cached in WordPress transients (24-hour TTL by default; filterable with `wp_mcp_ai_skill_catalogue_manifest_ttl`) and refreshed daily by a `wp_mcp_ai_skill_catalogue_refresh` WP-Cron job (`wp_mcp_ai_skill_catalogue_refresh_cadence` filter).

**Security**: catalogue fetches reuse the same SSRF-safe HTTPS-only helper that protects `/skills/install-url` (private/loopback/reserved-IP rejection, DNS-rebind pinning, response-size cap), and the actual skill install funnels through `WP_MCP_AI_Skill_Registry::install_skill()` so the existing extension allowlist + decompression-bomb cap apply unchanged. Only paths present in the manifest may be installed — user-supplied paths are rejected.

**REST endpoints** (admin-only, `manage_options`):

- `GET /wp-json/mcp-ai-pro/v1/catalogues` — list registered sources.
- `GET /wp-json/mcp-ai-pro/v1/catalogues/{id}/skills` — manifest for one source (with `installed` and `update_available` flags per skill).
- `POST /wp-json/mcp-ai-pro/v1/catalogues/{id}/install` — install one skill by repo path.
- `POST /wp-json/mcp-ai-pro/v1/catalogues/{id}/refresh` — force-refresh a source's manifest cache.

---

## File System Layout

```
wp-content/
└── uploads/
    └── mcp-ai-skills/
        ├── algorithmic-art/
        │   └── SKILL.md
        ├── pdf/
        │   └── SKILL.md
        └── my-custom-skill/
            └── SKILL.md
```

The skill index is cached in the WordPress option `wp_mcp_ai_skill_index` and refreshed whenever a skill is installed, updated, or deleted.

---

## Developer API

### `WP_MCP_AI_Skill_Registry`

Singleton. Access via `WP_MCP_AI_Skill_Registry::instance()`.

| Method | Returns | Description |
|---|---|---|
| `get_all_skills()` | `array` | All installed skills keyed by slug |
| `get_skill( $slug )` | `array\|null` | Single skill by slug |
| `install_skill( $content )` | `array\|WP_Error` | Install/update a skill from raw `SKILL.md` content |
| `delete_skill( $slug )` | `bool\|WP_Error` | Remove a skill from uploads |
| `build_skills_prompt( $slugs )` | `string` | Build the `# Active Skills` system prompt block |
| `install_bundled_skills()` | `array` | Copy bundled skills to uploads; returns `installed`/`skipped`/`errors` counts |
| `get_bundled_skills_dir()` | `string` | Absolute path to `includes/bundled-skills/` |

### `WP_MCP_AI_Skill_Parser`

Parses raw `SKILL.md` content into structured data.

```php
$parser = new WP_MCP_AI_Skill_Parser();
$skill  = $parser->parse( $raw_content );
// $skill = [ 'name' => '...', 'description' => '...', 'body' => '...', 'compatibility' => '...' ]
```

Returns a `WP_Error` when required fields are missing or exceed length limits.

---

## Adding a Custom Skill Programmatically

```php
$registry = WP_MCP_AI_Skill_Registry::instance();

$skill_content = <<<MD
---
name: my-custom-skill
description: Demonstrates how to add a skill via PHP.
---

# My Custom Skill

When the user asks you to do X, follow these steps...
MD;

$result = $registry->install_skill( $skill_content );
if ( is_wp_error( $result ) ) {
    // Handle error.
    error_log( $result->get_error_message() );
}
```

---

## Related

- [Assistant Editor Overview](../../README.md#-assistant-editor-overview)
- [Assistant Tool Shortcuts](../getting-started/first-steps/assistant-tool-shortcuts.md)
- [Skill Registry source](../../includes/class-wp-mcp-ai-skill-registry.php)
- [Skill Parser source](../../includes/class-wp-mcp-ai-skill-parser.php)
- [Pro Skill Manager source](../../addons/pro/includes/admin/class-wp-mcp-ai-skill-manager-admin-page.php)
