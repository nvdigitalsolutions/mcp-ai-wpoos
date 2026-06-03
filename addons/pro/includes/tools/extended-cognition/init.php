<?php
/**
 * Extended Cognition Toolkit Initialization
 *
 * Loads the Extended Cognition Toolkit — sensor inputs (camera, microphone,
 * screen, motion) for AI agents, grounded in Clark & Chalmers (1998)
 * extended mind theory.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_extended_cognition_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

if ( $is_enabled && ! $is_base ) {

	// Sensor session CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ext-cog-sensor-session.php';
	WP_MCP_AI_Ext_Cog_Sensor_Session::register_post_type();

	// REST controller.
	require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-ext-cog-rest.php';
	WP_MCP_AI_Ext_Cog_REST::init();

	// Admin settings (admin-only).
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ext-cog-settings.php';
	}

	// Front-end JS + CSS (not in admin).
	add_action( 'wp_enqueue_scripts', 'wp_mcp_ai_ext_cog_enqueue_assets' );
}

/**
 * Check whether the extended cognition toolkit is enabled.
 *
 * @return bool
 */
function wp_mcp_ai_ext_cog_is_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_extended_cognition_toolkit'] )
		&& ( ! function_exists( 'wp_mcp_ai_is_base_version' ) || ! wp_mcp_ai_is_base_version() );
}

/**
 * Get Extended Cognition settings subset from main wp_mcp_ai_settings option.
 *
 * @return array
 */
function wp_mcp_ai_ext_cog_get_settings() {
	$all = get_option( 'wp_mcp_ai_settings', array() );
	return array(
		'enabled'             => ! empty( $all['enable_extended_cognition_toolkit'] ),
		'sensor_camera'       => ! empty( $all['ext_cog_sensor_camera'] ),
		'sensor_microphone'   => ! empty( $all['ext_cog_sensor_microphone'] ),
		'sensor_screen'       => ! empty( $all['ext_cog_sensor_screen'] ),
		'sensor_motion'       => ! empty( $all['ext_cog_sensor_motion'] ),
		'guest_access'        => ! empty( $all['ext_cog_guest_access'] ),
		'store_captures'      => ! empty( $all['ext_cog_store_captures'] ),
		'gdpr_consent'        => ! empty( $all['ext_cog_gdpr_consent'] ),
		'retention_days'      => isset( $all['ext_cog_retention_days'] ) ? absint( $all['ext_cog_retention_days'] ) : 7,
		'rate_limit'          => isset( $all['ext_cog_rate_limit'] ) ? absint( $all['ext_cog_rate_limit'] ) : 10,
		'max_capture_size_kb' => isset( $all['ext_cog_max_capture_size_kb'] ) ? absint( $all['ext_cog_max_capture_size_kb'] ) : 2048,
		'vision_model'        => isset( $all['ext_cog_vision_model'] ) ? sanitize_text_field( $all['ext_cog_vision_model'] ) : 'auto',

		// Vision recognition (1.8.0).
		'hf_detection_model'         => isset( $all['ext_cog_hf_detection_model'] ) ? sanitize_text_field( $all['ext_cog_hf_detection_model'] ) : '',
		'hf_classification_model'    => isset( $all['ext_cog_hf_classification_model'] ) ? sanitize_text_field( $all['ext_cog_hf_classification_model'] ) : '',
		'hf_embedding_model'         => isset( $all['ext_cog_hf_embedding_model'] ) ? sanitize_text_field( $all['ext_cog_hf_embedding_model'] ) : '',
		'min_detection_confidence'   => isset( $all['ext_cog_min_detection_confidence'] ) ? (float) $all['ext_cog_min_detection_confidence'] : 0.5,
		'enable_video_analysis'      => ! empty( $all['ext_cog_enable_video_analysis'] ),
		'max_video_frames'           => isset( $all['ext_cog_max_video_frames'] ) ? absint( $all['ext_cog_max_video_frames'] ) : 60,
		'brand_catalog'              => isset( $all['ext_cog_brand_catalog'] ) ? sanitize_textarea_field( $all['ext_cog_brand_catalog'] ) : '',
	);
}

/**
 * Enqueue sensor bridge JS and CSS on the front end.
 */
function wp_mcp_ai_ext_cog_enqueue_assets() {
	if ( ! wp_mcp_ai_ext_cog_is_enabled() ) {
		return;
	}

	$settings = get_option( 'wp_mcp_ai_settings', array() );
	$ver      = WP_MCP_AI_PRO_VERSION;

	// CSS.
	wp_enqueue_style(
		'wp-mcp-ai-ext-cognition',
		WP_MCP_AI_PRO_URL . 'assets/css/ext-cognition.css',
		array(),
		$ver
	);

	// Core sensor modules.
	$scripts = array(
		'wp-mcp-ai-ext-cog-camera' => 'ext-cognition-camera.js',
		'wp-mcp-ai-ext-cog-audio'  => 'ext-cognition-audio.js',
		'wp-mcp-ai-ext-cog-screen' => 'ext-cognition-screen.js',
		'wp-mcp-ai-ext-cog-motion' => 'ext-cognition-motion.js',
	);
	$deps    = array();
	foreach ( $scripts as $handle => $file ) {
		wp_register_script( $handle, WP_MCP_AI_PRO_URL . 'assets/js/' . $file, array(), $ver, true );
		$deps[] = $handle;
	}

	// Camera viewfinder UI (1.8.0) — depends on camera module.
	wp_register_script(
		'wp-mcp-ai-ext-cog-viewfinder',
		WP_MCP_AI_PRO_URL . 'assets/js/ext-cognition-camera-viewfinder.js',
		array( 'wp-mcp-ai-ext-cog-camera' ),
		$ver,
		true
	);
	$deps[] = 'wp-mcp-ai-ext-cog-viewfinder';

	// Sensor bridge — depends on all sensor modules.
	wp_enqueue_script(
		'wp-mcp-ai-ext-cog-bridge',
		WP_MCP_AI_PRO_URL . 'assets/js/ext-cognition-sensor-bridge.js',
		$deps,
		$ver,
		true
	);

	wp_localize_script(
		'wp-mcp-ai-ext-cog-bridge',
		'nvOosExtCog',
		array(
			'restUrl'          => esc_url_raw( rest_url( 'mcp-ai/v1/ext-cog/' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'sensorCamera'     => ! empty( $settings['ext_cog_sensor_camera'] ),
			'sensorMicrophone' => ! empty( $settings['ext_cog_sensor_microphone'] ),
			'sensorScreen'     => ! empty( $settings['ext_cog_sensor_screen'] ),
			'sensorMotion'     => ! empty( $settings['ext_cog_sensor_motion'] ),
			'gdprConsent'      => ! empty( $settings['ext_cog_gdpr_consent'] ),
			'i18n'             => array(
				'consentRequired' => __( 'Allow AI agent to access your device sensors?', 'mcp-ai-wpoos' ),
				'httpsRequired'   => __( 'HTTPS is required for sensor access.', 'mcp-ai-wpoos' ),
			),
		)
	);
}
