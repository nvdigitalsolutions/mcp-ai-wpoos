<?php
/**
 * Simple Settings Page for NV oOS
 *
 * A diagnostic page under Settings > NV oOS that shows a flat list
 * of all saved settings values for easy verification.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Simple_Settings_Page' ) ) {
	/**
	 * Simple diagnostic settings page.
	 */
	class WP_MCP_AI_Simple_Settings_Page {
		const PAGE_SLUG = 'wp-mcp-ai-simple-settings';

		/**
		 * Initialize the simple settings page.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		}

		/**
		 * Register the simple settings page under Settings menu.
		 */
		public function register_settings_page() {
			add_options_page(
				__( 'NV oOS Settings', 'mcp-ai-wpoos' ),
				__( 'NV oOS', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Render the simple settings page.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
			}

			// Get all saved settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Get field definitions from General and Providers sections.
			$general_fields   = $this->get_general_fields();
			$providers_fields = $this->get_providers_fields();

			// Merge field definitions.
			$all_fields = array_merge( $general_fields, $providers_fields );

			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %s: Link to main settings dashboard */
							esc_html__( 'This page shows a flat list of all saved plugin settings for diagnostic purposes. To configure these settings, visit the %s.', 'mcp-ai-wpoos' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ) . '">' . esc_html__( 'main settings dashboard', 'mcp-ai-wpoos' ) . '</a>'
						);
						?>
					</p>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 35%;"><?php esc_html_e( 'Setting', 'mcp-ai-wpoos' ); ?></th>
							<th style="width: 45%;"><?php esc_html_e( 'Current Value', 'mcp-ai-wpoos' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Sort fields alphabetically by key for easier scanning.
						ksort( $all_fields );

						foreach ( $all_fields as $key => $field ) {
							$this->render_setting_row( $key, $field, $settings );
						}
						?>
					</tbody>
				</table>

				<div style="margin-top: 20px;">
					<p>
						<strong><?php esc_html_e( 'Total Settings:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html( count( $all_fields ) ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Settings with Values:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						$count_with_values = 0;
						foreach ( $all_fields as $key => $field ) {
							if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
								$count_with_values++;
							}
						}
						echo esc_html( $count_with_values );
						?>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Render a single setting row.
		 *
		 * @param string $key      Setting key.
		 * @param array  $field    Field definition.
		 * @param array  $settings All settings.
		 */
		private function render_setting_row( $key, $field, $settings ) {
			$label = isset( $field['label'] ) ? $field['label'] : $key;
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

			// Format the value for display.
			$display_value = $this->format_value_for_display( $value, $type, $key );

			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $label ); ?></strong><br>
					<code style="font-size: 11px; color: #666;"><?php echo esc_html( $key ); ?></code>
				</td>
				<td>
					<?php echo wp_kses_post( $display_value ); ?>
				</td>
				<td>
					<code><?php echo esc_html( $type ); ?></code>
				</td>
			</tr>
			<?php
		}

		/**
		 * Format a value for display.
		 *
		 * @param mixed  $value Raw value.
		 * @param string $type  Field type.
		 * @param string $key   Setting key.
		 * @return string Formatted value.
		 */
		private function format_value_for_display( $value, $type, $key ) {
			// Handle empty values.
			if ( '' === $value || null === $value ) {
				return '<em style="color: #999;">' . esc_html__( '(not set)', 'mcp-ai-wpoos' ) . '</em>';
			}

			// Hide sensitive fields (API keys, tokens, passwords).
			$sensitive_patterns = array( 'key', 'token', 'secret', 'password', 'api' );
			foreach ( $sensitive_patterns as $pattern ) {
				if ( false !== stripos( $key, $pattern ) || 'password' === $type ) {
					if ( '' !== $value && ! empty( $value ) ) {
						return '<code style="color: #4caf50;">' . esc_html__( '••• (hidden for security)', 'mcp-ai-wpoos' ) . '</code>';
					}
				}
			}

			// Handle different types.
			switch ( $type ) {
				case 'checkbox':
					return $value ? '<span style="color: #4caf50;">✓ ' . esc_html__( 'Enabled', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #999;">✗ ' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>';

				case 'number':
				case 'range':
				case 'slider':
					return '<code>' . esc_html( $value ) . '</code>';

				case 'textarea':
					$truncated = strlen( $value ) > 100 ? substr( $value, 0, 100 ) . '...' : $value;
					return '<span style="white-space: pre-wrap; font-family: monospace; font-size: 12px;">' . esc_html( $truncated ) . '</span>';

				case 'select':
					return '<strong>' . esc_html( $value ) . '</strong>';

				case 'url':
					if ( ! empty( $value ) ) {
						return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
					}
					return esc_html( $value );

				default:
					// Array values.
					if ( is_array( $value ) ) {
						return '<pre style="font-size: 11px; max-height: 150px; overflow: auto;">' . esc_html( print_r( $value, true ) ) . '</pre>';
					}

					// Regular text values.
					$truncated = strlen( $value ) > 200 ? substr( $value, 0, 200 ) . '...' : $value;
					return esc_html( $truncated );
			}
		}

		/**
		 * Get field definitions from General section.
		 *
		 * @return array Field definitions.
		 */
		private function get_general_fields() {
			// Try to get fields from the General section.
			$sections = WP_MCP_AI_Settings_Registry::get_sections( 'general' );
			$fields   = array();

			foreach ( $sections as $section ) {
				if ( method_exists( $section, 'get_fields' ) ) {
					$section_fields = $section->get_fields();
					if ( is_array( $section_fields ) ) {
						$fields = array_merge( $fields, $section_fields );
					}
				}
			}

			return $fields;
		}

		/**
		 * Get field definitions from Providers section.
		 *
		 * @return array Field definitions.
		 */
		private function get_providers_fields() {
			// Try to get fields from the Providers section.
			$sections = WP_MCP_AI_Settings_Registry::get_sections( 'providers' );
			$fields   = array();

			foreach ( $sections as $section ) {
				if ( method_exists( $section, 'get_fields' ) ) {
					$section_fields = $section->get_fields();
					if ( is_array( $section_fields ) ) {
						$fields = array_merge( $fields, $section_fields );
					}
				}
			}

			return $fields;
		}
	}
}
