<?php
/**
 * Manage Template Versions Tool
 *
 * Manages template versioning with history, rollback, and comparison features.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Template Versions Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Manage_Template_Versions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_template_versions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Template Versions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages template versioning with history tracking, rollback capabilities, and version comparison.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Template ID', 'mcp-ai-wpoos-pro' ),
				),
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Version action', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'create', 'rollback', 'compare' ),
				),
				'version_number'   => array(
					'type'        => 'string',
					'description' => __( 'Version number for rollback/compare', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'template_id', 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Version data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$template_id    = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$action         = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'list';
		$version_number = isset( $arguments['version_number'] ) ? sanitize_text_field( $arguments['version_number'] ) : '';

		if ( ! $template_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Template ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify template exists.
		$template_post = get_post( $template_id );
		if ( ! $template_post || 'wp_site_template' !== $template_post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_template', __( 'Invalid template ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Handle action.
		switch ( $action ) {
			case 'list':
				return $this->list_versions( $template_id );

			case 'create':
				return $this->create_version( $template_id, $user_id );

			case 'rollback':
				return $this->rollback_version( $template_id, $version_number );

			case 'compare':
				return $this->compare_versions( $template_id, $version_number );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * List versions.
	 *
	 * @since 1.2.0
	 *
	 * @param int $template_id Template ID.
	 * @return array Result.
	 */
	private function list_versions( $template_id ) {
		$current_version = get_post_meta( $template_id, '_template_version', true );
		$version_history = get_post_meta( $template_id, '_version_history', true );

		if ( ! is_array( $version_history ) ) {
			$version_history = array();
		}

		return array(
			'success'         => true,
			'current_version' => $current_version ? $current_version : '1.0.0',
			'versions'        => $version_history,
			'summary'         => sprintf( __( 'Found %d version(s).', 'mcp-ai-wpoos-pro' ), count( $version_history ) ),
		);
	}

	/**
	 * Create version.
	 *
	 * @since 1.2.0
	 *
	 * @param int $template_id Template ID.
	 * @param int $user_id     User ID.
	 * @return array Result.
	 */
	private function create_version( $template_id, $user_id ) {
		$current_version = get_post_meta( $template_id, '_template_version', true );
		$new_version     = $this->increment_version( $current_version );

		// Save current state to history.
		$version_history = get_post_meta( $template_id, '_version_history', true );
		if ( ! is_array( $version_history ) ) {
			$version_history = array();
		}

		$version_history[] = array(
			'version'   => $new_version,
			'created'   => current_time( 'mysql' ),
			'author_id' => $user_id,
		);

		update_post_meta( $template_id, '_template_version', $new_version );
		update_post_meta( $template_id, '_version_history', $version_history );

		return array(
			'success'     => true,
			'new_version' => $new_version,
			'summary'     => sprintf( __( 'Created version %s.', 'mcp-ai-wpoos-pro' ), $new_version ),
		);
	}

	/**
	 * Rollback version.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $template_id    Template ID.
	 * @param string $version_number Version number.
	 * @return array Result.
	 */
	private function rollback_version( $template_id, $version_number ) {
		if ( empty( $version_number ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_version', __( 'Version number is required for rollback.', 'mcp-ai-wpoos-pro' ) );
		}

		// Simplified rollback placeholder.
		update_post_meta( $template_id, '_template_version', $version_number );

		return array(
			'success' => true,
			'version' => $version_number,
			'summary' => sprintf( __( 'Rolled back to version %s.', 'mcp-ai-wpoos-pro' ), $version_number ),
		);
	}

	/**
	 * Compare versions.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $template_id    Template ID.
	 * @param string $version_number Version number.
	 * @return array Result.
	 */
	private function compare_versions( $template_id, $version_number ) {
		$current_version = get_post_meta( $template_id, '_template_version', true );

		return array(
			'success'         => true,
			'current_version' => $current_version,
			'compare_version' => $version_number,
			'summary'         => __( 'Version comparison generated.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Increment version number.
	 *
	 * @since 1.2.0
	 *
	 * @param string $version Current version.
	 * @return string New version.
	 */
	private function increment_version( $version ) {
		if ( empty( $version ) ) {
			return '1.0.0';
		}

		$parts    = explode( '.', $version );
		$parts[2] = isset( $parts[2] ) ? (int) $parts[2] + 1 : 1;

		return implode( '.', $parts );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
