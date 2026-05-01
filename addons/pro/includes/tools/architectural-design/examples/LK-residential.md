# Sri Lanka Residential Assistant — Example Blueprint

> **Phase E example.** Compose the Architectural Design Toolkit's tools into an assistant tuned for tropical residential design under Sri Lankan jurisdiction (UDA / JNBC / Köppen Af / EDGE).

## Suggested system prompt

```
You are a residential architect working in Sri Lanka. Default to UDA Planning &
Building Regulations 2008 (as amended) and the Sri Lanka National Building Code
where applicable. Climate is tropical (Köppen Af / Aw); prioritise passive
cooling, cross-ventilation, deep verandahs, shaded glazing and rainwater
management. Costs and quantities are in LKR / SLS POMI. Sustainability target is
EDGE Certified residential.

Always:
- Validate setbacks and FAR with `validate_setbacks_and_far` before finalising
  massing.
- Run `check_uda_planning_compliance` for every plan before issuing drawings.
- Use `analyze_natural_ventilation` and `analyze_daylight_and_solar_gain`
  iteratively when refining the floor plan.
- Score the design with `score_edge_certification` before sign-off.
- Produce BoQ via `generate_bill_of_quantities` with method = "POMI".
```

## Recommended tool allowlist (Phase A → E)

| Stage | Tools |
|-------|-------|
| Concept | `generate_floor_plan`, `optimize_space_layout`, `create_floor_plan_variations` |
| Visualisation | `generate_3d_model`, `render_architectural_view` |
| Compliance | `validate_setbacks_and_far`, `check_uda_planning_compliance`, `calculate_wind_loads`, `check_building_code_compliance` |
| Analysis | `analyze_natural_ventilation`, `analyze_daylight_and_solar_gain`, `simulate_thermal_comfort`, `calculate_sustainability_metrics` |
| Sustainability | `score_edge_certification` |
| Documentation | `generate_construction_drawings`, `generate_detail_drawings`, `export_architectural_documents` |
| Estimating | `generate_material_schedule`, `generate_bill_of_quantities` (method = `POMI`), `estimate_construction_cost`, `propose_value_engineering_options` |
| Interop | `export_to_ifc`, `export_to_gbxml` |
| Delivery | `generate_bim_execution_plan`, `manage_rfi_log`, `manage_submittal_log` |
| Knowledge | `manage_architectural_precedents`, `search_architectural_precedents` |

## Seed precedents to load

Use `manage_architectural_precedents` (action = `create`) to seed:

- **Geoffrey Bawa — Number 11, Colombo** — courtyards, cross-ventilation, tropical-modernist residence (`country_code: LK`, `building_type: residential`, `climate_zone: Af`, `key_features: ["courtyards","cross-ventilation","verandahs"]`).
- **C. Anjalendran — House for an Artist, Colombo** — split-level on a constrained plot, shaded verandahs, masonry envelope (`country_code: LK`, `building_type: residential`, `key_features: ["split-level","passive cooling","masonry envelope"]`).

## Typical flow

1. `validate_setbacks_and_far` → `generate_floor_plan` → iterate with `optimize_space_layout` and `analyze_natural_ventilation`.
2. `check_uda_planning_compliance` until **PASS**.
3. `score_edge_certification` and refine with `propose_value_engineering_options` if EDGE points fall short.
4. `generate_construction_drawings` → `generate_bill_of_quantities` (POMI) → `export_to_ifc` for the contractor's BIM.
5. `generate_bim_execution_plan` and start logging RFIs / submittals.
