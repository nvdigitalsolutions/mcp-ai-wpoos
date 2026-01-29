<?php
/**
 * Tools Filter Bar Renderer
 *
 * Reusable component for rendering search/filter bar across different tool views.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tools_Filter_Bar_Renderer' ) ) {
	/**
	 * Renders tools search and filter bar UI components.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tools_Filter_Bar_Renderer {

		/**
		 * Render the filter bar.
		 *
		 * @param array $args {
		 *     Configuration arguments for the filter bar.
		 *
		 *     @type string $tab          Current tab slug.
		 *     @type string $view         Current view (for views within tabs).
		 *     @type string $subtab       Current subtab (for subtabs within tabs).
		 *     @type string $search       Current search value.
		 *     @type string $filter_group Current filter group value.
		 *     @type array  $categories   Array of category options (key => label).
		 *     @type string $clear_url    URL for clear button.
		 * }
		 * @return string HTML output for filter bar.
		 */
		public static function render( $args = array() ) {
			// Parse arguments with defaults.
			$defaults = array(
				'tab'          => '',
				'view'         => '',
				'subtab'       => '',
				'search'       => '',
				'filter_group' => '',
				'categories'   => array(),
				'clear_url'    => '',
			);

			$args = wp_parse_args( $args, $defaults );

			// Get tool groups from registry if not provided.
			if ( empty( $args['categories'] ) ) {
				$args['categories'] = self::get_tool_categories();
			}

			$has_active_filters = ! empty( $args['search'] ) || ! empty( $args['filter_group'] );
			$filter_bar_class   = 'wp-mcp-ai-tools-filter-bar';
			if ( $has_active_filters ) {
				$filter_bar_class .= ' has-active-filters';
			}

			// Output buffering for filter bar rendering - buffer is closed with ob_get_clean() at line 123.
			ob_start();
			?>
			<!-- Search and Filter Bar -->
			<div class="<?php echo esc_attr( $filter_bar_class ); ?>" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
				<div class="wp-mcp-ai-tools-filter-form" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; align-items: start;">
					<div class="wp-mcp-ai-filter-group" style="display: flex; flex-direction: column; gap: 8px;">
						<label for="tool_search" style="font-weight: 600;">
							<?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?>
							<?php if ( ! empty( $args['search'] ) ) : ?>
								<span class="wp-mcp-ai-filter-active-badge">
									<span class="dashicons dashicons-filter" style="font-size: 11px; width: 11px; height: 11px;"></span>
									<?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?>
								</span>
							<?php endif; ?>
						</label>
						<input type="search"
								id="tool_search"
								name="tool_search"
								value="<?php echo esc_attr( $args['search'] ); ?>"
								placeholder="<?php esc_attr_e( 'Search tools...', 'mcp-ai-wpoos' ); ?>"
								style="width: 100%;">
					</div>

					<div class="wp-mcp-ai-filter-group" style="display: flex; flex-direction: column; gap: 8px;">
						<label for="tool_group" style="font-weight: 600;">
							<?php esc_html_e( 'Category:', 'mcp-ai-wpoos' ); ?>
							<?php if ( ! empty( $args['filter_group'] ) ) : ?>
								<span class="wp-mcp-ai-filter-active-badge">
									<span class="dashicons dashicons-filter" style="font-size: 11px; width: 11px; height: 11px;"></span>
									<?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?>
								</span>
							<?php endif; ?>
						</label>
						<select id="tool_group" name="tool_group" style="width: 100%;">
							<option value=""><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos' ); ?></option>
							<?php foreach ( $args['categories'] as $group_key => $group_label ) : ?>
								<option value="<?php echo esc_attr( $group_key ); ?>" <?php selected( $args['filter_group'], $group_key ); ?>>
									<?php echo esc_html( $group_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="wp-mcp-ai-filter-actions" style="grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end;">
						<button type="button" id="wp-mcp-ai-filter-tools" class="button">
							<?php esc_html_e( 'Filter', 'mcp-ai-wpoos' ); ?>
						</button>

						<?php if ( ! empty( $args['search'] ) || ! empty( $args['filter_group'] ) ) : ?>
							<a href="<?php echo esc_url( $args['clear_url'] ); ?>" class="button">
								<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for tools filter bar button functionality on this admin page only
				?>
				<script>
				(function($) {
					$('#wp-mcp-ai-filter-tools').on('click', function() {
						const $button = $(this);

						// Add loading state
						$button.addClass('is-loading').prop('disabled', true);

						const search = $('#tool_search').val();
						const group = $('#tool_group').val();
						const url = new URL(window.location.href);

						// Update URL parameters.
						url.searchParams.set('page', '<?php echo esc_js( WP_MCP_AI_Settings_Dashboard::PAGE_SLUG ); ?>');

						<?php if ( ! empty( $args['tab'] ) ) : ?>
						url.searchParams.set('tab', '<?php echo esc_js( $args['tab'] ); ?>');
						<?php endif; ?>

						<?php if ( ! empty( $args['view'] ) ) : ?>
						url.searchParams.set('view', '<?php echo esc_js( $args['view'] ); ?>');
						<?php endif; ?>

						<?php if ( ! empty( $args['subtab'] ) ) : ?>
						url.searchParams.set('subtab', '<?php echo esc_js( $args['subtab'] ); ?>');
						<?php endif; ?>

						if (search) {
							url.searchParams.set('tool_search', search);
						} else {
							url.searchParams.delete('tool_search');
						}

						if (group) {
							url.searchParams.set('tool_group', group);
						} else {
							url.searchParams.delete('tool_group');
						}

						// Navigate to filtered URL.
						window.location.href = url.toString();
					});

					// Allow Enter key to trigger filter.
					$('#tool_search, #tool_group').on('keypress', function(e) {
						if (e.which === 13) {
							e.preventDefault();
							$('#wp-mcp-ai-filter-tools').click();
						}
					});
				})(jQuery);
				</script>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Get default tool categories.
		 *
		 * @return array Array of category slugs and labels.
		 */
		private static function get_tool_categories() {
			return array(
				'wordpress-core'    => __( 'WordPress Core', 'mcp-ai-wpoos' ),
				'wordpress-plugins' => __( 'WordPress Plugins', 'mcp-ai-wpoos' ),
				'external-tools'    => __( 'External Tools', 'mcp-ai-wpoos' ),
				'other'             => __( 'Other Tools', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Filter tools by search term.
		 *
		 * @param array  $tools  Array of tool instances.
		 * @param string $search Search term.
		 * @return array Filtered tools.
		 */
		public static function filter_by_search( $tools, $search ) {
			if ( empty( $search ) || ! is_array( $tools ) ) {
				return $tools;
			}

			$search_term = strtolower( trim( $search ) );

			return array_filter(
				$tools,
				function ( $tool ) use ( $search_term ) {
					if ( ! is_object( $tool ) ) {
						return false;
					}

					$slug = method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : '';
					$name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : '';
					$desc = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';

					return false !== stripos( $slug, $search_term ) ||
							false !== stripos( $name, $search_term ) ||
							false !== stripos( $desc, $search_term );
				}
			);
		}

		/**
		 * Filter tools by category/group.
		 *
		 * @param array  $tools Array of tools grouped by category.
		 * @param string $group Category slug to filter by.
		 * @return array Filtered tools.
		 */
		public static function filter_by_category( $tools, $group ) {
			if ( empty( $group ) || ! is_array( $tools ) ) {
				return $tools;
			}

			// If tools are already grouped, return only the specified group.
			if ( isset( $tools[ $group ] ) ) {
				return array( $group => $tools[ $group ] );
			}

			return $tools;
		}
	}
}
