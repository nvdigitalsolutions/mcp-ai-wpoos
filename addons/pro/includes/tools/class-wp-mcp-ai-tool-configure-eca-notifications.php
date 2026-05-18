<?php
/**
 * Tool for configuring ECA notification rules.
 *
 * Sets up automated notification rules for an ECA.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets up automated notification rules for an ECA.
 */
class WP_MCP_AI_Tool_Configure_ECA_Notifications implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'configure_eca_notifications';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Configure ECA Notifications', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sets up automated notification rules for an ECA. Define triggers and actions for automatic email notifications when events like enrollment, waitlist promotion, cancellation, or attendance absences occur.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'rules'  => array(
					'type'        => 'array',
					'description' => __( 'Array of notification rules to configure (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'trigger' => array(
								'type'        => 'string',
								'description' => __( 'Event that triggers the notification', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'enrollment_confirmed', 'waitlist_promoted', 'session_cancelled', 'payment_overdue', 'attendance_absent' ),
							),
							'action'  => array(
								'type'        => 'string',
								'description' => __( 'Notification action to perform', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'email_student', 'email_parent', 'email_teacher' ),
							),
							'enabled' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether this rule is enabled', 'mcp-ai-wpoos-pro' ),
								'default'     => true,
							),
						),
						'required'             => array( 'trigger', 'action' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'eca_id', 'rules' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to configure ECA notifications.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate ECA.
		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		if ( ! $eca_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_id',
				__( 'ECA ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate rules.
		$rules = isset( $arguments['rules'] ) && is_array( $arguments['rules'] ) ? $arguments['rules'] : array();
		if ( empty( $rules ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_rules',
				__( 'At least one notification rule is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_triggers = array( 'enrollment_confirmed', 'waitlist_promoted', 'session_cancelled', 'payment_overdue', 'attendance_absent' );
		$valid_actions  = array( 'email_student', 'email_parent', 'email_teacher' );

		$sanitized_rules = array();

		foreach ( $rules as $index => $rule ) {
			$trigger = isset( $rule['trigger'] ) ? sanitize_key( $rule['trigger'] ) : '';
			if ( ! in_array( $trigger, $valid_triggers, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_trigger',
					sprintf(
						/* translators: %d: rule index */
						__( 'Invalid trigger in rule at index %d.', 'mcp-ai-wpoos-pro' ),
						$index
					)
				);
			}

			$action = isset( $rule['action'] ) ? sanitize_key( $rule['action'] ) : '';
			if ( ! in_array( $action, $valid_actions, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					sprintf(
						/* translators: %d: rule index */
						__( 'Invalid action in rule at index %d.', 'mcp-ai-wpoos-pro' ),
						$index
					)
				);
			}

			$enabled = isset( $rule['enabled'] ) ? (bool) $rule['enabled'] : true;

			$sanitized_rules[] = array(
				'trigger' => $trigger,
				'action'  => $action,
				'enabled' => $enabled,
			);
		}

		// Store rules in post meta.
		update_post_meta( $eca_id, '_eca_notification_rules', $sanitized_rules );

		return array(
			'success'     => true,
			'eca_id'      => $eca_id,
			'eca_name'    => sanitize_text_field( $eca->post_title ),
			'rules_count' => count( $sanitized_rules ),
			'rules'       => $sanitized_rules,
			'message'     => sprintf(
				/* translators: 1: rules count, 2: ECA name */
				__( '%1$d notification rule(s) configured for %2$s.', 'mcp-ai-wpoos-pro' ),
				count( $sanitized_rules ),
				$eca->post_title
			),
		);
	}
}
