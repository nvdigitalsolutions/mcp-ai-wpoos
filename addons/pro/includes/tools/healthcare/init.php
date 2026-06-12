<?php
/**
 * Healthcare Toolkit — Unified Bootstrap
 *
 * Single entry point for the three healthcare sub-toolkits (Medical Vitals,
 * Health & Wellness, Medical Imaging).  Mirrors the architectural-design
 * pattern: shared infrastructure is loaded eagerly, sub-toolkit modules are
 * loaded conditionally based on their respective `enable_*` settings.
 *
 * Phase A — this PR — only re-uses the existing per-sub-toolkit init files.
 * Phases B–E will progressively migrate tool registration and CPT setup
 * into this single bootstrap.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Always load shared infrastructure so other Pro code can rely on it.
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-healthcare-engine.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-healthcare-codes.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-healthcare-fhir.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-healthcare-capabilities.php';

// PHI-acknowledged gate (multisite); single-site installs always pass.
if ( ! WP_MCP_AI_Healthcare_Engine::phi_acknowledged() ) {
	return;
}

$wp_mcp_ai_settings = get_option( 'wp_mcp_ai_settings', array() );
if ( ! is_array( $wp_mcp_ai_settings ) ) {
	$wp_mcp_ai_settings = array();
}

// Sub-toolkit B: Health & Wellness Management (members / records / etc.).
// Loaded unconditionally to preserve pre-existing behaviour — the init file
// itself gates on `enable_health_wellness_management` for admin UI bits and
// always registers its CPTs and migration so existing data remains
// accessible even when the toggle is off.
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/init.php';

// Sub-toolkit C: Healthcare Imaging.
if ( ! empty( $wp_mcp_ai_settings['enable_healthcare_imaging'] ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging-init.php';
}

// Sub-toolkit A: Medical Vitals (Phase B).
// Defaults to the value of `enable_health_wellness_management` for BC.
$wp_mcp_ai_vitals_enabled = array_key_exists( 'enable_medical_vitals', $wp_mcp_ai_settings )
	? ! empty( $wp_mcp_ai_settings['enable_medical_vitals'] )
	: ! empty( $wp_mcp_ai_settings['enable_health_wellness_management'] );
if ( $wp_mcp_ai_vitals_enabled ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vaccination-schedules.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vital-log-cpt.php';
	WP_MCP_AI_Healthcare_Vital_Log_CPT::init();
}
// --- Performance optimization (per-member autoload, reminder pruning, care-plan cap) ---
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-hc-optimization.php';
WP_MCP_AI_Healthcare_Optimization::init();

unset( $wp_mcp_ai_vitals_enabled, $wp_mcp_ai_settings );
