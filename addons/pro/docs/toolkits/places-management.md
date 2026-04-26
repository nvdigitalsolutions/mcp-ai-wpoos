# Places Management Toolkit

> Custom post type and tools for places (attractions, businesses, locations) with
> Google Maps / Places integration and `@turf/turf` geospatial analysis.

| | |
|---|---|
| **Activation setting** | `enable_places_management` |
| **Admin location** | NV oOS → Settings → Pro Features → Places |
| **Custom Post Type** | 1 (`mcp_ai_place`) |
| **NPM** | `@turf/turf` v7.3.2 |

---

## What it provides

The toolkit registers a single Place CPT (`WP_MCP_AI_Place_CPT`) and a set of tools for
location management plus a geospatial analysis tool that uses Turf.js.

### Tools

- `create_place`, `get_place`, `delete_place` — CRUD for the Place CPT
- `analyze_geospatial` — point-in-polygon, distance, area, buffering, routing primitives

### Use cases

- Tourism / city-guide sites with curated attraction lists.
- Multi-location businesses publishing locations on a map.
- Real-estate or delivery sites that need polygon / proximity queries.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Places Management** under **NV oOS → Settings → Pro Features**.
3. (Optional) Configure a Google Maps / Places API key on the toolkit settings page.

---

## Related docs

- [Pro Toolkits index](README.md)
