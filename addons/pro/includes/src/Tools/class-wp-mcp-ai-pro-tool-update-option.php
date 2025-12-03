<?php
/**
 * Tool for updating WordPress options.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update Option Tool
 *
 * Updates or creates WordPress options in the wp_options table.
 */
class WP_MCP_AI_Pro_Tool_Update_Option implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_option';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Option', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates a WordPress option value. Can also be used to create a new option.', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'option_name'  => array(
					'type'        => 'string',
					'description' => __( 'The name of the option to update (e.g., "blogname").', 'wp-mcp-ai-pro' ),
				),
				'option_value' => array(
					'type'        => 'mixed',
					'description' => __( 'The new value for the option.', 'wp-mcp-ai-pro' ),
				),
			),
			'required'             => array( 'option_name', 'option_value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator features are enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator'] ) || empty( $settings['site_creator_allow_option_updates'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The update_option tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'wp-mcp-ai-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage options.', 'wp-mcp-ai-pro' )
			);
		}

		$option_name = isset( $arguments['option_name'] ) ? sanitize_text_field( $arguments['option_name'] ) : '';

		if ( empty( $option_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_option_name',
				__( 'Option name not provided.', 'wp-mcp-ai-pro' )
			);
		}

		$option_value = isset( $arguments['option_value'] ) ? $arguments['option_value'] : '';

		// Use update_option which handles both create and update.
		$updated = update_option( $option_name, $option_value );

		// update_option returns false if the value is the same, which isn't an error.
		return array(
			'success'      => true,
			'option_name'  => $option_name,
			'option_value' => $option_value,
			'message'      => $updated
				? sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" updated successfully.', 'wp-mcp-ai-pro' ),
					$option_name
				)
				: sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" was not updated (the new value may be the same as the old value).', 'wp-mcp-ai-pro' ),
					$option_name
				),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Modifies data.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires manage_options capability.
			'state-changing',       // Modifies database state.
			'idempotent',           // Safe to call multiple times.
		);
	}
}
