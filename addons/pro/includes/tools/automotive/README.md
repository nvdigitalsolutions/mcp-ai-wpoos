# Automotive Toolkit

> Vehicle estimation, VIN decoding, and automotive service tools.

## Purpose

Tools for vehicle identification (VIN decoding), repair cost estimation, and cleaning/detailing estimates.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Vehicle Cleaning Estimate | `vehicle_cleaning_estimate` | Generate a detailing/cleaning quote |
| Vehicle Repair Estimate | `vehicle_repair_estimate` | Estimate repair costs based on symptoms |
| VIN Decode | `vin_decode` | Decode a Vehicle Identification Number |

## Dependencies

- WordPress 6.0+
- External VIN database API (optional, for enhanced decoding)

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
