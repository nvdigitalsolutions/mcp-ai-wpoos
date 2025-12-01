<?php
/**
 * Plugins Integration Settings Section
 *
 * Consolidated section for all WordPress plugin integrations (JetEngine, WooCommerce, Elementor).
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Plugins_Integration' ) ) {
	/**
	 * Plugins integration settings section.
	 */
	class WP_MCP_AI_Section_Plugins_Integration extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'plugins_integration';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Plugins', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * Note: This section is now integrated as a subtab within the Tools section.
		 * Return an empty string to prevent it from appearing as a standalone section.
		 *
		 * @return string
		 */
		public function get_tab() {
			return '';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure WordPress plugin integrations including JetEngine, WooCommerce, and Elementor.', 'wp-mcp-ai' );
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 30;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			$jetengine_active   = class_exists( 'Jet_Engine' );
			$woocommerce_active = class_exists( 'WooCommerce' );
			$elementor_active   = did_action( 'elementor/loaded' );

			$fields = array();

			// JetEngine Section.
			$fields['jetengine_header'] = array(
				'type'    => 'html',
				'content' => $this->get_section_header( 'JetEngine', 'dashicons-admin-plugins', $jetengine_active ),
			);

			if ( ! $jetengine_active ) {
				$fields['jetengine_inactive'] = array(
					'type'    => 'html',
					'content' => $this->get_inactive_notice( 'JetEngine' ),
				);
			}

			if ( $jetengine_active ) {
				$fields['enable_jetengine_cct'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine CCT Storage', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable JetEngine CCT storage', 'wp-mcp-ai' ),
					'description'    => __( 'Use JetEngine Custom Content Types for efficient chat transcript and assistant data storage.', 'wp-mcp-ai' ),
					'default'        => true,
				);

				$fields['enable_jetengine_tools'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine AI Tools', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable JetEngine AI tools', 'wp-mcp-ai' ),
					'description'    => __( 'Activate JetEngine-specific tools for post type management, taxonomy operations, and CCT queries.', 'wp-mcp-ai' ),
					'default'        => true,
				);

				$fields['jetengine_tools'] = array(
					'type'    => 'html',
					'content' => $this->get_jetengine_tools_list(),
				);
			}

			// WooCommerce Section.
			$fields['woocommerce_header'] = array(
				'type'    => 'html',
				'content' => $this->get_section_header( 'WooCommerce', 'dashicons-cart', $woocommerce_active ),
			);

			if ( ! $woocommerce_active ) {
				$fields['woocommerce_inactive'] = array(
					'type'    => 'html',
					'content' => $this->get_inactive_notice( 'WooCommerce' ),
				);
			}

			if ( $woocommerce_active ) {
				$fields['enable_woocommerce_tools'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable WooCommerce AI Tools', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable WooCommerce AI tools', 'wp-mcp-ai' ),
					'description'    => __( 'Activate WooCommerce-specific tools for managing products, orders, and customers.', 'wp-mcp-ai' ),
					'default'        => true,
				);

				$fields['woocommerce_tools'] = array(
					'type'    => 'html',
					'content' => $this->get_woocommerce_tools_list(),
				);
			}

			// Elementor Section.
			$fields['elementor_header'] = array(
				'type'    => 'html',
				'content' => $this->get_section_header( 'Elementor', 'dashicons-editor-table', $elementor_active ),
			);

			if ( ! $elementor_active ) {
				$fields['elementor_inactive'] = array(
					'type'    => 'html',
					'content' => $this->get_inactive_notice( 'Elementor' ),
				);
			}

			if ( $elementor_active ) {
				$fields['enable_elementor_widgets'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Elementor AI Widgets', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Elementor AI widgets', 'wp-mcp-ai' ),
					'description'    => __( 'Add AI-powered chat widgets and other AI elements to Elementor page builder.', 'wp-mcp-ai' ),
					'default'        => true,
				);

				$fields['elementor_widgets'] = array(
					'type'    => 'html',
					'content' => $this->get_elementor_widgets_list(),
				);
			}

			return $fields;
		}

		/**
		 * Get section header HTML.
		 *
		 * @param string $name Plugin name.
		 * @param string $icon Dashicon class.
		 * @param bool   $active Whether plugin is active.
		 * @return string
		 */
		private function get_section_header( $name, $icon, $active ) {
			$status_icon  = $active ? '✓' : '○';
			$status_color = $active ? '#0a5f1a' : '#646970';
			$status_text  = $active ? __( 'Active', 'wp-mcp-ai' ) : __( 'Not Active', 'wp-mcp-ai' );

			return sprintf(
				'<div style="margin: 30px 0 10px 0; padding-top: 20px; border-top: 1px solid #ddd;">
					<h3 style="margin: 0; display: flex; align-items: center; gap: 8px; color: %s;">
						<span class="%s" style="font-size: 20px;"></span>
						<span>%s</span>
						<span style="font-size: 14px; font-weight: normal;">(%s %s)</span>
					</h3>
				</div>',
				esc_attr( $status_color ),
				esc_attr( $icon ),
				esc_html( $name ),
				esc_html( $status_icon ),
				esc_html( $status_text )
			);
		}

		/**
		 * Get inactive plugin notice HTML.
		 *
		 * @param string $name Plugin name.
		 * @return string
		 */
		private function get_inactive_notice( $name ) {
			return sprintf(
				'<div style="background: #f0f0f1; border-left: 4px solid #646970; padding: 1rem; margin: 10px 0;">
					<p style="margin: 0;">%s</p>
				</div>',
				sprintf(
					/* translators: %s: Plugin name */
					esc_html__( '%s is not installed or not active. Install and activate the plugin to enable integration features.', 'wp-mcp-ai' ),
					esc_html( $name )
				)
			);
		}

		/**
		 * Get JetEngine tools list HTML.
		 *
		 * @return string
		 */
		private function get_jetengine_tools_list() {
			return '<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
				<p style="margin: 0 0 5px 0; font-weight: 600;">' . esc_html__( 'Available JetEngine Tools:', 'wp-mcp-ai' ) . '</p>
				<ul style="margin: 5px 0; padding-left: 20px;">
					<li><code>jetengine_create_post_type</code> - ' . esc_html__( 'Create custom post types dynamically', 'wp-mcp-ai' ) . '</li>
					<li><code>jetengine_create_taxonomy</code> - ' . esc_html__( 'Create custom taxonomies', 'wp-mcp-ai' ) . '</li>
					<li><code>jetengine_query_cct</code> - ' . esc_html__( 'Query Custom Content Types efficiently', 'wp-mcp-ai' ) . '</li>
					<li><code>jetengine_create_cct_item</code> - ' . esc_html__( 'Create CCT entries programmatically', 'wp-mcp-ai' ) . '</li>
					<li><code>jetengine_update_cct_item</code> - ' . esc_html__( 'Update existing CCT items', 'wp-mcp-ai' ) . '</li>
				</ul>
			</div>';
		}

		/**
		 * Get WooCommerce tools list HTML.
		 *
		 * @return string
		 */
		private function get_woocommerce_tools_list() {
			return '<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
				<p style="margin: 0 0 5px 0; font-weight: 600;">' . esc_html__( 'Available WooCommerce Tools:', 'wp-mcp-ai' ) . '</p>
				<ul style="margin: 5px 0; padding-left: 20px;">
					<li><code>woocommerce_get_products</code> - ' . esc_html__( 'Retrieve product information and inventory', 'wp-mcp-ai' ) . '</li>
					<li><code>woocommerce_get_orders</code> - ' . esc_html__( 'Access order details and status', 'wp-mcp-ai' ) . '</li>
					<li><code>woocommerce_get_customers</code> - ' . esc_html__( 'Query customer data and purchase history', 'wp-mcp-ai' ) . '</li>
				</ul>
			</div>';
		}

		/**
		 * Get Elementor widgets list HTML.
		 *
		 * @return string
		 */
		private function get_elementor_widgets_list() {
			return '<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
				<p style="margin: 0 0 5px 0; font-weight: 600;">' . esc_html__( 'Available Elementor Widgets:', 'wp-mcp-ai' ) . '</p>
				<ul style="margin: 5px 0; padding-left: 20px;">
					<li><strong>' . esc_html__( 'AI Chat Widget', 'wp-mcp-ai' ) . '</strong> - ' . esc_html__( 'Embeddable AI chat interface for Elementor pages', 'wp-mcp-ai' ) . '</li>
					<li><strong>' . esc_html__( 'AI Assistant Selector', 'wp-mcp-ai' ) . '</strong> - ' . esc_html__( 'Widget to choose and interact with different AI assistants', 'wp-mcp-ai' ) . '</li>
				</ul>
			</div>';
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
