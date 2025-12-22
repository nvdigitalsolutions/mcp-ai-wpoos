<?php
/**
 * Primary Roles Metabox for Assistants.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Primary Roles metabox for assistant posts.
 *
 * Allows selecting up to 3 professions as primary roles for programmatic prompt construction.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Metabox_Primary_Roles extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class for constants.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_primary_roles';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Primary Roles', 'wp-mcp-ai' );
	}

	/**
	 * Check if current user can view this metabox.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function can_view() {
		global $post;
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( 'wp_mcp_ai_primary_roles_meta', 'wp_mcp_ai_primary_roles_meta_nonce' );

		// Get currently assigned primary roles.
		$primary_roles = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, true );
		if ( ! is_array( $primary_roles ) ) {
			$primary_roles = array();
		}

		// Get all available professions.
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		?>
		<div class="wp-mcp-ai-primary-roles">
			<p class="description">
				<?php esc_html_e( 'Select up to 3 professions to define the primary roles for this assistant. Role instructions will be programmatically combined to create the system prompt.', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( empty( $professions ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL to create profession */
						esc_html__( 'No professions found. Please %s first.', 'wp-mcp-ai' ),
						'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create a profession', 'wp-mcp-ai' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<div class="wp-mcp-ai-profession-search-wrapper" style="margin-bottom: 15px;">
					<input 
						type="text" 
						id="wp-mcp-ai-profession-search" 
						class="regular-text" 
						placeholder="<?php esc_attr_e( 'Search professions...', 'wp-mcp-ai' ); ?>"
						aria-label="<?php esc_attr_e( 'Search professions', 'wp-mcp-ai' ); ?>"
					/>
					<p class="description" style="margin-top: 5px;">
						<?php esc_html_e( 'Type to filter the profession list below', 'wp-mcp-ai' ); ?>
					</p>
				</div>
				<table class="widefat striped" id="wp-mcp-ai-professions-table">
					<thead>
						<tr>
							<th style="width: 40px;"></th>
							<th><?php esc_html_e( 'Profession', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Category', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $professions as $profession ) : ?>
							<?php
							$category    = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_category', true );
							$is_selected = in_array( $profession->ID, $primary_roles, true );
							$description = '';
							if ( $profession->post_excerpt ) {
								$description = $profession->post_excerpt;
							} elseif ( $profession->post_content ) {
								$description = wp_trim_words( wp_strip_all_tags( $profession->post_content ), 15 );
							}
							?>
							<tr class="wp-mcp-ai-profession-row"
								data-profession-title="<?php echo esc_attr( strtolower( $profession->post_title ) ); ?>"
								data-profession-category="<?php echo esc_attr( strtolower( $category ) ); ?>"
								data-profession-description="<?php echo esc_attr( strtolower( $description ) ); ?>">
								<td>
									<input 
										type="checkbox" 
										name="wp_mcp_ai_primary_roles[]" 
										value="<?php echo esc_attr( $profession->ID ); ?>"
										class="wp-mcp-ai-primary-role-checkbox"
										<?php checked( $is_selected ); ?>
									/>
								</td>
								<td><strong><?php echo esc_html( $profession->post_title ); ?></strong></td>
								<td>
									<?php if ( $category ) : ?>
										<span class="wp-mcp-ai-category-badge"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $category ) ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $description ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top: 10px;">
					<?php esc_html_e( 'Maximum 3 roles can be selected. Additional selections will uncheck the first selected role.', 'wp-mcp-ai' ); ?>
				</p>

				<style>
					.wp-mcp-ai-category-badge {
						display: inline-block;
						padding: 2px 8px;
						border-radius: 3px;
						background: #f0f0f1;
						font-size: 12px;
					}
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

				<script type="text/javascript">
				( function() {
					var maxRoles = 3;
					var searchDebounceTimer = null;
					
					document.addEventListener( 'DOMContentLoaded', function() {
						var checkboxes = document.querySelectorAll( '.wp-mcp-ai-primary-role-checkbox' );
						var searchInput = document.getElementById( 'wp-mcp-ai-profession-search' );
						var professionRows = document.querySelectorAll( '.wp-mcp-ai-profession-row' );
						
						// Handle checkbox selection with max limit.
						checkboxes.forEach( function( checkbox ) {
							checkbox.addEventListener( 'change', function() {
								var checked = document.querySelectorAll( '.wp-mcp-ai-primary-role-checkbox:checked' );
								
								if ( checked.length > maxRoles ) {
									// Uncheck the first checked item.
									checked[0].checked = false;
								}
							} );
						} );
						
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
								}, 300 );
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
			<?php endif; ?>
		</div>
		<?php
	}
}
