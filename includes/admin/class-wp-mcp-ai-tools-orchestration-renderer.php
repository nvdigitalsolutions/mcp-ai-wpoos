<?php
/**
 * Tools Orchestration Renderer
 *
 * Handles UI rendering for tool orchestration settings view.
 * Displays tools with their capabilities, model requirements, and orchestration metadata.
 *
 * CRITICAL: All methods implement defensive programming with try-catch blocks
 * and fallback rendering to ensure the admin UI never breaks.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tools_Orchestration_Renderer' ) ) {
	/**
	 * Renders tools orchestration view UI components.
	 *
	 * Each rendering method includes:
	 * - Try-catch error handling
	 * - Comprehensive logging on failure
	 * - Graceful fallback UI when errors occur
	 * - Security (escaping, sanitization)
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tools_Orchestration_Renderer {

		/**
		 * Render the complete tools orchestration view.
		 *
		 * @return string HTML output or fallback on error.
		 */
		public static function render_tools_view() {
			try {
				// Get all registered tools from the registry.
				$registry = WP_MCP_AI_Tool_Registry::get_instance();
				if ( ! $registry ) {
					throw new Exception( 'Tool registry not available' );
				}

				$all_tools = $registry->get_tools();
				if ( ! is_array( $all_tools ) ) {
					$all_tools = array();
				}

				// Get current filter values from GET parameters.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters.
				$filter_capability = isset( $_GET['filter_capability'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_capability'] ) ) : '';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$filter_status = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : '';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$filter_model = isset( $_GET['filter_model'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_model'] ) ) : '';

				// Apply filters.
				$filtered_tools = self::apply_filters( $all_tools, $filter_capability, $filter_status, $filter_model );

				ob_start();
				?>
				<h3><?php esc_html_e( 'Tool Orchestration Settings', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage orchestration settings for each tool. Enable or disable tools globally, and filter by capability flags, status, or model requirements.', 'wp-mcp-ai' ); ?>
				</p>

				<?php if ( empty( $all_tools ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No tools are currently registered. Please check your plugin configuration.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_filter_bar method.
					echo self::render_filter_bar( $all_tools, $filter_capability, $filter_status, $filter_model );

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_tools_table method.
					echo self::render_tools_table( $filtered_tools );

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_summary_statistics method.
					echo self::render_summary_statistics( $filtered_tools, $all_tools );

					// Add CSS for tool enable/disable styling.
					?>
					<style>
						.tool-disabled {
							opacity: 0.6;
							background-color: #f9f9f9;
						}
						.tool-disabled:hover {
							opacity: 0.8;
						}
					</style>
					<?php
					?>
				<?php endif; ?>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_error' ) ) {
					try {
						WP_MCP_AI_Logger::log_error(
							'Tools orchestration view rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'tools_orchestration_renderer',
								'method'    => 'render_tools_view',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'Tools orchestration view temporarily unavailable. Please refresh the page.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Apply filters to tools array.
		 *
		 * @param array  $all_tools         All registered tools.
		 * @param string $filter_capability Capability flag to filter by.
		 * @param string $filter_status     Status to filter by (enabled/disabled).
		 * @param string $filter_model      Model requirement to filter by.
		 * @return array Filtered tools array.
		 */
		private static function apply_filters( $all_tools, $filter_capability, $filter_status, $filter_model ) {
			$registry       = WP_MCP_AI_Tool_Registry::get_instance();
			$disabled_tools = $registry ? $registry->get_disabled_tools() : array();

			$filtered = array();

			foreach ( $all_tools as $tool_slug => $tool ) {
				$include = true;

				// Filter by status.
				if ( 'enabled' === $filter_status && in_array( $tool_slug, $disabled_tools, true ) ) {
					$include = false;
				} elseif ( 'disabled' === $filter_status && ! in_array( $tool_slug, $disabled_tools, true ) ) {
					$include = false;
				}

				// Filter by capability flag.
				if ( $include && ! empty( $filter_capability ) ) {
					if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
						$flags = $tool->get_capability_flags();
						if ( ! in_array( $filter_capability, $flags, true ) ) {
							$include = false;
						}
					} else {
						$include = false;
					}
				}

				// Filter by model requirement.
				if ( $include && ! empty( $filter_model ) ) {
					if ( $tool instanceof WP_MCP_AI_Tool_Model_Requirements_Interface ) {
						$requirements = $tool->get_model_requirements();
						if ( ! in_array( $filter_model, $requirements, true ) ) {
							$include = false;
						}
					} else {
						$include = false;
					}
				}

				if ( $include ) {
					$filtered[ $tool_slug ] = $tool;
				}
			}

			return $filtered;
		}

		/**
		 * Render filter bar for tools.
		 *
		 * @param array  $all_tools         All registered tools.
		 * @param string $filter_capability Current capability filter.
		 * @param string $filter_status     Current status filter.
		 * @param string $filter_model      Current model filter.
		 * @return string HTML output for filter bar.
		 */
		private static function render_filter_bar( $all_tools, $filter_capability, $filter_status, $filter_model ) {
			// Get unique capability flags from all tools.
			$all_capabilities = array();
			$all_models       = array();

			foreach ( $all_tools as $tool ) {
				if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
					$flags            = $tool->get_capability_flags();
					$all_capabilities = array_merge( $all_capabilities, $flags );
				}
				if ( $tool instanceof WP_MCP_AI_Tool_Model_Requirements_Interface ) {
					$requirements = $tool->get_model_requirements();
					$all_models   = array_merge( $all_models, $requirements );
				}
			}

			$all_capabilities = array_unique( $all_capabilities );
			$all_models       = array_unique( $all_models );
			sort( $all_capabilities );
			sort( $all_models );

			ob_start();
			?>
			<div class="wp-mcp-ai-tools-filter-bar" style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Filter Tools', 'wp-mcp-ai' ); ?></h4>
				<form method="get" action="" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
					<!-- Preserve existing query parameters -->
					<input type="hidden" name="page" value="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
					<input type="hidden" name="view" value="<?php echo esc_attr( isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />

					<!-- Status Filter -->
					<div style="flex: 1; min-width: 150px;">
						<label for="filter_status" style="display: block; margin-bottom: 5px; font-weight: bold;">
							<?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?>
						</label>
						<select name="filter_status" id="filter_status" style="width: 100%;">
							<option value=""><?php esc_html_e( 'All Tools', 'wp-mcp-ai' ); ?></option>
							<option value="enabled" <?php selected( $filter_status, 'enabled' ); ?>><?php esc_html_e( 'Enabled Only', 'wp-mcp-ai' ); ?></option>
							<option value="disabled" <?php selected( $filter_status, 'disabled' ); ?>><?php esc_html_e( 'Disabled Only', 'wp-mcp-ai' ); ?></option>
						</select>
					</div>

					<!-- Capability Filter -->
					<div style="flex: 1; min-width: 200px;">
						<label for="filter_capability" style="display: block; margin-bottom: 5px; font-weight: bold;">
							<?php esc_html_e( 'Capability Flag', 'wp-mcp-ai' ); ?>
						</label>
						<select name="filter_capability" id="filter_capability" style="width: 100%;">
							<option value=""><?php esc_html_e( 'All Capabilities', 'wp-mcp-ai' ); ?></option>
							<?php foreach ( $all_capabilities as $capability ) : ?>
								<option value="<?php echo esc_attr( $capability ); ?>" <?php selected( $filter_capability, $capability ); ?>>
									<?php echo esc_html( $capability ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<!-- Model Filter -->
					<?php if ( ! empty( $all_models ) ) : ?>
						<div style="flex: 1; min-width: 200px;">
							<label for="filter_model" style="display: block; margin-bottom: 5px; font-weight: bold;">
								<?php esc_html_e( 'Model Requirement', 'wp-mcp-ai' ); ?>
							</label>
							<select name="filter_model" id="filter_model" style="width: 100%;">
								<option value=""><?php esc_html_e( 'All Models', 'wp-mcp-ai' ); ?></option>
								<?php foreach ( $all_models as $model ) : ?>
									<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $filter_model, $model ); ?>>
										<?php echo esc_html( $model ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<!-- Filter Buttons -->
					<div>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Apply Filters', 'wp-mcp-ai' ); ?>
						</button>
						<a href="<?php echo esc_url( remove_query_arg( array( 'filter_capability', 'filter_status', 'filter_model' ) ) ); ?>" class="button">
							<?php esc_html_e( 'Clear Filters', 'wp-mcp-ai' ); ?>
						</a>
					</div>
				</form>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render the tools table.
		 *
		 * @param array $all_tools Array of registered tools.
		 * @return string HTML output or fallback on error.
		 */
		private static function render_tools_table( $all_tools ) {
			try {
				if ( ! is_array( $all_tools ) || empty( $all_tools ) ) {
					return '';
				}

				ob_start();
				?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 5%;"><?php esc_html_e( 'Enabled', 'wp-mcp-ai' ); ?></th>
							<th style="width: 18%;"><?php esc_html_e( 'Tool Name', 'wp-mcp-ai' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Slug', 'wp-mcp-ai' ); ?></th>
							<th style="width: 28%;"><?php esc_html_e( 'Capability Flags', 'wp-mcp-ai' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Model Requirements', 'wp-mcp-ai' ); ?></th>
							<th style="width: 22%;"><?php esc_html_e( 'Orchestration Info', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_tools as $tool_slug => $tool ) : ?>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_tool_row method.
							echo self::render_tool_row( $tool_slug, $tool );
							?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				return sprintf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'Tools table temporarily unavailable.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Render a single tool row.
		 *
		 * @param string                      $tool_slug Tool slug identifier.
		 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
		 * @return string HTML output for tool row.
		 */
		private static function render_tool_row( $tool_slug, $tool ) {
			try {
				$tool_name        = $tool->get_name();
				$tool_description = $tool->get_description();

				// Check if tool is enabled.
				$registry   = WP_MCP_AI_Tool_Registry::get_instance();
				$is_enabled = $registry ? $registry->is_tool_enabled( $tool_slug ) : true;

				// Get capability flags if tool implements the interface.
				$capability_flags = array();
				if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
					$capability_flags = $tool->get_capability_flags();
				}

				// Get model requirements if tool implements the interface.
				$model_requirements = array();
				if ( $tool instanceof WP_MCP_AI_Tool_Model_Requirements_Interface ) {
					$model_requirements = $tool->get_model_requirements();
				}

				// Get flow stages if tool implements the interface.
				$flow_stages = array( 'anytime' );
				if ( $tool instanceof WP_MCP_AI_Tool_Flow_Stage_Interface ) {
					$flow_stages = $tool->get_flow_stages();
				}

				// Get tool rules if tool implements the interface.
				$has_rules = $tool instanceof WP_MCP_AI_Tool_Rules_Interface;

				// Determine characteristics.
				$is_read_only      = in_array( 'read-only', $capability_flags, true );
				$is_state_changing = in_array( 'state-changing', $capability_flags, true ) || in_array( 'write', $capability_flags, true );
				$requires_creds    = in_array( 'requires-credentials', $capability_flags, true );
				$external_api      = in_array( 'external-api', $capability_flags, true ) || in_array( 'network-dependent', $capability_flags, true );
				$long_running      = in_array( 'long-running', $capability_flags, true ) || in_array( 'async', $capability_flags, true );

				ob_start();
				?>
				<tr data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>" class="<?php echo ! $is_enabled ? 'tool-disabled' : ''; ?>">
					<td style="text-align: center;">
						<input 
							type="checkbox" 
							name="wp_mcp_ai_enabled_tools[]" 
							value="<?php echo esc_attr( $tool_slug ); ?>" 
							<?php checked( $is_enabled ); ?>
							aria-label="<?php echo esc_attr( sprintf( __( 'Enable %s tool', 'wp-mcp-ai' ), $tool_name ) ); ?>"
						/>
					</td>
					<td>
						<strong><?php echo esc_html( $tool_name ); ?></strong>
						<?php if ( $tool_description ) : ?>
							<br><small class="description"><?php echo esc_html( wp_trim_words( $tool_description, 15 ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_capability_flags method.
						echo self::render_capability_flags( $capability_flags );
						?>
					</td>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_model_requirements method.
						echo self::render_model_requirements( $model_requirements );
						?>
					</td>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_orchestration_info method.
						echo self::render_orchestration_info( $flow_stages, $has_rules, $is_read_only, $is_state_changing, $requires_creds, $external_api, $long_running );
						?>
					</td>
				</tr>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				return sprintf(
					'<tr><td colspan="5">%s</td></tr>',
					esc_html__( 'Error rendering tool row.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Render capability flags grouped by category.
		 *
		 * @param array $capability_flags Array of capability flag strings.
		 * @return string HTML output for capability flags.
		 */
		private static function render_capability_flags( $capability_flags ) {
			if ( empty( $capability_flags ) || ! is_array( $capability_flags ) ) {
				return '<span style="color: #999; font-style: italic;">' . esc_html__( 'None', 'wp-mcp-ai' ) . '</span>';
			}

			// Group flags by category for better display.
			$grouped_flags = self::group_capability_flags( $capability_flags );

			ob_start();
			?>
			<div class="wp-mcp-ai-capability-flags" style="display: flex; flex-wrap: wrap; gap: 4px;">
				<?php foreach ( $grouped_flags as $group_key => $group_data ) : ?>
					<?php if ( ! empty( $group_data['flags'] ) ) : ?>
						<details style="margin: 2px 0;">
							<summary style="cursor: pointer; font-size: 11px; font-weight: bold; color: <?php echo esc_attr( $group_data['color'] ); ?>; padding: 2px 6px; background: #f0f0f0; border-radius: 3px; display: inline-block;">
								<?php echo esc_html( $group_data['label'] ); ?> (<?php echo count( $group_data['flags'] ); ?>)
							</summary>
							<div style="margin-top: 4px; padding: 4px;">
								<?php foreach ( $group_data['flags'] as $flag ) : ?>
									<span class="wp-mcp-ai-capability-badge" style="display: inline-block; font-size: 10px; padding: 2px 6px; margin: 2px; background: <?php echo esc_attr( $group_data['color'] ); ?>; color: white; border-radius: 3px;">
										<?php echo esc_html( $flag ); ?>
									</span>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Group capability flags by category.
		 *
		 * @param array $capability_flags Array of capability flag strings.
		 * @return array Grouped flags by category.
		 */
		private static function group_capability_flags( $capability_flags ) {
			// Group flags by category.
			$requirement_flags = array_filter(
				$capability_flags,
				function( $flag ) {
					return strpos( $flag, 'requires-' ) === 0;
				}
			);
			$operational_flags = array_filter(
				$capability_flags,
				function( $flag ) {
					return in_array(
						$flag,
						array( 'read-only', 'write', 'state-changing', 'reversible', 'idempotent', 'performance-impact', 'consumes-tokens', 'model-dependent' ),
						true
					);
				}
			);
			$network_flags     = array_filter(
				$capability_flags,
				function( $flag ) {
					return in_array(
						$flag,
						array( 'local-only', 'external-api', 'network-dependent', 'async', 'rate-limited', 'deferred-result', 'requires-polling', 'supports-webhook', 'requires-callback', 'long-running', 'may-timeout', 'background-only', 'streaming-capable' ),
						true
					);
				}
			);
			$data_flags        = array_filter(
				$capability_flags,
				function( $flag ) {
					return in_array(
						$flag,
						array( 'cacheable', 'non-deterministic', 'pii-data', 'large-response', 'paginated', 'supports-compression' ),
						true
					);
				}
			);

			return array(
				'requirements' => array(
					'label' => __( 'Requirements', 'wp-mcp-ai' ),
					'flags' => array_values( $requirement_flags ),
					'color' => '#d63638',
				),
				'operational'  => array(
					'label' => __( 'Operational', 'wp-mcp-ai' ),
					'flags' => array_values( $operational_flags ),
					'color' => '#2271b1',
				),
				'network'      => array(
					'label' => __( 'Network', 'wp-mcp-ai' ),
					'flags' => array_values( $network_flags ),
					'color' => '#f0b849',
				),
				'data'         => array(
					'label' => __( 'Data', 'wp-mcp-ai' ),
					'flags' => array_values( $data_flags ),
					'color' => '#46b450',
				),
			);
		}

		/**
		 * Render model requirements badges.
		 *
		 * @param array $model_requirements Array of model requirement strings.
		 * @return string HTML output for model requirements.
		 */
		private static function render_model_requirements( $model_requirements ) {
			if ( empty( $model_requirements ) || ! is_array( $model_requirements ) ) {
				return '<span style="color: #999; font-style: italic;">' . esc_html__( 'Any', 'wp-mcp-ai' ) . '</span>';
			}

			ob_start();
			?>
			<div style="display: flex; flex-wrap: wrap; gap: 4px;">
				<?php foreach ( $model_requirements as $requirement ) : ?>
					<span class="wp-mcp-ai-model-requirement-badge" style="display: inline-block; font-size: 10px; padding: 2px 6px; background: #8c68cd; color: white; border-radius: 3px;">
						<?php echo esc_html( $requirement ); ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render orchestration information column.
		 *
		 * @param array $flow_stages        Flow stage eligibility.
		 * @param bool  $has_rules          Whether tool has execution rules.
		 * @param bool  $is_read_only       Whether tool is read-only.
		 * @param bool  $is_state_changing  Whether tool modifies state.
		 * @param bool  $requires_creds     Whether tool requires credentials.
		 * @param bool  $external_api       Whether tool uses external API.
		 * @param bool  $long_running       Whether tool is long-running.
		 * @return string HTML output for orchestration info.
		 */
		private static function render_orchestration_info( $flow_stages, $has_rules, $is_read_only, $is_state_changing, $requires_creds, $external_api, $long_running ) {
			ob_start();
			?>
			<div style="font-size: 11px;">
				<?php if ( ! empty( $flow_stages ) && ! in_array( 'anytime', $flow_stages, true ) ) : ?>
					<div style="margin-bottom: 4px;">
						<strong><?php esc_html_e( 'Flow:', 'wp-mcp-ai' ); ?></strong>
						<?php echo esc_html( implode( ', ', $flow_stages ) ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $has_rules ) : ?>
					<div style="margin-bottom: 4px;">
						<span class="dashicons dashicons-admin-generic" style="font-size: 14px; color: #2271b1; vertical-align: middle;"></span>
						<span style="color: #2271b1; font-weight: bold;"><?php esc_html_e( 'Has Rules', 'wp-mcp-ai' ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $is_read_only ) : ?>
					<div style="color: #46b450;">
						<span class="dashicons dashicons-visibility" style="font-size: 14px; vertical-align: middle;"></span>
						<?php esc_html_e( 'Read-only', 'wp-mcp-ai' ); ?>
					</div>
				<?php elseif ( $is_state_changing ) : ?>
					<div style="color: #d63638;">
						<span class="dashicons dashicons-edit" style="font-size: 14px; vertical-align: middle;"></span>
						<?php esc_html_e( 'Modifies state', 'wp-mcp-ai' ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $requires_creds ) : ?>
					<div style="color: #f0b849;">
						<span class="dashicons dashicons-lock" style="font-size: 14px; vertical-align: middle;"></span>
						<?php esc_html_e( 'Requires credentials', 'wp-mcp-ai' ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $external_api ) : ?>
					<div style="color: #2271b1;">
						<span class="dashicons dashicons-cloud" style="font-size: 14px; vertical-align: middle;"></span>
						<?php esc_html_e( 'External API', 'wp-mcp-ai' ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $long_running ) : ?>
					<div style="color: #f0b849;">
						<span class="dashicons dashicons-clock" style="font-size: 14px; vertical-align: middle;"></span>
						<?php esc_html_e( 'Long-running', 'wp-mcp-ai' ); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render summary statistics panel.
		 *
		 * @param array $filtered_tools Filtered array of tools to show.
		 * @param array $all_tools      All registered tools (for showing totals).
		 * @return string HTML output for summary statistics.
		 */
		private static function render_summary_statistics( $filtered_tools, $all_tools = array() ) {
			try {
				// Use all_tools if provided, otherwise use filtered_tools for both.
				if ( empty( $all_tools ) ) {
					$all_tools = $filtered_tools;
				}

				// Calculate summary statistics.
				$stats          = self::calculate_tool_statistics( $filtered_tools );
				$registry       = WP_MCP_AI_Tool_Registry::get_instance();
				$disabled_tools = $registry ? $registry->get_disabled_tools() : array();

				// Calculate enabled/disabled counts.
				$enabled_count  = 0;
				$disabled_count = 0;
				foreach ( $all_tools as $tool_slug => $tool ) {
					if ( in_array( $tool_slug, $disabled_tools, true ) ) {
						++$disabled_count;
					} else {
						++$enabled_count;
					}
				}

				ob_start();
				?>
				<!-- Summary Statistics -->
				<div class="wp-mcp-ai-tools-summary" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Tools Summary', 'wp-mcp-ai' ); ?></h4>
					<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_stat_card method.
						echo self::render_stat_card( count( $all_tools ), __( 'Total Tools', 'wp-mcp-ai' ), '#2271b1' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['total_tools'], __( 'Filtered Tools', 'wp-mcp-ai' ), '#2271b1' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $enabled_count, __( 'Enabled', 'wp-mcp-ai' ), '#46b450' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $disabled_count, __( 'Disabled', 'wp-mcp-ai' ), '#d63638' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['tools_with_capabilities'], __( 'With Capabilities', 'wp-mcp-ai' ), '#46b450' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['tools_with_model_reqs'], __( 'With Model Reqs', 'wp-mcp-ai' ), '#8c68cd' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['tools_with_rules'], __( 'With Rules', 'wp-mcp-ai' ), '#2271b1' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['read_only_tools'], __( 'Read-only', 'wp-mcp-ai' ), '#46b450' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['state_changing_tools'], __( 'State-changing', 'wp-mcp-ai' ), '#d63638' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['external_api_tools'], __( 'External API', 'wp-mcp-ai' ), '#2271b1' );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::render_stat_card( $stats['long_running_tools'], __( 'Long-running', 'wp-mcp-ai' ), '#f0b849' );
						?>
					</div>
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				return '';
			}
		}

		/**
		 * Calculate tool statistics.
		 *
		 * @param array $all_tools Array of registered tools.
		 * @return array Statistics array.
		 */
		private static function calculate_tool_statistics( $all_tools ) {
			$stats = array(
				'total_tools'             => count( $all_tools ),
				'tools_with_capabilities' => 0,
				'tools_with_model_reqs'   => 0,
				'tools_with_rules'        => 0,
				'read_only_tools'         => 0,
				'state_changing_tools'    => 0,
				'external_api_tools'      => 0,
				'long_running_tools'      => 0,
			);

			foreach ( $all_tools as $tool ) {
				if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
					++$stats['tools_with_capabilities'];
					$flags = $tool->get_capability_flags();
					if ( in_array( 'read-only', $flags, true ) ) {
						++$stats['read_only_tools'];
					}
					if ( in_array( 'state-changing', $flags, true ) || in_array( 'write', $flags, true ) ) {
						++$stats['state_changing_tools'];
					}
					if ( in_array( 'external-api', $flags, true ) || in_array( 'network-dependent', $flags, true ) ) {
						++$stats['external_api_tools'];
					}
					if ( in_array( 'long-running', $flags, true ) || in_array( 'async', $flags, true ) ) {
						++$stats['long_running_tools'];
					}
				}
				if ( $tool instanceof WP_MCP_AI_Tool_Model_Requirements_Interface ) {
					++$stats['tools_with_model_reqs'];
				}
				if ( $tool instanceof WP_MCP_AI_Tool_Rules_Interface ) {
					++$stats['tools_with_rules'];
				}
			}

			return $stats;
		}

		/**
		 * Render a single stat card.
		 *
		 * @param int    $value Stat value.
		 * @param string $label Stat label.
		 * @param string $color Card color.
		 * @return string HTML output for stat card.
		 */
		private static function render_stat_card( $value, $label, $color ) {
			ob_start();
			?>
			<div class="stat-card">
				<div style="font-size: 24px; font-weight: bold; color: <?php echo esc_attr( $color ); ?>;">
					<?php echo esc_html( number_format_i18n( $value ) ); ?>
				</div>
				<div style="color: #666; font-size: 12px;"><?php echo esc_html( $label ); ?></div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
}
