<?php
/**
 * Tool: Configure Circuit Breaker
 *
 * Allows agents and admins to adjust circuit breaker sensitivity thresholds
 * for autonomous orchestration sessions. Provides read-back of current
 * configuration and validation of proposed settings.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configure Circuit Breaker Tool
 */
class WP_MCP_AI_Pro_Tool_Configure_Circuit_Breaker {

	/**
	 * Valid configuration keys and their constraints.
	 *
	 * @var array
	 */
	private $valid_keys = array(
		'error_threshold_pct'    => array(
			'min'     => 10,
			'max'     => 100,
			'default' => 50,
		),
		'volume_threshold'       => array(
			'min'     => 2,
			'max'     => 50,
			'default' => 5,
		),
		'reset_timeout_sec'      => array(
			'min'     => 60,
			'max'     => 3600,
			'default' => 300,
		),
		'max_consecutive_errors' => array(
			'min'     => 1,
			'max'     => 20,
			'default' => 3,
		),
		'no_progress_cycles'     => array(
			'min'     => 2,
			'max'     => 10,
			'default' => 3,
		),
	);

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'configure_circuit_breaker';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'configure_circuit_breaker',
			'description'         => 'Configure circuit breaker sensitivity thresholds for autonomous orchestration sessions. Use "view" action to read current settings, "update" to change them. Available settings: error_threshold_pct (10-100, default 50), volume_threshold (2-50, default 5), reset_timeout_sec (60-3600, default 300), max_consecutive_errors (1-20, default 3), no_progress_cycles (2-10, default 3).',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'update', 'reset' ),
						'description' => 'Action: view current settings, update specific settings, or reset to defaults',
					),
					'settings' => array(
						'type'        => 'object',
						'description' => 'Settings to update (required for "update" action). Keys: error_threshold_pct, volume_threshold, reset_timeout_sec, max_consecutive_errors, no_progress_cycles.',
						'properties'  => array(
							'error_threshold_pct'    => array(
								'type'        => 'integer',
								'description' => 'Error percentage threshold to open circuit (10-100)',
							),
							'volume_threshold'       => array(
								'type'        => 'integer',
								'description' => 'Minimum number of calls before evaluating error rate (2-50)',
							),
							'reset_timeout_sec'      => array(
								'type'        => 'integer',
								'description' => 'Seconds to wait before attempting circuit reset (60-3600)',
							),
							'max_consecutive_errors' => array(
								'type'        => 'integer',
								'description' => 'Maximum consecutive errors before opening circuit (1-20)',
							),
							'no_progress_cycles'     => array(
								'type'        => 'integer',
								'description' => 'Number of identical-output cycles before detecting stagnation (2-10)',
							),
						),
					),
				),
				'required'   => array( 'action' ),
			),
			'required_capability' => 'manage_options',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by tool interface.
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'view';

		switch ( $action ) {
			case 'view':
				return $this->view_settings();

			case 'update':
				return $this->update_settings( $arguments );

			case 'reset':
				return $this->reset_to_defaults();

			default:
				return new \WP_Error(
					'unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown action: %s', 'mcp-ai-wpoos' ),
						$action
					)
				);
		}
	}

	/**
	 * View current circuit breaker configuration.
	 *
	 * @return array
	 */
	private function view_settings() {
		$settings = get_option( 'wp_mcp_ai_circuit_breaker_config', array() );

		$current = array();
		foreach ( $this->valid_keys as $key => $constraints ) {
			$current[ $key ] = isset( $settings[ $key ] )
				? $settings[ $key ]
				: $constraints['default'];
		}

		return array(
			'success'  => true,
			'settings' => $current,
			'defaults' => $this->get_defaults(),
			'message'  => __( 'Current circuit breaker configuration retrieved.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Update circuit breaker settings.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function update_settings( array $arguments ) {
		if ( empty( $arguments['settings'] ) || ! is_array( $arguments['settings'] ) ) {
			return new \WP_Error(
				'missing_settings',
				__( 'Missing required argument: settings (object with threshold values)', 'mcp-ai-wpoos' )
			);
		}

		$settings     = get_option( 'wp_mcp_ai_circuit_breaker_config', array() );
		$new_settings = $arguments['settings'];
		$updated      = array();
		$rejected     = array();

		foreach ( $new_settings as $key => $value ) {
			if ( ! isset( $this->valid_keys[ $key ] ) ) {
				$rejected[] = $key;
				continue;
			}

			$int_value   = absint( $value );
			$constraints = $this->valid_keys[ $key ];

			if ( $int_value < $constraints['min'] || $int_value > $constraints['max'] ) {
				$rejected[] = sprintf(
					/* translators: 1: setting key, 2: min value, 3: max value */
					__( '%1$s (must be %2$d–%3$d)', 'mcp-ai-wpoos' ),
					$key,
					$constraints['min'],
					$constraints['max']
				);
				continue;
			}

			$settings[ $key ] = $int_value;
			$updated[]        = $key;
		}

		update_option( 'wp_mcp_ai_circuit_breaker_config', $settings );

		return array(
			'success'  => true,
			'updated'  => $updated,
			'rejected' => $rejected,
			'settings' => $settings,
			'message'  => sprintf(
				/* translators: 1: count of updated settings, 2: count of rejected settings */
				__( 'Updated %1$d setting(s). %2$d rejected.', 'mcp-ai-wpoos' ),
				count( $updated ),
				count( $rejected )
			),
		);
	}

	/**
	 * Reset circuit breaker configuration to defaults.
	 *
	 * @return array
	 */
	private function reset_to_defaults() {
		delete_option( 'wp_mcp_ai_circuit_breaker_config' );

		return array(
			'success'  => true,
			'settings' => $this->get_defaults(),
			'message'  => __( 'Circuit breaker configuration reset to defaults.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get default configuration values.
	 *
	 * @return array
	 */
	private function get_defaults() {
		$defaults = array();
		foreach ( $this->valid_keys as $key => $constraints ) {
			$defaults[ $key ] = $constraints['default'];
		}
		return $defaults;
	}
}
