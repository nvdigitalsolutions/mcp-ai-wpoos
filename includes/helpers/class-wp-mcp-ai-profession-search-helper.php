<?php
/**
 * Profession Search Helper
 *
 * Provides reusable search functionality for profession lists across the plugin.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for rendering searchable profession lists.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Profession_Search_Helper {

	/**
	 * Render a search input field for filtering professions.
	 *
	 * @since 1.0.0
	 * @param array $args Configuration arguments.
	 * @return void
	 */
	public static function render_search_field( $args = array() ) {
		$defaults = array(
			'id'          => 'wp-mcp-ai-profession-search',
			'placeholder' => __( 'Search professions...', 'wp-mcp-ai' ),
			'aria_label'  => __( 'Search professions', 'wp-mcp-ai' ),
			'help_text'   => __( 'Type to filter the profession list below', 'wp-mcp-ai' ),
			'class'       => 'regular-text',
		);

		$args = wp_parse_args( $args, $defaults );

		?>
		<div class="wp-mcp-ai-profession-search-wrapper" style="margin-bottom: 15px;">
			<input 
				type="text" 
				id="<?php echo esc_attr( $args['id'] ); ?>" 
				class="<?php echo esc_attr( $args['class'] ); ?>" 
				placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				aria-label="<?php echo esc_attr( $args['aria_label'] ); ?>"
			/>
			<?php if ( ! empty( $args['help_text'] ) ) : ?>
				<p class="description" style="margin-top: 5px;">
					<?php echo esc_html( $args['help_text'] ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get profession data attributes for search functionality.
	 *
	 * @since 1.0.0
	 * @param WP_Post $profession The profession post object.
	 * @return string HTML data attributes.
	 */
	public static function get_profession_data_attributes( $profession ) {
		$category    = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_category', true );
		$description = '';

		if ( $profession->post_excerpt ) {
			$description = $profession->post_excerpt;
		} elseif ( $profession->post_content ) {
			$description = wp_trim_words( wp_strip_all_tags( $profession->post_content ), 15 );
		}

		$attributes = sprintf(
			'data-profession-title="%s" data-profession-category="%s" data-profession-description="%s"',
			esc_attr( strtolower( $profession->post_title ) ),
			esc_attr( strtolower( $category ) ),
			esc_attr( strtolower( $description ) )
		);

		return $attributes;
	}

	/**
	 * Output the search JavaScript functionality.
	 *
	 * @since 1.0.0
	 * @param array $args Configuration arguments.
	 * @return void
	 */
	public static function render_search_script( $args = array() ) {
		$defaults = array(
			'search_input_id' => 'wp-mcp-ai-profession-search',
			'row_selector'    => '.wp-mcp-ai-profession-row',
			'debounce_delay'  => 300,
		);

		$args = wp_parse_args( $args, $defaults );

		?>
		<script type="text/javascript">
		( function() {
			var searchDebounceTimer = null;
			
			document.addEventListener( 'DOMContentLoaded', function() {
				var searchInput = document.getElementById( '<?php echo esc_js( $args['search_input_id'] ); ?>' );
				var professionRows = document.querySelectorAll( '<?php echo esc_js( $args['row_selector'] ); ?>' );
				
				// Handle search filtering.
				if ( searchInput ) {
					searchInput.addEventListener( 'input', function() {
						// Debounce search to avoid excessive filtering.
						clearTimeout( searchDebounceTimer );
						searchDebounceTimer = setTimeout( function() {
							var searchTerm = searchInput.value.toLowerCase().trim();
							var visibleCount = 0;
							
							professionRows.forEach( function( row ) {
								var title = row.getAttribute( 'data-profession-title' ) || '';
								var category = row.getAttribute( 'data-profession-category' ) || '';
								var description = row.getAttribute( 'data-profession-description' ) || '';
								
								// Check if search term matches title, category, or description.
								var matches = searchTerm === '' ||
									title.indexOf( searchTerm ) !== -1 ||
									category.indexOf( searchTerm ) !== -1 ||
									description.indexOf( searchTerm ) !== -1;
								
								if ( matches ) {
									row.style.display = '';
									visibleCount++;
								} else {
									row.style.display = 'none';
								}
							} );
							
							// Update aria-live region with results count.
							updateSearchResults( visibleCount, professionRows.length, searchTerm );
						}, <?php echo absint( $args['debounce_delay'] ); ?> );
					} );
					
					// Add aria-live region for screen readers.
					var searchWrapper = searchInput.closest( '.wp-mcp-ai-profession-search-wrapper' );
					if ( searchWrapper && ! document.getElementById( 'wp-mcp-ai-search-results' ) ) {
						var resultsDiv = document.createElement( 'div' );
						resultsDiv.id = 'wp-mcp-ai-search-results';
						resultsDiv.className = 'screen-reader-text';
						resultsDiv.setAttribute( 'aria-live', 'polite' );
						resultsDiv.setAttribute( 'aria-atomic', 'true' );
						searchWrapper.appendChild( resultsDiv );
					}
				}
			} );
			
			/**
			 * Update search results announcement for screen readers.
			 *
			 * @param {number} visibleCount - Number of visible professions.
			 * @param {number} totalCount   - Total number of professions.
			 * @param {string} searchTerm   - The search term.
			 */
			function updateSearchResults( visibleCount, totalCount, searchTerm ) {
				var resultsDiv = document.getElementById( 'wp-mcp-ai-search-results' );
				if ( ! resultsDiv ) {
					return;
				}
				
				if ( searchTerm === '' ) {
					resultsDiv.textContent = '';
				} else if ( visibleCount === 0 ) {
					resultsDiv.textContent = '<?php esc_html_e( 'No professions found matching your search.', 'wp-mcp-ai' ); ?>';
				} else if ( visibleCount === 1 ) {
					resultsDiv.textContent = '<?php esc_html_e( '1 profession found.', 'wp-mcp-ai' ); ?>';
				} else {
					resultsDiv.textContent = visibleCount + ' <?php esc_html_e( 'professions found.', 'wp-mcp-ai' ); ?>';
				}
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Output the search CSS styles.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_search_styles() {
		?>
		<style>
			.wp-mcp-ai-profession-search-wrapper {
				position: relative;
			}
			#wp-mcp-ai-profession-search {
				padding: 8px 12px;
				font-size: 14px;
				border: 1px solid #8c8f94;
				border-radius: 4px;
				box-shadow: 0 0 0 transparent;
				transition: border-color .1s ease-in-out, box-shadow .1s linear;
			}
			#wp-mcp-ai-profession-search:focus {
				border-color: #2271b1;
				box-shadow: 0 0 0 1px #2271b1;
				outline: 2px solid transparent;
			}
			.wp-mcp-ai-profession-row {
				transition: opacity 0.2s ease-in-out;
			}
			.wp-mcp-ai-profession-row[style*="display: none"] {
				opacity: 0;
			}
			.screen-reader-text {
				clip: rect(1px, 1px, 1px, 1px);
				clip-path: inset(50%);
				height: 1px;
				width: 1px;
				margin: -1px;
				overflow: hidden;
				padding: 0;
				position: absolute;
				word-wrap: normal !important;
			}
		</style>
		<?php
	}
}
