<?php
/**
 * Remote Connections Metabox for Assistants.
 *
 * Allows enabling/disabling remote site connections for specific assistants.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Metabox for managing remote connections on assistant edit page.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Metabox_Remote_Connections {

	/**
	 * Meta key for storing enabled connections.
	 *
	 * @var string
	 */
	const META_KEY = '_wp_mcp_ai_pro_remote_connections';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_mcp_ai_assistant', array( $this, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Add meta box to assistant edit page.
	 *
	 * @since 1.0.0
	 */
	public function add_meta_box() {
		add_meta_box(
			'wp-mcp-ai-pro-remote-connections',
			__( 'Remote Site Connections', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_meta_box' ),
			'mcp_ai_assistant',
			'side',
			'default'
		);
	}

	/**
	 * Render meta box content.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_pro_remote_connections', 'wp_mcp_ai_pro_remote_connections_nonce' );

		$connections         = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$enabled_connections = get_post_meta( $post->ID, self::META_KEY, true );

		if ( ! is_array( $enabled_connections ) ) {
			$enabled_connections = array();
		}

		if ( empty( $connections ) ) {
			?>
			<p><?php esc_html_e( 'No remote site connections configured.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>">
					<?php esc_html_e( 'Add Remote Site Connection', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
			<?php
			return;
		}

		?>
		<p><?php esc_html_e( 'Select which remote site connections this assistant can access:', 'mcp-ai-wpoos-pro' ); ?></p>

		<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
			<?php foreach ( $connections as $connection_key => $connection ) : ?>
				<?php
				// Use the array key as the connection ID (most reliable).
				$connection_id     = is_string( $connection_key ) ? $connection_key : ( isset( $connection['id'] ) ? $connection['id'] : '' );
				$is_enabled        = in_array( $connection_id, $enabled_connections, true );
				$connection_status = ! empty( $connection['enabled'] ) ? 'enabled' : 'disabled';
				?>
				<div style="margin-bottom: 10px; padding: 8px; background: #f9f9f9; border-left: 3px solid <?php echo 'enabled' === $connection_status ? '#46b450' : '#dc3232'; ?>;">
					<label style="display: block; margin-bottom: 5px;">
						<input type="checkbox" name="wp_mcp_ai_pro_remote_connections[]" value="<?php echo esc_attr( $connection_id ); ?>" <?php checked( $is_enabled ); ?> <?php disabled( 'disabled' === $connection_status ); ?>>
						<strong><?php echo esc_html( $connection['name'] ); ?></strong>
						<?php if ( 'disabled' === $connection_status ) : ?>
							<span style="color: #dc3232; font-size: 11px;">(<?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?>)</span>
						<?php endif; ?>
					</label>
					<div style="font-size: 11px; color: #666; margin-left: 22px;">
						<?php echo esc_html( $connection['url'] ); ?>
						<?php if ( ! empty( $connection['has_woocommerce'] ) ) : ?>
							<br><span style="color: #46b450;">● <?php esc_html_e( 'WooCommerce enabled', 'mcp-ai-wpoos-pro' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p style="margin-top: 10px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" style="font-size: 11px;">
				<?php esc_html_e( 'Manage Remote Site Connections', 'mcp-ai-wpoos-pro' ); ?>
			</a>
		</p>

		<p class="description">
			<?php esc_html_e( 'Only enabled connections can be selected. Remote connections allow this assistant to query posts, products, orders, and other data from external WordPress/WooCommerce sites.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta_box( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['wp_mcp_ai_pro_remote_connections_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_pro_remote_connections_nonce'] ) ), 'wp_mcp_ai_pro_remote_connections' ) ) {
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

		// Save enabled connections.
		$enabled_connections = array();

		if ( isset( $_POST['wp_mcp_ai_pro_remote_connections'] ) && is_array( $_POST['wp_mcp_ai_pro_remote_connections'] ) ) {
			$enabled_connections = array_map( 'sanitize_key', wp_unslash( $_POST['wp_mcp_ai_pro_remote_connections'] ) );
		}

		update_post_meta( $post_id, self::META_KEY, $enabled_connections );
	}
}

// Initialize metabox.
if ( is_admin() ) {
	new WP_MCP_AI_Pro_Metabox_Remote_Connections();
}
