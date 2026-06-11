# config/site-blueprints

Reusable subgraph templates for the node-graph site builder. Each `.json`
file defines a pre-wired collection of site nodes and edges with exposed
input/output ports — the ComfyUI "group into subgraph" equivalent.

## Blueprint JSON format

```jsonc
{
  "slug": "hero-with-cta",           // Must match filename (without .json)
  "name": "Hero Section with CTA",    // Human-readable palette label
  "description": "...",               // Tooltip text
  "version": "1.0.0",
  "inputs": {                         // Exposed input ports
    "heading": {
      "type": "string",
      "label": "Heading",
      "default": "Welcome"
    }
  },
  "outputs": {                        // Exposed output ports
    "html": { "type": "html", "node": "container", "port": "html" }
  },
  "internalGraph": {                  // The actual subgraph
    "nodes": {
      "text_1": { "slug": "text_block", "inputs": { "content": "{heading}" } },
      "container": { "slug": "flex_container", "inputs": { ... } }
    },
    "edges": [ ... ],
    "outputNode": "container"
  }
}
```

- `{placeholder}` values in node inputs are substituted at compile time
- Internal node IDs are prefixed with `slug__` to avoid collisions
- Blueprints are auto-discovered from directories registered via `wp_mcp_ai_site_blueprint_directories`

## Available blueprints

| Slug | Name | Description |
|------|------|-------------|
| `hero-with-cta` | Hero Section with CTA | Heading, subheading, and CTA button in a centered column |
| `two-column-text` | Two-Column Text Layout | Side-by-side feature columns with heading + body |
