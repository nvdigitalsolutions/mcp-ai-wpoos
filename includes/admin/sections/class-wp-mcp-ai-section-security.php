<?php
/**
 * Security Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Security' ) ) {
	/**
	 * Security settings section.
	 */
	class WP_MCP_AI_Section_Security extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'security';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Security Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'security';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure rate limiting, nefarious usage monitoring, and security features.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_root_security_key' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Root Security Key', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Require root security key for sensitive operations', 'wp-mcp-ai' ),
					'description'    => __( 'Adds an extra layer of security for administrative operations.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'root_security_key'        => array(
					'type'        => 'password',
					'label'       => __( 'Root Security Key', 'wp-mcp-ai' ),
					'description' => __( 'A secure key for sensitive operations (minimum 32 characters).', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'enable_rate_limiting'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Rate Limiting', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Limit request rates to prevent abuse', 'wp-mcp-ai' ),
					'description'    => __( 'Protects your installation from excessive API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'rate_limit_requests'      => array(
					'type'        => 'number',
					'label'       => __( 'Rate Limit (requests)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of requests allowed per time window.', 'wp-mcp-ai' ),
					'default'     => 100,
					'placeholder' => '100',
				),
				'rate_limit_window'        => array(
					'type'        => 'number',
					'label'       => __( 'Rate Limit Window (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'Time window for rate limiting.', 'wp-mcp-ai' ),
					'default'     => 3600,
					'placeholder' => '3600',
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

			// Validate root security key length.
			if ( isset( $input['root_security_key'] ) && ! empty( $input['root_security_key'] ) ) {
				if ( strlen( $input['root_security_key'] ) < 32 ) {
					$errors[] = __( 'Root Security Key must be at least 32 characters long.', 'wp-mcp-ai' );
				}
			}

			// Validate rate limit numbers.
			if ( isset( $input['rate_limit_requests'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['rate_limit_requests'],
					1,
					10000
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Rate Limit Requests: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			if ( isset( $input['rate_limit_window'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['rate_limit_window'],
					60,
					86400
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Rate Limit Window: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
