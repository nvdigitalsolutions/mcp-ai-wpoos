<?php
/**
 * Health and Wellness Management System Initialization
 *
 * Loads the Health and Wellness Custom Post Type class which handles registration
 * and management of health-related CPTs for managing members (people & pets),
 * policies, medical records, checkups, prescriptions, and allergies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load migration class.
require_once WP_MCP_AI_PRO_PATH . 'includes/migrations/class-wp-mcp-ai-migrate-medical-record-post-type.php';

// Run migration on admin init (only once).
add_action(
	'admin_init',
	function () {
		// Only run migration if needed.
		$status = WP_MCP_AI_Migrate_Medical_Record_Post_Type::get_status();
		if ( $status['needs_migration'] && ! $status['migration_completed'] ) {
			// Run migration automatically.
			$result = WP_MCP_AI_Migrate_Medical_Record_Post_Type::run();

			// Log result.
			if ( 'success' === $result['status'] && function_exists( 'wp_mcp_ai_log_activity' ) ) {
				wp_mcp_ai_log_activity(
					'migration_medical_record_post_type',
					sprintf( 'Migrated %d medical records from mcp_ai_medical_record to mcp_ai_med_record', $result['migrated'] )
				);
			}
		}
	}
);

// Load Health and Wellness CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-health-wellness-cpt.php';

// Load JetEngine Vital Signs CCT if JetEngine is active and health management enabled.
if ( function_exists( 'jet_engine' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-cct.php';
	WP_MCP_AI_JetEngine_Vitals_CCT::bootstrap();

	// Load the dedicated Vitals Log CCT — primary storage for compiled log entries.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
	WP_MCP_AI_JetEngine_Vitals_Log_CCT::bootstrap();
}

// Load Policy Research & Add page.
if ( is_admin() ) {
	// Check if health and wellness management is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_health_wellness_management'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		// Load Member (primary CPT) settings and research pages.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-member-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-member-research-page.php';

		// Load Policy settings and research pages.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-policy-research-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-policy-settings-page.php';

		// Load Health Records Consolidate & Add page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-records-consolidate-page.php';
	}
}

/**
 * Enqueue health and wellness management admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_health_wellness_management_admin_styles( $hook ) {
	// Only load on health and wellness management edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_member', 'mcp_ai_policy', 'mcp_ai_med_record', 'mcp_ai_checkup', 'mcp_ai_prescription', 'mcp_ai_allergy' ), true ) ) {
		return;
	}

	// Check if health and wellness management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_health_wellness_management'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-health-wellness-management.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-health-wellness-management-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-health-wellness-management.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_health_wellness_management_admin_styles' );

/**
 * Resolve the mcp_ai_member CPT post ID for a given WordPress user.
 *
 * Returns the ID of the first published mcp_ai_member post whose author
 * matches the supplied user ID, or 0 when no match is found.
 *
 * @param int $user_id WordPress user ID.
 * @return int Member post ID or 0.
 */
function wp_mcp_ai_get_member_id_by_user_id( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return 0;
	}

	$posts = get_posts(
		array(
			'post_type'      => 'mcp_ai_member',
			'author'         => $user_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return ! empty( $posts ) ? (int) $posts[0] : 0;
}
