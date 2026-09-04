<?php
/**
 * Lead Settings Admin Page
 *
 * Provides a dedicated settings page under the Lead CPT menu for
 * configuring AI assistant, default scoring, and source preferences.
 * Tabbed: Overview / AI Configuration / Tools / Help.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.24
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead Settings admin page handler.
 */
class WP_MCP_AI_Lead_Settings_Page {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_lead_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-lead-settings';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_submenu_page' ), 25 );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
	}

	/**
	 * Register the submenu page under Leads CPT.
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Lead_CPT' ) ? WP_MCP_AI_Lead_CPT::POST_TYPE : 'mcp_ai_lead';

		add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Lead Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
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

		if ( isset( $input['default_source'] ) ) {
			$valid_sources = array( 'website', 'referral', 'social_media', 'email_campaign', 'paid_ad', 'event', 'other' );
			if ( in_array( $input['default_source'], $valid_sources, true ) ) {
				$sanitized['default_source'] = $input['default_source'];
			}
		}

		if ( isset( $input['default_scoring_framework'] ) ) {
			$valid_frameworks = array( 'bant', 'meddic', 'champ' );
			if ( in_array( $input['default_scoring_framework'], $valid_frameworks, true ) ) {
				$sanitized['default_scoring_framework'] = $input['default_scoring_framework'];
			}
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
		$post_type  = class_exists( 'WP_MCP_AI_Lead_CPT' ) ? WP_MCP_AI_Lead_CPT::POST_TYPE : 'mcp_ai_lead';
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-admin-users" style="font-size: 32px; width: 32px; height: 32px;"></span>
				<?php echo esc_html( __( 'Lead Settings', 'mcp-ai-wpoos-pro' ) ); ?>
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
	 * @param string $post_type The lead CPT slug.
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
			<h2><?php esc_html_e( 'Lead Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
				<div class="toolkit-stat-card" style="background: #f0f6fc; padding: 20px; border-left: 4px solid #2271b1;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Total Leads', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo absint( $count ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'View All Leads', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Add New Lead', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=crm-lead-research' ) ); ?>"><?php esc_html_e( 'Research & Add Lead', 'mcp-ai-wpoos-pro' ); ?></a></li>
			</ul>

			<h3><?php esc_html_e( 'Current Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'AI Assistant', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$assistant_id = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : 'default';
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
						<th><?php esc_html_e( 'Default Scoring Framework', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( strtoupper( $settings['default_scoring_framework'] ?? 'bant' ) ); ?></td>
					</tr>
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

		$current_assistant = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : 'default';
		$default_source    = isset( $settings['default_source'] ) ? $settings['default_source'] : '';
		$scoring_framework = isset( $settings['default_scoring_framework'] ) ? $settings['default_scoring_framework'] : 'bant';

		$available_assistants = $this->get_available_assistants();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'AI Configuration for Lead Research', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'These settings control how AI assists with lead research, qualification, and scoring. They override the CRM Toolkit defaults for this CPT.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_NAME . '_group' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="lead_assistant_id"><?php esc_html_e( 'Research Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[assistant_id]" id="lead_assistant_id">
								<?php foreach ( $available_assistants as $a_id => $a_name ) : ?>
									<option value="<?php echo esc_attr( $a_id ); ?>" <?php selected( $current_assistant, $a_id ); ?>>
										<?php echo esc_html( $a_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select which AI assistant to use for lead research and qualification. Leave as "CRM Toolkit Default" to use the global setting.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="lead_default_source"><?php esc_html_e( 'Default Lead Source', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_source]" id="lead_default_source">
								<option value=""><?php esc_html_e( 'None', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="website" <?php selected( $default_source, 'website' ); ?>><?php esc_html_e( 'Website', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="referral" <?php selected( $default_source, 'referral' ); ?>><?php esc_html_e( 'Referral', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="social_media" <?php selected( $default_source, 'social_media' ); ?>><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="email_campaign" <?php selected( $default_source, 'email_campaign' ); ?>><?php esc_html_e( 'Email Campaign', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="paid_ad" <?php selected( $default_source, 'paid_ad' ); ?>><?php esc_html_e( 'Paid Ad', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="event" <?php selected( $default_source, 'event' ); ?>><?php esc_html_e( 'Event / Trade Show', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="other" <?php selected( $default_source, 'other' ); ?>><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Pre-selected lead source for the Research & Add form.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="lead_scoring_framework"><?php esc_html_e( 'Default Scoring Framework', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_scoring_framework]" id="lead_scoring_framework">
								<option value="bant" <?php selected( $scoring_framework, 'bant' ); ?>><?php esc_html_e( 'BANT', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="meddic" <?php selected( $scoring_framework, 'meddic' ); ?>><?php esc_html_e( 'MEDDIC', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="champ" <?php selected( $scoring_framework, 'champ' ); ?>><?php esc_html_e( 'CHAMP', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Qualification framework used when scoring and qualifying leads via AI.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Settings Hierarchy', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Lead assistant resolution order:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li><strong><?php esc_html_e( 'Lead Settings', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'This page (highest priority for lead research)', 'mcp-ai-wpoos-pro' ); ?></li>
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
		$tools = $this->get_lead_tools();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Lead Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The following tools are available for lead operations. Enable or disable them in the CRM Toolkit settings.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( empty( $tools ) ) : ?>
				<p><?php esc_html_e( 'No lead-specific tools found. Ensure the CRM toolkit is enabled.', 'mcp-ai-wpoos-pro' ); ?></p>
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
	 * @param string $post_type The lead CPT slug.
	 */
	protected function render_help_tab( $post_type ) {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Getting Started with Leads', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Configure your AI assistant', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Choose which assistant powers lead research in the AI Configuration tab.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Research and qualify leads', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Use the Research & Add page to find, qualify, and score leads with AI.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Convert to deals', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Once a lead is qualified, convert it to a deal for pipeline tracking.', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Resources', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Lead List', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=crm-lead-research' ) ); ?>"><?php esc_html_e( 'Research & Add Lead', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-crm-toolkit-settings' ) ); ?>"><?php esc_html_e( 'CRM Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></a></li>
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
			'default' => __( 'CRM Toolkit Default', 'mcp-ai-wpoos-pro' ),
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
	 * Get lead-specific tools.
	 *
	 * @return array List of tool slugs => labels.
	 */
	protected function get_lead_tools() {
		$tools = array();
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry  = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools = $registry->get_tools();
			foreach ( $all_tools as $slug => $tool ) {
				if ( strpos( $slug, 'lead' ) !== false || strpos( $slug, 'qualify' ) !== false ) {
					$tools[ $slug ] = isset( $tool['name'] ) ? $tool['name'] : $slug;
				}
			}
		}

		return $tools;
	}
}
