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
				<?php
				// Render search field using helper.
				WP_MCP_AI_Profession_Search_Helper::render_search_field();
				?>
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
							?>
							<tr class="wp-mcp-ai-profession-row" <?php echo WP_MCP_AI_Profession_Search_Helper::get_profession_data_attributes( $profession ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
									<?php
									if ( $profession->post_excerpt ) {
										echo esc_html( $profession->post_excerpt );
									} elseif ( $profession->post_content ) {
										echo esc_html( wp_trim_words( wp_strip_all_tags( $profession->post_content ), 15 ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top: 10px;">
					<?php esc_html_e( 'Maximum 3 roles can be selected. Additional selections will uncheck the first selected role.', 'wp-mcp-ai' ); ?>
				</p>

				<?php
				// Render search styles and script using helper.
				WP_MCP_AI_Profession_Search_Helper::render_search_styles();
				WP_MCP_AI_Profession_Search_Helper::render_search_script();
				?>

				<style>
					.wp-mcp-ai-category-badge {
						display: inline-block;
						padding: 2px 8px;
						border-radius: 3px;
						background: #f0f0f1;
						font-size: 12px;
					}
				</style>

				<script type="text/javascript">
				( function() {
					var maxRoles = 3;
					
					document.addEventListener( 'DOMContentLoaded', function() {
						var checkboxes = document.querySelectorAll( '.wp-mcp-ai-primary-role-checkbox' );
						
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
					} );
				} )();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}
}
