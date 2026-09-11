<?php
/**
 * Company Settings Page (ecosystem port — Wave F2, CRM admin settings batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/admin/class-wp-mcp-ai-company-settings-page.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Company Settings Admin Page
 *
 * Provides a dedicated settings page under the Company CPT menu for
 * configuring AI assistant, default fields, and import/export settings.
 * Tabbed: Overview / AI Configuration / Tools / Help.
 *
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 * @since 1.1.24
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Company Settings admin page handler.
 */
class WP_MCP_AI_Company_Settings_Page {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_company_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-company-settings';

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_submenu_page' ), 25 );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
	}

	/**
	 * Register the submenu page under Companies CPT.
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Company_CPT' ) ? WP_MCP_AI_Company_CPT::POST_TYPE : 'mcp_ai_company';

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Company Settings', 'nvoos-content-graph-pro' ),
			__( 'Settings', 'nvoos-content-graph-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
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
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$sanitized = $existing;

		if ( isset( $input['assistant_id'] ) ) {
			if ( 'default' === $input['assistant_id'] ) {
				$sanitized['assistant_id'] = 'default';
			} else {
				// Cast rather than absint(): absint( -5 ) would coerce a negative
				// value into an unrelated positive ID (5); negatives must clamp to 0.
				$sanitized['assistant_id'] = max( 0, (int) $input['assistant_id'] );
			}
		}

		if ( isset( $input['default_status'] ) ) {
			$valid_statuses = array( 'prospect', 'target', 'in_discussion', 'client', 'not_interested' );
			if ( in_array( $input['default_status'], $valid_statuses, true ) ) {
				$sanitized['default_status'] = $input['default_status'];
			}
		}

		if ( isset( $input['default_industry'] ) ) {
			$sanitized['default_industry'] = sanitize_text_field( $input['default_industry'] );
		}

		if ( isset( $input['default_country'] ) ) {
			$sanitized['default_country'] = sanitize_text_field( $input['default_country'] );
		}

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type  = class_exists( 'WP_MCP_AI_Company_CPT' ) ? WP_MCP_AI_Company_CPT::POST_TYPE : 'mcp_ai_company';
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-building" style="font-size: 32px; width: 32px; height: 32px;"></span>
				<?php echo esc_html( __( 'Company Settings', 'nvoos-content-graph-pro' ) ); ?>
			</h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_tabs( $active_tab ); ?>

			<div class="toolkit-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'configuration':
						$this->render_configuration_tab( $post_type );
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
			'overview'      => __( 'Overview', 'nvoos-content-graph-pro' ),
			'configuration' => __( 'AI Configuration', 'nvoos-content-graph-pro' ),
			'tools'         => __( 'Tools', 'nvoos-content-graph-pro' ),
			'help'          => __( 'Help', 'nvoos-content-graph-pro' ),
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
	 * @param string $post_type The company CPT slug.
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
			<h2><?php esc_html_e( 'Company Management Overview', 'nvoos-content-graph-pro' ); ?></h2>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
				<div class="toolkit-stat-card" style="background: #f0f6fc; padding: 20px; border-left: 4px solid #2271b1;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Total Companies', 'nvoos-content-graph-pro' ); ?></h3>
					<p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo absint( $count ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Quick Links', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'View All Companies', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Add New Company', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=crm-company-research' ) ); ?>"><?php esc_html_e( 'Research & Add Company', 'nvoos-content-graph-pro' ); ?></a></li>
			</ul>

			<h3><?php esc_html_e( 'Current Configuration', 'nvoos-content-graph-pro' ); ?></h3>
			<table class="widefat striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'AI Assistant', 'nvoos-content-graph-pro' ); ?></th>
						<td>
							<?php
							$assistant_id = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : 'default';
							if ( 'default' === $assistant_id ) {
								esc_html_e( 'CRM Toolkit Default', 'nvoos-content-graph-pro' );
							} elseif ( $assistant_id > 0 && get_post( $assistant_id ) ) {
								echo esc_html( get_the_title( $assistant_id ) );
							} else {
								esc_html_e( 'Not set', 'nvoos-content-graph-pro' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Default Status', 'nvoos-content-graph-pro' ); ?></th>
						<td><?php echo esc_html( $settings['default_status'] ?? 'target' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render AI Configuration tab.
	 *
	 * @param string $post_type The company CPT slug.
	 */
	protected function render_configuration_tab( $post_type ) {
		$settings = get_option( self::OPTION_NAME, array() );

		$current_assistant = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : 'default';
		$default_status    = isset( $settings['default_status'] ) ? $settings['default_status'] : 'target';
		$default_industry  = isset( $settings['default_industry'] ) ? $settings['default_industry'] : '';
		$default_country   = isset( $settings['default_country'] ) ? $settings['default_country'] : '';

		$available_assistants = $this->get_available_assistants();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'AI Configuration for Company Research', 'nvoos-content-graph-pro' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'These settings control how AI assists with company research and creation. They override the CRM Toolkit defaults for this CPT.', 'nvoos-content-graph-pro' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_NAME . '_group' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="company_assistant_id"><?php esc_html_e( 'Research Assistant', 'nvoos-content-graph-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[assistant_id]" id="company_assistant_id">
								<?php foreach ( $available_assistants as $a_id => $a_name ) : ?>
									<option value="<?php echo esc_attr( $a_id ); ?>" <?php selected( $current_assistant, $a_id ); ?>>
										<?php echo esc_html( $a_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select which AI assistant to use for company research. Leave as "CRM Toolkit Default" to use the global setting.', 'nvoos-content-graph-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="company_default_status"><?php esc_html_e( 'Default Target Status', 'nvoos-content-graph-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_status]" id="company_default_status">
								<option value="prospect" <?php selected( $default_status, 'prospect' ); ?>><?php esc_html_e( 'Prospect', 'nvoos-content-graph-pro' ); ?></option>
								<option value="target" <?php selected( $default_status, 'target' ); ?>><?php esc_html_e( 'Target', 'nvoos-content-graph-pro' ); ?></option>
								<option value="in_discussion" <?php selected( $default_status, 'in_discussion' ); ?>><?php esc_html_e( 'In Discussion', 'nvoos-content-graph-pro' ); ?></option>
								<option value="client" <?php selected( $default_status, 'client' ); ?>><?php esc_html_e( 'Client', 'nvoos-content-graph-pro' ); ?></option>
								<option value="not_interested" <?php selected( $default_status, 'not_interested' ); ?>><?php esc_html_e( 'Not Interested', 'nvoos-content-graph-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default status for newly created companies.', 'nvoos-content-graph-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="company_default_industry"><?php esc_html_e( 'Default Industry', 'nvoos-content-graph-pro' ); ?></label>
						</th>
						<td>
							<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_industry]" id="company_default_industry" value="<?php echo esc_attr( $default_industry ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Pre-filled industry for the Research & Add form.', 'nvoos-content-graph-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="company_default_country"><?php esc_html_e( 'Default Country', 'nvoos-content-graph-pro' ); ?></label>
						</th>
						<td>
							<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_country]" id="company_default_country" value="<?php echo esc_attr( $default_country ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Pre-filled country for the Research & Add form.', 'nvoos-content-graph-pro' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Settings Hierarchy', 'nvoos-content-graph-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Company assistant resolution order:', 'nvoos-content-graph-pro' ); ?></p>
			<ol>
				<li><strong><?php esc_html_e( 'Company Settings', 'nvoos-content-graph-pro' ); ?></strong> &mdash; <?php esc_html_e( 'This page (highest priority for company research)', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'CRM Toolkit Settings', 'nvoos-content-graph-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Global CRM Research & Add Assistant (medium priority)', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'First Available Assistant', 'nvoos-content-graph-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Auto-fallback to any published assistant (lowest priority)', 'nvoos-content-graph-pro' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render tools tab.
	 */
	protected function render_tools_tab() {
		$tools = $this->get_company_tools();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Company Tools', 'nvoos-content-graph-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The following tools are available for company operations. Enable or disable them in the CRM Toolkit settings.', 'nvoos-content-graph-pro' ); ?>
			</p>

			<?php if ( empty( $tools ) ) : ?>
				<p><?php esc_html_e( 'No company-specific tools found. Ensure the CRM toolkit is enabled.', 'nvoos-content-graph-pro' ); ?></p>
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
	 * @param string $post_type The company CPT slug.
	 */
	protected function render_help_tab( $post_type ) {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Getting Started with Companies', 'nvoos-content-graph-pro' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Configure your AI assistant', 'nvoos-content-graph-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Choose which assistant powers company research in the AI Configuration tab.', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Research companies', 'nvoos-content-graph-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Use the Research & Add page to find and analyze target companies with AI.', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Add contacts and deals', 'nvoos-content-graph-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Once a company is added, link leads and deals to it for full CRM tracking.', 'nvoos-content-graph-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Resources', 'nvoos-content-graph-pro' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Company List', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=crm-company-research' ) ); ?>"><?php esc_html_e( 'Research & Add Company', 'nvoos-content-graph-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-crm-toolkit-settings' ) ); ?>"><?php esc_html_e( 'CRM Toolkit Settings', 'nvoos-content-graph-pro' ); ?></a></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get available assistants for the dropdown.
	 *
	 * @return array List of 'id' => 'name'.
	 */
	protected function get_available_assistants() {
		$assistants = array(
			'default' => __( 'CRM Toolkit Default', 'nvoos-content-graph-pro' ),
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$assistants[ get_the_ID() ] = get_the_title();
			}
			wp_reset_postdata();
		}

		return $assistants;
	}

	/**
	 * Get company-specific tools.
	 *
	 * @return array List of tool slugs => labels.
	 */
	protected function get_company_tools() {
		$tools = array();
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry  = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools = $registry->get_tools();
			foreach ( $all_tools as $slug => $tool ) {
				if ( strpos( $slug, 'company' ) !== false ) {
					$tools[ $slug ] = isset( $tool['name'] ) ? $tool['name'] : $slug;
				}
			}
		}

		return $tools;
	}
}
