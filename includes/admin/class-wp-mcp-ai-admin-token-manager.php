<?php
/**
 * Admin Token Manager for WP oOS.
 *
 * This class provides a centralized UI for managing credentials/tokens across all assistants.
 * It follows industry standards for token management (similar to GitHub, Stripe, Auth0) including:
 * - Token lifecycle management (create, view, revoke, delete)
 * - Show token only once after creation (security best practice)
 * - Clear listing with metadata (creation date, status, associated assistant)
 * - Audit trail information (who created/revoked)
 * - Bulk visibility across all assistants
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-credentials.php';
}

/**
 * Renders the centralized management UI for assistant credentials/tokens.
 */
class WP_MCP_AI_Admin_Token_Manager {
	const PAGE_SLUG = 'wp-mcp-ai-token-manager';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 15 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wp_mcp_ai_token_manager_revoke', array( $this, 'handle_revoke_token' ) );
		add_action( 'admin_post_wp_mcp_ai_token_manager_delete', array( $this, 'handle_delete_token' ) );
	}

	/**
	 * Register the token manager page under the WP oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'WP oOS Token Manager', 'wp-mcp-ai' ),
			__( 'Token Manager', 'wp-mcp-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue lightweight styles for the token manager table.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		$inline_css = '.wp-mcp-ai-token-manager__intro{margin:1.5rem 0;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;}'
			. '.wp-mcp-ai-token-manager__intro p{margin:0.5rem 0;}'
			. '.wp-mcp-ai-token-manager__intro p:first-child{margin-top:0;}'
			. '.wp-mcp-ai-token-manager__intro p:last-child{margin-bottom:0;}'
			. '.wp-mcp-ai-token-manager__stats{display:flex;gap:1.5rem;margin:1.5rem 0;flex-wrap:wrap;}'
			. '.wp-mcp-ai-token-manager__stat{padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex:1;min-width:120px;}'
			. '.wp-mcp-ai-token-manager__stat-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-token-manager__stat-value{font-size:1.75rem;font-weight:600;color:#1d2327;}'
			. '.wp-mcp-ai-token-manager__table{margin-top:1.5rem;border-collapse:collapse;width:100%;}'
			. '.wp-mcp-ai-token-manager__table th,.wp-mcp-ai-token-manager__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-token-manager__table th{background:#f8f9ff;font-weight:600;}'
			. '.wp-mcp-ai-token-manager__empty{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-token-manager__empty h3{margin-top:0;}'
			. '.wp-mcp-ai-token-manager__empty ul{margin-left:1.5rem;}'
			. '.wp-mcp-ai-token-manager__actions form{display:inline-block;margin-right:0.5rem;}'
			. '.wp-mcp-ai-token-manager__status{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;}'
			. '.wp-mcp-ai-token-manager__status--active{background:#d5f0db;color:#0a5f1a;}'
			. '.wp-mcp-ai-token-manager__status--revoked{background:#fef7e0;color:#8b6c00;}'
			. '.wp-mcp-ai-token-manager__assistant-link{text-decoration:none;}'
			. '.wp-mcp-ai-token-manager__assistant-link:hover{text-decoration:underline;}'
			. '.wp-mcp-ai-token-manager__security-note{margin-top:1.5rem;padding:1rem;background:#fff8e5;border-left:4px solid #dba617;}'
			. '.wp-mcp-ai-token-manager__security-note p{margin:0;}';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline-only style registration.
		wp_register_style( 'wp-mcp-ai-token-manager-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-token-manager-inline' );
		wp_add_inline_style( 'wp-mcp-ai-token-manager-inline', $inline_css );
	}

	/**
	 * Handle revocation of a token from the manager.
	 */
	public function handle_revoke_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage tokens.', 'wp-mcp-ai' ) );
		}

		$assistant_id  = isset( $_POST['assistant_id'] ) ? absint( wp_unslash( $_POST['assistant_id'] ) ) : 0;
		$credential_id = isset( $_POST['credential_id'] ) ? sanitize_key( wp_unslash( $_POST['credential_id'] ) ) : '';

		if ( 0 === $assistant_id || '' === $credential_id ) {
			wp_die( esc_html__( 'Missing token identifier.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_token_manager_revoke_' . $assistant_id . '_' . $credential_id );

		$result = WP_MCP_AI_Credentials::revoke_credential( $assistant_id, $credential_id, get_current_user_id() );

		$redirect = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => is_wp_error( $result ) ? 'error' : 'revoked',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle deletion of a token from the manager.
	 */
	public function handle_delete_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage tokens.', 'wp-mcp-ai' ) );
		}

		$assistant_id  = isset( $_POST['assistant_id'] ) ? absint( wp_unslash( $_POST['assistant_id'] ) ) : 0;
		$credential_id = isset( $_POST['credential_id'] ) ? sanitize_key( wp_unslash( $_POST['credential_id'] ) ) : '';

		if ( 0 === $assistant_id || '' === $credential_id ) {
			wp_die( esc_html__( 'Missing token identifier.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_token_manager_delete_' . $assistant_id . '_' . $credential_id );

		$result = WP_MCP_AI_Credentials::delete_credential( $assistant_id, $credential_id, get_current_user_id() );

		$redirect = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => is_wp_error( $result ) ? 'error' : 'deleted',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Get all credentials across all assistants.
	 *
	 * @return array Array of credentials with assistant context.
	 */
	private function get_all_credentials() {
		$all_credentials = array();

		$assistants = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Credentials::ASSISTANT_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $assistants as $assistant_id ) {
			$credentials = WP_MCP_AI_Credentials::get_credentials( $assistant_id );
			$assistant   = get_post( $assistant_id );

			if ( ! $assistant ) {
				continue;
			}

			foreach ( $credentials as $credential ) {
				$all_credentials[] = array_merge(
					$credential,
					array(
						'assistant_id'    => $assistant_id,
						'assistant_title' => $assistant->post_title,
						// Pre-process timestamp for efficient sorting.
						'_sort_timestamp' => isset( $credential['created_at'] ) ? strtotime( $credential['created_at'] ) : 0,
					)
				);
			}
		}

		// Sort by created_at descending (newest first) using pre-processed timestamps.
		usort(
			$all_credentials,
			function ( $a, $b ) {
				return $b['_sort_timestamp'] - $a['_sort_timestamp'];
			}
		);

		return $all_credentials;
	}

	/**
	 * Get statistics for display.
	 *
	 * @param array $credentials Array of credentials.
	 * @return array Statistics array.
	 */
	private function get_statistics( $credentials ) {
		$total   = count( $credentials );
		$active  = 0;
		$revoked = 0;

		foreach ( $credentials as $credential ) {
			if ( ! empty( $credential['revoked_at'] ) ) {
				++$revoked;
			} else {
				++$active;
			}
		}

		$assistants_with_tokens = array();
		foreach ( $credentials as $credential ) {
			if ( isset( $credential['assistant_id'] ) ) {
				$assistants_with_tokens[ $credential['assistant_id'] ] = true;
			}
		}

		return array(
			'total'      => $total,
			'active'     => $active,
			'revoked'    => $revoked,
			'assistants' => count( $assistants_with_tokens ),
		);
	}

	/**
	 * Get display name for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string User display name or 'System'.
	 */
	private function get_user_display_name( $user_id ) {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return __( 'System', 'wp-mcp-ai' );
		}

		$user = get_userdata( $user_id );
		if ( $user ) {
			return $user->display_name;
		}

		return __( 'Unknown', 'wp-mcp-ai' );
	}

	/**
	 * Render the token manager page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$credentials = $this->get_all_credentials();
		$stats       = $this->get_statistics( $credentials );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP oOS Token Manager', 'wp-mcp-ai' ); ?></h1>

			<div class="wp-mcp-ai-token-manager__intro">
				<p><strong><?php esc_html_e( 'About Token Manager', 'wp-mcp-ai' ); ?></strong></p>
				<p><?php esc_html_e( 'The Token Manager provides centralized control over all external agent access tokens issued for your AI Assistants. These tokens allow external applications (like Codex CLI, MCP clients, or custom integrations) to authenticate with specific assistants.', 'wp-mcp-ai' ); ?></p>
				<p><?php esc_html_e( 'Industry best practices implemented: Tokens are shown only once upon creation, support granular revocation, and include full audit trails. Revoked tokens remain visible for security auditing but cannot be used for authentication.', 'wp-mcp-ai' ); ?></p>
			</div>

			<?php
			// Display action status messages.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display after redirect.
			if ( isset( $_GET['action'] ) ) :
				$action_result = sanitize_key( wp_unslash( $_GET['action'] ) );
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$valid_actions  = array( 'revoked', 'deleted', 'error' );
				$action_notices = array(
					'revoked' => array(
						'type'    => 'success',
						'message' => __( 'Token successfully revoked. It can no longer be used for authentication.', 'wp-mcp-ai' ),
					),
					'deleted' => array(
						'type'    => 'success',
						'message' => __( 'Token permanently deleted.', 'wp-mcp-ai' ),
					),
					'error'   => array(
						'type'    => 'error',
						'message' => __( 'The requested action could not be completed. The token may have already been modified or does not exist.', 'wp-mcp-ai' ),
					),
				);

				// Only display notice for valid, expected action values.
				if ( in_array( $action_result, $valid_actions, true ) && isset( $action_notices[ $action_result ] ) ) :
					$notice = $action_notices[ $action_result ];
					?>
					<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
						<p><?php echo esc_html( $notice['message'] ); ?></p>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php if ( ! empty( $credentials ) ) : ?>
				<div class="wp-mcp-ai-token-manager__stats">
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php esc_html_e( 'Total Tokens', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo esc_html( $stats['total'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo esc_html( $stats['active'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php esc_html_e( 'Revoked', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo esc_html( $stats['revoked'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php esc_html_e( 'Assistants', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo esc_html( $stats['assistants'] ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( empty( $credentials ) ) : ?>
				<div class="wp-mcp-ai-token-manager__empty">
					<h3><?php esc_html_e( 'No Tokens Issued', 'wp-mcp-ai' ); ?></h3>
					<p><?php esc_html_e( 'No external access tokens have been issued for any assistant yet. Tokens can be created from individual assistant edit screens.', 'wp-mcp-ai' ); ?></p>
					<p><strong><?php esc_html_e( 'To create a token:', 'wp-mcp-ai' ); ?></strong></p>
					<ul>
						<li><?php esc_html_e( 'Go to AI Assistants in the WordPress admin menu', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Edit the assistant you want to enable external access for', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Scroll to the "Credentials" metabox', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Click "Generate Credential" to create a new token', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p><strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong> <?php esc_html_e( 'Tokens are only displayed once upon creation. Store them securely immediately after generation.', 'wp-mcp-ai' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-mcp-ai-token-manager__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Token ID', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Assistant', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created By', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $credentials as $credential ) : ?>
							<?php
							$is_revoked   = ! empty( $credential['revoked_at'] );
							$created_at   = ! empty( $credential['created_at'] ) ? get_date_from_gmt( $credential['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Unknown', 'wp-mcp-ai' );
							$created_by   = $this->get_user_display_name( isset( $credential['created_by'] ) ? $credential['created_by'] : 0 );
							$assistant_id = isset( $credential['assistant_id'] ) ? absint( $credential['assistant_id'] ) : 0;
							$edit_link    = get_edit_post_link( $assistant_id );
							?>
							<tr>
								<td><code><?php echo esc_html( $credential['id'] ); ?></code></td>
								<td>
									<?php if ( $edit_link ) : ?>
										<a href="<?php echo esc_url( $edit_link ); ?>" class="wp-mcp-ai-token-manager__assistant-link">
											<?php echo esc_html( $credential['assistant_title'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $credential['assistant_title'] ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_revoked ) : ?>
										<span class="wp-mcp-ai-token-manager__status wp-mcp-ai-token-manager__status--revoked">
											<?php esc_html_e( 'Revoked', 'wp-mcp-ai' ); ?>
										</span>
										<?php
										$revoked_at = get_date_from_gmt( $credential['revoked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
										$revoked_by = $this->get_user_display_name( isset( $credential['revoked_by'] ) ? $credential['revoked_by'] : 0 );
										?>
										<br><small>
										<?php
										/* translators: 1: revocation date, 2: user who revoked */
										printf( esc_html__( '%1$s by %2$s', 'wp-mcp-ai' ), esc_html( $revoked_at ), esc_html( $revoked_by ) );
										?>
										</small>
									<?php else : ?>
										<span class="wp-mcp-ai-token-manager__status wp-mcp-ai-token-manager__status--active">
											<?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $created_at ); ?></td>
								<td><?php echo esc_html( $created_by ); ?></td>
								<td class="wp-mcp-ai-token-manager__actions">
									<?php if ( ! $is_revoked ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to revoke this token? It will no longer work for authentication.', 'wp-mcp-ai' ) ); ?>');">
											<input type="hidden" name="action" value="wp_mcp_ai_token_manager_revoke" />
											<input type="hidden" name="assistant_id" value="<?php echo esc_attr( $assistant_id ); ?>" />
											<input type="hidden" name="credential_id" value="<?php echo esc_attr( $credential['id'] ); ?>" />
											<?php wp_nonce_field( 'wp_mcp_ai_token_manager_revoke_' . $assistant_id . '_' . $credential['id'] ); ?>
											<?php submit_button( __( 'Revoke', 'wp-mcp-ai' ), 'secondary', '', false ); ?>
										</form>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete this token? This action cannot be undone.', 'wp-mcp-ai' ) ); ?>');">
										<input type="hidden" name="action" value="wp_mcp_ai_token_manager_delete" />
										<input type="hidden" name="assistant_id" value="<?php echo esc_attr( $assistant_id ); ?>" />
										<input type="hidden" name="credential_id" value="<?php echo esc_attr( $credential['id'] ); ?>" />
										<?php wp_nonce_field( 'wp_mcp_ai_token_manager_delete_' . $assistant_id . '_' . $credential['id'] ); ?>
										<?php submit_button( __( 'Delete', 'wp-mcp-ai' ), 'delete', '', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="wp-mcp-ai-token-manager__security-note">
					<p><strong><?php esc_html_e( 'Security Best Practices:', 'wp-mcp-ai' ); ?></strong></p>
					<p><?php esc_html_e( 'Regularly review active tokens and revoke any that are no longer needed. Tokens should be treated like passwords and stored securely. If you suspect a token has been compromised, revoke it immediately and generate a new one from the assistant edit screen.', 'wp-mcp-ai' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
