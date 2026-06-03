# Capability-Fence Audit (Phase P2)

**Date:** May 14, 2026  
**Auditor:** GitHub Copilot Agent  
**Branch:** `copilot/start-next-steps-unix-theory-compliance`  
**Proposal:** [Unix Theory Compliance Enhancement Proposal §2.3 / Phase P2](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#23-capability-fence-for-optional-dependencies)  
**Outcome:** ✅ **Pass** — no required code changes; one optional polish recommended for a follow-up.

---

## 1. Scope

Per [§0.5](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#05-optional-dependency-guards-are-already-widespread) of the proposal, JetEngine / JetFormBuilder paths are already widely guarded (17+ call sites). Phase P2 is therefore an **audit-only** phase that confirms the remaining integration touch-points are properly fenced:

1. Rank Math SEO (Base)
2. WPCode (Pro-only — confirm zero Base reach-throughs)
3. Pro → Base reach-throughs (verify direction of the call graph)
4. Spot-check Elementor, JetEngine, WooCommerce optional-dep tools for the canonical pattern

The canonical guard pattern documented in [`.context/conventions.md`](../../../.context/conventions.md) and [§2.3](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#23-capability-fence-for-optional-dependencies) is:

```php
public static function is_available() {
    return /* defined()/class_exists()/function_exists() probe */;
}

public function execute( array $arguments = array(), array $context = array() ) {
    if ( ! self::is_available() ) {
        return new WP_Error( 'wp_mcp_ai_<slug>_missing_plugin', /* translated reason */ );
    }
    // ...
}
```

Both gates matter: `is_available()` short-circuits registration in `WP_MCP_AI_Tool_Registry::register_tool()`, and the runtime `execute()` fence defends against the integration being deactivated *after* registration.

---

## 2. Findings

### 2.1 Rank Math SEO — ✅ Pass

**File:** [`includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php`](../../../includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php)

| Gate | Location | Code |
|------|----------|------|
| Registration fence | `is_available()` line 27 | `defined( 'RANK_MATH_VERSION' ) && class_exists( '\\RankMath\\Helper' )` |
| Runtime fence | `execute()` line 107 | `if ( ! self::is_available() ) { return new WP_Error( 'wp_mcp_ai_rankmath_missing_plugin', ... ); }` |
| Pro feature probe | `is_pro_active()` line 45 | `defined( 'RANK_MATH_PRO_VERSION' ) \|\| defined( 'RANK_MATH_PRO_FILE' )` |
| Conditional class probe inside Pro path | line 410, 435 | `class_exists( '\\RankMathPro\\Analytics\\Posts' )` |

Both layers (defined-constant + namespaced class) are checked, and the Pro analytics branch is independently guarded before reaching `$wpdb->prefix . 'rank_math_analytics_objects'`. No bare API calls escape the fence.

### 2.2 SEO Meta Optimizer (multi-provider) — ✅ Pass

**File:** [`includes/tools/class-wp-mcp-ai-tool-seo-meta-optimizer.php`](../../../includes/tools/class-wp-mcp-ai-tool-seo-meta-optimizer.php)

This tool intentionally **does not** declare `is_available()`. It writes to whichever SEO plugin is installed (Rank Math → Yoast → built-in custom-meta fallback), so it is always available.

| Branch | Probe | Line |
|--------|-------|------|
| Rank Math | `class_exists( 'RankMath' )` | 541 |
| Yoast SEO | `defined( 'WPSEO_VERSION' )` | 553 |
| Built-in fallback | (none — always safe) | 560–561 |

This is a different pattern from the canonical `is_available()` gate but is **equivalent**: every external touch-point is guarded by the appropriate probe, and the unguarded fallback writes only to the plugin's own post-meta keys (`_wp_mcp_ai_seo_title`, `_wp_mcp_ai_meta_description`). No bare integration call escapes a probe.

> **Optional polish (not P2-blocking):** consider hoisting the per-branch probe into a private `resolve_seo_provider()` method that returns one of `'rank_math' | 'yoast' | 'builtin'`. This would centralise the probe order and make future provider additions (e.g. The SEO Framework) a one-line change. Documented here as a future hardening opportunity, not a current compliance gap.

### 2.3 WPCode — ✅ Pass

**Scope check:** `grep -rn 'WPCode\|wpcode_' includes/ --include='*.php'` returns only three hits, none of which call a WPCode API:

| File | Hit | Purpose |
|------|-----|---------|
| `includes/class-wp-mcp-ai-tool-registry.php:1335` | Comment | Documentation only |
| `includes/admin/sections/class-wp-mcp-ai-section-tools.php:2622` | Plugin metadata | UI label string |
| `includes/admin/sections/class-wp-mcp-ai-section-tools.php:2630` | Class-exists hint | `'value' => 'WPCode_Snippet'` — surfaced to the admin probe, not invoked |

The actual integration lives in `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php` and never crosses the Pro→Base boundary. Base is WPCode-clean by design.

### 2.4 Pro → Base reach-throughs — ✅ Pass

The call graph direction is correct:

- **Base loads first** via the main plugin entry point.
- **Pro loads later** via `wp_mcp_ai_maybe_load_pro_addon()` (see [`includes/class-wp-mcp-ai-plugin.php`](../../../includes/class-wp-mcp-ai-plugin.php)).
- Pro code calls into Base classes (`WP_MCP_AI_Tool_Registry`, `WP_MCP_AI_Tool_Envelope`, etc.) — that is the *intended* direction and is always safe because Base is guaranteed to be loaded.
- Pro tests defensively gate their dependencies with `class_exists()` (e.g. `addons/pro/tests/test-cpt-settings-assistant-integration.php`) which is correct test hygiene, not a fence-compliance issue.

No instance found of **Base → Pro** reach-throughs (which would be an actual violation, since Pro isn't guaranteed to be present).

### 2.5 Spot-check of canonical pattern adoption — ✅ Pass

Five randomly selected optional-dep tools all use the canonical `is_available()` + `execute()` fence:

| Tool | Probe | Lines |
|------|-------|-------|
| `class-wp-mcp-ai-tool-import-elementor-template-kit.php` | `defined( 'ELEMENTOR_VERSION' ) \|\| class_exists( '\\Elementor\\Plugin', false )` | 28, 119 |
| `class-wp-mcp-ai-tool-get-elementor-templates.php` | Same Elementor probe + ElementorPro variant | 26–28, 112–113 |
| `class-wp-mcp-ai-tool-get-jetengine-items.php` | `function_exists( 'jet_engine' ) \|\| class_exists( 'Jet_Engine' )` | 26–27, 91–92 |
| `class-wp-mcp-ai-tool-get-woo-recent-orders.php` | `class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' )` | 32–33, 96–97 |
| `class-wp-mcp-ai-tool-create-woo-product.php` | `class_exists( 'WooCommerce' ) && class_exists( 'WC_Product' )` | 35–36, 380–381 |

`jet_engine`-keyed probes appear at **32 sites across `includes/`**, confirming the §0.5 observation that JetEngine integration is uniformly fenced.

---

## 3. Conclusion

The capability fence is **already enforced** for every optional dependency surveyed. The proposal's §2.3 / Phase P2 deliverable is therefore the audit document you are reading — no code changes are required.

| Touch-point | Status | Notes |
|-------------|--------|-------|
| Rank Math | ✅ Pass | Canonical `is_available()` + `execute()` fence; Pro-feature path independently guarded |
| Rank Math + Yoast (SEO Meta Optimizer) | ✅ Pass | Per-branch probes with a safe built-in fallback |
| WPCode | ✅ Pass | Zero Base touch-points — fully encapsulated in Pro |
| Pro → Base reach-throughs | ✅ Pass | Direction is correct; Pro tests defensively gate |
| Elementor / JetEngine / WooCommerce spot-check | ✅ Pass | Canonical pattern used uniformly |

### Recommendations (non-blocking)

1. Consider a future polish that introduces a private `resolve_seo_provider()` helper in the SEO Meta Optimizer to centralise its multi-branch probes.
2. Optionally, add a lint rule that flags any Base file (i.e. outside `addons/pro/`) referencing Pro class names directly (no current violations).

These are improvement opportunities tracked separately and are **not** required for Phase P2 to close.

---

## 4. References

- [Proposal §2.3 — Capability Fence for Optional Dependencies](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#23-capability-fence-for-optional-dependencies)
- [Proposal §0.5 — Optional-dependency guards are already widespread](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#05-optional-dependency-guards-are-already-widespread)
- [`.context/conventions.md`](../../../.context/conventions.md) — canonical guard pattern
- [`includes/class-wp-mcp-ai-tool-registry.php`](../../../includes/class-wp-mcp-ai-tool-registry.php) — `register_tool()` calls `is_available()` before adding a tool to the registry
