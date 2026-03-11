# Agent Skills

> **Specification:** [agentskills.io](https://agentskills.io/specification)  
> **Since:** 1.7.0  
> **Available in:** Base plugin (skill registry + 16 bundled skills) and Pro add-on (Skill Manager UI)

Agent Skills are portable, reusable behaviour packages that teach an assistant how to handle a specific class of task. Each skill is a single `SKILL.md` file — standard Markdown with a small YAML frontmatter block — stored under `wp-content/uploads/mcp-ai-skills/{skill-name}/SKILL.md`. When an assistant runs, any skills assigned to it are automatically injected into the system prompt so the model knows exactly when and how to use them.

---

## 16 Pre-Built Skills (Base Plugin)

The base plugin ships with **16 pre-built skills** inside `includes/bundled-skills/`. They are copied to `wp-content/uploads/mcp-ai-skills/` automatically on first plugin activation. **No Pro add-on is required** — all 16 skills are available on every install.

| Skill slug | Description |
|---|---|
| `algorithmic-art` | Generates algorithmic art using p5.js with seeded randomness and interactive parameters |
| `brand-guidelines` | Applies Anthropic's official brand colours and typography to any artifact |
| `canvas-design` | Creates beautiful visual art in PNG/PDF documents using design philosophy |
| `doc-coauthoring` | Guides users through a structured co-authoring workflow for documentation |
| `docx` | Creates, reads, edits, and manipulates Word `.docx` files |
| `frontend-design` | Produces distinctive, production-grade frontend interfaces with high design quality |
| `internal-comms` | Drafts all kinds of internal communications (memos, announcements, updates) |
| `mcp-builder` | Guides creation of high-quality MCP (Model Context Protocol) servers |
| `pdf` | Handles any PDF task — creation, reading, editing, and form filling |
| `pptx` | Handles any `.pptx` PowerPoint file as input or output |
| `skill-creator` | Creates, modifies, and measures the performance of other skills |
| `slack-gif-creator` | Creates animated GIFs optimised for Slack with design best practices |
| `theme-factory` | Applies consistent visual themes to slides, docs, and other artifacts |
| `web-artifacts-builder` | Builds elaborate multi-component HTML artifacts for Claude.ai |
| `webapp-testing` | Tests local web applications using Playwright browser automation |
| `xlsx` | Handles any spreadsheet file as primary input or output |

### Re-installing Bundled Skills

If a bundled skill is accidentally deleted or you want to reset customisations:

1. Go to **Settings → Advanced → Skill Management**
2. Click **Install Bundled Skills** to add any missing skills without touching existing ones, or
3. Click **Force Reinstall Bundled Skills** to reset all bundled skills to their shipped versions (custom edits to bundled skills will be overwritten).

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
- **Inline editor** — create or edit `SKILL.md` content in a browser-based CodeMirror editor with Markdown syntax highlighting
- **Delete** — uninstall a skill and remove it from all assigned assistants

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
