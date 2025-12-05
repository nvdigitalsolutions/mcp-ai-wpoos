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
				// Load filter bar renderer if not already loaded.
				if ( ! class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-tools-filter-bar-renderer.php';
				}

				// Get all registered tools from the registry.
				$registry = WP_MCP_AI_Tool_Registry::get_instance();
				if ( ! $registry ) {
					throw new Exception( 'Tool registry not available' );
				}

				$all_tools = $registry->get_tools();
				if ( ! is_array( $all_tools ) ) {
					$all_tools = array();
				}

				// Get search and filter parameters.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only operation.
				$search = isset( $_GET['tool_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tool_search'] ) ) : '';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only operation.
				$filter_group = isset( $_GET['tool_group'] ) ? sanitize_key( $_GET['tool_group'] ) : '';

				// Apply filters to tools if filter bar component is available.
				if ( ! empty( $search ) && class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ) ) {
					$all_tools = WP_MCP_AI_Tools_Filter_Bar_Renderer::filter_by_search( $all_tools, $search );
				}

				ob_start();
				?>
				<h3><?php esc_html_e( 'Tool Orchestration Settings', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'View and manage orchestration settings for each tool. Click "Edit" to customize capability flags and force synchronous execution for specific tools. Custom settings override default tool behavior.', 'wp-mcp-ai' ); ?>
				</p>

				<?php
				// Render filter bar if component is available.
				if ( class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in renderer.
					echo WP_MCP_AI_Tools_Filter_Bar_Renderer::render(
						array(
							'tab'          => 'orchestration',
							'view'         => 'tools',
							'search'       => $search,
							'filter_group' => $filter_group,
							'clear_url'    => admin_url( 'admin.php?page=' . WP_MCP_AI_Settings_Dashboard::PAGE_SLUG . '&tab=orchestration&view=tools' ),
						)
					);
				}
				?>

				<?php if ( empty( $all_tools ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No tools are currently registered. Please check your plugin configuration.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_tools_table method.
					echo self::render_tools_table( $all_tools );

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_summary_statistics method.
					echo self::render_summary_statistics( $all_tools );
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
							<th style="width: 18%;"><?php esc_html_e( 'Tool Name', 'wp-mcp-ai' ); ?></th>
							<th style="width: 13%;"><?php esc_html_e( 'Slug', 'wp-mcp-ai' ); ?></th>
							<th style="width: 28%;"><?php esc_html_e( 'Capability Flags', 'wp-mcp-ai' ); ?></th>
							<th style="width: 13%;"><?php esc_html_e( 'Model Requirements', 'wp-mcp-ai' ); ?></th>
							<th style="width: 18%;"><?php esc_html_e( 'Orchestration Info', 'wp-mcp-ai' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_tools as $tool ) : ?>
							<?php
							// Get tool slug from tool object since get_tools() returns numeric array.
							// Validate tool implements the required interface.
							if ( ! ( $tool instanceof WP_MCP_AI_Tool_Interface ) ) {
								continue;
							}
							$tool_slug = $tool->get_slug();
							if ( empty( $tool_slug ) ) {
								continue;
							}
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
		 * @param string                   $tool_slug Tool slug identifier.
		 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
		 * @return string HTML output for tool row.
		 */
		private static function render_tool_row( $tool_slug, $tool ) {
			try {
				$tool_name        = $tool->get_name();
				$tool_description = $tool->get_description();

				// Load tool settings manager.
				if ( ! class_exists( 'WP_MCP_AI_Tool_Settings_Manager' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';
				}

				// Get capability flags (with custom overrides if set).
				$capability_flags = WP_MCP_AI_Tool_Settings_Manager::get_capability_flags( $tool_slug, $tool );
				$has_custom_flags = ! empty( WP_MCP_AI_Tool_Settings_Manager::get_custom_capability_flags( $tool_slug ) );
				$force_sync       = WP_MCP_AI_Tool_Settings_Manager::is_force_sync_enabled( $tool_slug );

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
				<tr data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>" class="wp-mcp-ai-tool-row">
					<td>
						<strong><?php echo esc_html( $tool_name ); ?></strong>
						<?php if ( $tool_description ) : ?>
							<br><small class="description"><?php echo esc_html( wp_trim_words( $tool_description, 15 ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
					<td class="wp-mcp-ai-capability-flags-cell">
						<?php
						// Load editable capability flags renderer.
						if ( ! class_exists( 'WP_MCP_AI_Editable_Capability_Flags_Renderer' ) ) {
							require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-editable-capability-flags-renderer.php';
						}
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in renderer.
						echo WP_MCP_AI_Editable_Capability_Flags_Renderer::render( $tool_slug, $capability_flags, $has_custom_flags, $force_sync );
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
					<td class="wp-mcp-ai-tool-actions">
						<button type="button" class="button button-small wp-mcp-ai-edit-tool" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>">
							<?php esc_html_e( 'Edit', 'wp-mcp-ai' ); ?>
						</button>
						<div class="wp-mcp-ai-edit-actions" style="display: none;">
							<button type="button" class="button button-small button-primary wp-mcp-ai-save-tool" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>">
								<?php esc_html_e( 'Save', 'wp-mcp-ai' ); ?>
							</button>
							<button type="button" class="button button-small wp-mcp-ai-cancel-edit" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>">
								<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
							</button>
							<?php if ( $has_custom_flags || $force_sync ) : ?>
								<br>
								<button type="button" class="button button-small button-link-delete wp-mcp-ai-reset-tool" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>" style="margin-top: 5px;">
									<?php esc_html_e( 'Reset to Default', 'wp-mcp-ai' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				return sprintf(
					'<tr><td colspan="6">%s</td></tr>',
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
				function ( $flag ) {
					return strpos( $flag, 'requires-' ) === 0;
				}
			);
			$operational_flags = array_filter(
				$capability_flags,
				function ( $flag ) {
					return in_array(
						$flag,
						array( 'read-only', 'write', 'state-changing', 'reversible', 'idempotent', 'performance-impact', 'consumes-tokens', 'model-dependent' ),
						true
					);
				}
			);
			$network_flags     = array_filter(
				$capability_flags,
				function ( $flag ) {
					return in_array(
						$flag,
						array( 'local-only', 'external-api', 'network-dependent', 'async', 'rate-limited', 'deferred-result', 'requires-polling', 'supports-webhook', 'requires-callback', 'long-running', 'may-timeout', 'background-only', 'streaming-capable' ),
						true
					);
				}
			);
			$data_flags        = array_filter(
				$capability_flags,
				function ( $flag ) {
					return in_array(
						$flag,
						array( 'cacheable', 'non-deterministic', 'pii-data', 'large-response', 'paginated', 'supports-compression' ),
						true
					);
				}
			);
			$tier_flags        = array_filter(
				$capability_flags,
				function ( $flag ) {
					return in_array(
						$flag,
						array( 'pro' ),
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
				'tier'         => array(
					'label' => __( 'Tier', 'wp-mcp-ai' ),
					'flags' => array_values( $tier_flags ),
					'color' => '#9b51e0',
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
		 * @param array $all_tools Array of registered tools.
		 * @return string HTML output for summary statistics.
		 */
		private static function render_summary_statistics( $all_tools ) {
			try {
				// Calculate summary statistics.
				$stats = self::calculate_tool_statistics( $all_tools );

				ob_start();
				?>
				<!-- Summary Statistics -->
				<div class="wp-mcp-ai-tools-summary" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Tools Summary', 'wp-mcp-ai' ); ?></h4>
					<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_stat_card method.
						echo self::render_stat_card( $stats['total_tools'], __( 'Total Tools', 'wp-mcp-ai' ), '#2271b1' );
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
