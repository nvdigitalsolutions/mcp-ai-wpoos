# Architectural Design Toolkit

> AI-assisted architectural design with regional code compliance for **Sri Lanka (primary), Jamaica, and the United States**. Floor plans, 3D models, construction drawings, material schedules, regional code compliance (UDA / JNBC / IBC-IRC), structural & sustainability analysis, certification scoring (EDGE, LEED v4), BIM interoperability (DWG / IFC / gbXML), project delivery (BEP, RFI, submittals), and a searchable precedent library.

| | |
|---|---|
| **Activation setting** | `enable_architectural_design_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Architectural Design |
| **Toolkit settings** | `wp_mcp_ai_arch_design_settings` (default country, units, currency, code pack) |
| **Tools (Phase A–E, all shipped)** | 39 |
| **Custom Post Types** | Architectural Project, Drawing, Specification, Precedent |
| **Source** | `addons/pro/includes/tools/architectural-design/` |

> **Analytical / advisory output only.** Engage a registered architect (e.g. SLIA in Sri Lanka, Jamaican Institute of Architects in Jamaica, or AIA in the US), chartered structural engineer, MEP engineer, and quantity surveyor before any planning submission or construction contract.

---

## What this toolkit does

The Architectural Design Toolkit turns NV oOS into an end-to-end **AI co-pilot for the building lifecycle** — from initial massing through regulator-ready submission packages and post-tender delivery management. It is organised as a stack of composable tools (each registered with the standard tool registry) that share a single project model:

* **Conceptual design** — generate, vary, and optimise floor plans; translate hand sketches into vector plans; produce 3D massing and architectural renderings; create walkthrough animations.
* **Construction documentation** — synthesise full construction drawing sets, detail drawings, material schedules, and an automated construction timeline; export packaged documents.
* **Regional code compliance** — wind & seismic load calculations, setback / FAR validation, and country-specific code checks for Sri Lanka (UDA Planning & Building Regulations + 2025 gazette), Jamaica (JNBC 2018 with hurricane-zone overlays), and the US (IBC 2024, IRC 2024, IECC 2024, ASCE 7-22, ADA 2010). A consolidated `generate_compliance_dossier` rolls every check into a regulator-ready report.
* **Building physics & sustainability** — natural-ventilation, daylight & solar-gain, and thermal-comfort simulations; quantitative sustainability metrics; certification scoring against IFC EDGE and LEED v4.
* **Cost & schedule depth** — construction-cost estimation, full bill of quantities, value-engineering option proposals, and timeline scheduling.
* **Interoperability** — round-trip with CAD/BIM ecosystems via DWG floor-plan import, IFC 4.3 import/export, and gbXML export for energy-analysis tools.
* **Project delivery** — BIM Execution Plan generation (AIA E202/E203 / ISO 19650 aligned), RFI log management, and submittal log management.
* **Precedent library** — searchable, semantic-indexed precedent CPT (`mcp_ai_arch_precedent`) for case studies, materials, details, and design-pattern reuse.

A shared `WP_MCP_AI_Architectural_Engine` provides industry-standard math (units, FAR, occupancy, wind/seismic, ventilation, cost rates), `WP_MCP_AI_Architectural_Codes` exposes structured regional rule packs, and `WP_MCP_AI_Architectural_Precedents_Engine` powers semantic search over the precedent corpus.

---

## Module map

Tools are grouped by concern, mirroring the [CRE Debt Toolkit](cre-debt.md):

| Module | Folder | Tools | Phase |
|---|---|---|---|
| Floor Planning & Space Design | `floor-planning/` | 4 | A |
| 3D Modeling & Visualization | `visualization/` | 3 | A |
| Documentation & Blueprints | `documentation/` | 3 | A |
| Analysis & Compliance | `analysis-compliance/` | 5 | A + B |
| Estimation & Scheduling | `estimation-scheduling/` | 5 | A + C |
| Regional Compliance | `regional-compliance/` | 7 | B |
| Sustainability | `sustainability/` | 3 | B + C |
| Interoperability | `interoperability/` | 4 | D |
| Project Delivery | `project-delivery/` | 3 | D |
| Precedents | `precedents/` | 2 | E |
| **Total** | | **39** | **A–E shipped** |

---

## Tool categories

- **Floor plans & layouts:** `generate_floor_plan`, `convert_sketch_to_floor_plan`, `create_floor_plan_variations`, `optimize_space_layout`
- **3D & rendering:** `generate_3d_model`, `render_architectural_view`, `create_walkthrough_animation`
- **Construction docs:** `generate_construction_drawings`, `generate_detail_drawings`, `generate_material_schedule`, `generate_construction_timeline`, `export_architectural_documents`
- **Analysis & compliance (Phase A):** `analyze_structural_feasibility`, `check_building_code_compliance`, `calculate_sustainability_metrics`, `estimate_construction_cost`
- **Regional compliance (Phase B):** `calculate_wind_loads`, `calculate_seismic_loads`, `validate_setbacks_and_far`, `check_uda_planning_compliance`, `check_jnbc_hurricane_compliance`, `check_us_ibc_irc_compliance`, `generate_compliance_dossier`
- **Building physics (Phase B):** `analyze_natural_ventilation`, `analyze_daylight_and_solar_gain`, `simulate_thermal_comfort`
- **Sustainability scoring & costing depth (Phase C):** `score_edge_certification`, `score_leed_v4_certification`, `generate_bill_of_quantities`, `propose_value_engineering_options`
- **Interoperability (Phase D):** `import_dwg_floor_plan`, `import_ifc_model`, `export_to_ifc`, `export_to_gbxml`
- **Project delivery (Phase D):** `generate_bim_execution_plan`, `manage_rfi_log`, `manage_submittal_log`
- **Precedent library (Phase E):** `manage_architectural_precedents`, `search_architectural_precedents`

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

| CPT slug | Purpose |
|---|---|
| `mcp_ai_arch_project` | Architectural project root record |
| `mcp_ai_arch_drawing` | Generated/imported drawing or model |
| `mcp_ai_arch_spec` | Specification / schedule entry |
| `mcp_ai_arch_precedent` | Precedent-library entry (Phase E) |

Tool source: `addons/pro/includes/tools/architectural-design/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Architectural Design Toolkit** under **NV oOS → Settings → Pro Features**.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/architectural-design/README.md`](../../includes/tools/architectural-design/README.md)
