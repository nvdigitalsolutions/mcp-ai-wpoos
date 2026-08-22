<?php
/**
 * Artifact Governance metabox — Phase G admin surface.
 *
 * Renders, on the assistant edit screen: the unified evolution governor
 * report (budget + per-path rate counters), the pending human approval
 * queue for artifact promotions and drift-rollbacks (with nonce'd
 * approve/reject actions), and the lineage tree of the deployed prompt.
 *
 * The approve/reject endpoints are plain `admin-post.php` handlers:
 * capability-checked (`edit_post` on the assistant) and nonce-protected.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/assistants/metaboxes
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Governance metabox class.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Metabox_Artifact_Governance extends WP_MCP_AI_Metabox_Base {

	/**
	 * Nonce action for the approve/reject endpoints.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_artifact_governance';

	/**
	 * Reference to the Assistant CPT class.
	 *
	 * @since 1.9.0
	 * @var   WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;

		add_action( 'admin_post_wp_mcp_ai_artifact_queue_approve', array( $this, 'handle_queue_approve' ) );
		add_action( 'admin_post_wp_mcp_ai_artifact_queue_reject', array( $this, 'handle_queue_reject' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id() {
		return 'wp_mcp_ai_artifact_governance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_title() {
		return __( 'Artifact Governance', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_documentation_url() {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/project/proposals/007-artifact-evolution.md';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function can_view() {
		global $post;
		return current_user_can( 'edit_post', $post ? (int) $post->ID : 0 );
	}

	/**
	 * Render the metabox.
	 *
	 * @since 1.9.0
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		$assistant_id = (int) $post->ID;

		$this->render_documentation_link();

		if ( ! class_exists( 'WP_MCP_AI_Evolution_Governor' ) && ! class_exists( 'WP_MCP_AI_Artifact_Approval_Queue' ) ) {
			echo '<p>' . esc_html__( 'The artifact evolution subsystem is not loaded.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		$this->render_governor_report( $assistant_id );
		$this->render_queue( $assistant_id );
		$this->render_lineage( $assistant_id );
	}

	/**
	 * Approve a queue item via admin-post.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function handle_queue_approve() {
		$this->handle_queue_decision( 'approve' );
	}

	/**
	 * Reject a queue item via admin-post.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function handle_queue_reject() {
		$this->handle_queue_decision( 'reject' );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Render the unified governor report.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	private function render_governor_report( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Evolution_Governor' ) ) {
			return;
		}

		$report = WP_MCP_AI_Evolution_Governor::get_report( $assistant_id );

		echo '<h4>' . esc_html__( 'Evolution Governor', 'mcp-ai-wpoos' ) . '</h4>';
		echo '<table class="widefat striped" style="margin-bottom:12px;">';
		echo '<tbody>';
		echo '<tr><td>' . esc_html__( 'Budget remaining', 'mcp-ai-wpoos' ) . '</td><td>$' . esc_html( number_format( (float) $report['budget_remaining_usd'], 2, '.', '' ) ) . ' / $' . esc_html( number_format( (float) $report['budget_limit_usd'], 2, '.', '' ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html__( 'Mutations this hour (site)', 'mcp-ai-wpoos' ) . '</td><td>' . esc_html( (string) $report['site_mutations'] ) . ' / ' . esc_html( (string) $report['site_max_mutations'] ) . '</td></tr>';

		foreach ( $report['paths'] as $path => $stats ) {
			echo '<tr><td>' . esc_html( ucfirst( $path ) ) . '</td><td>' . esc_html( (string) $stats['mutations_this_hour'] ) . ' / ' . esc_html( (string) $stats['rate_limit'] ) . ' per hour · $' . esc_html( number_format( (float) $stats['spend_usd'], 2, '.', '' ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the pending approval queue with decision actions.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	private function render_queue( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Approval_Queue' ) ) {
			return;
		}

		$items = WP_MCP_AI_Artifact_Approval_Queue::list_items( $assistant_id, 'pending', 10 );

		echo '<h4>' . esc_html__( 'Approval Queue', 'mcp-ai-wpoos' ) . '</h4>';

		if ( empty( $items ) ) {
			echo '<p><em>' . esc_html__( 'No pending approvals.', 'mcp-ai-wpoos' ) . '</em></p>';
			return;
		}

		echo '<ul style="margin:0 0 12px;">';
		foreach ( $items as $item ) {
			$approve_url = wp_nonce_url(
				admin_url( 'admin-post.php' ) . '?action=wp_mcp_ai_artifact_queue_approve&item=' . rawurlencode( (string) $item['id'] ),
				self::NONCE_ACTION
			);
			$reject_url  = wp_nonce_url(
				admin_url( 'admin-post.php' ) . '?action=wp_mcp_ai_artifact_queue_reject&item=' . rawurlencode( (string) $item['id'] ),
				self::NONCE_ACTION
			);

			$created = (int) ( isset( $item['created_at'] ) ? $item['created_at'] : time() );

			echo '<li>';
			echo '<strong>' . esc_html( strtoupper( (string) $item['action'] ) ) . '</strong> ';
			echo esc_html( (string) $item['artifact_type'] );
			if ( '' !== (string) $item['candidate_hash'] ) {
				echo ' <code>' . esc_html( substr( (string) $item['candidate_hash'], 0, 8 ) ) . '</code>';
			}
			if ( '' !== (string) $item['reason'] ) {
				echo ' — ' . esc_html( (string) $item['reason'] );
			}
			echo ' <em>(' . esc_html( human_time_diff( $created, time() ) ) . ' ' . esc_html__( 'ago', 'mcp-ai-wpoos' ) . ')</em> ';
			echo '<a class="button button-small button-primary" href="' . esc_url( $approve_url ) . '">' . esc_html__( 'Approve', 'mcp-ai-wpoos' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( $reject_url ) . '">' . esc_html__( 'Reject', 'mcp-ai-wpoos' ) . '</a>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render the deployed prompt lineage tree.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	private function render_lineage( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Deploy' ) || ! class_exists( 'WP_MCP_AI_Artifact_Lineage' ) ) {
			return;
		}

		$prompt = WP_MCP_AI_Artifact_Deploy::get_deployed( $assistant_id, 'prompt' );
		if ( ! is_string( $prompt ) || '' === trim( $prompt ) ) {
			return;
		}

		$tree = WP_MCP_AI_Artifact_Lineage::render_ascii( 'prompt', WP_MCP_AI_Artifact_Lineage::hash_for( 'prompt', $prompt ), 5 );

		echo '<h4>' . esc_html__( 'Prompt Lineage', 'mcp-ai-wpoos' ) . '</h4>';

		if ( '' === $tree ) {
			echo '<p><em>' . esc_html__( 'No lineage recorded for the deployed prompt.', 'mcp-ai-wpoos' ) . '</em></p>';
			return;
		}

		echo '<pre style="white-space:pre;overflow:auto;">' . esc_html( $tree ) . '</pre>';
	}

	/**
	 * Shared admin-post handler for approve/reject decisions.
	 *
	 * @since 1.9.0
	 *
	 * @param string $intent Decision intent (approve|reject).
	 * @return void
	 */
	private function handle_queue_decision( $intent ) {
		check_admin_referer( self::NONCE_ACTION );

		$item_id = isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked via check_admin_referer.

		$redirect = wp_get_referer();
		if ( false === $redirect || '' === $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=mcp_ai_assistant' );
		}

		if ( '' === $item_id || ! class_exists( 'WP_MCP_AI_Artifact_Approval_Queue' ) ) {
			wp_safe_redirect( add_query_arg( 'wp_mcp_ai_artifact_queue_status', 'missing_item', $redirect ) );
			exit;
		}

		$item = WP_MCP_AI_Artifact_Approval_Queue::get_item( $item_id );
		if ( null === $item || ! current_user_can( 'edit_post', (int) $item['assistant_id'] ) ) {
			wp_safe_redirect( add_query_arg( 'wp_mcp_ai_artifact_queue_status', 'forbidden', $redirect ) );
			exit;
		}

		if ( 'approve' === $intent ) {
			$result = WP_MCP_AI_Artifact_Approval_Queue::approve( $item_id, get_current_user_id() );
		} else {
			$result = WP_MCP_AI_Artifact_Approval_Queue::reject( $item_id, get_current_user_id() );
		}

		$status = is_wp_error( $result ) ? $result->get_error_code() : 'ok';
		wp_safe_redirect( add_query_arg( 'wp_mcp_ai_artifact_queue_status', rawurlencode( (string) $status ), $redirect ) );
		exit;
	}
}
