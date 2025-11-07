<?php
/**
 * Advanced Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Advanced' ) ) {
	/**
	 * Advanced settings section.
	 */
	class WP_MCP_AI_Section_Advanced extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'advanced';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Advanced Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'advanced';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Performance tuning, debugging options, and advanced configuration.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'memory_max_file_bytes'   => array(
					'type'        => 'number',
					'label'       => __( 'Max Memory File Size (bytes)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum file size for memory operations. Default: 5242880 (5 MB)', 'wp-mcp-ai' ),
					'default'     => 5242880,
					'placeholder' => '5242880',
				),
				'enable_extended_logging' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Extended Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable verbose debug logging', 'wp-mcp-ai' ),
					'description'    => __( 'Logs additional debug information. Can impact performance.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_opcache_reset'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Auto OPcache Reset', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically reset OPcache when needed', 'wp-mcp-ai' ),
					'description'    => __( 'Helps ensure code changes take effect immediately.', 'wp-mcp-ai' ),
					'default'        => false,
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

			// Validate memory max file bytes.
			if ( isset( $input['memory_max_file_bytes'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['memory_max_file_bytes'],
					1024,
					104857600
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Memory File Size must be between 1 KB and 100 MB.', 'wp-mcp-ai' );
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
