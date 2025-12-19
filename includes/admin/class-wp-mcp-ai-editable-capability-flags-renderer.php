<?php
/**
 * Editable Capability Flags Renderer
 *
 * Renders editable capability flags UI with checkboxes and force-sync toggle.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Editable_Capability_Flags_Renderer' ) ) {
	/**
	 * Renders editable capability flags UI.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Editable_Capability_Flags_Renderer {

		/**
		 * Render capability flags with edit/view modes.
		 *
		 * @param string $tool_slug         Tool slug.
		 * @param array  $capability_flags  Current capability flags.
		 * @param bool   $has_custom_flags  Whether tool has custom flags set.
		 * @param bool   $force_sync        Whether force-sync is enabled.
		 * @return string HTML output.
		 */
		public static function render( $tool_slug, $capability_flags, $has_custom_flags = false, $force_sync = false ) {
			// Load tool settings manager.
			if ( ! class_exists( 'WP_MCP_AI_Tool_Settings_Manager' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';
			}

			// Group flags by category for better display.
			$grouped_flags   = self::group_capability_flags( $capability_flags );
			$available_flags = WP_MCP_AI_Tool_Settings_Manager::get_available_capability_flags();

			ob_start();
			?>
			<div class="wp-mcp-ai-capability-flags-container" data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>">
				<!-- View Mode -->
				<div class="wp-mcp-ai-capability-flags-view">
					<?php if ( $has_custom_flags ) : ?>
						<span class="wp-mcp-ai-custom-indicator" style="display: inline-block; padding: 2px 6px; background: #f0b849; color: white; border-radius: 3px; font-size: 10px; margin-right: 5px; font-weight: bold;">
							<?php esc_html_e( 'CUSTOM', 'wp-mcp-ai' ); ?>
						</span>
					<?php endif; ?>
					
					<?php if ( $force_sync ) : ?>
						<span class="wp-mcp-ai-force-sync-indicator" style="display: inline-block; padding: 2px 6px; background: #d63638; color: white; border-radius: 3px; font-size: 10px; margin-right: 5px; font-weight: bold;">
							<?php esc_html_e( 'FORCE SYNC', 'wp-mcp-ai' ); ?>
						</span>
					<?php endif; ?>

					<?php if ( empty( $capability_flags ) ) : ?>
						<span style="color: #999; font-style: italic;"><?php esc_html_e( 'None', 'wp-mcp-ai' ); ?></span>
					<?php else : ?>
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
					<?php endif; ?>
				</div>

				<!-- Edit Mode (hidden by default) -->
				<div class="wp-mcp-ai-capability-flags-edit" style="display: none;">
					<div style="margin-bottom: 10px;">
						<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
							<input type="checkbox" 
									class="wp-mcp-ai-force-sync-checkbox" 
									data-tool-slug="<?php echo esc_attr( $tool_slug ); ?>"
									<?php checked( $force_sync ); ?>>
							<strong style="color: #d63638;"><?php esc_html_e( 'Force Synchronous Execution', 'wp-mcp-ai' ); ?></strong>
							<span class="dashicons dashicons-info-outline" style="font-size: 16px; color: #666;" title="<?php esc_attr_e( 'When enabled, this tool will always execute synchronously, bypassing async orchestration. Use this for tools that must complete immediately within the same request.', 'wp-mcp-ai' ); ?>"></span>
						</label>
						<p class="description" style="margin: 5px 0 0 23px;">
							<?php esc_html_e( 'Bypass async orchestration and always execute this tool synchronously.', 'wp-mcp-ai' ); ?>
						</p>
					</div>

					<div style="border-top: 1px solid #ddd; padding-top: 10px; margin-top: 10px;">
						<strong><?php esc_html_e( 'Capability Flags:', 'wp-mcp-ai' ); ?></strong>
						<p class="description" style="margin: 5px 0 10px;">
							<?php esc_html_e( 'Select capability flags that describe this tool\'s requirements and behavior. Custom flags override the default settings.', 'wp-mcp-ai' ); ?>
						</p>

						<?php foreach ( $available_flags as $group_key => $group_data ) : ?>
							<fieldset style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
								<legend style="font-weight: bold; color: <?php echo esc_attr( self::get_group_color( $group_key ) ); ?>;">
									<?php echo esc_html( $group_data['label'] ); ?>
								</legend>
								<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px;">
									<?php foreach ( $group_data['flags'] as $flag_key => $flag_label ) : ?>
										<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
											<input type="checkbox" 
													class="wp-mcp-ai-capability-flag-checkbox" 
													name="capability_flags[]" 
													value="<?php echo esc_attr( $flag_key ); ?>"
													<?php checked( in_array( $flag_key, $capability_flags, true ) ); ?>>
											<span><?php echo esc_html( $flag_label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endforeach; ?>
					</div>
				</div>
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
		 * Get color for group.
		 *
		 * @param string $group_key Group key.
		 * @return string Color code.
		 */
		private static function get_group_color( $group_key ) {
			$colors = array(
				'requirements' => '#d63638',
				'operational'  => '#2271b1',
				'network'      => '#f0b849',
				'data'         => '#46b450',
				'tier'         => '#9b51e0',
			);

			return isset( $colors[ $group_key ] ) ? $colors[ $group_key ] : '#666';
		}
	}
}
