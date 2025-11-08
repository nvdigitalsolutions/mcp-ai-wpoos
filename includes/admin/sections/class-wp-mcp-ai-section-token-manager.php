<?php
/**
 * Token Manager Settings Section for WP oOS
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
			return __( 'Token Usage Manager', 'wp-mcp-ai' );
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
			return __( 'Monitor and manage API token consumption across all users, tools, and the entire site.', 'wp-mcp-ai' );
		}

		/**
		 * Render the token manager section.
		 */
		public function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$current_user_id = get_current_user_id();
			$active_view     = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'per_user'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			?>
			<div class="wp-mcp-ai-token-manager">
				<!-- View Tabs -->
				<nav class="wp-mcp-ai-token-manager__nav">
					<a href="<?php echo esc_url( $this->get_view_url( 'per_user' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo 'per_user' === $active_view ? 'active' : ''; ?>">
						<span class="dashicons dashicons-admin-users"></span>
						<?php esc_html_e( 'Per User', 'wp-mcp-ai' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_view_url( 'per_tool' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo 'per_tool' === $active_view ? 'active' : ''; ?>">
						<span class="dashicons dashicons-admin-tools"></span>
						<?php esc_html_e( 'Per Tool', 'wp-mcp-ai' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->get_view_url( 'per_site' ) ); ?>" class="wp-mcp-ai-token-manager__nav-item <?php echo 'per_site' === $active_view ? 'active' : ''; ?>">
						<span class="dashicons dashicons-admin-site-alt3"></span>
						<?php esc_html_e( 'Per Site', 'wp-mcp-ai' ); ?>
					</a>
				</nav>

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
						case 'per_user':
						default:
							$this->render_per_user_view();
							break;
					}
					?>
				</div>
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
			$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$meta_key
				)
			);

			?>
			<h3><?php esc_html_e( 'Token Usage by User', 'wp-mcp-ai' ); ?></h3>
			<p class="description"><?php esc_html_e( 'View and manage token consumption for each user across all AI models and providers.', 'wp-mcp-ai' ); ?></p>

			<?php if ( empty( $user_ids ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No token usage data has been recorded yet. Usage will appear here once users start interacting with AI assistants.', 'wp-mcp-ai' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Total Requests', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Total Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Prompt Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Completion Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Cached Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
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
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $user->display_name ); ?></strong>
									<br>
									<small class="description"><?php echo esc_html( $user->user_email ); ?></small>
								</td>
								<td><?php echo number_format_i18n( $totals['requests'] ); ?></td>
								<td><?php echo number_format_i18n( $totals['total_tokens'] ); ?></td>
								<td><?php echo number_format_i18n( $totals['prompt_tokens'] ); ?></td>
								<td><?php echo number_format_i18n( $totals['completion_tokens'] ); ?></td>
								<td><?php echo number_format_i18n( $totals['cached_tokens'] ); ?></td>
								<td>
									<button type="button" class="button button-small wp-mcp-ai-reset-user-usage" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-user-name="<?php echo esc_attr( $user->display_name ); ?>">
										<?php esc_html_e( 'Reset', 'wp-mcp-ai' ); ?>
									</button>
									<button type="button" class="button button-small wp-mcp-ai-view-user-details" data-user-id="<?php echo esc_attr( $user_id ); ?>">
										<?php esc_html_e( 'Details', 'wp-mcp-ai' ); ?>
									</button>
								</td>
							</tr>
							<tr class="wp-mcp-ai-user-details-row" id="user-details-<?php echo esc_attr( $user_id ); ?>" style="display: none;">
								<td colspan="7">
									<?php $this->render_user_details( $user_id, $usage ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div style="margin-top: 20px;">
					<button type="button" id="wp-mcp-ai-reset-all-usage" class="button button-secondary">
						<?php esc_html_e( 'Reset All Users\' Token Usage', 'wp-mcp-ai' ); ?>
					</button>
				</div>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render per-tool token limits view.
		 */
		private function render_per_tool_view() {
			$current_user_id = get_current_user_id();
			$user_tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $current_user_id );

			// Get all available tools.
			$all_tools = $this->get_all_available_tools();

			?>
			<h3><?php esc_html_e( 'Token Limits by Tool', 'wp-mcp-ai' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Configure daily token usage limits for individual tools. Different tools can have different limits based on their resource requirements.', 'wp-mcp-ai' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tool Name', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Tool Slug', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Daily Token Limit', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Total Users', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Total Requests', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Total Tokens Used', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $all_tools as $tool_slug => $tool_name ) :
						$tool_limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( $tool_slug );
						$tool_stats = WP_MCP_AI_Tool_Token_Limits::get_tool_statistics( $tool_slug );
						?>
						<tr>
							<td><strong><?php echo esc_html( $tool_name ); ?></strong></td>
							<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
							<td>
								<input type="number" class="wp-mcp-ai-tool-limit-input" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>" value="<?php echo esc_attr( $tool_limit ); ?>" min="0" step="1000" style="width: 120px;" />
								<span class="description"><?php esc_html_e( 'tokens/day', 'wp-mcp-ai' ); ?></span>
							</td>
							<td><?php echo number_format_i18n( $tool_stats['total_users'] ); ?></td>
							<td><?php echo number_format_i18n( $tool_stats['total_requests'] ); ?></td>
							<td><?php echo number_format_i18n( $tool_stats['total_tokens'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="button" id="wp-mcp-ai-save-all-tool-limits" class="button button-primary">
					<?php esc_html_e( 'Save All Tool Limits', 'wp-mcp-ai' ); ?>
				</button>
			</p>
			<?php
		}

		/**
		 * Render per-site token statistics view.
		 */
		private function render_per_site_view() {
			$site_stats = $this->get_site_wide_statistics();

			?>
			<h3><?php esc_html_e( 'Site-Wide Token Statistics', 'wp-mcp-ai' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Aggregate token usage statistics across all users, providers, models, and tools on this site.', 'wp-mcp-ai' ); ?></p>

			<!-- Summary Cards -->
			<div class="wp-mcp-ai-stats-grid">
				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-admin-users"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Active Users', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo number_format_i18n( $site_stats['total_users'] ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-performance"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Total Requests', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo number_format_i18n( $site_stats['total_requests'] ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-chart-bar"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Total Tokens', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo number_format_i18n( $site_stats['total_tokens'] ); ?></div>
					</div>
				</div>

				<div class="wp-mcp-ai-stats-card">
					<div class="wp-mcp-ai-stats-card__icon">
						<span class="dashicons dashicons-admin-tools"></span>
					</div>
					<div class="wp-mcp-ai-stats-card__content">
						<div class="wp-mcp-ai-stats-card__label"><?php esc_html_e( 'Tools Used', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-stats-card__value"><?php echo number_format_i18n( $site_stats['tools_used'] ); ?></div>
					</div>
				</div>
			</div>

			<!-- Usage by Provider -->
			<h4><?php esc_html_e( 'Usage by Provider', 'wp-mcp-ai' ); ?></h4>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Prompt Tokens', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Completion Tokens', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Total Tokens', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Cached Tokens', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $site_stats['by_provider'] ) ) :
						foreach ( $site_stats['by_provider'] as $provider => $stats ) :
							?>
							<tr>
								<td><strong><?php echo esc_html( ucfirst( $provider ) ); ?></strong></td>
								<td><?php echo number_format_i18n( $stats['requests'] ); ?></td>
								<td><?php echo number_format_i18n( $stats['prompt_tokens'] ); ?></td>
								<td><?php echo number_format_i18n( $stats['completion_tokens'] ); ?></td>
								<td><?php echo number_format_i18n( $stats['total_tokens'] ); ?></td>
								<td><?php echo number_format_i18n( $stats['cached_tokens'] ); ?></td>
							</tr>
							<?php
						endforeach;
					else :
						?>
						<tr>
							<td colspan="6" class="no-items"><?php esc_html_e( 'No provider data available yet.', 'wp-mcp-ai' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Top Models -->
			<h4><?php esc_html_e( 'Top Models by Usage', 'wp-mcp-ai' ); ?></h4>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Total Tokens', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $site_stats['top_models'] ) ) :
						foreach ( $site_stats['top_models'] as $model_data ) :
							?>
							<tr>
								<td><code><?php echo esc_html( $model_data['model'] ); ?></code></td>
								<td><?php echo esc_html( ucfirst( $model_data['provider'] ) ); ?></td>
								<td><?php echo number_format_i18n( $model_data['requests'] ); ?></td>
								<td><?php echo number_format_i18n( $model_data['total_tokens'] ); ?></td>
							</tr>
							<?php
						endforeach;
					else :
						?>
						<tr>
							<td colspan="4" class="no-items"><?php esc_html_e( 'No model data available yet.', 'wp-mcp-ai' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
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
				<h4><?php esc_html_e( 'Detailed Usage Breakdown', 'wp-mcp-ai' ); ?></h4>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Prompt Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Completion Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Total Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Cached Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'wp-mcp-ai' ); ?></th>
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
								?>
								<tr>
									<td><?php echo esc_html( ucfirst( $provider ) ); ?></td>
									<td><code><?php echo esc_html( $model ); ?></code></td>
									<td><?php echo number_format_i18n( $data['requests'] ); ?></td>
									<td><?php echo number_format_i18n( $data['prompt_tokens'] ); ?></td>
									<td><?php echo number_format_i18n( $data['completion_tokens'] ); ?></td>
									<td><?php echo number_format_i18n( $data['total_tokens'] ); ?></td>
									<td><?php echo number_format_i18n( $data['cached_tokens'] ); ?></td>
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
			$totals = array(
				'requests'          => 0,
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
				'cached_tokens'     => 0,
			);

			if ( ! is_array( $usage ) ) {
				return $totals;
			}

			foreach ( $usage as $provider => $models ) {
				if ( ! is_array( $models ) ) {
					continue;
				}

				foreach ( $models as $model => $data ) {
					if ( ! is_array( $data ) ) {
						continue;
					}

					$totals['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$totals['prompt_tokens']     += isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
					$totals['completion_tokens'] += isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
					$totals['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$totals['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;
				}
			}

			return $totals;
		}

		/**
		 * Get all available tools.
		 *
		 * @return array Tool slug => Tool name pairs.
		 */
		private function get_all_available_tools() {
			$tools = array();

			// Get all registered tools from the tool registry.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			
			if ( ! $registry ) {
				// Fallback to hardcoded tools if registry is not available.
				$tools = array(
					'run_crawl4ai_job' => __( 'Crawl4AI Web Scraper', 'wp-mcp-ai' ),
					'general_tools'    => __( 'General Tools (Default)', 'wp-mcp-ai' ),
				);
			} else {
				// Ensure registry is initialized.
				$registry->init();
				
				$registered_tools = $registry->get_tools();

				// Build array of tool slug => name pairs.
				foreach ( $registered_tools as $tool ) {
					if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
						$slug = $tool->get_slug();
						$name = $tool->get_name();
						
						if ( ! empty( $slug ) && ! empty( $name ) ) {
							$tools[ $slug ] = $name;
						}
					}
				}

				// Sort tools by name for better UI experience.
				asort( $tools );
			}

			/**
			 * Filter available tools for token limit configuration.
			 *
			 * @param array $tools Tool slug => Tool name pairs.
			 */
			return apply_filters( 'wp_mcp_ai_token_manager_tools', $tools );
		}

		/**
		 * Get site-wide statistics.
		 *
		 * @return array Site statistics.
		 */
		private function get_site_wide_statistics() {
			global $wpdb;

			$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;

			// Get all user IDs with usage data.
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$meta_key
				)
			);

			$stats = array(
				'total_users'    => count( $user_ids ),
				'total_requests' => 0,
				'total_tokens'   => 0,
				'by_provider'    => array(),
				'top_models'     => array(),
				'tools_used'     => 0,
			);

			$all_models = array();

			foreach ( $user_ids as $user_id ) {
				$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

				foreach ( $usage as $provider => $models ) {
					if ( ! isset( $stats['by_provider'][ $provider ] ) ) {
						$stats['by_provider'][ $provider ] = array(
							'requests'          => 0,
							'prompt_tokens'     => 0,
							'completion_tokens' => 0,
							'total_tokens'      => 0,
							'cached_tokens'     => 0,
						);
					}

					foreach ( $models as $model => $data ) {
						$stats['total_requests'] += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
						$stats['total_tokens']   += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;

						$stats['by_provider'][ $provider ]['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
						$stats['by_provider'][ $provider ]['prompt_tokens']     += isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
						$stats['by_provider'][ $provider ]['completion_tokens'] += isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
						$stats['by_provider'][ $provider ]['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
						$stats['by_provider'][ $provider ]['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;

						$model_key = $provider . '|' . $model;
						if ( ! isset( $all_models[ $model_key ] ) ) {
							$all_models[ $model_key ] = array(
								'provider'     => $provider,
								'model'        => $model,
								'requests'     => 0,
								'total_tokens' => 0,
							);
						}

						$all_models[ $model_key ]['requests']     += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
						$all_models[ $model_key ]['total_tokens'] += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					}
				}
			}

			// Sort models by total tokens and get top 10.
			uasort(
				$all_models,
				function ( $a, $b ) {
					return $b['total_tokens'] - $a['total_tokens'];
				}
			);

			$stats['top_models'] = array_slice( $all_models, 0, 10 );

			// Count tools used.
			$tool_meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
			$tool_users    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$tool_meta_key
				)
			);

			$tools_set = array();
			foreach ( $tool_users as $user_id ) {
				$tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
				foreach ( array_keys( $tool_usage ) as $tool_slug ) {
					$tools_set[ $tool_slug ] = true;
				}
			}

			$stats['tools_used'] = count( $tools_set );

			return $stats;
		}

		/**
		 * Override render_wrapper to exclude the form table.
		 */
		public function render_wrapper() {
			$description = $this->get_description();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php $this->render(); ?>
			</div>
			<?php
		}
	}
}
