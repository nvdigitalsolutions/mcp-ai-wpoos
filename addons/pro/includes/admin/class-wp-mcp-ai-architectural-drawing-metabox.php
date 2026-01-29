<?php
/**
 * Architectural Drawing Metabox for managing drawing-specific fields.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Architectural Drawing Details metabox.
 */
class WP_MCP_AI_Architectural_Drawing_Metabox {

	/**
	 * Initialize the metabox.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_mcp_ai_arch_draw', array( __CLASS__, 'save_metabox' ), 10, 2 );
	}

	/**
	 * Add the metabox.
	 */
	public static function add_metabox() {
		// Check if architectural design toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			return;
		}

		add_meta_box(
			'wp_mcp_ai_arch_drawing_details',
			__( 'Drawing Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_metabox' ),
			'mcp_ai_arch_draw',
			'normal',
			'high'
		);
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function render_metabox( $post ) {
		// Get existing values.
		$drawing_number = get_post_meta( $post->ID, '_arch_drawing_number', true );
		$project_id     = get_post_meta( $post->ID, '_arch_project_id', true );
		$scale          = get_post_meta( $post->ID, '_arch_scale', true );
		$revision       = get_post_meta( $post->ID, '_arch_revision', true );
		$file_url       = get_post_meta( $post->ID, '_arch_file_url', true );
		$file_format    = get_post_meta( $post->ID, '_arch_file_format', true );

		// Set defaults.
		if ( empty( $scale ) ) {
			$scale = '1/4" = 1\'-0"';
		}
		if ( empty( $revision ) ) {
			$revision = 'A';
		}
		if ( empty( $file_format ) ) {
			$file_format = 'pdf';
		}

		// Get projects for dropdown.
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_proj',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_arch_drawing_details', 'wp_mcp_ai_arch_drawing_details_nonce' );
		?>
		<div class="wp-mcp-ai-arch-drawing-details">
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
				<p>
					<label for="arch_drawing_number">
						<strong><?php esc_html_e( 'Drawing Number:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<input
						type="text"
						id="arch_drawing_number"
						name="arch_drawing_number"
						value="<?php echo esc_attr( $drawing_number ); ?>"
						class="widefat"
						placeholder="<?php esc_attr_e( 'A-101', 'mcp-ai-wpoos-pro' ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'e.g., A-101, A-102 (AIA standard)', 'mcp-ai-wpoos-pro' ); ?></span>
				</p>

				<p>
					<label for="arch_revision">
						<strong><?php esc_html_e( 'Revision:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<input
						type="text"
						id="arch_revision"
						name="arch_revision"
						value="<?php echo esc_attr( $revision ); ?>"
						class="widefat"
						placeholder="<?php esc_attr_e( 'A', 'mcp-ai-wpoos-pro' ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'Revision letter or number', 'mcp-ai-wpoos-pro' ); ?></span>
				</p>
			</div>

			<p>
				<label for="arch_project_id">
					<strong><?php esc_html_e( 'Parent Project:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="arch_project_id" name="arch_project_id" class="widefat">
					<option value=""><?php esc_html_e( '— Select Project —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $projects as $project ) : ?>
						<option value="<?php echo esc_attr( $project->ID ); ?>" <?php selected( $project_id, $project->ID ); ?>>
							<?php echo esc_html( $project->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="arch_scale">
					<strong><?php esc_html_e( 'Scale:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="text"
					id="arch_scale"
					name="arch_scale"
					value="<?php echo esc_attr( $scale ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( '1/4" = 1\'-0" or 1:100', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Imperial: 1/4" = 1\'-0" | Metric: 1:100', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="arch_file_url">
					<strong><?php esc_html_e( 'File URL:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="url"
					id="arch_file_url"
					name="arch_file_url"
					value="<?php echo esc_url( $file_url ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( 'https://example.com/drawings/A-101.pdf', 'mcp-ai-wpoos-pro' ); ?>"
				/>
			</p>

			<p>
				<label for="arch_file_format">
					<strong><?php esc_html_e( 'File Format:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="arch_file_format" name="arch_file_format" class="widefat">
					<option value="pdf" <?php selected( $file_format, 'pdf' ); ?>><?php esc_html_e( 'PDF (Universal)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="dwg" <?php selected( $file_format, 'dwg' ); ?>><?php esc_html_e( 'DWG (AutoCAD)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="ifc" <?php selected( $file_format, 'ifc' ); ?>><?php esc_html_e( 'IFC (BIM Standard)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="svg" <?php selected( $file_format, 'svg' ); ?>><?php esc_html_e( 'SVG (Vector)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="png" <?php selected( $file_format, 'png' ); ?>><?php esc_html_e( 'PNG (Raster)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="jpg" <?php selected( $file_format, 'jpg' ); ?>><?php esc_html_e( 'JPG (Raster)', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_metabox( $post_id, $post ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_arch_drawing_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_arch_drawing_details_nonce'] ) ), 'wp_mcp_ai_arch_drawing_details' ) ) {
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

		// Save drawing number.
		if ( isset( $_POST['arch_drawing_number'] ) ) {
			update_post_meta( $post_id, '_arch_drawing_number', sanitize_text_field( wp_unslash( $_POST['arch_drawing_number'] ) ) );
		}

		// Save project ID.
		if ( isset( $_POST['arch_project_id'] ) ) {
			update_post_meta( $post_id, '_arch_project_id', absint( $_POST['arch_project_id'] ) );
		}

		// Save scale.
		if ( isset( $_POST['arch_scale'] ) ) {
			update_post_meta( $post_id, '_arch_scale', sanitize_text_field( wp_unslash( $_POST['arch_scale'] ) ) );
		}

		// Save revision.
		if ( isset( $_POST['arch_revision'] ) ) {
			update_post_meta( $post_id, '_arch_revision', sanitize_text_field( wp_unslash( $_POST['arch_revision'] ) ) );
		}

		// Save file URL.
		if ( isset( $_POST['arch_file_url'] ) ) {
			update_post_meta( $post_id, '_arch_file_url', esc_url_raw( wp_unslash( $_POST['arch_file_url'] ) ) );
		}

		// Save file format.
		if ( isset( $_POST['arch_file_format'] ) ) {
			update_post_meta( $post_id, '_arch_file_format', sanitize_text_field( wp_unslash( $_POST['arch_file_format'] ) ) );
		}
	}
}
