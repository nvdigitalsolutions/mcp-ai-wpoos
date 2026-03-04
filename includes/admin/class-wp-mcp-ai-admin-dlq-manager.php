<?php
/**
 * Admin page for Dead Letter Queue management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Dead Letter Queue management UI.
 */
class WP_MCP_AI_Admin_DLQ_Manager {
	const PAGE_SLUG = 'wp-mcp-ai-dlq-manager';

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
		add_action( 'admin_menu', array( $this, 'register_page' ), 16 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wp_mcp_ai_dlq_bulk_action', array( $this, 'handle_bulk_action' ) );
		add_action( 'admin_post_wp_mcp_ai_dlq_single_action', array( $this, 'handle_single_action' ) );
	}

	/**
	 * Register the DLQ manager page under the NV oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Dead Letter Queue', 'mcp-ai-wpoos' ),
			__( 'Dead Letter Queue', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue styles for the DLQ manager page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		$inline_css = '
			.wp-mcp-ai-dlq__intro{margin:1.5rem 0;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;}
			.wp-mcp-ai-dlq__intro p{margin:0.5rem 0;}
			.wp-mcp-ai-dlq__stats{display:flex;gap:1.5rem;margin:1.5rem 0;}
			.wp-mcp-ai-dlq__stat{padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex:1;}
			.wp-mcp-ai-dlq__stat-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}
			.wp-mcp-ai-dlq__stat-value{font-size:1.75rem;font-weight:600;color:#1d2327;}
			.wp-mcp-ai-dlq__filters{margin:1.5rem 0;padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;}
			.wp-mcp-ai-dlq__filters label{margin-right:1rem;}
			.wp-mcp-ai-dlq__filters select,.wp-mcp-ai-dlq__filters input{margin-right:0.5rem;}
			.wp-mcp-ai-dlq__table{margin-top:1.5rem;width:100%;}
			.wp-mcp-ai-dlq__table th,.wp-mcp-ai-dlq__table td{padding:0.75rem;text-align:left;border:1px solid #dcdcde;}
			.wp-mcp-ai-dlq__table th{background:#f8f9ff;font-weight:600;}
			.wp-mcp-ai-dlq__table tbody tr:hover{background:#f6f7f7;}
			.wp-mcp-ai-dlq__type{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;}
			.wp-mcp-ai-dlq__type--webhook{background:#e0f2ff;color:#0056a0;}
			.wp-mcp-ai-dlq__type--cron{background:#d5f0db;color:#0a5f1a;}
			.wp-mcp-ai-dlq__type--async{background:#fef7e0;color:#8b6c00;}
			.wp-mcp-ai-dlq__type--queue{background:#f0e6ff;color:#5a1a8b;}
			.wp-mcp-ai-dlq__status--dismissed{opacity:0.6;}
			.wp-mcp-ai-dlq__error{font-family:monospace;font-size:0.875rem;color:#d63638;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.wp-mcp-ai-dlq__retry-count{font-weight:600;}
			.wp-mcp-ai-dlq__empty{margin-top:1.5rem;padding:2rem;text-align:center;background:#fff;border:1px solid #dcdcde;border-radius:4px;}
			.wp-mcp-ai-dlq__actions{white-space:nowrap;}
			.wp-mcp-ai-dlq__actions a{margin-right:0.5rem;}
		';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline style registered with no URL; version not applicable.
		wp_register_style( 'wp-mcp-ai-dlq-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-dlq-inline' );
		wp_add_inline_style( 'wp-mcp-ai-dlq-inline', $inline_css );
	}

	/**
	 * Handle bulk actions from the DLQ table.
	 */
	public function handle_bulk_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the dead letter queue.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( 'wp_mcp_ai_dlq_bulk_action' );

		$action   = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
		$item_ids = isset( $_POST['dlq_items'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['dlq_items'] ) ) : array();

		if ( empty( $action ) || empty( $item_ids ) ) {
			wp_safe_redirect( $this->get_page_url( array( 'error' => 'missing_params' ) ) );
			exit;
		}

		$processed = 0;
		$errors    = 0;

		foreach ( $item_ids as $item_id ) {
			switch ( $action ) {
				case 'retry':
					$result = WP_MCP_AI_Dead_Letter_Queue::retry( $item_id );
					break;

				case 'dismiss':
					$result = WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );
					break;

				case 'delete':
					$result = WP_MCP_AI_Dead_Letter_Queue::remove( $item_id );
					break;

				default:
					$result = false;
			}

			if ( $result && ! is_wp_error( $result ) ) {
				++$processed;
			} else {
				++$errors;
			}
		}

		wp_safe_redirect(
			$this->get_page_url(
				array(
					'bulk_action' => $action,
					'processed'   => $processed,
					'errors'      => $errors,
				)
			)
		);
		exit;
	}

	/**
	 * Handle single item actions.
	 */
	public function handle_single_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the dead letter queue.', 'mcp-ai-wpoos' ) );
		}

		$item_id    = isset( $_GET['item_id'] ) ? sanitize_key( $_GET['item_id'] ) : '';
		$dlq_action = isset( $_GET['dlq_action'] ) ? sanitize_key( $_GET['dlq_action'] ) : '';

		if ( '' === $item_id || '' === $dlq_action ) {
			wp_die( esc_html__( 'Missing parameters.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( 'wp_mcp_ai_dlq_' . $dlq_action . '_' . $item_id );

		$result = false;

		switch ( $dlq_action ) {
			case 'retry':
				$result = WP_MCP_AI_Dead_Letter_Queue::retry( $item_id );
				break;

			case 'dismiss':
				$result = WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );
				break;

			case 'delete':
				$result = WP_MCP_AI_Dead_Letter_Queue::remove( $item_id );
				break;
		}

		$redirect_args = array( 'action_result' => $dlq_action );

		if ( is_wp_error( $result ) ) {
			$redirect_args['error'] = $result->get_error_code();
		} else {
			$redirect_args['success'] = '1';
		}

		wp_safe_redirect( $this->get_page_url( $redirect_args ) );
		exit;
	}

	/**
	 * Render the DLQ manager page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get filter parameters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters for filtering.
		$filter_type      = isset( $_GET['filter_type'] ) ? sanitize_key( $_GET['filter_type'] ) : '';
		$filter_dismissed = isset( $_GET['filter_dismissed'] ) ? sanitize_key( $_GET['filter_dismissed'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Build filters array.
		$filters = array();
		if ( '' !== $filter_type && 'all' !== $filter_type ) {
			$filters['type'] = $filter_type;
		}
		if ( '' !== $filter_dismissed ) {
			$filters['dismissed'] = ( 'yes' === $filter_dismissed );
		}

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all( $filters );
		$stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dead Letter Queue', 'mcp-ai-wpoos' ); ?></h1>

			<?php $this->render_notices(); ?>

			<div class="wp-mcp-ai-dlq__intro">
				<p><strong><?php esc_html_e( 'About Dead Letter Queue', 'mcp-ai-wpoos' ); ?></strong></p>
				<p><?php esc_html_e( 'The Dead Letter Queue (DLQ) stores failed jobs, webhooks, and async operations that exceeded maximum retry attempts. Items here can be retried manually, dismissed, or deleted.', 'mcp-ai-wpoos' ); ?></p>
			</div>

			<?php $this->render_statistics( $stats ); ?>
			<?php $this->render_filters( $filter_type, $filter_dismissed ); ?>

			<?php if ( empty( $items ) ) : ?>
				<div class="wp-mcp-ai-dlq__empty">
					<h3><?php esc_html_e( 'No items in dead letter queue', 'mcp-ai-wpoos' ); ?></h3>
					<p><?php esc_html_e( 'This is good! All your jobs and webhooks are completing successfully.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php else : ?>
				<?php $this->render_table( $items ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 */
	protected function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters for notices.
		if ( isset( $_GET['success'] ) && '1' === $_GET['success'] ) {
			$action = isset( $_GET['action_result'] ) ? sanitize_key( $_GET['action_result'] ) : '';
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					switch ( $action ) {
						case 'retry':
							esc_html_e( 'Item successfully retried.', 'mcp-ai-wpoos' );
							break;
						case 'dismiss':
							esc_html_e( 'Item dismissed.', 'mcp-ai-wpoos' );
							break;
						case 'delete':
							esc_html_e( 'Item deleted.', 'mcp-ai-wpoos' );
							break;
						default:
							esc_html_e( 'Action completed successfully.', 'mcp-ai-wpoos' );
					}
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['bulk_action'] ) ) {
			$action    = sanitize_key( $_GET['bulk_action'] );
			$processed = isset( $_GET['processed'] ) ? absint( $_GET['processed'] ) : 0;
			$errors    = isset( $_GET['errors'] ) ? absint( $_GET['errors'] ) : 0;
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: number of items processed, 2: number of errors */
						esc_html__( 'Bulk action completed: %1$d items processed, %2$d errors.', 'mcp-ai-wpoos' ),
						(int) $processed,
						(int) $errors
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( $_GET['error'] );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'An error occurred. Please try again.', 'mcp-ai-wpoos' ); ?></p>
			</div>
			<?php
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render statistics boxes.
	 *
	 * @param array $stats Statistics array.
	 */
	protected function render_statistics( $stats ) {
		?>
		<div class="wp-mcp-ai-dlq__stats">
			<div class="wp-mcp-ai-dlq__stat">
				<div class="wp-mcp-ai-dlq__stat-label"><?php esc_html_e( 'Total Items', 'mcp-ai-wpoos' ); ?></div>
				<div class="wp-mcp-ai-dlq__stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></div>
			</div>
			<div class="wp-mcp-ai-dlq__stat">
				<div class="wp-mcp-ai-dlq__stat-label"><?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></div>
				<div class="wp-mcp-ai-dlq__stat-value"><?php echo esc_html( number_format_i18n( $stats['active'] ) ); ?></div>
			</div>
			<div class="wp-mcp-ai-dlq__stat">
				<div class="wp-mcp-ai-dlq__stat-label"><?php esc_html_e( 'Dismissed', 'mcp-ai-wpoos' ); ?></div>
				<div class="wp-mcp-ai-dlq__stat-value"><?php echo esc_html( number_format_i18n( $stats['dismissed'] ) ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render filter form.
	 *
	 * @param string $filter_type      Current type filter.
	 * @param string $filter_dismissed Current dismissed filter.
	 */
	protected function render_filters( $filter_type, $filter_dismissed ) {
		?>
		<form method="get" class="wp-mcp-ai-dlq__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

			<label>
				<?php esc_html_e( 'Type:', 'mcp-ai-wpoos' ); ?>
				<select name="filter_type">
					<option value="all" <?php selected( $filter_type, 'all' ); ?>><?php esc_html_e( 'All Types', 'mcp-ai-wpoos' ); ?></option>
					<option value="webhook" <?php selected( $filter_type, 'webhook' ); ?>><?php esc_html_e( 'Webhooks', 'mcp-ai-wpoos' ); ?></option>
					<option value="cron_job" <?php selected( $filter_type, 'cron_job' ); ?>><?php esc_html_e( 'Cron Jobs', 'mcp-ai-wpoos' ); ?></option>
					<option value="async_tool" <?php selected( $filter_type, 'async_tool' ); ?>><?php esc_html_e( 'Async Tools', 'mcp-ai-wpoos' ); ?></option>
					<option value="job_queue" <?php selected( $filter_type, 'job_queue' ); ?>><?php esc_html_e( 'Job Queue', 'mcp-ai-wpoos' ); ?></option>
				</select>
			</label>

			<label>
				<?php esc_html_e( 'Status:', 'mcp-ai-wpoos' ); ?>
				<select name="filter_dismissed">
					<option value="" <?php selected( $filter_dismissed, '' ); ?>><?php esc_html_e( 'All', 'mcp-ai-wpoos' ); ?></option>
					<option value="no" <?php selected( $filter_dismissed, 'no' ); ?>><?php esc_html_e( 'Active Only', 'mcp-ai-wpoos' ); ?></option>
					<option value="yes" <?php selected( $filter_dismissed, 'yes' ); ?>><?php esc_html_e( 'Dismissed Only', 'mcp-ai-wpoos' ); ?></option>
				</select>
			</label>

			<?php submit_button( __( 'Filter', 'mcp-ai-wpoos' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render items table.
	 *
	 * @param array $items DLQ items.
	 */
	protected function render_table( $items ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp_mcp_ai_dlq_bulk_action">
			<?php wp_nonce_field( 'wp_mcp_ai_dlq_bulk_action' ); ?>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<select name="action">
						<option value=""><?php esc_html_e( 'Bulk Actions', 'mcp-ai-wpoos' ); ?></option>
						<option value="retry"><?php esc_html_e( 'Retry', 'mcp-ai-wpoos' ); ?></option>
						<option value="dismiss"><?php esc_html_e( 'Dismiss', 'mcp-ai-wpoos' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos' ); ?></option>
					</select>
					<?php submit_button( __( 'Apply', 'mcp-ai-wpoos' ), 'action', '', false ); ?>
				</div>
			</div>

			<table class="wp-mcp-ai-dlq__table widefat">
				<thead>
					<tr>
						<th style="width:40px;"><input type="checkbox" id="select-all"></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Identifier', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Failure Reason', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Retries', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Added', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$dismissed_class = ! empty( $item['dismissed'] ) ? 'wp-mcp-ai-dlq__status--dismissed' : '';
						?>
						<tr class="<?php echo esc_attr( $dismissed_class ); ?>">
							<td>
								<input type="checkbox" name="dlq_items[]" value="<?php echo esc_attr( $item['id'] ); ?>">
							</td>
							<td>
								<?php echo wp_kses_post( $this->format_type( $item['type'] ) ); ?>
							</td>
							<td>
								<code><?php echo esc_html( $item['identifier'] ); ?></code>
								<?php if ( ! empty( $item['dismissed'] ) ) : ?>
									<br><em><?php esc_html_e( '(Dismissed)', 'mcp-ai-wpoos' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<div class="wp-mcp-ai-dlq__error" title="<?php echo esc_attr( $item['failure_reason'] ); ?>">
									<?php echo esc_html( $item['failure_reason'] ); ?>
								</div>
							</td>
							<td>
								<span class="wp-mcp-ai-dlq__retry-count"><?php echo esc_html( $item['retry_count'] ); ?></span>
							</td>
							<td>
								<?php echo esc_html( human_time_diff( $item['added_timestamp'], time() ) ); ?> ago
							</td>
							<td class="wp-mcp-ai-dlq__actions">
								<?php echo wp_kses_post( $this->render_item_actions( $item ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>

		<?php
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for select-all checkbox functionality on this admin page only.
		?>
		<script>
		document.getElementById('select-all').addEventListener('change', function() {
			const checkboxes = document.querySelectorAll('input[name="dlq_items[]"]');
			checkboxes.forEach(cb => cb.checked = this.checked);
		});
		</script>
		<?php
	}

	/**
	 * Format item type as badge.
	 *
	 * @param string $type Item type.
	 * @return string HTML badge.
	 */
	protected function format_type( $type ) {
		$labels = array(
			'webhook'    => __( 'Webhook', 'mcp-ai-wpoos' ),
			'cron_job'   => __( 'Cron Job', 'mcp-ai-wpoos' ),
			'async_tool' => __( 'Async Tool', 'mcp-ai-wpoos' ),
			'job_queue'  => __( 'Job Queue', 'mcp-ai-wpoos' ),
		);

		$label = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
		$class = 'wp-mcp-ai-dlq__type wp-mcp-ai-dlq__type--' . sanitize_html_class( $type );

		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Render action links for an item.
	 *
	 * @param array $item DLQ item.
	 * @return string HTML links.
	 */
	protected function render_item_actions( $item ) {
		$item_id = $item['id'];
		$actions = array();

		// Retry link.
		$retry_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'wp_mcp_ai_dlq_single_action',
					'item_id'    => $item_id,
					'dlq_action' => 'retry',
				),
				admin_url( 'admin-post.php' )
			),
			'wp_mcp_ai_dlq_retry_' . $item_id
		);
		$actions[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $retry_url ),
			esc_html__( 'Retry', 'mcp-ai-wpoos' )
		);

		// Dismiss link (if not already dismissed).
		if ( empty( $item['dismissed'] ) ) {
			$dismiss_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'wp_mcp_ai_dlq_single_action',
						'item_id'    => $item_id,
						'dlq_action' => 'dismiss',
					),
					admin_url( 'admin-post.php' )
				),
				'wp_mcp_ai_dlq_dismiss_' . $item_id
			);
			$actions[]   = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $dismiss_url ),
				esc_html__( 'Dismiss', 'mcp-ai-wpoos' )
			);
		}

		// Delete link.
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'wp_mcp_ai_dlq_single_action',
					'item_id'    => $item_id,
					'dlq_action' => 'delete',
				),
				admin_url( 'admin-post.php' )
			),
			'wp_mcp_ai_dlq_delete_' . $item_id
		);
		$actions[]  = sprintf(
			'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( 'Are you sure you want to delete this item?', 'mcp-ai-wpoos' ) ),
			esc_html__( 'Delete', 'mcp-ai-wpoos' )
		);

		return implode( ' | ', $actions );
	}

	/**
	 * Get page URL with query parameters.
	 *
	 * @param array $args Query arguments.
	 * @return string Page URL.
	 */
	protected function get_page_url( $args = array() ) {
		$base_args = array( 'page' => self::PAGE_SLUG );
		$args      = array_merge( $base_args, $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
