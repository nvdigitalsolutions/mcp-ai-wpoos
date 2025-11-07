<?php
/**
 * Tools & Features Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Tools' ) ) {
	/**
	 * Tools & Features settings section.
	 */
	class WP_MCP_AI_Section_Tools extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'tools';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Tools & Features Configuration', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure tool limits, mesh computing, and federation settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_mesh_computing' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Mesh Computing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable distributed computing features', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to participate in mesh computing networks.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_federation'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable federated discovery', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to be discovered by and connect to other WP oOS instances.', 'wp-mcp-ai' ),
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
	}
}
