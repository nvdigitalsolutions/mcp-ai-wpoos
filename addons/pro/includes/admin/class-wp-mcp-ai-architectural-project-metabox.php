<?php
/**
 * Architectural Project Metabox for managing project-specific fields.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Architectural Project Details metabox.
 */
class WP_MCP_AI_Architectural_Project_Metabox {

	/**
	 * Initialize the metabox.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_mcp_ai_arch_proj', array( __CLASS__, 'save_metabox' ), 10, 2 );
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
			'wp_mcp_ai_arch_project_details',
			__( 'Project Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_metabox' ),
			'mcp_ai_arch_proj',
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
		$client_name     = get_post_meta( $post->ID, '_arch_client_name', true );
		$location        = get_post_meta( $post->ID, '_arch_location', true );
		$budget          = get_post_meta( $post->ID, '_arch_budget', true );
		$square_footage  = get_post_meta( $post->ID, '_arch_square_footage', true );
		$start_date      = get_post_meta( $post->ID, '_arch_start_date', true );
		$completion_date = get_post_meta( $post->ID, '_arch_completion_date', true );
		$unit_system     = get_post_meta( $post->ID, '_arch_unit_system', true );
		$building_code   = get_post_meta( $post->ID, '_arch_building_code', true );

		// Set defaults.
		if ( empty( $unit_system ) ) {
			$unit_system = 'imperial';
		}
		if ( empty( $building_code ) ) {
			$building_code = 'ibc';
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_arch_project_details', 'wp_mcp_ai_arch_project_details_nonce' );
		?>
		<div class="wp-mcp-ai-arch-project-details">
			<p>
				<label for="arch_client_name">
					<strong><?php esc_html_e( 'Client Name:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="text"
					id="arch_client_name"
					name="arch_client_name"
					value="<?php echo esc_attr( $client_name ); ?>"
					class="widefat"
				/>
			</p>

			<p>
				<label for="arch_location">
					<strong><?php esc_html_e( 'Location:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="text"
					id="arch_location"
					name="arch_location"
					value="<?php echo esc_attr( $location ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( 'City, State/Province, Country', 'mcp-ai-wpoos-pro' ); ?>"
				/>
			</p>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
				<p>
					<label for="arch_budget">
						<strong><?php esc_html_e( 'Budget:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<input
						type="number"
						id="arch_budget"
						name="arch_budget"
						value="<?php echo esc_attr( $budget ); ?>"
						class="widefat"
						step="0.01"
						min="0"
						placeholder="<?php esc_attr_e( 'USD', 'mcp-ai-wpoos-pro' ); ?>"
					/>
				</p>

				<p>
					<label for="arch_square_footage">
						<strong><?php esc_html_e( 'Square Footage:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<input
						type="number"
						id="arch_square_footage"
						name="arch_square_footage"
						value="<?php echo esc_attr( $square_footage ); ?>"
						class="widefat"
						step="0.01"
						min="0"
					/>
				</p>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
				<p>
					<label for="arch_start_date">
						<strong><?php esc_html_e( 'Start Date:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<input
						type="date"
						id="arch_start_date"
						name="arch_start_date"
						value="<?php echo esc_attr( $start_date ); ?>"
						class="widefat"
					/>
				</p>

				<p>
					<label for="arch_completion_date">
						<strong><?php esc_html_e( 'Target Completion Date:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<input
						type="date"
						id="arch_completion_date"
						name="arch_completion_date"
						value="<?php echo esc_attr( $completion_date ); ?>"
						class="widefat"
					/>
				</p>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
				<p>
					<label for="arch_unit_system">
						<strong><?php esc_html_e( 'Unit System:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<select id="arch_unit_system" name="arch_unit_system" class="widefat">
						<option value="imperial" <?php selected( $unit_system, 'imperial' ); ?>><?php esc_html_e( 'Imperial (feet, inches)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="metric" <?php selected( $unit_system, 'metric' ); ?>><?php esc_html_e( 'Metric (meters, centimeters)', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</p>

				<p>
					<label for="arch_building_code">
						<strong><?php esc_html_e( 'Building Code Standard:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label><br>
					<select id="arch_building_code" name="arch_building_code" class="widefat">
						<option value="ibc" <?php selected( $building_code, 'ibc' ); ?>><?php esc_html_e( 'International Building Code (IBC)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="irc" <?php selected( $building_code, 'irc' ); ?>><?php esc_html_e( 'International Residential Code (IRC)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="local" <?php selected( $building_code, 'local' ); ?>><?php esc_html_e( 'Local/Custom', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
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
		if ( ! isset( $_POST['wp_mcp_ai_arch_project_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_arch_project_details_nonce'] ) ), 'wp_mcp_ai_arch_project_details' ) ) {
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

		// Save client name.
		if ( isset( $_POST['arch_client_name'] ) ) {
			update_post_meta( $post_id, '_arch_client_name', sanitize_text_field( wp_unslash( $_POST['arch_client_name'] ) ) );
		}

		// Save location.
		if ( isset( $_POST['arch_location'] ) ) {
			update_post_meta( $post_id, '_arch_location', sanitize_text_field( wp_unslash( $_POST['arch_location'] ) ) );
		}

		// Save budget.
		if ( isset( $_POST['arch_budget'] ) ) {
			update_post_meta( $post_id, '_arch_budget', floatval( $_POST['arch_budget'] ) );
		}

		// Save square footage.
		if ( isset( $_POST['arch_square_footage'] ) ) {
			update_post_meta( $post_id, '_arch_square_footage', floatval( $_POST['arch_square_footage'] ) );
		}

		// Save start date.
		if ( isset( $_POST['arch_start_date'] ) ) {
			update_post_meta( $post_id, '_arch_start_date', sanitize_text_field( wp_unslash( $_POST['arch_start_date'] ) ) );
		}

		// Save completion date.
		if ( isset( $_POST['arch_completion_date'] ) ) {
			update_post_meta( $post_id, '_arch_completion_date', sanitize_text_field( wp_unslash( $_POST['arch_completion_date'] ) ) );
		}

		// Save unit system.
		if ( isset( $_POST['arch_unit_system'] ) ) {
			update_post_meta( $post_id, '_arch_unit_system', sanitize_text_field( wp_unslash( $_POST['arch_unit_system'] ) ) );
		}

		// Save building code.
		if ( isset( $_POST['arch_building_code'] ) ) {
			update_post_meta( $post_id, '_arch_building_code', sanitize_text_field( wp_unslash( $_POST['arch_building_code'] ) ) );
		}
	}
}
