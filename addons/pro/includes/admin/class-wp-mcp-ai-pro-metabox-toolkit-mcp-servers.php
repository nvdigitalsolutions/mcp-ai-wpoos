<?php
/**
 * Toolkit MCP Servers Metabox for Assistants.
 *
 * Renders on the mcp_ai_assistant edit screen and lets editors choose which
 * per-toolkit MCP servers this assistant is allowed to invoke. Enabled servers
 * are persisted in post-meta so the REST controller can gate access per-
 * assistant when needed.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metabox class — Toolkit MCP Servers on the assistant edit screen.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers {

	/**
	 * Meta key storing the allowed server slugs for this assistant.
	 *
	 * Value: array<string> (list of server slugs the assistant may invoke).
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const META_KEY = '_wp_mcp_ai_pro_allowed_mcp_servers';

	/**
	 * Nonce action used on save.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_pro_toolkit_mcp_servers';

	/**
	 * Nonce field name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const NONCE_FIELD = 'wp_mcp_ai_pro_toolkit_mcp_servers_nonce';

	/**
	 * Bind WordPress hooks.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_mcp_ai_assistant', array( $this, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Register the meta box on the assistant edit screen.
	 *
	 * @since 1.4.0
	 */
	public function add_meta_box() {
		add_meta_box(
			'wp-mcp-ai-pro-toolkit-mcp-servers',
			__( 'Toolkit MCP Servers', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_meta_box' ),
			'mcp_ai_assistant',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// Retrieve persisted allowed slugs for this assistant.
		$allowed = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_array( $allowed ) ) {
			$allowed = array();
		}

		// Early-exit when the registry class is not available (plain env).
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
			echo '<p>' . esc_html__( 'Toolkit MCP Server registry not available.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		$servers = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->all();

		if ( empty( $servers ) ) {
			echo '<p>' . esc_html__( 'No toolkit MCP servers are registered.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		?>
		<p class="description" style="margin-bottom:8px;">
			<?php esc_html_e( 'Select which toolkit MCP servers this assistant may invoke. Leave all unchecked to allow access to all enabled servers.', 'mcp-ai-wpoos-pro' ); ?>
		</p>

		<div style="max-height:280px;overflow-y:auto;border:1px solid #ddd;padding:8px;background:#fff;">
			<?php foreach ( $servers as $slug => $server ) : ?>
				<?php
				$is_checked   = in_array( $slug, $allowed, true );
				$is_server_on = $server instanceof WP_MCP_AI_Toolkit_Server_Base && $server->is_enabled();
				$server_label = ( $server instanceof WP_MCP_AI_Toolkit_Server_Interface ) ? $server->get_name() : $slug;
				$border_color = $is_server_on ? '#46b450' : '#dc3232';
				$status_label = $is_server_on ? __( 'enabled', 'mcp-ai-wpoos-pro' ) : __( 'disabled', 'mcp-ai-wpoos-pro' );
				?>
				<div style="margin-bottom:8px;padding:6px;background:#f9f9f9;border-left:3px solid <?php echo esc_attr( $border_color ); ?>;">
					<label style="display:block;">
						<input type="checkbox"
							name="wp_mcp_ai_pro_allowed_mcp_servers[]"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( $is_checked ); ?>
						>
						<strong><?php echo esc_html( $server_label ); ?></strong>
						<span style="font-size:11px;color:#888;">
							(<?php echo esc_html( $status_label ); ?>)
						</span>
					</label>
				</div>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:8px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-toolkit-mcp-servers' ) ); ?>"
				style="font-size:11px;">
				<?php esc_html_e( 'Manage Toolkit MCP Servers →', 'mcp-ai-wpoos-pro' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Persist the meta box selection on save.
	 *
	 * @since 1.4.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta_box( $post_id, $post ) {
		// Bail on autosave / revision.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		// Nonce check.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}
		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['wp_mcp_ai_pro_allowed_mcp_servers'] )
			&& is_array( $_POST['wp_mcp_ai_pro_allowed_mcp_servers'] )
		) {
			$slugs = array_map(
				'sanitize_key',
				wp_unslash( $_POST['wp_mcp_ai_pro_allowed_mcp_servers'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map above.
			);
			update_post_meta( $post_id, self::META_KEY, $slugs );
		} else {
			// Empty array = allow all.
			delete_post_meta( $post_id, self::META_KEY );
		}
	}

	/**
	 * Return the list of allowed server slugs for a given assistant.
	 *
	 * An empty array means "allow all enabled servers".
	 *
	 * @since 1.4.0
	 *
	 * @param int $post_id Assistant post ID.
	 * @return string[] Allowed server slugs.
	 */
	public static function get_allowed_servers( $post_id ) {
		$allowed = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $allowed ) ? $allowed : array();
	}
}
