<?php
/**
 * Fashion Studio Bridge Initialization (Pro).
 *
 * Phase 2 of the fashion photography enhancement plan: identity library,
 * fashion presets, and the batch/review queue. Loads only when the
 * Media Studio addon is active (module registry `requires` gate) and wires
 * the Pro implementations into the base addon's filter seams.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The module registry gates on this class; keep the belt-and-braces guard.
if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-fashion-model-cpt.php';
require_once __DIR__ . '/class-wp-mcp-ai-fashion-batch.php';
require_once __DIR__ . '/class-wp-mcp-ai-fashion-rest.php';

add_action( 'init', array( 'WP_MCP_AI_Fashion_Model_CPT', 'init' ), 5 );
add_action( 'init', array( 'WP_MCP_AI_Fashion_Batch', 'init' ), 6 );
add_action( 'rest_api_init', array( 'WP_MCP_AI_Fashion_REST', 'register_routes' ) );

// Wire the Pro implementations into the base addon's seams.
add_filter( 'nvoos_media_studio_models', array( 'WP_MCP_AI_Fashion_Model_CPT', 'get_models' ) );
add_filter( 'nvoos_media_studio_identity_consent', array( 'WP_MCP_AI_Fashion_Model_CPT', 'consent_filter' ), 10, 2 );
add_filter(
	'nvoos_media_studio_presets',
	static function ( $presets ) {
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
			return $presets;
		}
		return array_merge( (array) $presets, WP_MCP_AI_Media_Template_Presets::get_fashion_presets() );
	}
);
