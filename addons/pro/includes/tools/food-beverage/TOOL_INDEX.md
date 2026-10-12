# Food & Beverage Management — Tool Index

| Tool | Purpose | Spec anchors | Tier |
|---|---|---|---|
| `fnb_read_table` | Read a data table (1.0–17.0, assumptions) | G-01, G-03 | READ |
| `fnb_search_table` | Filter a table by column/date range | G-03 | READ |
| `fnb_calculate_metric` | Deterministic M-01…M-21 calculation | G-02, G-08 | READ |
| `fnb_compare_periods` | Month-vs-month headline metrics | Sep vs Aug demo | READ |
| `fnb_variance_split` | Volume vs per-cover effect | M-07, M-08, A3-01 | READ |
| `fnb_stock_used` | Opening + bought − closing | M-15, G-06, A2-01 | READ |
| `fnb_expected_use` | Units sold × recipe quantity | M-16, A2-02 | READ |
| `fnb_stock_variance` | Used − expected − logged waste | M-17, A2-03 | READ |
| `fnb_waste_analysis` | Waste % by logged reason, MoM | M-18, A2-08 | READ |
| `fnb_days_of_cover` | Stock ÷ 14-day daily use + flags | M-14, A2-06 | READ |
| `fnb_weekend_demand` | Expected covers/portions, cut-offs | M-12, M-13, A2-04/05 | READ |
| `fnb_price_change_impact` | (new − old) × qty at new price | M-19 | READ |
| `fnb_budget_variance` | Actual − budget by line | M-20, A3-02 | READ |
| `fnb_dish_margin` | Price − recipe cost + quadrant | M-21, decision 2026-10-08 | READ |
| `fnb_duplicate_invoice_scan` | Flag possible duplicates | A3-04 | READ |
| `fnb_labour_analysis` | Overtime per 100 covers, labour % | M-09, M-10 | READ |
| `fnb_utilities_per_cover` | kWh/water/LPG per cover + costs | M-11 | READ |
| `fnb_generate_report` | R-01…R-13 in library layouts | G-09 | READ |
| `fnb_save_draft` | Save output to Drafts folder | G-12 | DRAFT |
| `fnb_list_drafts` | List drafts | G-12 | READ |
| `fnb_audit_log` | Read-only audit viewer for CEO | open Q8 | READ |
| `fnb_import_table` | Sheet → native CPT upsert (idempotent) | Phase 9, Change records row | ACT (`manage_options`) |
| `fnb_pr_create` | Purchase request → QMS draft (R-04 layout) | Appendix A, A2-07 | DRAFT (`manage_qms`) |
| `fnb_pr_submit` | draft → in_review + A2-05/A2-06 gates | Appendix A §A5 | ACT — off |
| `fnb_pr_return` | in_review/approved → draft (+comments) | Appendix A §A3 | ACT — off |
| `fnb_pr_approve` | in_review → approved (budget + duties gates) | Appendix A §A5, M-20 | ACT — off |
| `fnb_pr_release` | approved → released (+PO number, hash freeze) | Appendix A §A4/A5 | ACT — off |
| `fnb_pr_supersede` | released → superseded (+new draft revision) | Appendix A §A3 | ACT — off |
| `fnb_pr_cancel` | any → obsolete (reason audited) | Appendix A §A5 | ACT — off |
| `fnb_pr_list` | Purchase-request register | Appendix A §A6 | READ |
| `fnb_pr_audit` | Immutable PR audit trail | open Q8 | READ |
| `import_fnb_blueprint` | Install A1–A4 assistant blueprints | Blueprints page parity | DRAFT (`edit_posts`) |
