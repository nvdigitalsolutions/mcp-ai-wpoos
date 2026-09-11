<?php
/**
 * Approvals manager page (Wave E-UI-2, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_Approvals`
 * (`includes/admin/class-wp-mcp-ai-admin-approvals.php`):
 * byte-identical dashboard surface — the `mcp-ai-approvals` page
 * slug with the pending-count awaiting-mod badge menu title, the two
 * AJAX actions (`wp_mcp_ai_list_approvals`,
 * `wp_mcp_ai_resolve_approval`) with the `wp_mcp_ai_approvals`
 * nonce, the `wpMcpAiApprovals` localized config envelope (incl. the
 * nine-string i18n block), the render surface (toolbar + assistant
 * filter + refresh + the seven-column table), the assistant option
 * list, the pending-count probe, and the list/resolve AJAX flows
 * (403/400 envelopes, requester display-name + date enrichment,
 * approve/deny transitions with the resolution note).
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
 *  - The approval queue resolves per install mode via the
 *    `approval_queue()` seam (`defined( 'WP_MCP_AI_PATH' )`
 *    discriminator): base `WP_MCP_AI_Approval_Queue` monolith / the
 *    platform's `Approvals\ApprovalQueue` standalone (same
 *    `get_pending()`/`approve()`/`deny()` contract — the E3 port).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - The page's own asset (admin-approvals.js) is copied
 *    byte-identically into the platform asset tree.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Managers
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Managers;

/**
 * Approvals Queue admin page.
 *
 * @since 2.0.0
 */
class ApprovalsManager {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcp-ai-approvals';

	/**
	 * Nonce action for the page AJAX handlers.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_approvals';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu_page' ), 26 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_list_approvals', array( $this, 'ajax_list' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_resolve_approval', array( $this, 'ajax_resolve' ) );
	}

	/**
	 * Approval queue singleton (per-mode seam).
	 *
	 * Monolith resolves the base queue; standalone the platform's
	 * `Approvals\ApprovalQueue` (same contract — the E3 port).
	 *
	 * @return object|null
	 */
	protected static function approval_queue() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( \class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
				return \WP_MCP_AI_Approval_Queue::get_instance();
			}

			return null;
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\Approvals\ApprovalQueue' ) ) {
			return \NvoosContentGraphAiPlatform\Approvals\ApprovalQueue::get_instance();
		}

		return null;
	}

	/**
	 * Register submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$pending_count = $this->get_pending_count();
		$menu_title    = $pending_count > 0
			/* translators: %d: number of pending approval items */
			? \sprintf( __( 'Approvals <span class="awaiting-mod">%d</span>', 'nvoos-content-graph-ai-platform' ), $pending_count )
			: __( 'Approvals', 'nvoos-content-graph-ai-platform' );

		\add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Approval Queue', 'nvoos-content-graph-ai-platform' ),
			$menu_title,
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === \strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		\wp_enqueue_script(
			'wp-mcp-ai-approvals',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/js/admin-approvals.js',
			array( 'jquery' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION,
			true
		);

		\wp_localize_script(
			'wp-mcp-ai-approvals',
			'wpMcpAiApprovals',
			array(
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'approve'   => __( 'Approve', 'nvoos-content-graph-ai-platform' ),
					'deny'      => __( 'Deny', 'nvoos-content-graph-ai-platform' ),
					'loading'   => __( 'Loading…', 'nvoos-content-graph-ai-platform' ),
					'noPending' => __( 'No pending approvals.', 'nvoos-content-graph-ai-platform' ),
					/* translators: %s is the action being confirmed (e.g. Approve, Deny). */
					'confirm'   => __( 'Please confirm: %s', 'nvoos-content-graph-ai-platform' ),
					'approved'  => __( 'Approved', 'nvoos-content-graph-ai-platform' ),
					'denied'    => __( 'Denied', 'nvoos-content-graph-ai-platform' ),
					'noteLabel' => __( 'Note (optional):', 'nvoos-content-graph-ai-platform' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}
		?>
		<div class="wrap wp-mcp-ai-approvals">
			<h1>
				<?php \esc_html_e( 'NV oOS — Approval Queue', 'nvoos-content-graph-ai-platform' ); ?>
				<span id="wp-mcp-ai-approvals-badge"></span>
			</h1>
			<p class="description">
				<?php \esc_html_e( 'Review and resolve Human-in-the-Loop approval requests from the AI agentic loop.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<div id="wp-mcp-ai-approvals-app">
				<div class="approvals-toolbar">
					<label for="approvals-filter-assistant"><?php \esc_html_e( 'Assistant:', 'nvoos-content-graph-ai-platform' ); ?></label>
					<select id="approvals-filter-assistant">
						<option value=""><?php \esc_html_e( '— All —', 'nvoos-content-graph-ai-platform' ); ?></option>
						<?php $this->render_assistant_options(); ?>
					</select>
					<button id="approvals-refresh" class="button"><?php \esc_html_e( 'Refresh', 'nvoos-content-graph-ai-platform' ); ?></button>
				</div>

				<table class="wp-list-table widefat fixed striped" id="approvals-table">
					<thead>
						<tr>
							<th><?php \esc_html_e( 'ID', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th><?php \esc_html_e( 'Tool', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th><?php \esc_html_e( 'Reason', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th><?php \esc_html_e( 'Requester', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th><?php \esc_html_e( 'Created', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th><?php \esc_html_e( 'Expires', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
						</tr>
					</thead>
					<tbody id="approvals-tbody">
						<tr><td colspan="7"><?php \esc_html_e( 'Loading…', 'nvoos-content-graph-ai-platform' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render assistant <option> elements.
	 *
	 * @return void
	 */
	protected function render_assistant_options() {
		$assistants = \get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		foreach ( $assistants as $id ) {
			\printf(
				'<option value="%s">%s</option>',
				\esc_attr( $id ),
				\esc_html( \get_the_title( $id ) )
			);
		}
	}

	/**
	 * Get count of pending approvals for the badge.
	 *
	 * @return int
	 */
	protected function get_pending_count() {
		$queue = self::approval_queue();
		if ( ! $queue ) {
			return 0;
		}
		$items = $queue->get_pending( array( 'limit' => 100 ) );
		return \count( $items );
	}

	/**
	 * AJAX: list pending approvals.
	 *
	 * @return void
	 */
	public function ajax_list(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter param, no state change.
		$assistant_id = isset( $_GET['assistant_id'] ) ? \absint( \wp_unslash( $_GET['assistant_id'] ) ) : 0;
		$queue        = self::approval_queue();
		$items        = $queue->get_pending(
			array(
				'assistant_id' => $assistant_id,
				'limit'        => 50,
			)
		);

		// Enrich with requester display name.
		foreach ( $items as &$item ) {
			$user                         = $item['requester_id'] ? \get_userdata( $item['requester_id'] ) : false;
			$item['requester_name']       = $user ? $user->display_name : __( 'Unknown', 'nvoos-content-graph-ai-platform' );
			$item['created_at_formatted'] = $item['created_at'] ? \wp_date( 'Y-m-d H:i', $item['created_at'] ) : '—';
			$item['expires_at_formatted'] = $item['expires_at'] ? \wp_date( 'Y-m-d H:i', $item['expires_at'] ) : '—';
		}
		unset( $item );

		\wp_send_json_success( array( 'approvals' => $items ) );
	}

	/**
	 * AJAX: resolve (approve or deny) an approval.
	 *
	 * @return void
	 */
	public function ajax_resolve(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$approval_id = \absint( \wp_unslash( $_POST['approval_id'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$action = isset( $_POST['resolution'] ) ? \sanitize_key( \wp_unslash( $_POST['resolution'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$note = isset( $_POST['note'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['note'] ) ) : '';

		if ( $approval_id <= 0 ) {
			\wp_send_json_error( array( 'message' => __( 'Invalid approval ID.', 'nvoos-content-graph-ai-platform' ) ), 400 );
		}

		$queue = self::approval_queue();

		if ( 'approve' === $action ) {
			$result = $queue->approve( $approval_id, \get_current_user_id(), $note );
		} elseif ( 'deny' === $action ) {
			$result = $queue->deny( $approval_id, \get_current_user_id(), $note );
		} else {
			\wp_send_json_error( array( 'message' => __( 'Invalid resolution. Use "approve" or "deny".', 'nvoos-content-graph-ai-platform' ) ), 400 );
		}

		if ( \is_wp_error( $result ) ) {
			\wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		\wp_send_json_success(
			array(
				'approval_id' => $approval_id,
				'status'      => 'approve' === $action ? 'approved' : 'denied',
			)
		);
	}
}
