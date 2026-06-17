# Research Toolkit

> Generic AI-assisted content research tools for posts, pages, products, projects, and policies.

## Purpose

Cross-cutting research tools that perform web research, data gathering, and AI-assisted content generation for various WordPress content types. These tools are domain-agnostic and complement the per-toolkit research tools (e.g., `research_eca`, `research_place`).

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Research Blog Post | `research_blog_post` | Research and draft blog post content |
| Research Page | `research_page` | Research and draft page content |
| Research Policy | `research_policy` | Research regulatory/compliance policy content |
| Research Post | `research_post` | Research and draft generic post content |
| Research Product | `research_product` | Research product information and specs |
| Research Project | `research_project` | Research project-related content |

## Shared Trait

`Trait_WP_MCP_AI_Tool_Research_Template_Analysis` — provides template analysis and structured data extraction shared across research tools.

## Dependencies

- WordPress 6.0+
- AI provider (OpenAI, Gemini, or Ollama)

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
