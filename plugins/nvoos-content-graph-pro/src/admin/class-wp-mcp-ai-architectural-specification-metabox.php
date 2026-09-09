<?php
/**
 * Architectural_Specification_Metabox (ecosystem port - Wave F2, architectural-design admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root for the base-class requires (the
 * `__DIR__` trait requires stay portable).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




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
			__( 'Specification Details', 'nvoos-content-graph-pro' ),
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
					<strong><?php esc_html_e( 'Specification Number:', 'nvoos-content-graph-pro' ); ?></strong>
				</label><br>
				<input
					type="text"
					id="arch_spec_number"
					name="arch_spec_number"
					value="<?php echo esc_attr( $spec_number ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( '03 30 00', 'nvoos-content-graph-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'CSI MasterFormat number (e.g., 03 30 00 for Cast-in-Place Concrete)', 'nvoos-content-graph-pro' ); ?></span>
			</p>

			<p>
				<label for="arch_project_id">
					<strong><?php esc_html_e( 'Parent Project:', 'nvoos-content-graph-pro' ); ?></strong>
				</label><br>
				<select id="arch_project_id" name="arch_project_id" class="widefat">
					<option value=""><?php esc_html_e( '— Select Project —', 'nvoos-content-graph-pro' ); ?></option>
					<?php foreach ( $projects as $project ) : ?>
						<option value="<?php echo esc_attr( $project->ID ); ?>" <?php selected( $project_id, $project->ID ); ?>>
							<?php echo esc_html( $project->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<div style="border-top: 1px solid #ddd; margin: 20px 0; padding-top: 20px;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Three-Part Specification Format', 'nvoos-content-graph-pro' ); ?></h4>
				
				<p>
					<label for="arch_spec_part_1">
						<strong><?php esc_html_e( 'Part 1 - General:', 'nvoos-content-graph-pro' ); ?></strong>
					</label><br>
					<textarea
						id="arch_spec_part_1"
						name="arch_spec_part_1"
						rows="5"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Summary, references, submittals, quality assurance, delivery and storage...', 'nvoos-content-graph-pro' ); ?>"
					><?php echo esc_textarea( $part_1 ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Administrative and procedural requirements', 'nvoos-content-graph-pro' ); ?></span>
				</p>

				<p>
					<label for="arch_spec_part_2">
						<strong><?php esc_html_e( 'Part 2 - Products:', 'nvoos-content-graph-pro' ); ?></strong>
					</label><br>
					<textarea
						id="arch_spec_part_2"
						name="arch_spec_part_2"
						rows="5"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Materials, manufacturers, fabrication, finishes, accessories...', 'nvoos-content-graph-pro' ); ?>"
					><?php echo esc_textarea( $part_2 ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Material and product specifications', 'nvoos-content-graph-pro' ); ?></span>
				</p>

				<p>
					<label for="arch_spec_part_3">
						<strong><?php esc_html_e( 'Part 3 - Execution:', 'nvoos-content-graph-pro' ); ?></strong>
					</label><br>
					<textarea
						id="arch_spec_part_3"
						name="arch_spec_part_3"
						rows="5"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Preparation, installation, field quality control, cleaning, protection...', 'nvoos-content-graph-pro' ); ?>"
					><?php echo esc_textarea( $part_3 ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Installation and workmanship requirements', 'nvoos-content-graph-pro' ); ?></span>
				</p>
			</div>

			<div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin-top: 15px;">
				<p style="margin: 0; font-size: 13px;">
					<strong><?php esc_html_e( 'Note:', 'nvoos-content-graph-pro' ); ?></strong>
					<?php esc_html_e( 'Use the main content editor above for the full specification text. These fields provide quick access to the three-part format sections for reference and organization.', 'nvoos-content-graph-pro' ); ?>
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
