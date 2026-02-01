<?php
/**
 * Pro Dashboard Chart Settings Page
 *
 * Provides a simple interface for updating chart data via WordPress admin.
 *
 * @package WP_MCP_AI
 * @since 1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard_Chart_Settings' ) ) {
	/**
	 * Chart settings page for Pro Dashboard.
	 */
	class WP_MCP_AI_Pro_Dashboard_Chart_Settings {
		/**
		 * Initialize the settings page.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ), 30 );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}

		/**
		 * Add settings page to Pro Dashboard menu.
		 */
		public function add_settings_page() {
			add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Chart Settings', 'mcp-ai-wpoos' ),
				__( 'Chart Settings', 'mcp-ai-wpoos' ),
				'manage_options',
				'nvoos-pro-chart-settings',
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Register settings.
		 */
		public function register_settings() {
			register_setting(
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_risk_data',
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_risk_data' ),
				)
			);

			register_setting(
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_metrics_data',
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_metrics_data' ),
				)
			);

			// Risk data section.
			add_settings_section(
				'wp_mcp_ai_risk_section',
				__( 'Risk Distribution Data', 'mcp-ai-wpoos' ),
				array( $this, 'render_risk_section_description' ),
				'wp_mcp_ai_chart_settings'
			);

			add_settings_field(
				'risk_critical',
				__( 'Critical Risks', 'mcp-ai-wpoos' ),
				array( $this, 'render_risk_critical_field' ),
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_risk_section'
			);

			add_settings_field(
				'risk_high',
				__( 'High Risks', 'mcp-ai-wpoos' ),
				array( $this, 'render_risk_high_field' ),
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_risk_section'
			);

			add_settings_field(
				'risk_medium',
				__( 'Medium Risks', 'mcp-ai-wpoos' ),
				array( $this, 'render_risk_medium_field' ),
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_risk_section'
			);

			add_settings_field(
				'risk_low',
				__( 'Low Risks', 'mcp-ai-wpoos' ),
				array( $this, 'render_risk_low_field' ),
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_risk_section'
			);

			// Metrics data section.
			add_settings_section(
				'wp_mcp_ai_metrics_section',
				__( 'Security Metrics Data (Last 6 Months)', 'mcp-ai-wpoos' ),
				array( $this, 'render_metrics_section_description' ),
				'wp_mcp_ai_chart_settings'
			);

			add_settings_field(
				'metrics_incidents',
				__( 'Security Incidents', 'mcp-ai-wpoos' ),
				array( $this, 'render_metrics_incidents_field' ),
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_metrics_section'
			);

			add_settings_field(
				'metrics_vulnerabilities',
				__( 'Vulnerabilities Fixed', 'mcp-ai-wpoos' ),
				array( $this, 'render_metrics_vulnerabilities_field' ),
				'wp_mcp_ai_chart_settings',
				'wp_mcp_ai_metrics_section'
			);
		}

		/**
		 * Render settings page.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Handle form submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for success message display.
			if ( isset( $_GET['settings-updated'] ) ) {
				add_settings_error(
					'wp_mcp_ai_chart_settings',
					'wp_mcp_ai_message',
					__( 'Chart settings saved successfully. Charts will update automatically.', 'mcp-ai-wpoos' ),
					'success'
				);
			}

			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Update chart data for the Pro Dashboard. Changes will be reflected immediately in all charts.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php settings_errors( 'wp_mcp_ai_chart_settings' ); ?>

				<form method="post" action="options.php">
					<?php
					settings_fields( 'wp_mcp_ai_chart_settings' );
					do_settings_sections( 'wp_mcp_ai_chart_settings' );
					submit_button( __( 'Save Chart Settings', 'mcp-ai-wpoos' ) );
					?>
				</form>

				<hr />

				<h2><?php esc_html_e( 'Preview Current Data', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: Link to Pro Dashboard */
						esc_html__( 'View the charts on the %s to see your changes.', 'mcp-ai-wpoos' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=nvoos-pro-dashboard' ) ) . '">' . esc_html__( 'Pro Dashboard', 'mcp-ai-wpoos' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}

		/**
		 * Render risk section description.
		 */
		public function render_risk_section_description() {
			echo '<p>' . esc_html__( 'Enter the current count of risks by severity level. These values will be displayed in the Risk Distribution chart.', 'mcp-ai-wpoos' ) . '</p>';
		}

		/**
		 * Render metrics section description.
		 */
		public function render_metrics_section_description() {
			echo '<p>' . esc_html__( 'Enter 6 values (one for each of the last 6 months) separated by commas. These values will be displayed in the Security Metrics chart.', 'mcp-ai-wpoos' ) . '</p>';
		}

		/**
		 * Render risk critical field.
		 */
		public function render_risk_critical_field() {
			$risk_data = get_option( 'wp_mcp_ai_risk_data', array() );
			$value     = isset( $risk_data['critical'] ) ? $risk_data['critical'] : 0;
			?>
			<input type="number" name="wp_mcp_ai_risk_data[critical]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1" />
			<?php
		}

		/**
		 * Render risk high field.
		 */
		public function render_risk_high_field() {
			$risk_data = get_option( 'wp_mcp_ai_risk_data', array() );
			$value     = isset( $risk_data['high'] ) ? $risk_data['high'] : 0;
			?>
			<input type="number" name="wp_mcp_ai_risk_data[high]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1" />
			<?php
		}

		/**
		 * Render risk medium field.
		 */
		public function render_risk_medium_field() {
			$risk_data = get_option( 'wp_mcp_ai_risk_data', array() );
			$value     = isset( $risk_data['medium'] ) ? $risk_data['medium'] : 0;
			?>
			<input type="number" name="wp_mcp_ai_risk_data[medium]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1" />
			<?php
		}

		/**
		 * Render risk low field.
		 */
		public function render_risk_low_field() {
			$risk_data = get_option( 'wp_mcp_ai_risk_data', array() );
			$value     = isset( $risk_data['low'] ) ? $risk_data['low'] : 0;
			?>
			<input type="number" name="wp_mcp_ai_risk_data[low]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1" />
			<?php
		}

		/**
		 * Render metrics incidents field.
		 */
		public function render_metrics_incidents_field() {
			$metrics_data = get_option( 'wp_mcp_ai_metrics_data', array() );
			$value        = isset( $metrics_data['incidents'] ) ? implode( ', ', $metrics_data['incidents'] ) : '5, 3, 2, 4, 1, 2';
			?>
			<input type="text" name="wp_mcp_ai_metrics_data[incidents]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
			<p class="description"><?php esc_html_e( 'Enter 6 numbers separated by commas (e.g., 5, 3, 2, 4, 1, 2)', 'mcp-ai-wpoos' ); ?></p>
			<?php
		}

		/**
		 * Render metrics vulnerabilities field.
		 */
		public function render_metrics_vulnerabilities_field() {
			$metrics_data = get_option( 'wp_mcp_ai_metrics_data', array() );
			$value        = isset( $metrics_data['vulnerabilities_fixed'] ) ? implode( ', ', $metrics_data['vulnerabilities_fixed'] ) : '8, 12, 10, 15, 14, 12';
			?>
			<input type="text" name="wp_mcp_ai_metrics_data[vulnerabilities_fixed]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
			<p class="description"><?php esc_html_e( 'Enter 6 numbers separated by commas (e.g., 8, 12, 10, 15, 14, 12)', 'mcp-ai-wpoos' ); ?></p>
			<?php
		}

		/**
		 * Sanitize risk data.
		 *
		 * @param array $input Input data.
		 * @return array Sanitized data.
		 */
		public function sanitize_risk_data( $input ) {
			$sanitized = array();

			if ( isset( $input['critical'] ) ) {
				$sanitized['critical'] = absint( $input['critical'] );
			}
			if ( isset( $input['high'] ) ) {
				$sanitized['high'] = absint( $input['high'] );
			}
			if ( isset( $input['medium'] ) ) {
				$sanitized['medium'] = absint( $input['medium'] );
			}
			if ( isset( $input['low'] ) ) {
				$sanitized['low'] = absint( $input['low'] );
			}

			return $sanitized;
		}

		/**
		 * Sanitize metrics data.
		 *
		 * @param array $input Input data.
		 * @return array Sanitized data.
		 */
		public function sanitize_metrics_data( $input ) {
			$sanitized = array();

			if ( isset( $input['incidents'] ) ) {
				if ( is_string( $input['incidents'] ) ) {
					$incidents       = array_map( 'trim', explode( ',', $input['incidents'] ) );
					$incidents       = array_map( 'absint', $incidents );
					$incidents       = array_slice( $incidents, 0, 6 );
					$incidents_count = count( $incidents );
					while ( $incidents_count < 6 ) {
						$incidents_count = count( $incidents );
						$incidents[]     = 0;
					}
					$sanitized['incidents'] = $incidents;
				} elseif ( is_array( $input['incidents'] ) ) {
					$sanitized['incidents'] = array_map( 'absint', $input['incidents'] );
				}
			}

			if ( isset( $input['vulnerabilities_fixed'] ) ) {
				if ( is_string( $input['vulnerabilities_fixed'] ) ) {
					$vulns           = array_map( 'trim', explode( ',', $input['vulnerabilities_fixed'] ) );
					$vulns           = array_map( 'absint', $vulns );
					$vulns           = array_slice( $vulns, 0, 6 );
						$vulns_count = count( $vulns );
					while ( $vulns_count < 6 ) {
						$vulns[] = 0;
											$vulns_count = count( $vulns );
					}
					$sanitized['vulnerabilities_fixed'] = $vulns;
				} elseif ( is_array( $input['vulnerabilities_fixed'] ) ) {
					$sanitized['vulnerabilities_fixed'] = array_map( 'absint', $input['vulnerabilities_fixed'] );
				}
			}

			return $sanitized;
		}
	}
}

// Initialize the settings page if Pro Dashboard is active.
if ( defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED ) {
	new WP_MCP_AI_Pro_Dashboard_Chart_Settings();
}
