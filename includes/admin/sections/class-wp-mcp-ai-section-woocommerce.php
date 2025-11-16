<?php
/**
 * WooCommerce Integration Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Section_WooCommerce_Integration' ) ) {
	/**
	 * WooCommerce integration settings section.
	 */
	class WP_MCP_AI_Section_WooCommerce_Integration extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'integration_woocommerce';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'WooCommerce Integration', 'wp-mcp-ai' );
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
			$woo_active = class_exists( 'WooCommerce' );
			if ( ! $woo_active ) {
				return __( 'WooCommerce is not active. Install and activate WooCommerce to enable e-commerce AI tools.', 'wp-mcp-ai' );
			}
			return __( 'WooCommerce integration provides AI tools for product management, order processing, and customer analytics.', 'wp-mcp-ai' );
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 40;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			$woo_active = class_exists( 'WooCommerce' );

			$fields = array(
				'woocommerce_status' => array(
					'type'    => 'html',
					'content' => $this->get_status_content(),
				),
			);

			if ( $woo_active ) {
				$fields['enable_woocommerce_tools'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable WooCommerce AI Tools', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable WooCommerce AI tools', 'wp-mcp-ai' ),
					'description'    => __( 'Activate AI tools for product management, inventory tracking, and order operations.', 'wp-mcp-ai' ),
					'default'        => true,
				);

				$fields['enable_woo_analytics'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Sales Analytics Tools', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable sales analytics tools', 'wp-mcp-ai' ),
					'description'    => __( 'Allow AI to query sales data, revenue metrics, and customer analytics.', 'wp-mcp-ai' ),
					'default'        => true,
				);

				$fields['woocommerce_tools_list'] = array(
					'type'    => 'html',
					'content' => $this->get_tools_list_content(),
				);
			}

			return $fields;
		}

		/**
		 * Get status content HTML.
		 *
		 * @return string
		 */
		private function get_status_content() {
			$woo_active = class_exists( 'WooCommerce' );

			$content = '<div style="background: ' . ( $woo_active ? '#d5f0db' : '#f0f0f1' ) . '; border-left: 4px solid ' . ( $woo_active ? '#0a5f1a' : '#646970' ) . '; padding: 1rem; margin: 1rem 0;">';

			if ( $woo_active ) {
				$content .= '<h4 style="margin-top: 0; color: #0a5f1a;">' . esc_html__( '✓ WooCommerce Active', 'wp-mcp-ai' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'WooCommerce is installed and active. E-commerce AI tools are available.', 'wp-mcp-ai' ) . '</p>';
			} else {
				$content .= '<h4 style="margin-top: 0; color: #646970;">' . esc_html__( 'WooCommerce Not Active', 'wp-mcp-ai' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'WooCommerce is not installed or not active. To enable WooCommerce integration:', 'wp-mcp-ai' ) . '</p>';
				$content .= '<ol>';
				$content .= '<li>' . esc_html__( 'Install WooCommerce plugin from WordPress.org', 'wp-mcp-ai' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Activate the plugin', 'wp-mcp-ai' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Return to this page to configure integration settings', 'wp-mcp-ai' ) . '</li>';
				$content .= '</ol>';
			}

			$content .= '</div>';

			return $content;
		}

		/**
		 * Get tools list content HTML.
		 *
		 * @return string
		 */
		private function get_tools_list_content() {
			$content  = '<div style="margin: 1rem 0;">';
			$content .= '<h4>' . esc_html__( 'Available WooCommerce Tools', 'wp-mcp-ai' ) . '</h4>';
			$content .= '<ul style="margin-left: 1.5rem;">';
			$content .= '<li><strong>woo_create_product</strong> - ' . esc_html__( 'Create new products with full metadata', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>woo_update_product</strong> - ' . esc_html__( 'Update existing product details and pricing', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>woo_query_orders</strong> - ' . esc_html__( 'Search and analyze order data', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>woo_get_analytics</strong> - ' . esc_html__( 'Retrieve sales metrics and revenue reports', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>woo_manage_inventory</strong> - ' . esc_html__( 'Track and update product stock levels', 'wp-mcp-ai' ) . '</li>';
			$content .= '</ul>';
			$content .= '<p style="margin-top: 1rem;"><em>' . esc_html__( 'Note: WooCommerce tools are available only in Full Version mode. Set WP_MCP_AI_BASE_VERSION to false in wp-config.php to enable.', 'wp-mcp-ai' ) . '</em></p>';
			$content .= '</div>';

			return $content;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				if ( 'html' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in helper methods.
					echo $field['content'];
				} else {
					$this->render_field( $key, $field );
				}
			}
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			// All fields are boolean checkboxes, no special validation needed.
			return $input;
		}
	}
}
