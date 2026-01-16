<?php
/**
 * Media Collection Operations Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Operations metabox for media collections.
 *
 * Manages template selection for batch operations.
 */
class WP_MCP_AI_Media_Collection_Metabox_Operations extends WP_MCP_AI_Media_Template_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_media_collection_operations';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Batch Operations & Templates', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'normal';
	}

	/**
	 * Get metabox priority.
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'default';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		// Get assigned templates.
		$templates = get_post_meta( $post->ID, '_mcp_ai_collection_templates', true );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		// Get available templates.
		$available_templates = $this->get_available_templates();

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_media_collection_operations_nonce', 'wp_mcp_ai_media_collection_operations_nonce' );
		?>
		<div class="wp-mcp-ai-media-collection-operations">
			<p class="description" style="margin-bottom: 15px;">
				<?php esc_html_e( 'Select templates to apply to all items in this collection. Templates will be applied in the order listed.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_collection_templates">
							<?php esc_html_e( 'Assigned Templates:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<select name="wp_mcp_ai_collection_templates[]" id="wp_mcp_ai_collection_templates" class="regular-text" multiple size="10" style="height: 200px;">
							<?php if ( empty( $available_templates ) ) : ?>
								<option value="" disabled><?php esc_html_e( 'No templates available. Create templates first.', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php else : ?>
								<?php foreach ( $available_templates as $template_id => $template_info ) : ?>
									<option value="<?php echo esc_attr( $template_id ); ?>" <?php selected( in_array( $template_id, $templates, true ) ); ?>>
										<?php echo esc_html( $template_info['title'] . ' (' . $template_info['operation'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl (Cmd on Mac) to select multiple templates. Templates will be applied in the order they appear.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<?php if ( ! empty( $available_templates ) ) : ?>
							<p style="margin-top: 10px;">
								<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button button-secondary">
									<span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Create New Template', 'mcp-ai-wpoos-pro' ); ?>
								</a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $templates ) ) : ?>
				<div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Processing Pipeline', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'Templates will be applied to collection items in this order:', 'mcp-ai-wpoos-pro' ); ?></p>
					<ol style="margin-left: 20px;">
						<?php foreach ( $templates as $template_id ) : ?>
							<?php if ( isset( $available_templates[ $template_id ] ) ) : ?>
								<li>
									<strong><?php echo esc_html( $available_templates[ $template_id ]['title'] ); ?></strong>
									<span style="color: #646970;">
										- <?php echo esc_html( ucfirst( str_replace( '_', ' ', $available_templates[ $template_id ]['operation'] ) ) ); ?>
									</span>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>

			<div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
				<h4 style="margin-top: 0;">
					<span class="dashicons dashicons-info" style="color: #856404;"></span>
					<?php esc_html_e( 'About Batch Processing', 'mcp-ai-wpoos-pro' ); ?>
				</h4>
				<ul style="margin-left: 20px;">
					<li><?php esc_html_e( 'Templates are applied to ALL items in the collection when you use batch processing tools', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Each template creates a NEW version of each image (originals are preserved)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Processing order matters - templates are applied sequentially', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Use AI tools like <code>process_collection</code> to start batch processing', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Get available templates.
	 *
	 * @return array Array of templates with ID => info.
	 */
	protected function get_available_templates() {
		$templates = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_media_tpl',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$operation = get_post_meta( $post_id, '_mcp_ai_template_operation', true );

				$templates[ $post_id ] = array(
					'title'     => get_the_title(),
					'operation' => $operation ? $operation : __( 'Not set', 'mcp-ai-wpoos-pro' ),
				);
			}
			wp_reset_postdata();
		}

		return $templates;
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_media_collection_operations_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_media_collection_operations_nonce'] ) ), 'wp_mcp_ai_media_collection_operations_nonce' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save assigned templates.
		if ( isset( $_POST['wp_mcp_ai_collection_templates'] ) ) {
			$templates = array_map( 'absint', (array) $_POST['wp_mcp_ai_collection_templates'] );
			// Validate each template exists.
			$valid_templates = array();
			foreach ( $templates as $template_id ) {
				if ( get_post_type( $template_id ) === 'mcp_ai_media_tpl' ) {
					$valid_templates[] = $template_id;
				}
			}
			update_post_meta( $post_id, '_mcp_ai_collection_templates', $valid_templates );
		} else {
			// No templates selected - clear the meta.
			update_post_meta( $post_id, '_mcp_ai_collection_templates', array() );
		}
	}
}
