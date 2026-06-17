# E-commerce Blueprint Examples

This directory contains three curated assistant blueprints for the E-commerce
Toolkit. Each blueprint models a distinct role within a WooCommerce store and
comes pre-configured with the relevant toolset, AI model, and system prompt.

| Blueprint | File | Role Summary |
|---|---|---|
| **E-commerce Store Manager** | `store-manager.json` | Full-service store manager: monitors sales, processes orders, manages inventory, creates discount campaigns, handles customer segmentation, and runs abandoned cart recovery. |
| **Product Merchandiser** | `product-merchandiser.json` | Creates compelling product listings with AI-generated images, descriptions, and competitive pricing. Handles bulk product import, image optimization, and cross-sell setup. |
| **Order Fulfillment Manager** | `order-fulfillment.json` | End-to-end order processor: reviews orders, estimates shipping, syncs inventory, processes refunds, and manages customer communication from purchase to delivery. |

All blueprints use the OpenAI `gpt-4.1` model with low temperatures
(0.2–0.6) to balance accuracy with creative product descriptions.

## Import

Use the `import_ecommerce_blueprint` tool (implemented in
`class-wp-mcp-ai-tool-import-ecommerce-blueprint.php`) to install a
blueprint programmatically. The tool delegates to the shared
`WP_MCP_AI_Blueprint_Installer` for file loading, duplicate detection,
post insertion, and meta population.

## Schema

All JSON files conform to the schema at:
`https://schemas.nvdigitalsolutions.com/mcp-ai/assistant-blueprint.schema.json`
