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
			return __( 'Security Settings', 'mcp-ai-wpoos' );
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
			return __( 'Configure rate limiting, nefarious usage monitoring, and security features.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/security/SECURITY_HARDENING.md';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_root_security_key'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Root Security Key', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require root security key for sensitive operations', 'mcp-ai-wpoos' ),
					'description'    => __( 'Adds an extra layer of security for administrative operations.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'root_security_key'                        => array(
					'type'        => 'password',
					'label'       => __( 'Root Security Key', 'mcp-ai-wpoos' ),
					'description' => __( 'A secure key for sensitive operations (minimum 32 characters).', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'enable_rate_limiting'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Rate Limiting', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Limit request rates to prevent abuse', 'mcp-ai-wpoos' ),
					'description'    => __( 'Protects your installation from excessive API requests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'rate_limit_requests'                      => array(
					'type'        => 'number',
					'label'       => __( 'Rate Limit (requests)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of requests allowed per time window.', 'mcp-ai-wpoos' ),
					'default'     => 100,
					'placeholder' => '100',
				),
				'rate_limit_window'                        => array(
					'type'        => 'number',
					'label'       => __( 'Rate Limit Window (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'Time window for rate limiting.', 'mcp-ai-wpoos' ),
					'default'     => 3600,
					'placeholder' => '3600',
				),
				'enable_loopback_ssl_bypass'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Loopback/Private Network SSL Bypass', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically disable SSL verification for localhost and private network addresses', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, SSL verification is automatically disabled for requests to localhost (127.x.x.x), private IPv4 addresses (10.x.x.x, 172.16-31.x.x, 192.168.x.x), and private IPv6 addresses (fc00::/7). This is necessary for local AI services like Ollama and LM Studio which typically do not have valid SSL certificates. Disable this if you have proper SSL certificates configured for your local services or want stricter security.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_loopback_private_network_requests' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Private Network Requests', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Allow HTTP requests to private network addresses', 'mcp-ai-wpoos' ),
					'description'    => __( 'WordPress blocks requests to local and private IP addresses by default for security. Enable this to allow connections to local AI services (LM Studio, Ollama, etc.) running on private network addresses like 192.168.2.222:11434. Required for local AI providers on your network.', 'mcp-ai-wpoos' ),
					'default'        => true,
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
					$errors[] = __( 'Root Security Key must be at least 32 characters long.', 'mcp-ai-wpoos' );
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
					$errors[] = __( 'Rate Limit Requests: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			if ( isset( $input['rate_limit_window'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['rate_limit_window'],
					60,
					86400
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Rate Limit Window: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
