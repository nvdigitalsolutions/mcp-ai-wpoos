<?php
/**
 * Token Manager Settings Section for NV oOS
 *
 * Provides comprehensive token usage management and monitoring.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Token_Manager' ) ) {
	/**
	 * Token Manager section for settings dashboard.
	 */
	class WP_MCP_AI_Section_Token_Manager extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'token_manager';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Token Usage Manager', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab this section belongs to.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'token_manager';
		}

		/**
		 * Get field definitions (none for this custom section).
		 *
		 * @return array
		 */
		public function get_fields() {
			return array();
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Monitor and manage API token consumption across all users, tools, and the entire site.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/performance/TOKEN_MANAGEMENT_GUIDE.md';
		}

		/**
		 * Render the token manager section.
		 */
		public function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$current_user_id = get_current_user_id();
			$active_view     = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'per_user'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation parameter; not a state-changing operation.

			?>
			<div class="wp-mcp-ai-token-manager">
				<!-- View Tabs -->
				<nav class="wp-mcp-ai-token-manager__nav">
					<a href="<?php echo esc_url( $this->get_view_url( 'per_user' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo esc_attr( 'per_user' === $active_view ? 'active' : '' ); ?>">
						<span class="dashicons dashicons-admin-users"></span>
						<?php esc_html_e( 'Per User', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_view_url( 'per_tool' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo esc_attr( 'per_tool' === $active_view ? 'active' : '' ); ?>">
						<span class="dashicons dashicons-admin-tools"></span>
						<?php esc_html_e( 'Per Tool', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_view_url( 'per_site' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo esc_attr( 'per_site' === $active_view ? 'active' : '' ); ?>">
						<span class="dashicons dashicons-admin-site-alt3"></span>
						<?php esc_html_e( 'Per Site', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_view_url( 'per_models' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo esc_attr( 'per_models' === $active_view ? 'active' : '' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Per Models', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_view_url( 'model_manager' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo esc_attr( 'model_manager' === $active_view ? 'active' : '' ); ?>">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Model Manager', 'mcp-ai-wpoos' ); ?>
					</a>
					<?php if ( class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) : ?>
						<a href="<?php echo esc_url( $this->get_view_url( 'analytics' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo esc_attr( 'analytics' === $active_view ? 'active' : '' ); ?>">
							<span class="dashicons dashicons-chart-line"></span>
							<?php esc_html_e( 'Analytics', 'mcp-ai-wpoos' ); ?>
						</a>
					<?php endif; ?>
				</nav>

				<!-- Hidden field to preserve view during form submission -->
				<input type="hidden" name="view" value="<?php echo esc_attr( $active_view ); ?>" />

				<!-- View Content -->
				<div class="wp-mcp-ai-token-manager__content">
					<?php
					switch ( $active_view ) {
						case 'per_tool':
							$this->render_per_tool_view();
							break;
						case 'per_site':
							$this->render_per_site_view();
							break;
						case 'per_models':
							$this->render_per_models_view();
							break;
						case 'model_manager':
							$this->render_model_manager_view();
							break;
						case 'analytics':
							if ( class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
								$this->render_analytics_view();
							} else {
								$this->render_per_user_view();
							}
							break;
						case 'per_user':
						default:
							$this->render_per_user_view();
							break;
					}
					?>
				</div>
				<?php $this->render_pro_banner(); ?>
			</div>
			<?php
		}

		/**
		 * Get URL for a specific view.
		 *
		 * @param string $view View name.
		 * @return string
		 */
		private function get_view_url( $view ) {
			return add_query_arg(
				array(
					'page' => WP_MCP_AI_Settings_Dashboard::PAGE_SLUG,
					'tab'  => 'token_manager',
					'view' => $view,
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Render per-user token usage view.
		 */
		private function render_per_user_view() {
			global $wpdb;

			// Get all users with usage data.
			$meta_key  = WP_MCP_AI_Usage_Tracker::USER_META_KEY;
			$cache_key = 'wp_mcp_ai_token_users_' . md5( $meta_key );
			$user_ids  = wp_cache_get( $cache_key, 'wp_mcp_ai' );

			if ( false === $user_ids ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$user_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
						$meta_key
					)
				);
				wp_cache_set( $cache_key, $user_ids, 'wp_mcp_ai', 300 ); // Cache for 5 minutes.
			}

			// Preload tiers for all users to optimize performance.
			if ( ! empty( $user_ids ) && class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
				WP_MCP_AI_Tool_Token_Limits::preload_user_tiers( $user_ids );
			}

			?>
			<h3><?php esc_html_e( 'Token Usage by User', 'mcp-ai-wpoos' ); ?></h3>
			<p class="description"><?php esc_html_e( 'View and manage token consumption for each user across all AI models and providers.', 'mcp-ai-wpoos' ); ?></p>

			<!-- Analytics Charts Section -->
			<div class="wp-mcp-ai-analytics-section">
				<div class="wp-mcp-ai-chart-controls">
					<h3><?php esc_html_e( 'Usage Analytics', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-chart-controls-right">
						<label for="wp-mcp-ai-chart-period" class="wp-mcp-ai-chart-period-label"><?php esc_html_e( 'Time Period:', 'mcp-ai-wpoos' ); ?></label>
						<select id="wp-mcp-ai-chart-period" class="wp-mcp-ai-chart-period-select">
							<option value="1"><?php esc_html_e( 'Today', 'mcp-ai-wpoos' ); ?></option>
							<option value="7" selected><?php esc_html_e( 'Last 7 Days', 'mcp-ai-wpoos' ); ?></option>
							<option value="30"><?php esc_html_e( 'Last 30 Days', 'mcp-ai-wpoos' ); ?></option>
							<option value="90"><?php esc_html_e( 'Last 90 Days', 'mcp-ai-wpoos' ); ?></option>
						</select>
						<button type="button" id="wp-mcp-ai-refresh-charts" class="button">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>
				</div>

				<!-- Usage Trend Chart -->
				<div class="wp-mcp-ai-chart-container wp-mcp-ai-chart-full">
					<canvas id="wp-mcp-ai-usage-trend-chart"></canvas>
				</div>

				<!-- Tool Breakdown & Tier Distribution -->
				<div class="wp-mcp-ai-chart-row">
					<div class="wp-mcp-ai-chart-container wp-mcp-ai-chart-half">
						<canvas id="wp-mcp-ai-tool-breakdown-chart"></canvas>
					</div>
					<div class="wp-mcp-ai-chart-container wp-mcp-ai-chart-half">
						<canvas id="wp-mcp-ai-tier-distribution-chart"></canvas>
					</div>
				</div>
			</div>

			<?php if ( empty( $user_ids ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No token usage data has been recorded yet. Usage will appear here once users start interacting with AI assistants.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php else : ?>
				<!-- Bulk Actions Toolbar -->
				<div class="tablenav top" style="margin-bottom: 10px;">
					<div class="alignleft actions bulkactions">
						<label for="bulk-tier-selector" class="screen-reader-text"><?php esc_html_e( 'Select bulk tier action', 'mcp-ai-wpoos' ); ?></label>
						<select name="bulk_tier" id="bulk-tier-selector">
							<option value=""><?php esc_html_e( 'Bulk Tier Assignment', 'mcp-ai-wpoos' ); ?></option>
							<option value="free"><?php esc_html_e( 'Set to Free Tier', 'mcp-ai-wpoos' ); ?></option>
							<option value="pro"><?php esc_html_e( 'Set to Pro Tier', 'mcp-ai-wpoos' ); ?></option>
							<option value="enterprise"><?php esc_html_e( 'Set to Enterprise Tier', 'mcp-ai-wpoos' ); ?></option>
						</select>
						<button type="button" id="wp-mcp-ai-apply-bulk-tier" class="button action" disabled>
							<?php esc_html_e( 'Apply', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>
				</div>

				<div class="wp-mcp-ai-table-wrapper">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="check-column">
								<input type="checkbox" id="wp-mcp-ai-select-all-users" />
							</th>
							<th><?php esc_html_e( 'User', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Tier', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Total Requests', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Total Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Prompt Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Completion Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Cached Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Est. Cost (USD)', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $user_ids as $user_id ) :
							$user = get_userdata( $user_id );
							if ( ! $user ) {
								continue;
							}

							$usage  = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );
							$totals = $this->calculate_usage_totals( $usage );
							$tier   = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );

							// Tier badge styling.
							$tier_colors = array(
								'free'       => '#999',
								'pro'        => '#0073aa',
								'enterprise' => '#46b450',
							);
							$tier_color  = isset( $tier_colors[ $tier ] ) ? $tier_colors[ $tier ] : '#999';
							?>
							<tr>
								<td class="check-column">
									<input type="checkbox" class="wp-mcp-ai-user-checkbox" value="<?php echo esc_attr( $user_id ); ?>" />
								</td>
								<td>
									<strong><?php echo esc_html( $user->display_name ); ?></strong>
									<br>
									<small class="description"><?php echo esc_html( $user->user_email ); ?></small>
								</td>
								<td>
									<span class="wp-mcp-ai-tier-badge" style="background-color: <?php echo esc_attr( $tier_color ); ?>; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase;">
										<?php echo esc_html( $tier ); ?>
									</span>
								</td>
								<td><?php echo esc_html( number_format_i18n( $totals['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $totals['total_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $totals['prompt_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $totals['completion_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $totals['cached_tokens'] ) ); ?></td>
								<td>
									<?php
									$cost = isset( $totals['total_cost'] ) ? $totals['total_cost'] : 0.0;
									if ( $cost > 0 ) {
										echo esc_html( '$' . number_format( $cost, 4 ) );
									} else {
										echo '<span style="color: #999;">' . esc_html__( 'N/A', 'mcp-ai-wpoos' ) . '</span>';
									}
									?>
								</td>
								<td>
									<button type="button" class="button button-small wp-mcp-ai-reset-user-usage" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-user-name="<?php echo esc_attr( $user->display_name ); ?>">
										<?php esc_html_e( 'Reset', 'mcp-ai-wpoos' ); ?>
									</button>
									<button type="button" class="button button-small wp-mcp-ai-view-user-details" data-user-id="<?php echo esc_attr( $user_id ); ?>">
										<?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?>
									</button>
								</td>
							</tr>
							<tr class="wp-mcp-ai-user-details-row" id="user-details-<?php echo esc_attr( $user_id ); ?>" style="display: none;">
								<td colspan="10">
									<?php $this->render_user_details( $user_id, $usage ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<div style="margin-top: 20px;">
					<button type="button" id="wp-mcp-ai-export-usage-csv" class="button button-primary">
						<?php esc_html_e( 'Export to CSV', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" id="wp-mcp-ai-reset-all-usage" class="button button-secondary">
						<?php esc_html_e( 'Reset All Users\' Token Usage', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render per-tool token limits view.
		 */
		private function render_per_tool_view() {
			// Load filter bar renderer if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-tools-filter-bar-renderer.php';
			}

			$current_user_id = get_current_user_id();
			$user_tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $current_user_id );

			// Get all available tools.
			$all_tools = $this->get_all_available_tools();

			// Get search and filter parameters.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only operation.
			$search = isset( $_GET['tool_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tool_search'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only operation.
			$filter_group = isset( $_GET['tool_group'] ) ? sanitize_key( $_GET['tool_group'] ) : '';

			?>
		<h3><?php esc_html_e( 'Token Limits by Tool', 'mcp-ai-wpoos' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Configure daily token usage limits and multipliers for individual tools. Different tools can have different limits based on their resource requirements. Multipliers adjust base tier limits for resource-intensive tools.', 'mcp-ai-wpoos' ); ?></p>

			<?php
			// Render filter bar if component is available.
			if ( class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render() method.
				echo WP_MCP_AI_Tools_Filter_Bar_Renderer::render(
					array(
						'tab'          => 'token_manager',
						'view'         => 'per_tool',
						'search'       => $search, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped with esc_attr() in WP_MCP_AI_Tools_Filter_Bar_Renderer::render().
						'filter_group' => $filter_group, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped with esc_attr() in WP_MCP_AI_Tools_Filter_Bar_Renderer::render().
						'clear_url'    => admin_url( 'admin.php?page=' . WP_MCP_AI_Settings_Dashboard::PAGE_SLUG . '&tab=token_manager&view=per_tool' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped with esc_url() in WP_MCP_AI_Tools_Filter_Bar_Renderer::render().
					)
				);
			}
			?>

		<!-- Recommendations Notice -->
			<?php
			$mismatched_count = count( WP_MCP_AI_Tool_Recommendations::get_mismatched_tools() );
			$current_preset   = WP_MCP_AI_Tool_Recommendations::detect_current_preset();
			$presets          = WP_MCP_AI_Tool_Recommendations::get_presets();

			if ( $mismatched_count > 0 || 'custom' === $current_preset ) :
				?>
			<div class="notice notice-info inline" style="margin: 15px 0;">
				<p>
					<span class="dashicons dashicons-lightbulb" style="color: #f0b849; vertical-align: middle;"></span>
					<strong><?php esc_html_e( 'Optimization Available:', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					if ( 'custom' === $current_preset ) {
						esc_html_e( 'Your settings appear to be custom configured. You can apply a preset for optimized performance.', 'mcp-ai-wpoos' );
					} else {
						/* translators: %d: Number of tools that can be optimized */
						printf( esc_html__( '%d tools have settings that differ from recommended values. Choose a preset to optimize based on your needs.', 'mcp-ai-wpoos' ), absint( $mismatched_count ) );
					}
					?>
				</p>
				<p>
					<label for="wp-mcp-ai-preset-selector" style="font-weight: 600; margin-right: 10px;">
						<?php esc_html_e( 'Select Preset:', 'mcp-ai-wpoos' ); ?>
					</label>
					<select id="wp-mcp-ai-preset-selector" style="min-width: 200px;">
						<?php foreach ( $presets as $preset_key => $preset_data ) : ?>
							<option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $current_preset, $preset_key ); ?>>
								<?php echo esc_html( $preset_data['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" id="wp-mcp-ai-apply-preset" class="button button-secondary">
						<?php esc_html_e( 'Apply Preset', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" id="wp-mcp-ai-view-recommendations" class="button button-secondary" style="margin-left: 10px;">
						<?php esc_html_e( 'View Details', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
				<div id="wp-mcp-ai-preset-description" style="margin-top: 10px; padding: 8px 12px; background: #f0f0f0; border-left: 3px solid #0073aa; font-size: 13px;">
					<?php
					$current_preset_data = isset( $presets[ $current_preset ] ) ? $presets[ $current_preset ] : $presets['balanced'];
					echo esc_html( $current_preset_data['description'] );
					?>
				</div>
			</div>
			<?php elseif ( 'balanced' === $current_preset ) : ?>
			<div class="notice notice-success inline" style="margin: 15px 0;">
				<p>
					<span class="dashicons dashicons-yes-alt" style="color: #46b450; vertical-align: middle;"></span>
					<strong><?php esc_html_e( 'Optimal Configuration:', 'mcp-ai-wpoos' ); ?></strong>
					<?php esc_html_e( 'Your tools are using the balanced (recommended) preset with optimal settings for performance and cost.', 'mcp-ai-wpoos' ); ?>
					<button type="button" id="wp-mcp-ai-view-recommendations" class="button button-small" style="margin-left: 10px;">
						<?php esc_html_e( 'View Details', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-info inline" style="margin: 15px 0;">
				<p>
					<span class="dashicons dashicons-admin-settings" style="color: #0073aa; vertical-align: middle;"></span>
					<strong><?php esc_html_e( 'Current Preset:', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					$current_preset_data = isset( $presets[ $current_preset ] ) ? $presets[ $current_preset ] : null;
					if ( $current_preset_data ) {
						echo esc_html( $current_preset_data['name'] ) . ' - ' . esc_html( $current_preset_data['description'] );
					}
					?>
					<button type="button" id="wp-mcp-ai-view-recommendations" class="button button-small" style="margin-left: 10px;">
						<?php esc_html_e( 'Change Preset', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
			</div>
		<?php endif; ?>

		<!-- Tier Reference Card -->
		<div class="wp-mcp-ai-tier-reference" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Tier Base Limits (tokens/day)', 'mcp-ai-wpoos' ); ?></h4>
			<div style="display: flex; gap: 20px;">
				<div>
					<span class="wp-mcp-ai-tier-badge" style="background-color: #999; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase;">
						<?php esc_html_e( 'FREE', 'mcp-ai-wpoos' ); ?>
					</span>
					<strong><?php echo esc_html( number_format_i18n( 50000 ) ); ?></strong>
				</div>
				<div>
					<span class="wp-mcp-ai-tier-badge" style="background-color: #0073aa; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase;">
						<?php esc_html_e( 'PRO', 'mcp-ai-wpoos' ); ?>
					</span>
					<strong><?php echo esc_html( number_format_i18n( 200000 ) ); ?></strong>
				</div>
				<div>
					<span class="wp-mcp-ai-tier-badge" style="background-color: #46b450; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase;">
						<?php esc_html_e( 'ENTERPRISE', 'mcp-ai-wpoos' ); ?>
					</span>
					<strong><?php echo esc_html( number_format_i18n( 1000000 ) ); ?></strong>
				</div>
			</div>
			<p class="description" style="margin-bottom: 0; margin-top: 10px;">
				<?php esc_html_e( 'Tool multipliers are applied to these base limits. For example, a tool with a 2.0× multiplier would have 100k tokens/day for Free tier users (50k × 2.0).', 'mcp-ai-wpoos' ); ?>
			</p>
		</div>

		<div class="wp-mcp-ai-table-wrapper">
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 18%;"><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 12%;"><?php esc_html_e( 'Tool Slug', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 15%;" class="wp-mcp-ai-tooltip" title="<?php esc_attr_e( 'Preferred AI model for this tool', 'mcp-ai-wpoos' ); ?>">
						<?php esc_html_e( 'Preferred Model', 'mcp-ai-wpoos' ); ?>
						<span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: middle;"></span>
					</th>
					<th style="width: 8%;" class="wp-mcp-ai-tooltip" title="<?php esc_attr_e( 'Multiplier applied to base tier limits for this tool', 'mcp-ai-wpoos' ); ?>">
						<?php esc_html_e( 'Multiplier', 'mcp-ai-wpoos' ); ?>
						<span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: middle;"></span>
					</th>
					<th style="width: 12%;"><?php esc_html_e( 'Effective Limits', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 8%;"><?php esc_html_e( 'Total Users', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 8%;"><?php esc_html_e( 'Total Requests', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 9%;"><?php esc_html_e( 'Tokens Used', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 10%;"><?php esc_html_e( 'Usage %', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				// Apply category filter if provided.
				if ( ! empty( $filter_group ) ) {
					$registry  = WP_MCP_AI_Tool_Registry::get_instance();
					$group_map = $registry->get_tool_group_map();

					$all_tools = array_filter(
						$all_tools,
						function ( $tool_name, $tool_slug ) use ( $filter_group, $group_map ) {
							// Check if tool belongs to the selected category.
							$tool_group = isset( $group_map[ $tool_slug ] ) ? $group_map[ $tool_slug ] : 'other';
							return $tool_group === $filter_group;
						},
						ARRAY_FILTER_USE_BOTH
					);
				}

				// Apply search filter if provided.
				if ( ! empty( $search ) ) {
					$all_tools = array_filter(
						$all_tools,
						function ( $tool_name, $tool_slug ) use ( $search ) {
							$search_term = strtolower( trim( $search ) );
							return false !== stripos( $tool_slug, $search_term ) ||
									false !== stripos( $tool_name, $search_term );
						},
						ARRAY_FILTER_USE_BOTH
					);
				}

				foreach ( $all_tools as $tool_slug => $tool_name ) :
					$tool_limit       = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( $tool_slug );
					$tool_stats       = WP_MCP_AI_Tool_Token_Limits::get_tool_statistics( $tool_slug );
					$multiplier       = $this->get_tool_multiplier( $tool_slug );
					$model_preference = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug );
					$available_models = WP_MCP_AI_Tool_Token_Limits::get_available_models( $tool_slug );

					// Get recommendation for this tool.
					$recommendation = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( $tool_slug );
					$match_status   = WP_MCP_AI_Tool_Recommendations::check_recommendation_match( $tool_slug, $multiplier, $model_preference );

					// Calculate effective limits for each tier.
					$free_limit       = (int) ( 50000 * $multiplier );
					$pro_limit        = (int) ( 200000 * $multiplier );
					$enterprise_limit = (int) ( 1000000 * $multiplier );

					// Calculate usage percentage (based on enterprise tier as max).
					$usage_pct = $tool_stats['total_tokens'] > 0 && $enterprise_limit > 0
						? min( 100, round( ( $tool_stats['total_tokens'] / $enterprise_limit ) * 100, 1 ) )
						: 0;

					// Determine usage color.
					if ( $usage_pct >= 80 ) {
						$usage_color = '#dc3232'; // Red.
					} elseif ( $usage_pct >= 50 ) {
						$usage_color = '#f56e28'; // Orange.
					} else {
						$usage_color = '#46b450'; // Green.
					}

					// Determine row class based on recommendation match.
					$row_class = $match_status['matches'] ? '' : 'wp-mcp-ai-tool-row-recommended';
					?>
					<tr class="<?php echo esc_attr( $row_class ); ?>" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>">
						<td>
							<strong><?php echo esc_html( $tool_name ); ?></strong>
							<?php if ( ! $match_status['matches'] ) : ?>
								<span class="dashicons dashicons-lightbulb wp-mcp-ai-recommendation-icon"
										style="color: #f0b849; font-size: 16px; vertical-align: middle; cursor: help;"
										title="<?php echo esc_attr( $match_status['reason'] ); ?>"></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
						<td>
							<select
								class="wp-mcp-ai-tool-model-input"
								data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>"
								style="width: 100%; max-width: 250px;"
							>
								<?php
								foreach ( $available_models as $group_key => $group_data ) :
									// Handle optgroup or single option.
									if ( is_array( $group_data ) && isset( $group_data['label'] ) && isset( $group_data['options'] ) ) {
										?>
										<optgroup label="<?php echo esc_attr( $group_data['label'] ); ?>">
											<?php foreach ( $group_data['options'] as $model_id => $model_label ) : ?>
												<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $model_preference, $model_id ); ?>>
													<?php echo esc_html( $model_label ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
										<?php
									} else {
										// Single option (like "default").
										?>
										<option value="<?php echo esc_attr( $group_key ); ?>" <?php selected( $model_preference, $group_key ); ?>>
											<?php echo esc_html( $group_data ); ?>
										</option>
										<?php
									}
								endforeach;
								?>
							</select>
						</td>
						<td>
							<div style="position: relative; display: inline-block;">
								<input
									type="number"
									class="wp-mcp-ai-tool-multiplier-input"
									data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>"
									data-recommended="<?php echo esc_attr( $recommendation['multiplier'] ); ?>"
									value="<?php echo esc_attr( $multiplier ); ?>"
									min="0.1"
									max="10"
									step="0.1"
									style="width: 70px;"
								/>×
								<?php if ( ! $match_status['multiplier_matches'] ) : ?>
									<span class="wp-mcp-ai-recommended-value" style="display: block; font-size: 10px; color: #f0b849; font-style: italic;">
										<?php
										/* translators: %s: recommended multiplier value */
										printf( esc_html__( 'Rec: %s×', 'mcp-ai-wpoos' ), esc_html( $recommendation['multiplier'] ) );
										?>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td style="font-size: 11px;">
							<div title="<?php esc_attr_e( 'Free tier limit', 'mcp-ai-wpoos' ); ?>">
								<span style="color: #999;">F:</span> <?php echo esc_html( number_format_i18n( $free_limit ) ); ?>
							</div>
							<div title="<?php esc_attr_e( 'Pro tier limit', 'mcp-ai-wpoos' ); ?>">
								<span style="color: #0073aa;">P:</span> <?php echo esc_html( number_format_i18n( $pro_limit ) ); ?>
							</div>
							<div title="<?php esc_attr_e( 'Enterprise tier limit', 'mcp-ai-wpoos' ); ?>">
								<span style="color: #46b450;">E:</span> <?php echo esc_html( number_format_i18n( $enterprise_limit ) ); ?>
							</div>
						</td>
						<td><?php echo esc_html( number_format_i18n( $tool_stats['total_users'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $tool_stats['total_requests'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $tool_stats['total_tokens'] ) ); ?></td>
						<td>
							<div class="wp-mcp-ai-usage-bar" style="background: #f0f0f0; border-radius: 3px; overflow: hidden; height: 20px; position: relative;">
								<div style="background: <?php echo esc_attr( $usage_color ); ?>; width: <?php echo esc_attr( $usage_pct ); ?>%; height: 100%; transition: width 0.3s ease;"></div>
								<span style="position: absolute; left: 0; right: 0; top: 0; text-align: center; line-height: 20px; font-size: 11px; font-weight: bold; color: #333;">
									<?php echo esc_html( $usage_pct ); ?>%
								</span>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<!-- Recommendations Modal -->
		<div id="wp-mcp-ai-recommendations-modal" class="wp-mcp-ai-modal" style="display: none;">
			<div class="wp-mcp-ai-modal-overlay"></div>
			<div class="wp-mcp-ai-modal-content" style="max-width: 900px; max-height: 80vh; overflow-y: auto;">
				<div class="wp-mcp-ai-modal-header">
					<h2><?php esc_html_e( 'Tool Configuration Recommendations', 'mcp-ai-wpoos' ); ?></h2>
					<button type="button" class="wp-mcp-ai-modal-close">&times;</button>
				</div>
				<div class="wp-mcp-ai-modal-body">
					<p class="description">
						<?php esc_html_e( 'These recommendations are based on analysis of tool complexity, resource requirements, and typical usage patterns. Choose a preset that matches your deployment needs.', 'mcp-ai-wpoos' ); ?>
					</p>

					<!-- Presets -->
					<h3><?php esc_html_e( 'Available Presets', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-table-wrapper">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 20%;"><?php esc_html_e( 'Preset', 'mcp-ai-wpoos' ); ?></th>
								<th style="width: 15%;"><?php esc_html_e( 'Multiplier Adjustment', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
								<th style="width: 15%;"><?php esc_html_e( 'Best For', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$presets        = WP_MCP_AI_Tool_Recommendations::get_presets();
							$current_preset = WP_MCP_AI_Tool_Recommendations::detect_current_preset();

							$best_for = array(
								'conservative' => __( 'Cost Control', 'mcp-ai-wpoos' ),
								'balanced'     => __( 'Most Sites', 'mcp-ai-wpoos' ),
								'performance'  => __( 'High Traffic', 'mcp-ai-wpoos' ),
								'aggressive'   => __( 'Complex Operations', 'mcp-ai-wpoos' ),
							);

							foreach ( $presets as $preset_key => $preset_data ) :
								$is_current = ( $preset_key === $current_preset );
								?>
								<tr <?php echo $is_current ? 'style="' . esc_attr( 'background-color: #e7f7e7;' ) . '"' : ''; ?>>
									<td>
										<strong><?php echo esc_html( $preset_data['name'] ); ?></strong>
										<?php if ( $is_current ) : ?>
											<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( number_format( $preset_data['multiplier_adjustment'] * 100 ) ); ?>%</td>
									<td><?php echo esc_html( $preset_data['description'] ); ?></td>
									<td><?php echo esc_html( isset( $best_for[ $preset_key ] ) ? $best_for[ $preset_key ] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>

					<h3 style="margin-top: 20px;"><?php esc_html_e( 'Tool Categories', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Tools are grouped into categories based on their characteristics. Multipliers are applied to base tier limits.', 'mcp-ai-wpoos' ); ?>
					</p>
					<div class="wp-mcp-ai-table-wrapper">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Tool Count', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Base Multiplier', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$category_stats = WP_MCP_AI_Tool_Recommendations::get_category_statistics();
							foreach ( $category_stats as $category => $stats ) :
								?>
								<tr>
									<td><strong><?php echo esc_html( $stats['name'] ); ?></strong></td>
									<td><?php echo esc_html( $stats['tool_count'] ); ?></td>
									<td><?php echo esc_html( number_format( $stats['multiplier'], 1 ) ); ?>×</td>
									<td><?php echo esc_html( $stats['description'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>

					<h3 style="margin-top: 20px;"><?php esc_html_e( 'Tools Needing Optimization', 'mcp-ai-wpoos' ); ?></h3>
					<?php
					$mismatched = WP_MCP_AI_Tool_Recommendations::get_mismatched_tools();
					if ( ! empty( $mismatched ) ) :
						?>
						<div class="wp-mcp-ai-table-wrapper">
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tool', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Current Multiplier', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Recommended', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $mismatched as $tool_slug => $mismatch_data ) : ?>
									<tr>
										<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
										<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $mismatch_data['category'] ) ) ); ?></td>
										<td><?php echo esc_html( number_format( $mismatch_data['current_multiplier'], 1 ) ); ?>×</td>
										<td><strong><?php echo esc_html( number_format( $mismatch_data['recommended_multiplier'], 1 ) ); ?>×</strong></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php else : ?>
						<div class="notice notice-success inline">
							<p><?php esc_html_e( 'All tools are using recommended settings!', 'mcp-ai-wpoos' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
				<div class="wp-mcp-ai-modal-footer">
					<button type="button" class="button button-secondary wp-mcp-ai-modal-close">
						<?php esc_html_e( 'Close', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
			</div>
		</div>

			<?php
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
			?>
		<style>
			.wp-mcp-ai-tool-row-recommended {
				background-color: #fffbf0 !important;
			}
			.wp-mcp-ai-recommendation-icon {
				animation: pulse 2s infinite;
			}
			@keyframes pulse {
				0%, 100% { opacity: 1; }
				50% { opacity: 0.5; }
			}
			.wp-mcp-ai-modal {
				position: fixed;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				z-index: 100000;
			}
			.wp-mcp-ai-modal-overlay {
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background: rgba(0, 0, 0, 0.7);
			}
			.wp-mcp-ai-modal-content {
				position: relative;
				background: #fff;
				margin: 50px auto;
				padding: 0;
				border-radius: 4px;
				box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
			}
			.wp-mcp-ai-modal-header {
				padding: 20px;
				border-bottom: 1px solid #ddd;
				position: relative;
			}
			.wp-mcp-ai-modal-header h2 {
				margin: 0;
				padding-right: 30px;
			}
			.wp-mcp-ai-modal-close {
				position: absolute;
				top: 15px;
				right: 15px;
				background: none;
				border: none;
				font-size: 28px;
				line-height: 1;
				cursor: pointer;
				color: #666;
			}
			.wp-mcp-ai-modal-close:hover {
				color: #000;
			}
			.wp-mcp-ai-modal-body {
				padding: 20px;
			}
			.wp-mcp-ai-modal-footer {
				padding: 15px 20px;
				border-top: 1px solid #ddd;
				text-align: right;
			}
		</style>

		<p class="submit">
			<button type="button" id="wp-mcp-ai-save-all-tool-settings" class="button button-primary">
					<?php esc_html_e( 'Save All Tool Settings', 'mcp-ai-wpoos' ); ?>
			</button>
			<span class="spinner" style="float: none; margin: 0 10px;"></span>
			<span id="wp-mcp-ai-tool-settings-message" style="margin-left: 10px;"></span>
		</p>
			<?php
		}

		/**
		 * Get tool multiplier for token limits.
		 *
		 * @param string $tool_slug Tool slug.
		 * @return float Multiplier value.
		 */
		private function get_tool_multiplier( $tool_slug ) {
			return WP_MCP_AI_Token_Usage_Service::get_tool_multiplier( $tool_slug );
		}


		/**
		 * Render per-site token statistics view.
		 */
		private function render_per_site_view() {
			$site_stats = $this->get_site_wide_statistics();

			?>
			<h3><?php esc_html_e( 'Site-Wide Token Statistics', 'mcp-ai-wpoos' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Aggregate token usage statistics across all users, providers, models, and tools on this site.', 'mcp-ai-wpoos' ); ?></p>

			<!-- Summary Cards -->
			<div class="wp-mcp-ai-stats-grid">
				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-admin-users"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Active Users', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo esc_html( number_format_i18n( $site_stats['total_users'] ) ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-performance"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Total Requests', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo esc_html( number_format_i18n( $site_stats['total_requests'] ) ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-chart-bar"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Total Tokens', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo esc_html( number_format_i18n( $site_stats['total_tokens'] ) ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-admin-tools"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Tools Used', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo esc_html( number_format_i18n( $site_stats['tools_used'] ) ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-money-alt"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Est. Total Cost', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value">
							<?php
							$total_cost = isset( $site_stats['total_cost'] ) ? $site_stats['total_cost'] : 0.0;
							if ( $total_cost > 0 ) {
								echo esc_html( '$' . number_format( $total_cost, 2 ) );
							} else {
								echo '<span style="color: #999;">' . esc_html__( 'N/A', 'mcp-ai-wpoos' ) . '</span>';
							}
							?>
						</div>
					</div>
				</div>
			</div>

			<!-- Analytics Charts Section -->
			<div class="wp-mcp-ai-analytics-section" style="margin-top: 30px;">
				<h4><?php esc_html_e( 'Usage Distribution', 'mcp-ai-wpoos' ); ?></h4>

				<!-- Provider & Model Distribution Charts -->
				<div class="wp-mcp-ai-chart-row">
					<div class="wp-mcp-ai-chart-container wp-mcp-ai-chart-half">
						<canvas id="wp-mcp-ai-provider-distribution-chart"></canvas>
					</div>
					<div class="wp-mcp-ai-chart-container wp-mcp-ai-chart-half">
						<canvas id="wp-mcp-ai-model-distribution-chart"></canvas>
					</div>
				</div>
			</div>

			<!-- Usage by Provider -->
			<h4><?php esc_html_e( 'Usage by Provider', 'mcp-ai-wpoos' ); ?></h4>
			<div class="wp-mcp-ai-table-wrapper">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Provider', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Prompt Tokens', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Completion Tokens', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Total Tokens', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Cached Tokens', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Est. Cost (USD)', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $site_stats['by_provider'] ) ) :
						foreach ( $site_stats['by_provider'] as $provider => $stats ) :
							?>
							<tr>
								<td><strong><?php echo esc_html( $this->get_provider_display_name( $provider ) ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $stats['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stats['prompt_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stats['completion_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stats['total_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stats['cached_tokens'] ) ); ?></td>
								<td>
									<?php
									$provider_cost = isset( $stats['total_cost'] ) ? $stats['total_cost'] : 0.0;
									if ( $provider_cost > 0 ) {
										echo esc_html( '$' . number_format( $provider_cost, 4 ) );
									} else {
										echo '<span style="color: #999;">' . esc_html__( 'N/A', 'mcp-ai-wpoos' ) . '</span>';
									}
									?>
								</td>
							</tr>
							<?php
						endforeach;
					else :
						?>
						<tr>
							<td colspan="7" class="no-items"><?php esc_html_e( 'No provider data available yet.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Top Models -->
			<h4><?php esc_html_e( 'Top Models by Usage', 'mcp-ai-wpoos' ); ?></h4>
			<p class="description" style="margin-top: -5px; margin-bottom: 10px;">
				<?php esc_html_e( 'Top 10 AI models sorted by total token usage. Models with the same name from different providers are tracked separately.', 'mcp-ai-wpoos' ); ?>
				<?php if ( ! empty( $site_stats['top_models'] ) ) : ?>
					<span class="wp-mcp-ai-provider-badge" style="display: inline-block; background-color: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; margin-left: 8px;"><?php esc_html_e( 'PROVIDER', 'mcp-ai-wpoos' ); ?></span>
					<span style="font-size: 11px; margin-left: 5px;"><?php esc_html_e( 'badges indicate models used across multiple providers', 'mcp-ai-wpoos' ); ?></span>
				<?php endif; ?>
			</p>
			<div class="wp-mcp-ai-table-wrapper">
			<table class="wp-list-table widefat fixed striped wp-mcp-ai-top-models-table">
				<thead>
					<tr>
						<th style="width: 35%;"><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Provider', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 12%;"><?php esc_html_e( 'Requests', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Total Tokens', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 13%;"><?php esc_html_e( 'Est. Cost (USD)', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $site_stats['top_models'] ) ) :
						$model_name_counts = array();
						// First pass: count how many times each model name appears.
						foreach ( $site_stats['top_models'] as $model_data ) {
							$model_name = $model_data['model'];
							if ( ! isset( $model_name_counts[ $model_name ] ) ) {
								$model_name_counts[ $model_name ] = 0;
							}
							++$model_name_counts[ $model_name ];
						}

						foreach ( $site_stats['top_models'] as $model_data ) :
							$model_name     = $model_data['model'];
							$provider_name  = $this->get_provider_display_name( $model_data['provider'] );
							$is_mixed       = isset( $model_name_counts[ $model_name ] ) && $model_name_counts[ $model_name ] > 1;
							$provider_badge = $is_mixed ? '<span class="wp-mcp-ai-provider-badge" style="display: inline-block; background-color: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; margin-left: 5px;">' . esc_html( $provider_name ) . '</span>' : '';
							?>
							<tr>
								<td>
									<code><?php echo esc_html( $model_name ); ?></code>
									<?php
									if ( $is_mixed ) {
										echo $provider_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $provider_badge is a literal HTML string constructed with esc_html() applied to all dynamic values.
									}
									?>
								</td>
								<td>
									<strong><?php echo esc_html( $provider_name ); ?></strong>
								</td>
								<td><?php echo esc_html( number_format_i18n( $model_data['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $model_data['total_tokens'] ) ); ?></td>
								<td>
									<?php
									$model_cost = isset( $model_data['total_cost'] ) ? $model_data['total_cost'] : 0.0;
									if ( $model_cost > 0 ) {
										echo esc_html( '$' . number_format( $model_cost, 4 ) );
									} else {
										echo '<span style="color: #999;">' . esc_html__( 'N/A', 'mcp-ai-wpoos' ) . '</span>';
									}
									?>
								</td>
							</tr>
							<?php
						endforeach;
					else :
						?>
						<tr>
							<td colspan="5" class="no-items"><?php esc_html_e( 'No model data available yet.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			</div>

			<!-- Top Tools -->
			<h4 style="margin-top: 30px;"><?php esc_html_e( 'Top Tools by Usage', 'mcp-ai-wpoos' ); ?></h4>
			<p class="description" style="margin-top: -5px; margin-bottom: 10px;">
				<?php esc_html_e( 'Top 10 AI tools sorted by total token usage across all users.', 'mcp-ai-wpoos' ); ?>
			</p>
			<div class="wp-mcp-ai-table-wrapper">
			<table class="wp-list-table widefat fixed striped wp-mcp-ai-top-tools-table">
				<thead>
					<tr>
						<th style="width: 35%;"><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'Tool Slug', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Total Users', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Total Requests', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'Tokens Used', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $site_stats['top_tools'] ) ) :
						foreach ( $site_stats['top_tools'] as $tool_data ) :
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $tool_data['tool_name'] ); ?></strong>
								</td>
								<td>
									<code><?php echo esc_html( $tool_data['tool_slug'] ); ?></code>
								</td>
								<td><?php echo esc_html( number_format_i18n( $tool_data['total_users'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $tool_data['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $tool_data['total_tokens'] ) ); ?></td>
							</tr>
							<?php
						endforeach;
					else :
						?>
						<tr>
							<td colspan="5" class="no-items"><?php esc_html_e( 'No tool data available yet.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			</div>
			<?php
		}

		/**
		 * Render per-models view.
		 *
		 * Displays per-model configuration table.
		 * Delegates rendering to WP_MCP_AI_Model_Config_Renderer (SoC).
		 */
		private function render_per_models_view() {
			// Load renderer class if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Model_Config_Renderer' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-model-config-renderer.php';
			}

			// Delegate rendering to the renderer class (SoC).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_model_table() method.
			echo WP_MCP_AI_Model_Config_Renderer::render_model_table();

			// Output JavaScript for inline editing.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_javascript() method.
			echo WP_MCP_AI_Model_Config_Renderer::render_javascript();
		}

		/**
		 * Render Model Manager view.
		 *
		 * Displays interface for discovering, researching, and adding AI models.
		 */
		private function render_model_manager_view() {
			// AJAX handlers are now loaded in the main plugin file during admin_init.

			?>
			<div class="wp-mcp-ai-model-manager">
				<div class="wp-mcp-ai-model-manager__header">
					<h2><?php esc_html_e( 'AI Model Manager', 'mcp-ai-wpoos' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Discover, research, and add new AI models to the plugin configuration using the built-in research tools.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>

				<!-- Quick Actions -->
				<div class="wp-mcp-ai-model-manager__actions">
					<div class="wp-mcp-ai-card">
						<h3><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Discover New Models', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Automatically discover newly released models from all configured AI providers.', 'mcp-ai-wpoos' ); ?></p>
						<button type="button" class="button button-primary" id="wp-mcp-ai-discover-models">
							<?php esc_html_e( 'Discover Models', 'mcp-ai-wpoos' ); ?>
						</button>
						<span class="spinner" style="float: none; margin: 0 10px;"></span>
					</div>

					<div class="wp-mcp-ai-card">
						<h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Research Specific Model', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Research a specific model by ID to get its specifications and configuration.', 'mcp-ai-wpoos' ); ?></p>
						<div class="wp-mcp-ai-research-form">
							<input type="text" id="wp-mcp-ai-model-id" placeholder="<?php esc_attr_e( 'e.g., gpt-4.5-turbo', 'mcp-ai-wpoos' ); ?>" class="regular-text" />
							<select id="wp-mcp-ai-model-provider" class="regular-text">
								<option value=""><?php esc_html_e( 'Select Provider', 'mcp-ai-wpoos' ); ?></option>
								<option value="openai">OpenAI</option>
								<option value="anthropic">Anthropic (Claude)</option>
								<option value="gemini">Google Gemini</option>
								<option value="huggingface">Hugging Face</option>
								<option value="ollama">Ollama (Local)</option>
								<option value="lm_studio">LM Studio (Local)</option>
								<option value="cloudflare">Cloudflare Workers AI</option>
							</select>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-research-model">
								<?php esc_html_e( 'Research Model', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="spinner" style="float: none; margin: 0 10px;"></span>
						</div>
					</div>
				</div>

				<!-- Results Area -->
				<div id="wp-mcp-ai-model-manager-results" style="margin-top: 20px;"></div>

				<!-- Available Tools Info -->
				<div class="wp-mcp-ai-model-manager__info" style="margin-top: 30px;">
					<h3><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos' ); ?></h3>
					<p><?php esc_html_e( 'The Model Manager uses three built-in tools for AI model management:', 'mcp-ai-wpoos' ); ?></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><strong>research_model</strong> - <?php esc_html_e( 'Uses AI to research model specifications from documentation', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>discover_new_models</strong> - <?php esc_html_e( 'Queries provider APIs to find newly released models', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>add_model_config</strong> - <?php esc_html_e( 'Adds researched models to the orchestration configuration', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p>
						<?php
						$docs_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/MODEL-RESEARCH-TOOLS.md';
						printf(
							/* translators: %s: Documentation URL */
							wp_kses_post( __( 'For detailed documentation, see <a href="%s" target="_blank">Model Research Tools Documentation</a>', 'mcp-ai-wpoos' ) ),
							esc_url( $docs_url )
						);
						?>
					</p>
				</div>

				<!-- Configured Models List -->
				<div class="wp-mcp-ai-model-manager__configured" style="margin-top: 30px;">
					<h3><?php esc_html_e( 'Currently Configured Models', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php
						printf(
							/* translators: %s: Per Models view link */
							wp_kses_post( __( 'View and edit existing model configurations in the %s view.', 'mcp-ai-wpoos' ) ),
							'<a href="' . esc_url( $this->get_view_url( 'per_models' ) ) . '">' . esc_html__( 'Per Models', 'mcp-ai-wpoos' ) . '</a>'
						);
						?>
					</p>
					<?php
					$all_configs = WP_MCP_AI_Model_Config::get_all_configs();
					$providers   = array();
					foreach ( $all_configs as $model_id => $config ) {
						$provider = isset( $config['provider'] ) ? $config['provider'] : 'unknown';
						if ( ! isset( $providers[ $provider ] ) ) {
							$providers[ $provider ] = array();
						}
						$providers[ $provider ][] = $model_id;
					}
					?>
					<div class="wp-mcp-ai-providers-summary">
						<?php foreach ( $providers as $provider => $models ) : ?>
							<div class="wp-mcp-ai-provider-card">
								<h4><?php echo esc_html( ucfirst( $provider ) ); ?></h4>
								<p>
									<?php
									printf(
										/* translators: %d: number of models */
										esc_html( _n( '%d model configured', '%d models configured', count( $models ), 'mcp-ai-wpoos' ) ),
										esc_html( count( $models ) )
									);
									?>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
			?>
			<style>
			.wp-mcp-ai-model-manager__actions {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 20px;
			}
			.wp-mcp-ai-card h3 {
				margin-top: 0;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.wp-mcp-ai-card .dashicons {
				color: #2271b1;
			}
			.wp-mcp-ai-research-form {
				display: flex;
				gap: 10px;
				align-items: center;
				flex-wrap: wrap;
			}
			#wp-mcp-ai-model-manager-results {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 20px;
				display: none;
			}
			#wp-mcp-ai-model-manager-results.has-content {
				display: block;
			}
			.wp-mcp-ai-providers-summary {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
				gap: 15px;
				margin-top: 15px;
			}
			.wp-mcp-ai-provider-card {
				background: #f6f7f7;
				padding: 15px;
				border-radius: 4px;
				border-left: 3px solid #2271b1;
			}
			.wp-mcp-ai-provider-card h4 {
				margin: 0 0 5px 0;
			}
			.wp-mcp-ai-provider-card p {
				margin: 0;
				color: #646970;
			}
			.wp-mcp-ai-model-result {
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 15px;
				margin: 10px 0;
			}
			.wp-mcp-ai-model-result h4 {
				margin-top: 0;
			}
			.wp-mcp-ai-model-specs {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 10px;
				margin: 15px 0;
			}
			.wp-mcp-ai-model-spec {
				padding: 10px;
				background: #f6f7f7;
				border-radius: 3px;
			}
			.wp-mcp-ai-model-spec strong {
				display: block;
				margin-bottom: 3px;
				color: #1d2327;
			}
			</style>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
			?>
			<script>
			jQuery(document).ready(function($) {
				// Discover models action
				$('#wp-mcp-ai-discover-models').on('click', function() {
					var button = $(this);
					var spinner = button.next('.spinner');
					var resultsDiv = $('#wp-mcp-ai-model-manager-results');

					button.prop('disabled', true);
					spinner.addClass('is-active');
					resultsDiv.html('<p><?php esc_html_e( 'Discovering models...', 'mcp-ai-wpoos' ); ?></p>').addClass('has-content');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_discover_models',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_model_manager' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								renderDiscoveryResults(response.data);
							} else {
								resultsDiv.html('<div class="notice notice-error"><p>' + (response.data || '<?php esc_html_e( 'Failed to discover models.', 'mcp-ai-wpoos' ); ?>') + '</p></div>');
							}
						},
						error: function() {
							resultsDiv.html('<div class="notice notice-error"><p><?php esc_html_e( 'Failed to discover models. Please try again.', 'mcp-ai-wpoos' ); ?></p></div>');
						},
						complete: function() {
							button.prop('disabled', false);
							spinner.removeClass('is-active');
						}
					});
				});

				// Research model action
				$('#wp-mcp-ai-research-model').on('click', function() {
					var button = $(this);
					var spinner = button.next('.spinner');
					var modelId = $('#wp-mcp-ai-model-id').val();
					var provider = $('#wp-mcp-ai-model-provider').val();
					var resultsDiv = $('#wp-mcp-ai-model-manager-results');

					if (!modelId || !provider) {
						alert('<?php esc_html_e( 'Please enter a model ID and select a provider.', 'mcp-ai-wpoos' ); ?>');
						return;
					}

					button.prop('disabled', true);
					spinner.addClass('is-active');
					resultsDiv.html('<p><?php esc_html_e( 'Researching model...', 'mcp-ai-wpoos' ); ?></p>').addClass('has-content');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_research_model',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_model_manager' ) ); ?>',
							model_id: modelId,
							provider: provider
						},
						success: function(response) {
							if (response.success) {
								renderResearchResults(response.data, modelId);
							} else {
								resultsDiv.html('<div class="notice notice-error"><p>' + (response.data || '<?php esc_html_e( 'Failed to research model.', 'mcp-ai-wpoos' ); ?>') + '</p></div>');
							}
						},
						error: function() {
							resultsDiv.html('<div class="notice notice-error"><p><?php esc_html_e( 'Failed to research model. Please try again.', 'mcp-ai-wpoos' ); ?></p></div>');
						},
						complete: function() {
							button.prop('disabled', false);
							spinner.removeClass('is-active');
						}
					});
				});

				function renderDiscoveryResults(data) {
					var html = '<h3><?php esc_html_e( 'Discovery Results', 'mcp-ai-wpoos' ); ?></h3>';

					if (data.discovered && data.discovered.length > 0) {
						html += '<h4><?php esc_html_e( 'Newly Discovered Models', 'mcp-ai-wpoos' ); ?> (' + data.discovered.length + ')</h4>';
						data.discovered.forEach(function(model) {
							html += '<div class="wp-mcp-ai-model-result">';
							html += '<h4>' + escapeHtml(model.name) + ' (' + escapeHtml(model.provider) + ')</h4>';
							html += '<p><code>' + escapeHtml(model.model_id) + '</code></p>';
							html += '<button class="button button-primary research-and-add-btn" data-model-id="' + escapeHtml(model.model_id) + '" data-provider="' + escapeHtml(model.provider) + '"><?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos' ); ?></button>';
							html += '</div>';
						});
					}

					if (data.already_exists && data.already_exists.length > 0) {
						html += '<h4><?php esc_html_e( 'Already Configured', 'mcp-ai-wpoos' ); ?> (' + data.already_exists.length + ')</h4>';
						html += '<p><?php esc_html_e( 'These models are already in your configuration:', 'mcp-ai-wpoos' ); ?></p>';
						html += '<ul>';
						data.already_exists.forEach(function(model) {
							html += '<li><code>' + escapeHtml(model.model_id) + '</code> (' + escapeHtml(model.provider) + ')</li>';
						});
						html += '</ul>';
					}

					if (!data.discovered || data.discovered.length === 0) {
						html += '<p><?php esc_html_e( 'No new models discovered. All provider models are already configured.', 'mcp-ai-wpoos' ); ?></p>';
					}

					$('#wp-mcp-ai-model-manager-results').html(html).addClass('has-content');

					// Bind research and add buttons
					$('.research-and-add-btn').on('click', function() {
						var btn = $(this);
						var modelId = btn.data('model-id');
						var provider = btn.data('provider');

						$('#wp-mcp-ai-model-id').val(modelId);
						$('#wp-mcp-ai-model-provider').val(provider);
						$('#wp-mcp-ai-research-model').click();
					});
				}

				function renderResearchResults(data, modelId) {
					var html = '<h3><?php esc_html_e( 'Research Results', 'mcp-ai-wpoos' ); ?></h3>';
					html += '<div class="wp-mcp-ai-model-result">';
					html += '<h4>' + escapeHtml(data.name) + '</h4>';
					html += '<p><strong><?php esc_html_e( 'Provider:', 'mcp-ai-wpoos' ); ?></strong> ' + escapeHtml(data.provider) + '</p>';
					html += '<div class="wp-mcp-ai-model-specs">';
					html += '<div class="wp-mcp-ai-model-spec"><strong><?php esc_html_e( 'Context Window', 'mcp-ai-wpoos' ); ?></strong>' + Number(data.context_window).toLocaleString() + ' tokens</div>';
					html += '<div class="wp-mcp-ai-model-spec"><strong><?php esc_html_e( 'Cost per 1K', 'mcp-ai-wpoos' ); ?></strong>$' + data.cost_per_1k + '</div>';
					html += '<div class="wp-mcp-ai-model-spec"><strong><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></strong>' + escapeHtml(data.status) + '</div>';
					if (data._research_metadata && data._research_metadata.confidence) {
						html += '<div class="wp-mcp-ai-model-spec"><strong><?php esc_html_e( 'Confidence', 'mcp-ai-wpoos' ); ?></strong>' + data._research_metadata.confidence + '%</div>';
					}
					html += '</div>';
					html += '<button class="button button-primary" id="add-researched-model" data-model-id="' + escapeHtml(modelId) + '"><?php esc_html_e( 'Add to Configuration', 'mcp-ai-wpoos' ); ?></button>';
					html += '</div>';

					$('#wp-mcp-ai-model-manager-results').html(html).addClass('has-content');

					// Store data for add action
					$('#add-researched-model').data('config', data);
				}

				// Add researched model
				$(document).on('click', '#add-researched-model', function() {
					var button = $(this);
					var config = button.data('config');
					var modelId = button.data('model-id');

					if (!config || !modelId) return;

					button.prop('disabled', true).text('<?php esc_html_e( 'Adding...', 'mcp-ai-wpoos' ); ?>');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_add_model_config',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_model_manager' ) ); ?>',
							model_id: modelId,
							config: JSON.stringify(config)
						},
						success: function(response) {
							if (response.success) {
								button.text('<?php esc_html_e( 'Added!', 'mcp-ai-wpoos' ); ?>').removeClass('button-primary').addClass('button-secondary');
								$('<div class="notice notice-success inline"><p>' + escapeHtml(response.data.message || '<?php esc_html_e( 'Model added successfully!', 'mcp-ai-wpoos' ); ?>') + '</p></div>').insertBefore('#wp-mcp-ai-model-manager-results');
								setTimeout(function() {
									location.reload();
								}, 2000);
							} else {
								alert('<?php esc_html_e( 'Failed to add model:', 'mcp-ai-wpoos' ); ?> ' + (response.data || ''));
								button.prop('disabled', false).text('<?php esc_html_e( 'Add to Configuration', 'mcp-ai-wpoos' ); ?>');
							}
						},
						error: function() {
							alert('<?php esc_html_e( 'Failed to add model. Please try again.', 'mcp-ai-wpoos' ); ?>');
							button.prop('disabled', false).text('<?php esc_html_e( 'Add to Configuration', 'mcp-ai-wpoos' ); ?>');
						}
					});
				});

				function escapeHtml(text) {
					if (typeof text !== 'string') return text;
					var map = {
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;',
						"'": '&#039;'
					};
					return text.replace(/[&<>"']/g, function(m) { return map[m]; });
				}
			});
			</script>
			<?php
		}

		/**
		 * Render detailed usage breakdown for a user.
		 *
		 * @param int   $user_id User ID.
		 * @param array $usage Usage data.
		 */
		private function render_user_details( $user_id, $usage ) {
			?>
			<div class="wp-mcp-ai-user-details">
				<h4><?php esc_html_e( 'Detailed Usage Breakdown', 'mcp-ai-wpoos' ); ?></h4>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Provider', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Prompt Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Completion Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Total Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Cached Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Est. Cost (USD)', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $usage as $provider => $models ) :
							foreach ( $models as $model => $data ) :
								$last_used = '';
								if ( ! empty( $data['last_used_gmt'] ) ) {
									$last_used = get_date_from_gmt(
										$data['last_used_gmt'],
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
									);
								}

								$prompt_tokens     = isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
								$completion_tokens = isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
								$cost              = WP_MCP_AI_Usage_Tracker::calculate_cost( $provider, $model, $prompt_tokens, $completion_tokens );
								?>
								<tr>
									<td><?php echo esc_html( $this->get_provider_display_name( $provider ) ); ?></td>
									<td><code><?php echo esc_html( $model ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( $data['requests'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $prompt_tokens ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $completion_tokens ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $data['total_tokens'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $data['cached_tokens'] ) ); ?></td>
									<td>
										<?php
										if ( $cost > 0 ) {
											echo esc_html( '$' . number_format( $cost, 4 ) );
										} else {
											echo '<span style="color: #999;">' . esc_html__( 'N/A', 'mcp-ai-wpoos' ) . '</span>';
										}
										?>
									</td>
									<td><?php echo esc_html( $last_used ); ?></td>
								</tr>
								<?php
							endforeach;
						endforeach;
						?>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Calculate total usage from usage array.
		 *
		 * @param array $usage Usage data.
		 * @return array Totals.
		 */
		private function calculate_usage_totals( $usage ) {
			return WP_MCP_AI_Token_Usage_Service::calculate_usage_totals( $usage );
		}

		/**
		 * Get all available tools.
		 *
		 * @return array Tool slug => Tool name pairs.
		 */
		private function get_all_available_tools() {
			return WP_MCP_AI_Token_Usage_Service::get_all_available_tools();
		}

		/**
		 * Get site-wide statistics.
		 *
		 * @return array Site statistics.
		 */
		private function get_site_wide_statistics() {
			return WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics();
		}

		/**
		 * Get formatted provider display name.
		 *
		 * @param string $provider Provider key.
		 * @return string Formatted provider name.
		 */
		private function get_provider_display_name( $provider ) {
			return WP_MCP_AI_Token_Usage_Service::get_provider_display_name( $provider );
		}

		/**
		 * Render analytics view with sub-tabs.
		 */
		private function render_analytics_view() {
			$analytics_tab = isset( $_GET['analytics_tab'] ) ? sanitize_key( $_GET['analytics_tab'] ) : 'trends'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics sub-tab navigation parameter; not a state-changing operation.

			// Enqueue Chart.js for analytics charts.
			if ( class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				WP_MCP_AI_Chart_JS_Helper::enqueue_chart_js();
			}

			?>
			<div class="wp-mcp-ai-analytics">
				<!-- Analytics Sub-Tabs -->
				<nav class="wp-mcp-ai-analytics__nav" style="margin-bottom: 20px; border-bottom: 1px solid #ccc;">
					<a href="<?php echo esc_url( $this->get_analytics_tab_url( 'trends' ) ); ?>" class="wp-mcp-ai-analytics__nav-item <?php echo esc_attr( 'trends' === $analytics_tab ? 'active' : '' ); ?>" style="display: inline-block; padding: 10px 15px; text-decoration: none; border-bottom: <?php echo esc_attr( 'trends' === $analytics_tab ? '2px solid #2271b1' : 'none' ); ?>; font-weight: <?php echo esc_attr( 'trends' === $analytics_tab ? 'bold' : 'normal' ); ?>;">
						<span class="dashicons dashicons-chart-line"></span>
						<?php esc_html_e( 'Trends', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_analytics_tab_url( 'patterns' ) ); ?>" class="wp-mcp-ai-analytics__nav-item <?php echo esc_attr( 'patterns' === $analytics_tab ? 'active' : '' ); ?>" style="display: inline-block; padding: 10px 15px; text-decoration: none; border-bottom: <?php echo esc_attr( 'patterns' === $analytics_tab ? '2px solid #2271b1' : 'none' ); ?>; font-weight: <?php echo esc_attr( 'patterns' === $analytics_tab ? 'bold' : 'normal' ); ?>;">
						<span class="dashicons dashicons-chart-bar"></span>
						<?php esc_html_e( 'Patterns', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_analytics_tab_url( 'anomalies' ) ); ?>" class="wp-mcp-ai-analytics__nav-item <?php echo esc_attr( 'anomalies' === $analytics_tab ? 'active' : '' ); ?>" style="display: inline-block; padding: 10px 15px; text-decoration: none; border-bottom: <?php echo esc_attr( 'anomalies' === $analytics_tab ? '2px solid #2271b1' : 'none' ); ?>; font-weight: <?php echo esc_attr( 'anomalies' === $analytics_tab ? 'bold' : 'normal' ); ?>;">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Anomalies', 'mcp-ai-wpoos' ); ?>
					</a>
				</nav>

				<!-- Analytics Content -->
				<div class="wp-mcp-ai-analytics__content">
					<?php
					switch ( $analytics_tab ) {
						case 'patterns':
							$this->render_analytics_patterns_tab();
							break;
						case 'anomalies':
							$this->render_analytics_anomalies_tab();
							break;
						case 'trends':
						default:
							$this->render_analytics_trends_tab();
							break;
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Get URL for analytics sub-tab.
		 *
		 * @param string $analytics_tab Analytics tab name.
		 * @return string
		 */
		private function get_analytics_tab_url( $analytics_tab ) {
			return add_query_arg(
				array(
					'page'          => WP_MCP_AI_Settings_Dashboard::PAGE_SLUG,
					'tab'           => 'token_manager',
					'view'          => 'analytics',
					'analytics_tab' => $analytics_tab,
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Render analytics trends tab.
		 */
		private function render_analytics_trends_tab() {
			?>
			<div class="wp-mcp-ai-analytics-trends-content">
				<h3><?php esc_html_e( 'Usage Trend Analysis', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Linear regression analysis of token usage over time with trend projections.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php
				$data = array(
					'user_id' => 0, // Site-wide.
					'days'    => 30,
				);
				include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-trends.php';
				?>
			</div>
			<?php
		}

		/**
		 * Render analytics patterns tab.
		 */
		private function render_analytics_patterns_tab() {
			?>
			<div class="wp-mcp-ai-analytics-patterns-content">
				<h3><?php esc_html_e( 'Usage Pattern Analysis', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Discover hourly and daily usage patterns to optimize resource allocation.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php
				$data = array(
					'user_id' => get_current_user_id(),
				);
				include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-patterns.php';
				?>
			</div>
			<?php
		}

		/**
		 * Render analytics anomalies tab.
		 */
		private function render_analytics_anomalies_tab() {
			?>
			<div class="wp-mcp-ai-analytics-anomalies-content">
				<h3><?php esc_html_e( 'Anomaly Detection', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Statistical anomaly detection using Z-score analysis to identify unusual usage patterns.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php
				$data = array(
					'user_id'   => 0, // Site-wide.
					'threshold' => 3.0,
				);
				include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-anomalies.php';
				?>
			</div>
			<?php
		}

		/**
		 * Override render_wrapper to exclude the form table.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>
				<?php $this->render(); ?>
			</div>
			<?php
		}

		/**
		 * Render Pro addon promotional banner for base version.
		 */
		private function render_pro_banner() {
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				return;
			}
			?>
			<div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; margin: 20px 0;">
				<p style="margin: 0 0 10px 0; font-size: 14px;">
					<strong><?php esc_html_e( 'Get NV oOS Pro for Premium Features', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<p style="margin: 0 0 10px 0;">
					<?php
					echo wp_kses_post(
						__(
							'Enable AI assistants to automatically install themes, plugins, update options, and create content. More powerful features available in the Pro addon.',
							'mcp-ai-wpoos'
						)
					);
					?>
				</p>
				<p style="margin: 0;">
					<a href="https://link.nvdigital.solutions/wpoos-pro-buy" target="_blank" class="button button-primary" style="margin-right: 10px;">
						<?php esc_html_e( 'Get NV oOS Pro', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="https://link.nvdigital.solutions/wpoos-pro-info" target="_blank" class="button">
						<?php esc_html_e( 'Learn More About Pro Tools', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}
}
