# Architectural Design Toolkit

> AI-assisted architectural design with regional code compliance for **Sri Lanka (primary), Jamaica, and the United States**. Floor plans, 3D models, construction drawings, material schedules, code compliance, and cost estimation.

| | |
|---|---|
| **Activation setting** | `enable_architectural_design_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Architectural Design |
| **Toolkit settings** | `wp_mcp_ai_arch_design_settings` (default country, units, currency, code pack) |
| **Tools (Phase A)** | 16 |
| **Custom Post Types** | Architectural Project, Drawing, Specification |
| **Source** | `addons/pro/includes/tools/architectural-design/` |

> **Analytical / advisory output only.** Engage a registered architect (e.g. SLIA in Sri Lanka, Jamaican Institute of Architects in Jamaica, or AIA in the US), chartered structural engineer, MEP engineer, and quantity surveyor before any planning submission or construction contract.

---

## Module map

Tools are grouped by concern, mirroring the [CRE Debt Toolkit](cre-debt.md):

| Module | Folder | Tools |
|---|---|---|
| Floor Planning & Space Design | `floor-planning/` | 4 |
| 3D Modeling & Visualization | `visualization/` | 3 |
| Documentation & Blueprints | `documentation/` | 3 |
| Analysis & Compliance | `analysis-compliance/` | 3 |
| Estimation & Scheduling | `estimation-scheduling/` | 3 |
| Regional Compliance | `regional-compliance/` | _(Phase B — planned)_ |
| Sustainability | `sustainability/` | _(Phase B — planned)_ |
| Interoperability | `interoperability/` | _(Phase D — planned)_ |
| Project Delivery | `project-delivery/` | _(Phase D — planned)_ |

A shared `WP_MCP_AI_Architectural_Engine` provides industry-standard math (units, FAR, occupancy, wind/seismic, ventilation, cost rates), and `WP_MCP_AI_Architectural_Codes` exposes structured regional rule packs.

---

## Tool categories

- **Floor plans & layouts:** `generate_floor_plan`, `convert_sketch_to_floor_plan`, `create_floor_plan_variations`, `optimize_space_layout`
- **3D & rendering:** `generate_3d_model`, `render_architectural_view`, `create_walkthrough_animation`
- **Construction docs:** `generate_construction_drawings`, `generate_detail_drawings`, `generate_material_schedule`, `generate_construction_timeline`, `export_architectural_documents`
- **Analysis & compliance:** `analyze_structural_feasibility`, `check_building_code_compliance`, `calculate_sustainability_metrics`, `estimate_construction_cost`

---

## Regional notes

### Sri Lanka 🇱🇰

- Default unit system: **metric** (m², perches for lots).
- Default currency: **LKR**.
- Default code pack: `lk_uda_2021`. Toggle to `lk_uda_2025_gazette` once the 1 April 2025 gazette is in force for your jurisdiction.
- Wind: BS 6399-2 / IS 875-3 (referenced via SLS); Seismic: IS 1893 (referenced); Ventilation: SLS 947:2009.
- Cost rates: ICTAD/CIDA-style indices (filterable via `wp_mcp_ai_arch_cost_rates`).
- Liability: a registered architect (SLIA) and chartered engineer (IESL) signoff is required for any UDA submission. **This toolkit does not replace that signoff.**

### Jamaica 🇯🇲

- Default unit system: **metric**.
- Default currency: **JMD**.
- Default code pack: `jm_jnbc_2018`.
- Wind: ASCE 7 referenced via JNBC Part 7. Coastal zone basic-wind ≈ 67 m/s (~150 mph). Hurricane-resistant opening protection and continuous tie-down load paths are mandatory.
- Ventilation: JS 35:1996.
- Parish council overlays can be registered via the `wp_mcp_ai_arch_code_packs` filter.

### United States 🇺🇸

- Default unit system: **imperial** (sqft).
- Default currency: **USD**.
- Default code pack: `us_ibc_2024` (with `us_irc_2024`, `us_iecc_2024`, `us_asce_7_22`, `us_nfpa_101`, `us_ada_2010`, `us_ashrae_90_1` registered alongside).

---

## Industry-standards alignment

* **Sri Lanka:** UDA Planning & Building Regulations (2021 baseline + Gazette 2430/13 of 2025); SLS 947:2009 ventilation; BS 6399-2 / IS 875-3 wind; IS 1893 seismic; NBRO landslide; CIDA / ICTAD cost indices; SLIA architect registration.
* **Jamaica:** Jamaica National Building Code 2018; ASCE 7 wind/seismic via JNBC Part 7; JS 35 natural ventilation; Bureau of Standards Jamaica oversight.
* **United States:** IBC 2024, IRC 2024, IECC 2024, ASCE 7-22, NFPA 101, ADA 2010, ASHRAE 90.1 / 62.1 / 55.
* **Cross-cutting:** buildingSMART IFC 4.3 and gbXML (interoperability — Phase D); CSI MasterFormat 2020 / UniFormat II / OmniClass; AIA E202/E203 BIM Execution Plan; ISO 19650.

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
