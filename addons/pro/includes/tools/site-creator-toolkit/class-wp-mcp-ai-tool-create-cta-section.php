<?php
/**
 * Create CTA Section Tool
 *
 * Generates call-to-action sections with compelling copy, urgency elements,
 * and conversion-optimized buttons.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create CTA Section Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_CTA_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'create_cta_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create CTA Section', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates call-to-action sections with compelling copy, urgency elements, and conversion-optimized buttons. Includes multiple CTA styles.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'headline'         => array(
					'type'        => 'string',
					'description' => __( 'CTA headline', 'mcp-ai-wpoos-pro' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Supporting description text', 'mcp-ai-wpoos-pro' ),
				),
				'button_text'      => array(
					'type'        => 'string',
					'description' => __( 'Button text', 'mcp-ai-wpoos-pro' ),
					'default'     => 'Get Started Now',
				),
				'style'            => array(
					'type'        => 'string',
					'description' => __( 'CTA style', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'bold', 'subtle', 'gradient', 'minimal' ),
					'default'     => 'bold',
				),
				'urgency'          => array(
					'type'        => 'boolean',
					'description' => __( 'Include urgency elements', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'headline' ),
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
	 * @return array|WP_Error CTA section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$headline    = isset( $arguments['headline'] ) ? sanitize_text_field( $arguments['headline'] ) : '';
		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$button_text = isset( $arguments['button_text'] ) ? sanitize_text_field( $arguments['button_text'] ) : 'Get Started Now';
		$style       = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'bold';
		$urgency     = isset( $arguments['urgency'] ) ? (bool) $arguments['urgency'] : false;

		if ( empty( $headline ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Headline is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$cta_section = array(
			'type'        => 'cta',
			'style'       => $style,
			'content'     => array(
				'headline'    => $headline,
				'description' => ! empty( $description ) ? $description : 'Take action today and transform your experience',
				'button'      => array(
					'text'  => $button_text,
					'style' => $style,
				),
			),
		);

		if ( $urgency ) {
			$cta_section['content']['urgency'] = array(
				'text' => 'Limited time offer - Act now!',
				'type' => 'countdown',
			);
		}

		return array(
			'success'     => true,
			'cta_section' => $cta_section,
			'summary'     => sprintf( __( 'Generated %s CTA section with headline and button.', 'mcp-ai-wpoos-pro' ), $style ),
			'timestamp'   => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
