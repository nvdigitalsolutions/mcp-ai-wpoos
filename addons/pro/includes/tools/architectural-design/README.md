# Architectural Design Toolkit

> AI-assisted architectural design for tropical, hurricane-prone, and temperate jurisdictions — Sri Lanka (primary), Jamaica, and the United States.

This directory contains the **Architectural Design Toolkit** for NV oOS Pro: a suite of 30 production tools (Phase A: 16 + Phase B: 10 + Phase C: 4) covering floor planning, 3D visualisation, documentation, regional code compliance, structural / sustainability analysis, certification scoring, and cost engineering.

The toolkit follows the same architecture as the [CRE Debt & Securitization Toolkit](../cre-debt/README.md) and the Health & Wellness toolkit:

* **Module subdirectories** — tools are grouped by concern.
* **Shared engine** — a single `WP_MCP_AI_Architectural_Engine` class provides industry-standard math (units, FAR, occupancy, wind/seismic, ventilation, cost rates).
* **Regional code registry** — `WP_MCP_AI_Architectural_Codes` exposes structured rule packs for each jurisdiction so compliance tools dispatch real evaluations rather than hard-coded examples.
* **Per-toolkit settings** — `wp_mcp_ai_arch_design_settings` stores defaults for country, units, code pack, currency and IFC version, separate from the main plugin options.
* **CPT-backed entities** — projects, drawings and specifications are stored as `mcp_ai_arch_*` custom post types so tools share a single source of truth.
* **Optional `is_available()` / `get_unavailable_reason()`** on each tool, mirroring CRE Debt, so the orchestrator can skip the toolkit cleanly when it is disabled.

> **Analytical / advisory output only.** This toolkit assists with early-stage design and review. **Engage a registered architect, chartered structural engineer, MEP engineer and quantity surveyor** before any submission to a planning authority or construction contract.

---

## Module map

| Module | Folder | Tools |
|---|---|---|
| Floor Planning & Space Design | `floor-planning/` | 4 |
| 3D Modeling & Visualization | `visualization/` | 3 |
| Documentation & Blueprints | `documentation/` | 3 |
| Analysis & Compliance | `analysis-compliance/` | 5 |
| Estimation & Scheduling | `estimation-scheduling/` | 5 |
| Regional Compliance | `regional-compliance/` | 7 |
| Sustainability | `sustainability/` | 3 |
| Interoperability (IFC / gbXML / DWG) | `interoperability/` | _(Phase D — planned)_ |
| Project Delivery (BEP / RFI / Submittal) | `project-delivery/` | _(Phase D — planned)_ |

---

## Tool inventory (Phase A + Phase B — current)

### Phase A — Foundations

| Slug | Module | Capability flags |
|---|---|---|
| `generate_floor_plan` | floor-planning | `read-only`, `cacheable`, `consumes-tokens` |
| `optimize_space_layout` | floor-planning | `read-only`, `cacheable`, `consumes-tokens` |
| `create_floor_plan_variations` | floor-planning | `read-only`, `cacheable`, `consumes-tokens` |
| `convert_sketch_to_floor_plan` | floor-planning | `read-only`, `cacheable`, `consumes-tokens`, `requires-vision-model` |
| `generate_3d_model` | visualization | `read-only`, `cacheable`, `consumes-tokens` |
| `render_architectural_view` | visualization | `read-only`, `cacheable`, `consumes-tokens` |
| `create_walkthrough_animation` | visualization | `read-only`, `cacheable`, `consumes-tokens` |
| `generate_construction_drawings` | documentation | `write`, `state-changing` |
| `generate_detail_drawings` | documentation | `write`, `state-changing` |
| `export_architectural_documents` | documentation | `read-only`, `external-api` |
| `check_building_code_compliance` | analysis-compliance | `read-only`, `cacheable` |
| `analyze_structural_feasibility` | analysis-compliance | `read-only`, `cacheable` |
| `calculate_sustainability_metrics` | analysis-compliance | `read-only`, `cacheable` |
| `generate_material_schedule` | estimation-scheduling | `write`, `state-changing` |
| `estimate_construction_cost` | estimation-scheduling | `read-only`, `cacheable` |
| `generate_construction_timeline` | estimation-scheduling | `write`, `state-changing` |

### Phase B — Regional Compliance & Analysis Depth

| Slug | Module | Capability flags |
|---|---|---|
| `calculate_wind_loads` | regional-compliance | `read-only`, `cacheable` |
| `calculate_seismic_loads` | regional-compliance | `read-only`, `cacheable` |
| `validate_setbacks_and_far` | regional-compliance | `read-only`, `cacheable` |
| `check_uda_planning_compliance` | regional-compliance | `read-only`, `cacheable` |
| `check_jnbc_hurricane_compliance` | regional-compliance | `read-only`, `cacheable` |
| `check_us_ibc_irc_compliance` | regional-compliance | `read-only`, `cacheable` |
| `generate_compliance_dossier` | regional-compliance | `read-only`, `cacheable` |
| `analyze_natural_ventilation` | analysis-compliance | `read-only`, `cacheable` |
| `analyze_daylight_and_solar_gain` | analysis-compliance | `read-only`, `cacheable` |
| `simulate_thermal_comfort` | sustainability | `read-only`, `cacheable` |

### Phase C — Sustainability scoring & costing depth

| Slug | Module | Capability flags |
|---|---|---|
| `score_edge_certification` | sustainability | `read-only`, `cacheable` |
| `score_leed_v4_certification` | sustainability | `read-only`, `cacheable` |
| `generate_bill_of_quantities` | estimation-scheduling | `read-only`, `cacheable` |
| `propose_value_engineering_options` | estimation-scheduling | `read-only`, `cacheable` |

Phase C is backed by a dedicated engine — [`class-wp-mcp-ai-architectural-sustainability.php`](class-wp-mcp-ai-architectural-sustainability.php) — that exposes:

- The full **LEED v4 BD+C** credit catalogue with per-category prerequisites and certification thresholds (`get_leed_v4_bdc_catalog()`, `get_leed_thresholds()`, `score_leed_v4_bdc()`).
- **IFC EDGE** baselines for residential and commercial use across LK, JM, US, plus tier definitions and a savings calculator (`get_edge_baselines()`, `get_edge_tiers()`, `score_edge()`).
- The **POMI / SMM7 / NRM2 / CSI MasterFormat 2020** classification catalogues with per-country preferred format dispatch (`get_boq_format_catalog()`, `preferred_boq_format()`).
- A curated **value-engineering library** (`get_value_engineering_library()`) covering finishes, structure, envelope, foundation, and MEP substitutions with applicability tags per country.

`calculate_sustainability_metrics` (Phase A) was refactored to delegate to the EDGE engine and to add tropical-climate-aware recommendations for LK/JM versus US.

Capability flag definitions follow [`includes/interfaces/class-wp-mcp-ai-tool-capability-flags-interface.php`](../../../../../includes/interfaces/class-wp-mcp-ai-tool-capability-flags-interface.php).

---

## Industry-standards alignment

The shared engine and code registry are aligned with these primary references:

### Sri Lanka (primary)

* **UDA Planning & Building Regulations** — Urban Development Authority. Includes the 2021 baseline rules and the **Gazette 2430/13** revision effective 1 April 2025.
* **SLS 947:2009** — Code of practice for ventilation in buildings (Sri Lanka Standards Institution).
* **BS 6399-2 / IS 875-3** — Wind loading, referenced by Sri Lankan Standards Institution and the Institution of Engineers Sri Lanka (IESL).
* **IS 1893** — Seismic design (referenced for Sri Lanka's low-to-moderate seismicity).
* **NBRO** — Landslide hazard zonation (National Building Research Organisation).
* **CIDA / ICTAD** — Construction Industry Development Authority cost indices (used in `WP_MCP_AI_Architectural_Engine::get_cost_rate()`).
* **SLIA** — Sri Lanka Institute of Architects (registered architect signoff).

### Jamaica

* **Jamaica National Building Code (JNBC) 2018** — Bureau of Standards Jamaica; references ASCE 7 for wind/seismic.
* **JS 35:1996** — Code of practice for natural ventilation.
* **Parish council overlays** — pluggable via `wp_mcp_ai_arch_code_packs` filter.

### United States

* **IBC 2024 / IRC 2024** — International Code Council building / residential codes.
* **IECC 2024** — International Energy Conservation Code.
* **ASCE 7-22** — Minimum design loads (wind, seismic, snow, etc.).
* **NFPA 101** — Life Safety Code.
* **ADA 2010** — Accessibility standards.
* **ASHRAE 90.1-2022 / 62.1 / 55** — Energy, ventilation, and thermal-comfort standards.

### Cross-cutting

* **buildingSMART IFC 4.3** and **gbXML** — open-BIM exchange formats (interoperability module — Phase D).
* **CSI MasterFormat 2020 / UniFormat II / OmniClass** — classification of cost & specs.
* **AIA E202 / E203** — BIM Execution Plan.
* **ISO 19650** — Information management for the built environment.

---

## Settings option (`wp_mcp_ai_arch_design_settings`)

```php
array(
    'default_country'     => 'LK',                 // 'LK' | 'JM' | 'US'
    'default_unit_system' => 'metric',             // 'metric' | 'imperial'
    'default_currency'    => 'LKR',                // ISO-4217
    'default_code_pack'   => 'lk_uda_2021',        // see WP_MCP_AI_Architectural_Codes
    'ifc_export_version'  => '4.3',
    'masterformat_year'   => '2020',
    'currency_rates'      => array(),              // optional override map
);
```

Programmatic access: `WP_MCP_AI_Architectural_Engine::get_toolkit_settings()`. Filterable via `wp_mcp_ai_arch_toolkit_settings`.

---

## Extending the toolkit

The toolkit is fully filterable for partner customisation:

| Filter / Action | Purpose |
|---|---|
| `wp_mcp_ai_arch_code_packs` | Register additional jurisdictions (UK, India, GCC, …). |
| `wp_mcp_ai_arch_default_code_packs` | Map a country to its canonical pack. |
| `wp_mcp_ai_arch_currency_rates` | Override the FX rate table. |
| `wp_mcp_ai_arch_cost_rates` | Override per-country cost-rate tables. |
| `wp_mcp_ai_arch_cost_type_multipliers` | Override construction-type multipliers. |
| `wp_mcp_ai_arch_wind_tables` | Refine wind-zone basic-speed tables. |
| `wp_mcp_ai_arch_seismic_tables` | Refine seismic SDS tables. |
| `wp_mcp_ai_arch_occupancy_factors` | Override IBC-style occupancy area factors. |
| `wp_mcp_ai_arch_location_factor` | Plug in regional cost-index data. |
| `wp_mcp_ai_arch_toolkit_settings` | Final-pass filter on the resolved settings array. |
| `wp_mcp_ai_arch_before_compliance_check` | Hook in before any compliance run. |
| `wp_mcp_ai_arch_after_compliance_check` | Hook in after any compliance run. |
| `wp_mcp_ai_arch_after_cost_estimate` | Hook in after a cost estimate completes. |

---

## Roadmap

Phase A laid the foundation; Phase B delivered regional-compliance dispatch, wind/seismic load engines, ventilation / daylight / thermal-comfort analysis and per-country compliance dossiers. **Phase C (this milestone)** delivers sustainability scoring (EDGE + LEED v4 BD+C) and cost-engineering depth (BoQ generation in POMI / SMM7 / CSI MasterFormat, value-engineering option library). Subsequent phases:

* **Phase D** — Interoperability + project delivery: `import_dwg_floor_plan`, `import_ifc_model`, `export_to_ifc`, `export_to_gbxml`, `generate_bim_execution_plan`, `manage_rfi_log`, `manage_submittal_log`, plus a `mcp_ai_arch_precedent` CPT with embedding-based semantic search.
* **Phase E** — Documentation polish, region-specific example assistants (LK residential, JM hurricane-resilient, US commercial).
