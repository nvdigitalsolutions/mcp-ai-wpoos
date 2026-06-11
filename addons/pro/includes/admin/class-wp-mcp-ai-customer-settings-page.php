<?php
/**
 * Customer Settings Admin Page
 *
 * Provides a dedicated settings page under the Customer CPT menu for
 * configuring AI assistant, default field values, lifecycle preferences,
 * and customer research options.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer Settings admin page handler.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Customer_Settings_Page {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_customer_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-customer-settings';

	/**
	 * Initialize the page.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_submenu_page' ), 25 );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
	}

	/**
	 * Register the submenu page under Customers CPT.
	 *
	 * @since 2.6.0
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Customer_CPT' ) ? WP_MCP_AI_Customer_CPT::POST_TYPE : 'mcp_ai_customer';

		add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Customer Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @since 2.6.0
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_NAME . '_group',
			self::OPTION_NAME,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 2.6.0
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// AI Integration.
		if ( isset( $input['research_assistant'] ) ) {
			if ( 'default' === $input['research_assistant'] ) {
				$sanitized['research_assistant'] = 'default';
			} else {
				$sanitized['research_assistant'] = absint( $input['research_assistant'] );
			}
		}

		$sanitized['default_lifecycle_stage'] = isset( $input['default_lifecycle_stage'] )
			? sanitize_key( $input['default_lifecycle_stage'] )
			: 'customer';

		$sanitized['default_currency'] = isset( $input['default_currency'] )
			? strtoupper( sanitize_text_field( $input['default_currency'] ) )
			: 'USD';

		$sanitized['auto_create_company'] = ! empty( $input['auto_create_company'] );

		return $sanitized;
	}

	/**
	 * Get current settings with defaults.
	 *
	 * @since 2.6.0
	 *
	 * @return array
	 */
	private function get_settings() {
		$defaults = array(
			'research_assistant'      => 'default',
			'default_lifecycle_stage' => 'customer',
			'default_currency'        => 'USD',
			'auto_create_company'     => false,
		);

		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $defaults, $stored );
	}

	/**
	 * Get available assistants for the dropdown.
	 *
	 * @since 2.7.0
	 *
	 * @return array<string,string> Assistant ID => label pairs.
	 */
	private function get_available_assistants() {
		$assistants = array(
			'default' => __( 'CRM Toolkit Default', 'mcp-ai-wpoos-pro' ),
		);

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$assistants[ $post->ID ] = $post->post_title;
		}

		return $assistants;
	}

	/**
	 * Get customer-specific tools for the tools tab.
	 *
	 * @since 2.7.0
	 *
	 * @return array<string,string>
	 */
	private function get_customer_tools() {
		return array(
			'create_customer'          => __( 'Create Customer', 'mcp-ai-wpoos-pro' ),
			'get_customer'             => __( 'Get Customer', 'mcp-ai-wpoos-pro' ),
			'list_customers'           => __( 'List Customers', 'mcp-ai-wpoos-pro' ),
			'update_customer'          => __( 'Update Customer', 'mcp-ai-wpoos-pro' ),
			'delete_customer'          => __( 'Delete Customer', 'mcp-ai-wpoos-pro' ),
			'convert_lead_to_customer' => __( 'Convert Lead to Customer', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since 2.6.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type  = class_exists( 'WP_MCP_AI_Customer_CPT' ) ? WP_MCP_AI_Customer_CPT::POST_TYPE : 'mcp_ai_customer';
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-smiley" style="font-size: 32px; width: 32px; height: 32px;"></span>
				<?php echo esc_html( __( 'Customer Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_tabs( $active_tab ); ?>

			<div class="toolkit-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'configuration':
						$this->render_configuration_tab();
						break;
					case 'tools':
						$this->render_tools_tab();
						break;
					case 'help':
						$this->render_help_tab( $post_type );
						break;
					default:
						$this->render_overview_tab( $post_type );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	protected function render_tabs( $active_tab ) {
		$tabs = array(
			'overview'      => __( 'Overview', 'mcp-ai-wpoos-pro' ),
			'configuration' => __( 'AI Configuration', 'mcp-ai-wpoos-pro' ),
			'tools'         => __( 'Tools', 'mcp-ai-wpoos-pro' ),
			'help'          => __( 'Help', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render overview tab.
	 *
	 * @param string $post_type The customer CPT slug.
	 */
	protected function render_overview_tab( $post_type ) {
		$count = 0;
		if ( post_type_exists( $post_type ) ) {
			$counts = wp_count_posts( $post_type );
			$count  = isset( $counts->publish ) ? $counts->publish : 0;
		}

		$settings = get_option( self::OPTION_NAME, array() );
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Customer Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
				<div class="toolkit-stat-card" style="background: #f0f6fc; padding: 20px; border-left: 4px solid #2271b1;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Total Customers', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo absint( $count ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'View All Customers', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Add New Customer', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=research-customer' ) ); ?>"><?php esc_html_e( 'Research & Add Customer', 'mcp-ai-wpoos-pro' ); ?></a></li>
			</ul>

			<h3><?php esc_html_e( 'Current Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'AI Assistant', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$assistant_id = isset( $settings['research_assistant'] ) ? $settings['research_assistant'] : 'default';
							if ( 'default' === $assistant_id ) {
								esc_html_e( 'CRM Toolkit Default', 'mcp-ai-wpoos-pro' );
							} elseif ( $assistant_id > 0 && get_post( $assistant_id ) ) {
								echo esc_html( get_the_title( $assistant_id ) );
							} else {
								esc_html_e( 'Not set', 'mcp-ai-wpoos-pro' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Default Lifecycle Stage', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( ucfirst( $settings['default_lifecycle_stage'] ?? 'customer' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Default Currency', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( $settings['default_currency'] ?? 'USD' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Customer Fields', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Field', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><strong><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>Email</td><td><?php esc_html_e( 'Primary contact email', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'First / Last Name', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>Text</td><td><?php esc_html_e( 'Customer name', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>Text</td><td><?php esc_html_e( 'Associated organisation', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Total Revenue', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>Number</td><td><?php esc_html_e( 'Cumulative revenue', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Lifetime Value (LTV)', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>Number</td><td><?php esc_html_e( 'Estimated total value', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Customer Since', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>Date</td><td><?php esc_html_e( 'Conversion date', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Source Lead', 'mcp-ai-wpoos-pro' ); ?></strong></td><td>ID</td><td><?php esc_html_e( 'Originating lead record', 'mcp-ai-wpoos-pro' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render AI Configuration tab.
	 */
	protected function render_configuration_tab() {
		$settings = get_option( self::OPTION_NAME, array() );

		$current_assistant = isset( $settings['research_assistant'] ) ? $settings['research_assistant'] : 'default';
		$lifecycle_stage   = isset( $settings['default_lifecycle_stage'] ) ? $settings['default_lifecycle_stage'] : 'customer';
		$default_currency  = isset( $settings['default_currency'] ) ? $settings['default_currency'] : 'USD';
		$auto_company      = ! empty( $settings['auto_create_company'] );

		$available_assistants = $this->get_available_assistants();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'AI Configuration for Customer Research', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'These settings control how AI assists with customer research, health analysis, lifecycle management, and conversion. They override the CRM Toolkit defaults for this CPT.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_NAME . '_group' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="customer_research_assistant"><?php esc_html_e( 'Research Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[research_assistant]" id="customer_research_assistant">
								<?php foreach ( $available_assistants as $a_id => $a_name ) : ?>
									<option value="<?php echo esc_attr( $a_id ); ?>" <?php selected( $current_assistant, $a_id ); ?>>
										<?php echo esc_html( $a_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select which AI assistant to use for customer research, health analysis, and lifecycle management. Leave as "CRM Toolkit Default" to use the global setting.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="default_lifecycle_stage"><?php esc_html_e( 'Default Lifecycle Stage', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select id="default_lifecycle_stage" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_lifecycle_stage]">
								<option value="customer" <?php selected( $lifecycle_stage, 'customer' ); ?>><?php esc_html_e( 'Customer', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="evangelist" <?php selected( $lifecycle_stage, 'evangelist' ); ?>><?php esc_html_e( 'Evangelist', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default lifecycle stage assigned to new customers created via AI research.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="default_currency"><?php esc_html_e( 'Default Currency', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_currency" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_currency]"
								value="<?php echo esc_attr( $default_currency ); ?>" maxlength="3" style="width: 80px;" />
							<p class="description"><?php esc_html_e( 'ISO 4217 currency code (e.g. USD, EUR, GBP).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="auto_create_company"><?php esc_html_e( 'Auto-Create Company', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" id="auto_create_company" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_create_company]"
									value="1" <?php checked( $auto_company ); ?> />
								<?php esc_html_e( 'Automatically create a company record when a customer is created with a new company name.', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Settings Hierarchy', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Customer assistant resolution order:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li><strong><?php esc_html_e( 'Customer Settings', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'This page (highest priority for customer research)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'CRM Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Global CRM Research & Add Assistant (medium priority)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'First Available Assistant', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Auto-fallback to any published assistant (lowest priority)', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render tools tab.
	 */
	protected function render_tools_tab() {
		$tools = $this->get_customer_tools();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Customer Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The following tools are available for customer operations. Enable or disable them in the CRM Toolkit settings.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( empty( $tools ) ) : ?>
				<p><?php esc_html_e( 'No customer-specific tools found. Ensure the CRM toolkit is enabled.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<?php foreach ( $tools as $slug => $label ) : ?>
					<div class="tool-item">
						<strong><?php echo esc_html( $label ); ?></strong>
						<code><?php echo esc_html( $slug ); ?></code>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render help tab.
	 *
	 * @param string $post_type The customer CPT slug.
	 */
	protected function render_help_tab( $post_type ) {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Getting Started with Customer Management', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Configure your AI assistant', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Choose which assistant powers customer research in the AI Configuration tab.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Research and convert leads', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Use the Research & Add page to analyse customer health, track revenue, and manage lifecycle stages with AI.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Monitor customer health', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Use the Review & Health dashboard to track completeness, churn risk, and revenue tiers.', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Customer List', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=research-customer' ) ); ?>"><?php esc_html_e( 'Research & Add Customer', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-crm-toolkit-settings' ) ); ?>"><?php esc_html_e( 'CRM Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></a></li>
			</ul>
		</div>
		<?php
	}
}
