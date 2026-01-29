<?php
/**
 * Architectural Specification Metabox for managing specification-specific fields.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Architectural Specification Details metabox.
 */
class WP_MCP_AI_Architectural_Specification_Metabox {

	/**
	 * Initialize the metabox.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_mcp_ai_arch_spec', array( __CLASS__, 'save_metabox' ), 10, 2 );
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
			'wp_mcp_ai_arch_spec_details',
			__( 'Specification Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_metabox' ),
			'mcp_ai_arch_spec',
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
		$spec_number = get_post_meta( $post->ID, '_arch_spec_number', true );
		$project_id  = get_post_meta( $post->ID, '_arch_project_id', true );
		$part_1      = get_post_meta( $post->ID, '_arch_spec_part_1', true );
		$part_2      = get_post_meta( $post->ID, '_arch_spec_part_2', true );
		$part_3      = get_post_meta( $post->ID, '_arch_spec_part_3', true );

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
		wp_nonce_field( 'wp_mcp_ai_arch_spec_details', 'wp_mcp_ai_arch_spec_details_nonce' );
		?>
		<div class="wp-mcp-ai-arch-spec-details">
			<p>
				<label for="arch_spec_number">
					<strong><?php esc_html_e( 'Specification Number:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="text"
					id="arch_spec_number"
					name="arch_spec_number"
					value="<?php echo esc_attr( $spec_number ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( '03 30 00', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'CSI MasterFormat number (e.g., 03 30 00 for Cast-in-Place Concrete)', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

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

			<div style="border-top: 1px solid #ddd; margin: 20px 0; padding-top: 20px;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Three-Part Specification Format', 'mcp-ai-wpoos-pro' ); ?></h4>
				
				<p>
					<label for="arch_spec_part_1">
						<strong><?php esc_html_e( 'Part 1 - General:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<textarea
						id="arch_spec_part_1"
						name="arch_spec_part_1"
						rows="5"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Summary, references, submittals, quality assurance, delivery and storage...', 'mcp-ai-wpoos-pro' ); ?>"
					><?php echo esc_textarea( $part_1 ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Administrative and procedural requirements', 'mcp-ai-wpoos-pro' ); ?></span>
				</p>

				<p>
					<label for="arch_spec_part_2">
						<strong><?php esc_html_e( 'Part 2 - Products:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<textarea
						id="arch_spec_part_2"
						name="arch_spec_part_2"
						rows="5"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Materials, manufacturers, fabrication, finishes, accessories...', 'mcp-ai-wpoos-pro' ); ?>"
					><?php echo esc_textarea( $part_2 ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Material and product specifications', 'mcp-ai-wpoos-pro' ); ?></span>
				</p>

				<p>
					<label for="arch_spec_part_3">
						<strong><?php esc_html_e( 'Part 3 - Execution:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<textarea
						id="arch_spec_part_3"
						name="arch_spec_part_3"
						rows="5"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Preparation, installation, field quality control, cleaning, protection...', 'mcp-ai-wpoos-pro' ); ?>"
					><?php echo esc_textarea( $part_3 ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Installation and workmanship requirements', 'mcp-ai-wpoos-pro' ); ?></span>
				</p>
			</div>

			<div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin-top: 15px;">
				<p style="margin: 0; font-size: 13px;">
					<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( 'Use the main content editor above for the full specification text. These fields provide quick access to the three-part format sections for reference and organization.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>
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
		if ( ! isset( $_POST['wp_mcp_ai_arch_spec_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_arch_spec_details_nonce'] ) ), 'wp_mcp_ai_arch_spec_details' ) ) {
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

		// Save spec number.
		if ( isset( $_POST['arch_spec_number'] ) ) {
			update_post_meta( $post_id, '_arch_spec_number', sanitize_text_field( wp_unslash( $_POST['arch_spec_number'] ) ) );
		}

		// Save project ID.
		if ( isset( $_POST['arch_project_id'] ) ) {
			update_post_meta( $post_id, '_arch_project_id', absint( $_POST['arch_project_id'] ) );
		}

		// Save Part 1.
		if ( isset( $_POST['arch_spec_part_1'] ) ) {
			update_post_meta( $post_id, '_arch_spec_part_1', wp_kses_post( wp_unslash( $_POST['arch_spec_part_1'] ) ) );
		}

		// Save Part 2.
		if ( isset( $_POST['arch_spec_part_2'] ) ) {
			update_post_meta( $post_id, '_arch_spec_part_2', wp_kses_post( wp_unslash( $_POST['arch_spec_part_2'] ) ) );
		}

		// Save Part 3.
		if ( isset( $_POST['arch_spec_part_3'] ) ) {
			update_post_meta( $post_id, '_arch_spec_part_3', wp_kses_post( wp_unslash( $_POST['arch_spec_part_3'] ) ) );
		}
	}
}
