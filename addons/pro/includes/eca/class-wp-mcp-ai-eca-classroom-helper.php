<?php
/**
 * Shared helpers for the Google Classroom ECA tools.
 *
 * Keeps the twelve Classroom tools thin: feature-gate checks, credential
 * resolution, client construction, and Google-ID → WordPress record lookups
 * live here so each tool only owns its parameter schema and business logic.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Classroom ECA helper.
 */
class WP_MCP_AI_ECA_Classroom_Helper {

	/**
	 * Option key for the per-connection Classroom sync state.
	 *
	 * @var string
	 */
	const SYNC_STATE_OPTION = 'wp_mcp_ai_classroom_sync_state';

	/**
	 * Whether the Classroom ECA integration is enabled.
	 *
	 * Requires the ECA management toggle plus the Classroom feature flag.
	 *
	 * @return bool
	 */
	public static function feature_enabled() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( empty( $settings['enable_eca_management'] ) ) {
			return false;
		}

		return ! empty( $settings['enable_eca_classroom_integration'] );
	}

	/**
	 * Why the Classroom ECA integration is unavailable, or an empty string.
	 *
	 * @return string Reason, or empty when available.
	 */
	public static function unavailable_reason() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return __( 'Google Classroom integration is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( empty( $settings['enable_eca_management'] ) ) {
			return __( 'ECA Management must be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( $settings['enable_eca_classroom_integration'] ) ) {
			return __( 'Google Classroom integration must be enabled in ECA settings.', 'mcp-ai-wpoos-pro' );
		}

		return '';
	}

	/**
	 * Load the shared Google Classroom foundation classes.
	 *
	 * @return void
	 */
	public static function require_foundation() {
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-scopes.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-client.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-credentials.php';
	}

	/**
	 * Resolve credentials and build a client for a connection, enforcing the
	 * caller's capability.
	 *
	 * @param string $connection_id Remote Sites connection ID.
	 * @param string $capability    WordPress capability required of the actor.
	 * @param array  $context       Tool execution context (user_id).
	 * @return array<string,mixed>|WP_Error {
	 *     @type WP_MCP_AI_Google_Classroom_Client $client      Configured client.
	 *     @type array                              $credentials Resolved credentials.
	 * } or WP_Error.
	 */
	public static function get_client( $connection_id, $capability = 'edit_posts', array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Callers pass fixed capability strings.
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use Google Classroom tools.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		self::require_foundation();

		$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve( $connection_id );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$client = WP_MCP_AI_Google_Classroom_Credentials::make_client( $credentials );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return array(
			'client'      => $client,
			'credentials' => $credentials,
		);
	}

	/**
	 * Find a student post by Google user ID.
	 *
	 * @param string $google_user_id Google Classroom user ID.
	 * @return int Post ID, or 0.
	 */
	public static function find_student_by_google_id( $google_user_id ) {
		$google_user_id = trim( (string) $google_user_id );

		if ( '' === $google_user_id ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single indexed lookup by identity key.
					array(
						'key'   => '_student_google_id',
						'value' => $google_user_id,
					),
				),
			)
		);

		$ids = $query->posts;

		return ! empty( $ids ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Find a student post by email address.
	 *
	 * @param string $email Email address.
	 * @return int Post ID, or 0.
	 */
	public static function find_student_by_email( $email ) {
		$email = sanitize_email( (string) $email );

		if ( '' === $email ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single indexed lookup by identity key.
					array(
						'key'   => '_student_email',
						'value' => $email,
					),
				),
			)
		);

		$ids = $query->posts;

		return ! empty( $ids ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Find an ECA post by Google Classroom course ID.
	 *
	 * @param string $course_id Google Classroom course ID.
	 * @return int Post ID, or 0.
	 */
	public static function find_eca_by_google_course( $course_id ) {
		$course_id = trim( (string) $course_id );

		if ( '' === $course_id ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single indexed lookup by identity key.
					array(
						'key'   => '_eca_google_course_id',
						'value' => $course_id,
					),
				),
			)
		);

		$ids = $query->posts;

		return ! empty( $ids ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Split a Google profile full name into first and last components.
	 *
	 * @param string $full_name Full display name.
	 * @return array<string,string> {
	 *     @type string $first First name.
	 *     @type string $last  Last name (empty when unparseable).
	 * }
	 */
	public static function split_full_name( $full_name ) {
		$full_name = trim( (string) $full_name );
		$parts     = preg_split( '/\s+/', $full_name );

		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return array(
				'first' => '',
				'last'  => '',
			);
		}

		if ( count( $parts ) < 2 ) {
			return array(
				'first' => sanitize_text_field( $parts[0] ),
				'last'  => '',
			);
		}

		$last = array_pop( $parts );

		return array(
			'first' => sanitize_text_field( implode( ' ', $parts ) ),
			'last'  => sanitize_text_field( $last ),
		);
	}

	/**
	 * Read the per-connection sync state, defaulting empty.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array<string,mixed>
	 */
	public static function get_sync_state( $connection_id ) {
		$state = get_option( self::SYNC_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$key   = sanitize_key( $connection_id );

		return isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
	}

	/**
	 * Persist the per-connection sync state.
	 *
	 * @param string              $connection_id Connection ID.
	 * @param array<string,mixed> $state         Sync state.
	 * @return void
	 */
	public static function set_sync_state( $connection_id, array $state ) {
		$all                                   = get_option( self::SYNC_STATE_OPTION, array() );
		$all                                   = is_array( $all ) ? $all : array();
		$all[ sanitize_key( $connection_id ) ] = $state;

		update_option( self::SYNC_STATE_OPTION, $all, false );
	}
}
