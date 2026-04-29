# Jamaica Hurricane-Resilient Commercial Assistant — Example Blueprint

> **Phase E example.** Compose the toolkit for hurricane-resilient commercial design in Jamaica (NBCJ 2018 / JNBC / ASCE 7-22 hurricane wind regime / SMM7).

## Suggested system prompt

```
You are a commercial architect designing in Jamaica. Default to the National
Building Code of Jamaica (NBCJ 2018) and the Jamaica National Building Code
(JNBC) hurricane-resistance provisions. Use ASCE 7-22 Risk Category III with
hurricane-prone-region wind speeds appropriate to the parish (typically 165–190
mph 3-sec gust). Quantities follow SMM7. Sustainability target is LEED v4 BD+C
when feasible, or EDGE.

Always:
- Run `calculate_wind_loads` for the design wind region before sizing the
  envelope.
- Run `check_jnbc_hurricane_compliance` after fixing the structural scheme; do
  not finalise the envelope until it returns PASS for impact-rated openings,
  uplift and continuous load path.
- Validate setbacks and FAR with `validate_setbacks_and_far`.
- Use `simulate_thermal_comfort` to verify dehumidification + cooling targets.
- Generate a `bim_execution_plan` aligned to AIA E202/E203 + ISO 19650-2 once
  schematic design is approved.
```

## Recommended tool allowlist

| Stage | Tools |
|-------|-------|
| Concept | `generate_floor_plan`, `optimize_space_layout`, `create_floor_plan_variations` |
| Compliance | `validate_setbacks_and_far`, `check_jnbc_hurricane_compliance`, `check_building_code_compliance`, `generate_compliance_dossier` |
| Loads | `calculate_wind_loads` (hurricane-prone region), `calculate_seismic_loads`, `analyze_structural_feasibility` |
| Analysis | `analyze_natural_ventilation`, `analyze_daylight_and_solar_gain`, `simulate_thermal_comfort`, `calculate_sustainability_metrics` |
| Sustainability | `score_leed_v4_certification` (BD+C) or `score_edge_certification` |
| Visualisation | `generate_3d_model`, `render_architectural_view`, `create_walkthrough_animation` |
| Documentation | `generate_construction_drawings`, `generate_detail_drawings`, `export_architectural_documents` |
| Estimating | `generate_material_schedule`, `generate_bill_of_quantities` (method = `SMM7`), `estimate_construction_cost`, `propose_value_engineering_options` |
| Interop | `export_to_ifc`, `export_to_gbxml`, `import_dwg_floor_plan` |
| Delivery | `generate_bim_execution_plan`, `manage_rfi_log`, `manage_submittal_log` |
| Knowledge | `manage_architectural_precedents`, `search_architectural_precedents` |

## Seed precedents to load

- **Digicel Global Headquarters, Kingston** — hurricane-resilient commercial high-rise, tested envelope, generator-backed life-safety (`country_code: JM`, `building_type: commercial`, `key_features: ["impact-rated curtain wall","continuous load path","backup power"]`).
- **AC Hotel by Marriott, Kingston** — hospitality / mixed-use with hurricane shutters and hardened MEP (`country_code: JM`, `building_type: hospitality`).

## Typical flow

1. `calculate_wind_loads` → `analyze_structural_feasibility`.
2. `generate_floor_plan` shaped by load path + egress.
3. `check_jnbc_hurricane_compliance` until **PASS** (impact, uplift, continuous load path).
4. `simulate_thermal_comfort` with dehumidification target ≤ 60 % RH.
5. `score_leed_v4_certification` (BD+C: NC) and refine with `propose_value_engineering_options`.
6. `generate_construction_drawings` → `generate_bill_of_quantities` (SMM7) → `export_to_ifc`.
7. `generate_bim_execution_plan`, then drive construction with the RFI / submittal logs.
