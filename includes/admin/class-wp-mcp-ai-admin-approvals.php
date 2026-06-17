<?php
/**
 * Admin Page: Approvals Queue
 *
 * Provides an admin interface for managing Human-in-the-Loop approval requests.
 * Administrators can review, approve, or deny pending tool-execution approvals
 * from this page.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approvals Queue admin page.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Admin_Approvals {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_list_approvals', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_wp_mcp_ai_resolve_approval', array( $this, 'ajax_resolve' ) );
	}

	/**
	 * Register submenu page.
	 */
	public function add_menu_page() {
		$pending_count = $this->get_pending_count();
		$menu_title    = $pending_count > 0
			/* translators: %d: number of pending approval items */
			? sprintf( __( 'Approvals <span class="awaiting-mod">%d</span>', 'mcp-ai-wpoos' ), $pending_count )
			: __( 'Approvals', 'mcp-ai-wpoos' );

		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Approval Queue', 'mcp-ai-wpoos' ),
			$menu_title,
			'manage_options',
			'mcp-ai-approvals',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'mcp-ai-approvals' ) ) {
			return;
		}

		wp_enqueue_script(
			'wp-mcp-ai-approvals',
			WP_MCP_AI_URL . 'assets/js/admin-approvals.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-approvals',
			'wpMcpAiApprovals',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_approvals' ),
				'i18n'    => array(
					'approve'   => __( 'Approve', 'mcp-ai-wpoos' ),
					'deny'      => __( 'Deny', 'mcp-ai-wpoos' ),
					'loading'   => __( 'Loading…', 'mcp-ai-wpoos' ),
					'noPending' => __( 'No pending approvals.', 'mcp-ai-wpoos' ),
					'confirm'   => __( 'Please confirm: %s', 'mcp-ai-wpoos' ),
					'approved'  => __( 'Approved', 'mcp-ai-wpoos' ),
					'denied'    => __( 'Denied', 'mcp-ai-wpoos' ),
					'noteLabel' => __( 'Note (optional):', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}
		?>
		<div class="wrap wp-mcp-ai-approvals">
			<h1>
				<?php esc_html_e( 'NV oOS — Approval Queue', 'mcp-ai-wpoos' ); ?>
				<span id="wp-mcp-ai-approvals-badge"></span>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Review and resolve Human-in-the-Loop approval requests from the AI agentic loop.', 'mcp-ai-wpoos' ); ?>
			</p>

			<div id="wp-mcp-ai-approvals-app">
				<div class="approvals-toolbar">
					<label for="approvals-filter-assistant"><?php esc_html_e( 'Assistant:', 'mcp-ai-wpoos' ); ?></label>
					<select id="approvals-filter-assistant">
						<option value=""><?php esc_html_e( '— All —', 'mcp-ai-wpoos' ); ?></option>
						<?php $this->render_assistant_options(); ?>
					</select>
					<button id="approvals-refresh" class="button"><?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?></button>
				</div>

				<table class="wp-list-table widefat fixed striped" id="approvals-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Requester', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody id="approvals-tbody">
						<tr><td colspan="7"><?php esc_html_e( 'Loading…', 'mcp-ai-wpoos' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render assistant <option> elements.
	 */
	private function render_assistant_options() {
		$assistants = get_posts(
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
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $id ),
				esc_html( get_the_title( $id ) )
			);
		}
	}

	/**
	 * Get count of pending approvals for the badge.
	 *
	 * @return int
	 */
	private function get_pending_count() {
		if ( ! class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			return 0;
		}
		$items = WP_MCP_AI_Approval_Queue::get_instance()->get_pending( array( 'limit' => 100 ) );
		return count( $items );
	}

	/**
	 * AJAX: list pending approvals.
	 */
	public function ajax_list() {
		check_ajax_referer( 'wp_mcp_ai_approvals', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ), 403 );
		}

		$assistant_id = isset( $_GET['assistant_id'] ) ? absint( wp_unslash( $_GET['assistant_id'] ) ) : 0;
		$queue        = WP_MCP_AI_Approval_Queue::get_instance();
		$items        = $queue->get_pending(
			array(
				'assistant_id' => $assistant_id,
				'limit'        => 50,
			)
		);

		// Enrich with requester display name.
		foreach ( $items as &$item ) {
			$user                         = $item['requester_id'] ? get_userdata( $item['requester_id'] ) : false;
			$item['requester_name']       = $user ? $user->display_name : __( 'Unknown', 'mcp-ai-wpoos' );
			$item['created_at_formatted'] = $item['created_at'] ? wp_date( 'Y-m-d H:i', $item['created_at'] ) : '—';
			$item['expires_at_formatted'] = $item['expires_at'] ? wp_date( 'Y-m-d H:i', $item['expires_at'] ) : '—';
		}
		unset( $item );

		wp_send_json_success( array( 'approvals' => $items ) );
	}

	/**
	 * AJAX: resolve (approve or deny) an approval.
	 */
	public function ajax_resolve() {
		check_ajax_referer( 'wp_mcp_ai_approvals', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ), 403 );
		}

		$approval_id = absint( wp_unslash( $_POST['approval_id'] ?? 0 ) );
		$action      = isset( $_POST['resolution'] ) ? sanitize_key( wp_unslash( $_POST['resolution'] ) ) : '';
		$note        = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( $approval_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid approval ID.', 'mcp-ai-wpoos' ) ), 400 );
		}

		$queue = WP_MCP_AI_Approval_Queue::get_instance();

		if ( 'approve' === $action ) {
			$result = $queue->approve( $approval_id, get_current_user_id(), $note );
		} elseif ( 'deny' === $action ) {
			$result = $queue->deny( $approval_id, get_current_user_id(), $note );
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid resolution. Use "approve" or "deny".', 'mcp-ai-wpoos' ) ), 400 );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'approval_id' => $approval_id,
				'status'      => 'approve' === $action ? 'approved' : 'denied',
			)
		);
	}
}
