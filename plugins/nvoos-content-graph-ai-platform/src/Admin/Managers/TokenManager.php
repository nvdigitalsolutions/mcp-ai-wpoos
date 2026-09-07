<?php
/**
 * Token manager page (Wave E-UI-2, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_Token_Manager`
 * (`includes/admin/class-wp-mcp-ai-admin-token-manager.php`):
 * byte-identical page surface — the `wp-mcp-ai-token-manager` page
 * slug (priority 15, `manage_options`), the two `admin_post_*`
 * handlers (`wp_mcp_ai_token_manager_revoke`,
 * `wp_mcp_ai_token_manager_delete`) with their per-token nonces,
 * the inline-stylesheet enqueue + the `restrictions-admin.js` asset
 * with the `wpMcpAiRestrictionsAdmin` localized config envelope, the
 * intro / restricted-users panel / action notices / statistics cards
 * / credentials table (status pill, revocation audit line, per-row
 * revoke + delete forms) / empty state / security-note render
 * surface, the credentials listing (all assistants, newest-first via
 * `_sort_timestamp`), the statistics shape
 * (total/active/revoked/assistants), the user display-name helper,
 * and the revoke/delete redirect flows.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Managers\`).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerManagers()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`).
 *  - The credentials store resolves per install mode via the
 *    `credentials_class()` seam (`defined( 'WP_MCP_AI_PATH' )`
 *    discriminator): base `WP_MCP_AI_Credentials` monolith / null
 *    standalone (no credentials store ported yet) — the listing
 *    degrades to the byte-identical "No Tokens Issued" empty state
 *    and the revoke/delete handlers redirect with `action=error`
 *    (documented degradation).
 *  - The restrictions panel probe is boot-gated
 *    (`defined( 'WP_MCP_AI_PATH' ) && class_exists( ... )`) —
 *    standalone hides the panel (documented).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - The page's own asset (restrictions-admin.js) is copied
 *    byte-identically into the platform asset tree; the inline CSS is
 *    byte-identical.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Managers
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Managers;

/**
 * Renders the centralized management UI for assistant credentials/tokens.
 *
 * @since 2.0.0
 */
class TokenManager {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-token-manager';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_page' ), 15 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'admin_post_wp_mcp_ai_token_manager_revoke', array( $this, 'handle_revoke_token' ) );
		\add_action( 'admin_post_wp_mcp_ai_token_manager_delete', array( $this, 'handle_delete_token' ) );
	}

	/**
	 * Credentials store class name (per-mode seam).
	 *
	 * Monolith resolves the base store; standalone has no ported
	 * credentials store yet (documented degradation — see the class
	 * docblock).
	 *
	 * @return string|null
	 */
	protected static function credentials_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Credentials' ) ) {
			return 'WP_MCP_AI_Credentials';
		}

		return null;
	}

	/**
	 * Register the token manager page under the NV Platform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'NV oOS Token Manager', 'nvoos-content-graph-ai-platform' ),
			__( 'Token Manager', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue lightweight styles for the token manager table.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
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
			. '.wp-mcp-ai-token-manager__table-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch;margin-top:1.5rem;}'
			. '.wp-mcp-ai-token-manager__table{border-collapse:collapse;width:100%;min-width:700px;}'
			. '.wp-mcp-ai-token-manager__table th,.wp-mcp-ai-token-manager__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-token-manager__table th{background:#f8f9ff;font-weight:600;white-space:nowrap;}'
			. '.wp-mcp-ai-token-manager__empty{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-token-manager__empty h3{margin-top:0;}'
			. '.wp-mcp-ai-token-manager__empty ul{margin-left:1.5rem;}'
			. '.wp-mcp-ai-token-manager__actions form{display:inline-block;margin-right:0.5rem;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-token-manager__status{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;}'
			. '.wp-mcp-ai-token-manager__status--active{background:#d5f0db;color:#0a5f1a;}'
			. '.wp-mcp-ai-token-manager__status--revoked{background:#fef7e0;color:#8b6c00;}'
			. '.wp-mcp-ai-token-manager__assistant-link{text-decoration:none;}'
			. '.wp-mcp-ai-token-manager__assistant-link:hover{text-decoration:underline;}'
			. '.wp-mcp-ai-token-manager__security-note{margin-top:1.5rem;padding:1rem;background:#fff8e5;border-left:4px solid #dba617;}'
			. '.wp-mcp-ai-token-manager__security-note p{margin:0;}'
			. '@media screen and (max-width:782px){'
			. '.wp-mcp-ai-token-manager__stats{flex-direction:column;gap:1rem;}'
			. '.wp-mcp-ai-token-manager__stat{min-width:auto;}'
			. '.wp-mcp-ai-token-manager__intro{padding:0.75rem;margin:1rem 0;}'
			. '.wp-mcp-ai-token-manager__table{font-size:14px;}'
			. '.wp-mcp-ai-token-manager__table th,.wp-mcp-ai-token-manager__table td{padding:0.5rem;}'
			. '.wp-mcp-ai-token-manager__actions form{display:block;margin:0.25rem 0;}'
			. '.wp-mcp-ai-token-manager__actions .button{width:100%;}'
			. '}';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline-only style registration.
		\wp_register_style( 'wp-mcp-ai-token-manager-inline', false );
		\wp_enqueue_style( 'wp-mcp-ai-token-manager-inline' );
		\wp_add_inline_style( 'wp-mcp-ai-token-manager-inline', $inline_css );

		// Restriction lift/dismiss helpers.
		\wp_enqueue_script(
			'wp-mcp-ai-restrictions-admin',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/js/restrictions-admin.js',
			array( 'jquery' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION,
			true
		);
		\wp_localize_script(
			'wp-mcp-ai-restrictions-admin',
			'wpMcpAiRestrictionsAdmin',
			array(
				'ajaxUrl'     => \admin_url( 'admin-ajax.php' ),
				'nonce'       => \wp_create_nonce( 'wp_mcp_ai_dashboard' ),
				'confirmLift' => __( 'Lift this restriction? The user will immediately regain access to AI features.', 'nvoos-content-graph-ai-platform' ),
				'liftFailed'  => __( 'Failed to lift the restriction. Please try again.', 'nvoos-content-graph-ai-platform' ),
			)
		);
	}

	/**
	 * Render the restricted-users panel listing active restrictions.
	 *
	 * Each row offers a one-click lift action backed by the
	 * wp_mcp_ai_lift_user_restriction AJAX endpoint.
	 *
	 * @return void
	 */
	protected function render_restrictions_panel() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! \class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			return;
		}

		$data = \WP_MCP_AI_Restriction_Registry::get_active(
			array(
				'per_page' => 50,
				'page'     => 1,
			)
		);
		?>
		<div class="wp-mcp-ai-restrictions-panel" style="margin:1.5rem 0;">
			<h2><?php \esc_html_e( 'Restricted Users', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'Users blocked by rate limits, token overages, or session budgets. Lifting a restriction also resets the user\'s counters so they can continue immediately.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<?php if ( empty( $data['rows'] ) ) : ?>
				<p><em><?php \esc_html_e( 'No active restrictions.', 'nvoos-content-graph-ai-platform' ); ?></em></p>
			<?php else : ?>
				<div class="wp-mcp-ai-token-manager__table-wrapper">
					<table class="wp-mcp-ai-token-manager__table">
						<thead>
							<tr>
								<th scope="col"><?php \esc_html_e( 'User', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th scope="col"><?php \esc_html_e( 'Type', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th scope="col"><?php \esc_html_e( 'Scope', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th scope="col"><?php \esc_html_e( 'Reason', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th scope="col"><?php \esc_html_e( 'Triggered', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th scope="col"><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['rows'] as $row ) : ?>
								<?php
								$triggered = ! empty( $row['triggered_at'] )
									? \get_date_from_gmt( \gmdate( 'Y-m-d H:i:s', (int) $row['triggered_at'] ), \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ) )
									: '-';
								?>
								<tr>
									<td>
										<strong><?php echo \esc_html( $row['display_name'] ); ?></strong>
										<?php if ( ! empty( $row['user_login'] ) ) : ?>
											<br><small><?php echo \esc_html( $row['user_login'] ); ?></small>
										<?php endif; ?>
									</td>
									<td><span class="wp-mcp-ai-token-manager__status wp-mcp-ai-token-manager__status--revoked"><?php echo \esc_html( $row['type_label'] ); ?></span></td>
									<td><?php echo \esc_html( $row['scope'] ); ?></td>
									<td><?php echo \esc_html( '' !== $row['reason'] ? $row['reason'] : '-' ); ?></td>
									<td><?php echo \esc_html( $triggered ); ?></td>
									<td class="wp-mcp-ai-token-manager__actions">
										<button
											type="button"
											class="button button-secondary wp-mcp-ai-lift-restriction"
											data-user-id="<?php echo \esc_attr( $row['user_id'] ); ?>"
											data-type="<?php echo \esc_attr( $row['type'] ); ?>"
										>
											<?php \esc_html_e( 'Lift Restriction', 'nvoos-content-graph-ai-platform' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle revocation of a token from the manager.
	 *
	 * @return void
	 */
	public function handle_revoke_token(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to manage tokens.', 'nvoos-content-graph-ai-platform' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer below.
		$assistant_id  = isset( $_POST['assistant_id'] ) ? \absint( \wp_unslash( $_POST['assistant_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer below.
		$credential_id = isset( $_POST['credential_id'] ) ? \sanitize_key( \wp_unslash( $_POST['credential_id'] ) ) : '';

		if ( 0 === $assistant_id || '' === $credential_id ) {
			\wp_die( \esc_html__( 'Missing token identifier.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_token_manager_revoke_' . $assistant_id . '_' . $credential_id );

		$credentials_class = self::credentials_class();
		if ( null === $credentials_class ) {
			// Standalone: no credentials store ported yet — documented degrade.
			\wp_safe_redirect( $this->manager_redirect( 'error' ) );
			exit;
		}

		$result = $credentials_class::revoke_credential( $assistant_id, $credential_id, \get_current_user_id() );

		\wp_safe_redirect( $this->manager_redirect( \is_wp_error( $result ) ? 'error' : 'revoked' ) );
		exit;
	}

	/**
	 * Handle deletion of a token from the manager.
	 *
	 * @return void
	 */
	public function handle_delete_token(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to manage tokens.', 'nvoos-content-graph-ai-platform' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer below.
		$assistant_id  = isset( $_POST['assistant_id'] ) ? \absint( \wp_unslash( $_POST['assistant_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer below.
		$credential_id = isset( $_POST['credential_id'] ) ? \sanitize_key( \wp_unslash( $_POST['credential_id'] ) ) : '';

		if ( 0 === $assistant_id || '' === $credential_id ) {
			\wp_die( \esc_html__( 'Missing token identifier.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_token_manager_delete_' . $assistant_id . '_' . $credential_id );

		$credentials_class = self::credentials_class();
		if ( null === $credentials_class ) {
			// Standalone: no credentials store ported yet — documented degrade.
			\wp_safe_redirect( $this->manager_redirect( 'error' ) );
			exit;
		}

		$result = $credentials_class::delete_credential( $assistant_id, $credential_id, \get_current_user_id() );

		\wp_safe_redirect( $this->manager_redirect( \is_wp_error( $result ) ? 'error' : 'deleted' ) );
		exit;
	}

	/**
	 * Post-action redirect URL back to the manager page.
	 *
	 * @param string $action Notice action slug (revoked/deleted/error).
	 * @return string
	 */
	protected function manager_redirect( $action ) {
		return \add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => $action,
			),
			\admin_url( 'admin.php' )
		);
	}

	/**
	 * Get all credentials across all assistants.
	 *
	 * @return array Array of credentials with assistant context.
	 */
	protected function get_all_credentials() {
		$all_credentials = array();

		$credentials_class = self::credentials_class();
		if ( null === $credentials_class ) {
			return array();
		}

		$post_type  = defined( 'WP_MCP_AI_PATH' ) ? \WP_MCP_AI_Credentials::ASSISTANT_POST_TYPE : 'mcp_ai_assistant';
		$assistants = \get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $assistants as $assistant_id ) {
			$credentials = $credentials_class::get_credentials( $assistant_id );
			$assistant   = \get_post( $assistant_id );

			if ( ! $assistant ) {
				continue;
			}

			foreach ( $credentials as $credential ) {
				$all_credentials[] = \array_merge(
					$credential,
					array(
						'assistant_id'    => $assistant_id,
						'assistant_title' => $assistant->post_title,
						// Pre-process timestamp for efficient sorting.
						'_sort_timestamp' => isset( $credential['created_at'] ) ? \strtotime( $credential['created_at'] ) : 0,
					)
				);
			}
		}

		// Sort by created_at descending (newest first) using pre-processed timestamps.
		\usort(
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
	protected function get_statistics( $credentials ) {
		$total   = \count( $credentials );
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
			'assistants' => \count( $assistants_with_tokens ),
		);
	}

	/**
	 * Get display name for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string User display name or 'System'.
	 */
	protected function get_user_display_name( $user_id ) {
		$user_id = \absint( $user_id );
		if ( 0 === $user_id ) {
			return __( 'System', 'nvoos-content-graph-ai-platform' );
		}

		$user = \get_userdata( $user_id );
		if ( $user ) {
			return $user->display_name;
		}

		return __( 'Unknown', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * Render the token manager page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$credentials = $this->get_all_credentials();
		$stats       = $this->get_statistics( $credentials );
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'NV oOS Token Manager', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<div class="wp-mcp-ai-token-manager__intro">
				<p><strong><?php \esc_html_e( 'About Token Manager', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
				<p><?php \esc_html_e( 'The Token Manager provides centralized control over all external agent access tokens issued for your AI Assistants. These tokens allow external applications (like Codex CLI, MCP clients, or custom integrations) to authenticate with specific assistants.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<p><?php \esc_html_e( 'Industry best practices implemented: Tokens are shown only once upon creation, support granular revocation, and include full audit trails. Revoked tokens remain visible for security auditing but cannot be used for authentication.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>

			<?php $this->render_restrictions_panel(); ?>

			<?php
			// Display action status messages.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display after redirect.
			if ( isset( $_GET['action'] ) ) :
				$action_result = \sanitize_key( \wp_unslash( $_GET['action'] ) );
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$valid_actions  = array( 'revoked', 'deleted', 'error' );
				$action_notices = array(
					'revoked' => array(
						'type'    => 'success',
						'message' => __( 'Token successfully revoked. It can no longer be used for authentication.', 'nvoos-content-graph-ai-platform' ),
					),
					'deleted' => array(
						'type'    => 'success',
						'message' => __( 'Token permanently deleted.', 'nvoos-content-graph-ai-platform' ),
					),
					'error'   => array(
						'type'    => 'error',
						'message' => __( 'The requested action could not be completed. The token may have already been modified or does not exist.', 'nvoos-content-graph-ai-platform' ),
					),
				);

				// Only display notice for valid, expected action values.
				if ( \in_array( $action_result, $valid_actions, true ) && isset( $action_notices[ $action_result ] ) ) :
					$notice = $action_notices[ $action_result ];
					?>
					<div class="notice notice-<?php echo \esc_attr( $notice['type'] ); ?> is-dismissible">
						<p><?php echo \esc_html( $notice['message'] ); ?></p>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php if ( ! empty( $credentials ) ) : ?>
				<div class="wp-mcp-ai-token-manager__stats">
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php \esc_html_e( 'Total Tokens', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo \esc_html( $stats['total'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php \esc_html_e( 'Active', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo \esc_html( $stats['active'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php \esc_html_e( 'Revoked', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo \esc_html( $stats['revoked'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-token-manager__stat">
						<div class="wp-mcp-ai-token-manager__stat-label"><?php \esc_html_e( 'Assistants', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-token-manager__stat-value"><?php echo \esc_html( $stats['assistants'] ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( empty( $credentials ) ) : ?>
				<div class="wp-mcp-ai-token-manager__empty">
					<h3><?php \esc_html_e( 'No Tokens Issued', 'nvoos-content-graph-ai-platform' ); ?></h3>
					<p><?php \esc_html_e( 'No external access tokens have been issued for any assistant yet. Tokens can be created from individual assistant edit screens.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<p><strong><?php \esc_html_e( 'To create a token:', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
					<ul>
						<li><?php \esc_html_e( 'Go to AI Assistants in the WordPress admin menu', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Edit the assistant you want to enable external access for', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Scroll to the "Credentials" metabox', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Click "Generate Credential" to create a new token', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ul>
					<p><strong><?php \esc_html_e( 'Security Note:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Tokens are only displayed once upon creation. Store them securely immediately after generation.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wp-mcp-ai-token-manager__table-wrapper">
					<table class="wp-mcp-ai-token-manager__table">
					<thead>
						<tr>
							<th scope="col"><?php \esc_html_e( 'Token ID', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Assistant', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Status', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Created', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Created By', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $credentials as $credential ) : ?>
							<?php
							$is_revoked   = ! empty( $credential['revoked_at'] );
							$created_at   = ! empty( $credential['created_at'] ) ? \get_date_from_gmt( $credential['created_at'], \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ) ) : __( 'Unknown', 'nvoos-content-graph-ai-platform' );
							$created_by   = $this->get_user_display_name( isset( $credential['created_by'] ) ? $credential['created_by'] : 0 );
							$assistant_id = isset( $credential['assistant_id'] ) ? \absint( $credential['assistant_id'] ) : 0;
							$edit_link    = \get_edit_post_link( $assistant_id );
							?>
							<tr>
								<td><code><?php echo \esc_html( $credential['id'] ); ?></code></td>
								<td>
									<?php if ( $edit_link ) : ?>
										<a href="<?php echo \esc_url( $edit_link ); ?>" class="wp-mcp-ai-token-manager__assistant-link">
											<?php echo \esc_html( $credential['assistant_title'] ); ?>
										</a>
									<?php else : ?>
										<?php echo \esc_html( $credential['assistant_title'] ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_revoked ) : ?>
										<span class="wp-mcp-ai-token-manager__status wp-mcp-ai-token-manager__status--revoked">
											<?php \esc_html_e( 'Revoked', 'nvoos-content-graph-ai-platform' ); ?>
										</span>
										<?php
										$revoked_at = \get_date_from_gmt( $credential['revoked_at'], \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ) );
										$revoked_by = $this->get_user_display_name( isset( $credential['revoked_by'] ) ? $credential['revoked_by'] : 0 );
										?>
										<br><small>
										<?php
										/* translators: 1: revocation date, 2: user who revoked */
										\printf( \esc_html__( '%1$s by %2$s', 'nvoos-content-graph-ai-platform' ), \esc_html( $revoked_at ), \esc_html( $revoked_by ) );
										?>
										</small>
									<?php else : ?>
										<span class="wp-mcp-ai-token-manager__status wp-mcp-ai-token-manager__status--active">
											<?php \esc_html_e( 'Active', 'nvoos-content-graph-ai-platform' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo \esc_html( $created_at ); ?></td>
								<td><?php echo \esc_html( $created_by ); ?></td>
								<td class="wp-mcp-ai-token-manager__actions">
									<?php if ( ! $is_revoked ) : ?>
										<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo \esc_js( __( 'Are you sure you want to revoke this token? It will no longer work for authentication.', 'nvoos-content-graph-ai-platform' ) ); ?>');">
											<input type="hidden" name="action" value="wp_mcp_ai_token_manager_revoke" />
											<input type="hidden" name="assistant_id" value="<?php echo \esc_attr( $assistant_id ); ?>" />
											<input type="hidden" name="credential_id" value="<?php echo \esc_attr( $credential['id'] ); ?>" />
											<?php \wp_nonce_field( 'wp_mcp_ai_token_manager_revoke_' . $assistant_id . '_' . $credential['id'] ); ?>
											<?php \submit_button( __( 'Revoke', 'nvoos-content-graph-ai-platform' ), 'secondary', '', false ); ?>
										</form>
									<?php endif; ?>
									<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo \esc_js( __( 'Are you sure you want to permanently delete this token? This action cannot be undone.', 'nvoos-content-graph-ai-platform' ) ); ?>');">
										<input type="hidden" name="action" value="wp_mcp_ai_token_manager_delete" />
										<input type="hidden" name="assistant_id" value="<?php echo \esc_attr( $assistant_id ); ?>" />
										<input type="hidden" name="credential_id" value="<?php echo \esc_attr( $credential['id'] ); ?>" />
										<?php \wp_nonce_field( 'wp_mcp_ai_token_manager_delete_' . $assistant_id . '_' . $credential['id'] ); ?>
										<?php \submit_button( __( 'Delete', 'nvoos-content-graph-ai-platform' ), 'delete', '', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<div class="wp-mcp-ai-token-manager__security-note">
					<p><strong><?php \esc_html_e( 'Security Best Practices:', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
					<p><?php \esc_html_e( 'Regularly review active tokens and revoke any that are no longer needed. Tokens should be treated like passwords and stored securely. If you suspect a token has been compromised, revoke it immediately and generate a new one from the assistant edit screen.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
