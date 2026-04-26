# Architectural Design Toolkit

> AI-powered floor plans, 3D models, construction drawings, material schedules, building-
> code compliance, and cost estimation. 17 tools.

| | |
|---|---|
| **Activation setting** | `enable_architectural_design_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Architectural Design |
| **Tools** | 17 |
| **Custom Post Types** | Architectural Project, Drawing, Specification |

---

## Tool categories

- **Floor plans & layouts:** `generate_floor_plan`, `convert_sketch_to_floor_plan`,
  `create_floor_plan_variations`, `optimize_space_layout`
- **3D & rendering:** `generate_3d_model`, `render_architectural_view`,
  `create_walkthrough_animation`
- **Construction docs:** `generate_construction_drawings`, `generate_detail_drawings`,
  `generate_material_schedule`, `generate_construction_timeline`,
  `export_architectural_documents`
- **Analysis & compliance:** `analyze_structural_feasibility`,
  `check_building_code_compliance`, `calculate_sustainability_metrics`,
  `estimate_construction_cost`

### Custom post types

| CPT slug |
|---|
| `mcp_ai_arch_project` |
| `mcp_ai_arch_drawing` |
| `mcp_ai_arch_spec` |

Tool source: `addons/pro/includes/tools/architectural-design/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Architectural Design Toolkit** under **NV oOS → Settings → Pro Features**.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/architectural-design/README.md`](../../includes/tools/architectural-design/README.md)
