<?php
/**
 * Health and Wellness Management System Initialization
 *
 * Loads the Health and Wellness Custom Post Type class which handles registration
 * and management of health-related CPTs for managing members (people & pets),
 * policies, medical records, checkups, prescriptions, and allergies.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// F-PRIV-03: On multisite, the health & wellness addon must only run on sites
// where an administrator has explicitly acknowledged PHI handling obligations
// by setting the wp_mcp_ai_phi_acknowledged setting to 'yes' (checkbox: true).
if ( is_multisite() ) {
	$_phi_settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $_phi_settings['wp_mcp_ai_phi_acknowledged'] ) ) {
		return;
	}
	unset( $_phi_settings );
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

// Register healthcare meta fields with JetEngine for listing/discovery.
if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_member' );
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_med_record' );
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_allergy' );
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_prescription' );
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_checkup' );
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_policy' );
}

// Load Health and Wellness meta boxes (WP Admin form fields, save hooks, admin columns).
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-health-wellness-meta-boxes.php';
WP_MCP_AI_Health_Wellness_Meta_Boxes::init();

// Load JetEngine CCTs if JetEngine is active.
if ( function_exists( 'jet_engine' ) ) {
	// Load the vitals_log CCT — primary storage for all vital-sign log entries.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
	WP_MCP_AI_JetEngine_Vitals_Log_CCT::bootstrap();

	// The legacy vital_signs CCT is intentionally NOT bootstrapped here.
	// All vital-sign writes go to the vitals_log CCT above.
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

		// Load Health & Wellness Dashboard page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-wellness-dashboard-page.php';

		// Load Medical Vitals Dashboard page (separate from Health & Wellness).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-medical-vitals-dashboard-page.php';
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
 * Users with edit_posts capability (authors, editors, admins) manage ALL
 * members via the picker, so this function returns 0 for them to ensure the
 * full member picker is shown rather than silently pre-selecting one member.
 *
 * @param int $user_id WordPress user ID.
 * @return int Member post ID or 0.
 */
function wp_mcp_ai_get_member_id_by_user_id( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return 0;
	}

	// Users above subscriber level should see all members via the picker.
	if ( user_can( $user_id, 'edit_posts' ) ) {
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


// F-PRIV-03: Audit-log every single-post read of a health CPT when a user
// views the post in the WP admin (load-edit.php / load-post.php) or visits it
// on the front end via a singular query.  We bind to `the_post` (fires for
// both WP admin and front-end template rendering) and log using the base
// plugin's WP_MCP_AI_Logger so the event ends up in the standard audit trail.
if ( ! has_action( 'the_post', 'wp_mcp_ai_health_cpt_read_audit' ) ) {
	add_action( 'the_post', 'wp_mcp_ai_health_cpt_read_audit' );
}

if ( ! function_exists( 'wp_mcp_ai_health_cpt_read_audit' ) ) {
	/**
	 * Write an audit-log entry whenever a health & wellness CPT post is displayed.
	 *
	 * @param WP_Post $post The post object being displayed.
	 * @return void
	 */
	function wp_mcp_ai_health_cpt_read_audit( $post ) {
		static $health_types = array(
			'mcp_ai_member',
			'mcp_ai_policy',
			'mcp_ai_med_record',
			'mcp_ai_checkup',
			'mcp_ai_prescription',
			'mcp_ai_allergy',
		);

		if ( ! ( $post instanceof WP_Post ) || ! in_array( $post->post_type, $health_types, true ) ) {
			return;
		}

		// Only log individual record reads, not archive / query loops.
		if ( ! is_singular() && ! is_admin() ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'health_cpt_read',
			sprintf(
			/* translators: 1: CPT slug  2: post ID */
				__( 'Health record read: type=%1$s id=%2$d', 'mcp-ai-wpoos-pro' ),
				esc_html( $post->post_type ),
				(int) $post->ID
			),
			array(
				'post_type' => $post->post_type,
				'post_id'   => $post->ID,
				'user_id'   => get_current_user_id(),
				'context'   => is_admin() ? 'admin' : 'frontend',
			)
		);
	}
}
