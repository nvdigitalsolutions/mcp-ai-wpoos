<?php
/**
 * Customer Settings Admin Page
 *
 * Provides a dedicated settings page under the Customer CPT menu for
 * configuring AI assistant, default field values, and source preferences.
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
	 * Render the settings page.
	 *
	 * @since 2.6.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings   = $this->get_settings();
		$post_type  = class_exists( 'WP_MCP_AI_Customer_CPT' ) ? WP_MCP_AI_Customer_CPT::POST_TYPE : 'mcp_ai_customer';
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-smiley" style="font-size: 32px; width: 32px; height: 32px;"></span>
				<?php esc_html_e( 'Customer Settings', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'overview' ) ); ?>"
					class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'configuration' ) ); ?>"
					class="nav-tab <?php echo 'configuration' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Configuration', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</nav>

			<?php if ( 'overview' === $active_tab ) : ?>
				<div class="card" style="max-width: 800px; margin-top: 20px;">
					<h2><?php esc_html_e( 'Customer Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
					<p><?php esc_html_e( 'Customers are post-conversion records created when leads progress through the lifecycle. Each customer record stores contact details, billing history, lifetime value estimates, and source attribution linking back to the originating lead.', 'mcp-ai-wpoos-pro' ); ?></p>

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

					<h3><?php esc_html_e( 'AI Assistant Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul>
						<li><strong><?php esc_html_e( 'Create Customer', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Create new customer records manually or via AI assistant.', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Get Customer', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Retrieve customer details by ID.', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'List Customers', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Browse customers with filtering and search.', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Convert Lead to Customer', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Convert a lead to a customer, automatically creating the customer record.', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
							<?php esc_html_e( 'View All Customers', 'mcp-ai-wpoos-pro' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
							<?php esc_html_e( 'Add New Customer', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php elseif ( 'configuration' === $active_tab ) : ?>
				<form method="post" action="options.php" style="max-width: 800px; margin-top: 20px;">
					<?php settings_fields( self::OPTION_NAME . '_group' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="default_lifecycle_stage"><?php esc_html_e( 'Default Lifecycle Stage', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<select id="default_lifecycle_stage" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_lifecycle_stage]">
									<option value="customer" <?php selected( $settings['default_lifecycle_stage'], 'customer' ); ?>><?php esc_html_e( 'Customer', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="evangelist" <?php selected( $settings['default_lifecycle_stage'], 'evangelist' ); ?>><?php esc_html_e( 'Evangelist', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Default lifecycle stage assigned to new customers.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="default_currency"><?php esc_html_e( 'Default Currency', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<input type="text" id="default_currency" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_currency]"
									value="<?php echo esc_attr( $settings['default_currency'] ); ?>" maxlength="3" style="width: 80px;" />
								<p class="description"><?php esc_html_e( 'ISO 4217 currency code (e.g. USD, EUR, GBP).', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="auto_create_company"><?php esc_html_e( 'Auto-Create Company', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="auto_create_company" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_create_company]"
										value="1" <?php checked( $settings['auto_create_company'] ); ?> />
									<?php esc_html_e( 'Automatically create a company record when a customer is created with a new company name.', 'mcp-ai-wpoos-pro' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
