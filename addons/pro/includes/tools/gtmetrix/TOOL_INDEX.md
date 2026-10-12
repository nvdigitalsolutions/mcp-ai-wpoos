# GTmetrix Toolkit — Tool Index

| # | Slug | Class | Description |
|---|---|---|---|
| 1 | `gtmetrix_account_status` | `WP_MCP_AI_Tool_GTmetrix_Account_Status` | Credits, refill, plan type and PRO flags |
| 2 | `gtmetrix_list_locations` | `WP_MCP_AI_Tool_GTmetrix_List_Locations` | Test locations with availability/region |
| 3 | `gtmetrix_list_browsers` | `WP_MCP_AI_Tool_GTmetrix_List_Browsers` | Browsers with feature flags |
| 4 | `gtmetrix_list_simulated_devices` | `WP_MCP_AI_Tool_GTmetrix_List_Simulated_Devices` | Simulated devices (PRO) |
| 5 | `gtmetrix_start_test` | `WP_MCP_AI_Tool_GTmetrix_Start_Test` | Start a test (credit-gated) |
| 6 | `gtmetrix_get_test_status` | `WP_MCP_AI_Tool_GTmetrix_Get_Test_Status` | Poll test state |
| 7 | `gtmetrix_list_tests` | `WP_MCP_AI_Tool_GTmetrix_List_Tests` | Recent tests (24 h window) |
| 8 | `gtmetrix_get_report` | `WP_MCP_AI_Tool_GTmetrix_Get_Report` | Scores, metrics, resources |
| 9 | `gtmetrix_get_lighthouse_audits` | `WP_MCP_AI_Tool_GTmetrix_Get_Lighthouse_Audits` | Impact-ordered audits |
| 10 | `gtmetrix_get_resource_summary` | `WP_MCP_AI_Tool_GTmetrix_Get_Resource_Summary` | Per-type counts/sizes |
| 11 | `gtmetrix_list_report_resources` | `WP_MCP_AI_Tool_GTmetrix_List_Report_Resources` | Resources with expiry/sensitivity |
| 12 | `gtmetrix_compare_reports` | `WP_MCP_AI_Tool_GTmetrix_Compare_Reports` | Before/after deltas |
| 13 | `gtmetrix_analyze_report` | `WP_MCP_AI_Tool_GTmetrix_Analyze_Report` | CWV + budgets + fix list |
| 14 | `gtmetrix_generate_wpcode_snippet` | `WP_MCP_AI_Tool_GTmetrix_Generate_WPCode_Snippet` | WPCode-ready snippet code |
| 15 | `gtmetrix_install_wpcode_fix` | `WP_MCP_AI_Tool_GTmetrix_Install_WPCode_Fix` | Inactive WPCode draft (chat-restricted) |
| 16 | `gtmetrix_list_pages` | `WP_MCP_AI_Tool_GTmetrix_List_Pages` | Account page registry |
| 17 | `gtmetrix_get_page` | `WP_MCP_AI_Tool_GTmetrix_Get_Page` | Page detail + latest report |
| 18 | `gtmetrix_retest_page` | `WP_MCP_AI_Tool_GTmetrix_Retest_Page` | Retest with saved options |
| 19 | `gtmetrix_set_monitoring` | `WP_MCP_AI_Tool_GTmetrix_Set_Monitoring` | Monitoring frequency |
| 20 | `gtmetrix_get_alert_conditions` | `WP_MCP_AI_Tool_GTmetrix_Get_Alert_Conditions` | Alert config |
| 21 | `gtmetrix_set_alert_conditions` | `WP_MCP_AI_Tool_GTmetrix_Set_Alert_Conditions` | Replace alert config |
| 22 | `gtmetrix_get_alert_events` | `WP_MCP_AI_Tool_GTmetrix_Get_Alert_Events` | Alert trigger history |
| 23 | `gtmetrix_manage_page_tags` | `WP_MCP_AI_Tool_GTmetrix_Manage_Page_Tags` | Tag list/create/update/attach/detach |

Services: `WP_MCP_AI_GTmetrix_Client` (API adapter) ·
`WP_MCP_AI_GTmetrix_Poller` (AS lifecycle) ·
`WP_MCP_AI_GTmetrix_Scheduler` (sweeps + credit budget) ·
`WP_MCP_AI_GTmetrix_Snapshot_Store` (CPT store) ·
`WP_MCP_AI_GTmetrix_Fixture_Reader` (fixtures) ·
`WP_MCP_AI_GTmetrix_Score_Engine` (deterministic assessment) ·
`WP_MCP_AI_GTmetrix_Fix_Engine` (audit→fix taxonomy) ·
`WP_MCP_AI_GTmetrix_WPCode_Bridge` (draft installs).
