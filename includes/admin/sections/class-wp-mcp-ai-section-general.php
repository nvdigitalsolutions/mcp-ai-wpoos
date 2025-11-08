<?php
/**
 * General Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_General' ) ) {
	/**
	 * General settings section.
	 */
	class WP_MCP_AI_Section_General extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'general';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'General Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'general';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 10;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure basic plugin settings and operational parameters.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_logging'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable basic error and activity logging', 'wp-mcp-ai' ),
					'description'    => __( 'Records errors, warnings, and key activity (tool executions, API requests) to help troubleshoot issues. View logs in the Advanced tab.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'delete_on_uninstall'  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Delete Data on Uninstall', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Remove all plugin data when uninstalling', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, all settings and data will be deleted when the plugin is uninstalled.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'max_history_messages' => array(
					'type'        => 'number',
					'label'       => __( 'Max History Messages', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of previous messages to include in chat context.', 'wp-mcp-ai' ),
					'default'     => 10,
					'placeholder' => '10',
				),
				'request_timeout'      => array(
					'type'        => 'number',
					'label'       => __( 'Request Timeout (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'How long to wait for AI provider responses before timing out.', 'wp-mcp-ai' ),
					'default'     => 60,
					'placeholder' => '60',
				),
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate max_history_messages.
			if ( isset( $input['max_history_messages'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['max_history_messages'],
					1,
					100
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}

			// Validate request_timeout.
			if ( isset( $input['request_timeout'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['request_timeout'],
					10,
					600
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
