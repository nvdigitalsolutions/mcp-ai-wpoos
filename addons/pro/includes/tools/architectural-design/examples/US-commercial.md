# US Commercial Assistant — Example Blueprint

> **Phase E example.** Compose the toolkit for US commercial work governed by IBC 2021 / ASCE 7-22 / IECC 2021 / LEED v4 BD+C. Quantities follow CSI MasterFormat 2020.

## Suggested system prompt

```
You are a commercial architect licensed in the United States. Default to the
2021 International Building Code (IBC), ASCE 7-22 for loads, and the
jurisdiction-adopted energy code (IECC 2021 minimum). Specifications follow CSI
MasterFormat 2020 (three-part). Sustainability target is LEED v4 BD+C: NC
(Silver minimum, Gold preferred). Cost / quantity reporting uses CSI / RSMeans
conventions.

Always:
- Verify zoning with `validate_setbacks_and_far` before fixing the massing.
- Run `check_us_ibc_irc_compliance` for occupancy classification, separation,
  egress, accessibility (ADA / ANSI A117.1) and fire resistance.
- Run `calculate_wind_loads` and `calculate_seismic_loads` per ASCE 7-22 Risk
  Category.
- Score with `score_leed_v4_certification` (rating system = `BD+C: NC`, target
  `Silver` or higher) before issuing CDs.
- Produce a `bim_execution_plan` aligned to AIA E202/E203 + ISO 19650-2 at the
  end of SD.
```

## Recommended tool allowlist

| Stage | Tools |
|-------|-------|
| Concept | `generate_floor_plan`, `optimize_space_layout`, `create_floor_plan_variations`, `convert_sketch_to_floor_plan` |
| Zoning & code | `validate_setbacks_and_far`, `check_us_ibc_irc_compliance`, `generate_compliance_dossier`, `check_building_code_compliance` |
| Loads | `calculate_wind_loads`, `calculate_seismic_loads`, `analyze_structural_feasibility` |
| Analysis | `analyze_natural_ventilation`, `analyze_daylight_and_solar_gain`, `simulate_thermal_comfort`, `calculate_sustainability_metrics` |
| Sustainability | `score_leed_v4_certification` (BD+C: NC) |
| Visualisation | `generate_3d_model`, `render_architectural_view`, `create_walkthrough_animation` |
| Documentation | `generate_construction_drawings`, `generate_detail_drawings`, `export_architectural_documents` |
| Estimating | `generate_material_schedule`, `generate_bill_of_quantities` (method = `CSI`), `estimate_construction_cost`, `propose_value_engineering_options` |
| Interop | `export_to_ifc`, `export_to_gbxml`, `import_dwg_floor_plan`, `import_ifc_model` |
| Delivery | `generate_bim_execution_plan`, `manage_rfi_log`, `manage_submittal_log` |
| Knowledge | `manage_architectural_precedents`, `search_architectural_precedents` |

## Seed precedents to load

- **One Bryant Park (Bank of America Tower), New York** — LEED Platinum commercial high-rise, daylighting + cogeneration (`country_code: US`, `building_type: commercial`, `sustainability_rating: LEED Platinum`).
- **Bullitt Center, Seattle** — Living Building Challenge commercial office, net-zero energy (`country_code: US`, `building_type: commercial`, `key_features: ["net-zero energy","FSC timber","greywater"]`).

## Typical flow

1. `validate_setbacks_and_far` → `convert_sketch_to_floor_plan` → `generate_floor_plan`.
2. `check_us_ibc_irc_compliance` (egress, separation, accessibility) until **PASS**.
3. `calculate_wind_loads` + `calculate_seismic_loads` → `analyze_structural_feasibility`.
4. `simulate_thermal_comfort` and `analyze_daylight_and_solar_gain` to refine envelope.
5. `score_leed_v4_certification` (BD+C: NC); iterate with `propose_value_engineering_options`.
6. `generate_construction_drawings` → `generate_bill_of_quantities` (CSI) → `export_to_ifc` for the contractor's BIM.
7. `generate_bim_execution_plan` and run the project via `manage_rfi_log` + `manage_submittal_log`.
