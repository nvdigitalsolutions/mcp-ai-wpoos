<?php
/**
 * DLQ manager page (Wave E-UI-2, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_DLQ_Manager`
 * (`includes/admin/class-wp-mcp-ai-admin-dlq-manager.php`):
 * byte-identical page surface — the `wp-mcp-ai-dlq-manager` page
 * slug (priority 16, `manage_options`), the
 * `admin_post_wp_mcp_ai_dlq_bulk_action` /
 * `admin_post_wp_mcp_ai_dlq_single_action` handlers with their
 * nonce actions, the inline-stylesheet enqueue
 * (`wp-mcp-ai-dlq-inline`), the intro / notices / statistics cards /
 * filter form / seven-column items table (type badge, identifier,
 * failure reason, retry count, human-time added, per-row retry/
 * dismiss/delete links) / empty state render surface, the
 * bulk-action processed/errors redirect envelope, the single-action
 * success/error redirect envelopes, the type-badge and item-action
 * helpers, and the page-URL builder.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Managers\`. The
 *    class is named `DlqManager` to disambiguate from the ported
 *    runtime `Queues\DeadLetterQueue` (E2).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerManagers()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`).
 *  - The dead-letter queue resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the base
 *    `WP_MCP_AI_Dead_Letter_Queue` monolith / the platform's
 *    `Queues\DeadLetterQueue` standalone (byte-compatible static
 *    `get_all()`/`get_stats()`/`retry()`/`dismiss()`/`remove()`
 *    contract — the E2 port). The base calls the class
 *    unconditionally; the port degrades the render to an empty
 *    listing + zeroed stats when no queue class resolves (additive
 *    guard, documented).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Managers
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Managers;

/**
 * Renders the Dead Letter Queue management UI.
 *
 * @since 2.0.0
 */
class DlqManager {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-dlq-manager';

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
		\add_action( 'admin_menu', array( $this, 'register_page' ), 16 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'admin_post_wp_mcp_ai_dlq_bulk_action', array( $this, 'handle_bulk_action' ) );
		\add_action( 'admin_post_wp_mcp_ai_dlq_single_action', array( $this, 'handle_single_action' ) );
	}

	/**
	 * Dead-letter queue class name (per-mode seam).
	 *
	 * @return string|null
	 */
	protected static function dlq_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			return 'WP_MCP_AI_Dead_Letter_Queue';
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\Queues\DeadLetterQueue' ) ) {
			return 'NvoosContentGraphAiPlatform\Queues\DeadLetterQueue';
		}

		return null;
	}

	/**
	 * Register the DLQ manager page under the NV Platform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Dead Letter Queue', 'nvoos-content-graph-ai-platform' ),
			__( 'Dead Letter Queue', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue styles for the DLQ manager page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
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
		\wp_register_style( 'wp-mcp-ai-dlq-inline', false );
		\wp_enqueue_style( 'wp-mcp-ai-dlq-inline' );
		\wp_add_inline_style( 'wp-mcp-ai-dlq-inline', $inline_css );
	}

	/**
	 * Handle bulk actions from the DLQ table.
	 *
	 * @return void
	 */
	public function handle_bulk_action(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to manage the dead letter queue.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_dlq_bulk_action' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer above.
		$action   = isset( $_POST['action'] ) ? \sanitize_key( \wp_unslash( $_POST['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer above.
		$item_ids = isset( $_POST['dlq_items'] ) ? array_map( 'sanitize_key', \wp_unslash( (array) $_POST['dlq_items'] ) ) : array();

		if ( empty( $action ) || empty( $item_ids ) ) {
			\wp_safe_redirect( $this->get_page_url( array( 'error' => 'missing_params' ) ) );
			exit;
		}

		$dlq_class = self::dlq_class();
		$processed = 0;
		$errors    = 0;

		foreach ( $item_ids as $item_id ) {
			$result = false;

			if ( null !== $dlq_class ) {
				switch ( $action ) {
					case 'retry':
						$result = $dlq_class::retry( $item_id );
						break;

					case 'dismiss':
						$result = $dlq_class::dismiss( $item_id );
						break;

					case 'delete':
						$result = $dlq_class::remove( $item_id );
						break;
				}
			}

			if ( $result && ! \is_wp_error( $result ) ) {
				++$processed;
			} else {
				++$errors;
			}
		}

		\wp_safe_redirect(
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
	 *
	 * @return void
	 */
	public function handle_single_action(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to manage the dead letter queue.', 'nvoos-content-graph-ai-platform' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-post GET parameters; the per-action nonce is verified by check_admin_referer below.
		$item_id    = isset( $_GET['item_id'] ) ? \sanitize_key( \wp_unslash( $_GET['item_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-post GET parameters; the per-action nonce is verified by check_admin_referer below.
		$dlq_action = isset( $_GET['dlq_action'] ) ? \sanitize_key( \wp_unslash( $_GET['dlq_action'] ) ) : '';

		if ( '' === $item_id || '' === $dlq_action ) {
			\wp_die( \esc_html__( 'Missing parameters.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_dlq_' . $dlq_action . '_' . $item_id );

		$result    = false;
		$dlq_class = self::dlq_class();

		if ( null !== $dlq_class ) {
			switch ( $dlq_action ) {
				case 'retry':
					$result = $dlq_class::retry( $item_id );
					break;

				case 'dismiss':
					$result = $dlq_class::dismiss( $item_id );
					break;

				case 'delete':
					$result = $dlq_class::remove( $item_id );
					break;
			}
		}

		$redirect_args = array( 'action_result' => $dlq_action );

		if ( \is_wp_error( $result ) ) {
			$redirect_args['error'] = $result->get_error_code();
		} else {
			$redirect_args['success'] = '1';
		}

		\wp_safe_redirect( $this->get_page_url( $redirect_args ) );
		exit;
	}

	/**
	 * Render the DLQ manager page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get filter parameters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters for filtering.
		$filter_type      = isset( $_GET['filter_type'] ) ? \sanitize_key( \wp_unslash( $_GET['filter_type'] ) ) : '';
		$filter_dismissed = isset( $_GET['filter_dismissed'] ) ? \sanitize_key( \wp_unslash( $_GET['filter_dismissed'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Build filters array.
		$filters = array();
		if ( '' !== $filter_type && 'all' !== $filter_type ) {
			$filters['type'] = $filter_type;
		}
		if ( '' !== $filter_dismissed ) {
			$filters['dismissed'] = ( 'yes' === $filter_dismissed );
		}

		$dlq_class = self::dlq_class();

		if ( null !== $dlq_class ) {
			$items = $dlq_class::get_all( $filters );
			$stats = $dlq_class::get_stats();
		} else {
			// Additive guard: no queue class resolves — degrade to the
			// byte-identical empty state with zeroed statistics.
			$items = array();
			$stats = array(
				'total'     => 0,
				'active'    => 0,
				'dismissed' => 0,
			);
		}

		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Dead Letter Queue', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<?php $this->render_notices(); ?>

			<div class="wp-mcp-ai-dlq__intro">
				<p><strong><?php \esc_html_e( 'About Dead Letter Queue', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
				<p><?php \esc_html_e( 'The Dead Letter Queue (DLQ) stores failed jobs, webhooks, and async operations that exceeded maximum retry attempts. Items here can be retried manually, dismissed, or deleted.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>

			<?php $this->render_statistics( $stats ); ?>
			<?php $this->render_filters( $filter_type, $filter_dismissed ); ?>

			<?php if ( empty( $items ) ) : ?>
				<div class="wp-mcp-ai-dlq__empty">
					<h3><?php \esc_html_e( 'No items in dead letter queue', 'nvoos-content-graph-ai-platform' ); ?></h3>
					<p><?php \esc_html_e( 'This is good! All your jobs and webhooks are completing successfully.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php else : ?>
				<?php $this->render_table( $items ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	protected function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters for notices.
		if ( isset( $_GET['success'] ) && '1' === \sanitize_key( \wp_unslash( $_GET['success'] ) ) ) {
			$action = isset( $_GET['action_result'] ) ? \sanitize_key( \wp_unslash( $_GET['action_result'] ) ) : '';
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					switch ( $action ) {
						case 'retry':
							\esc_html_e( 'Item successfully retried.', 'nvoos-content-graph-ai-platform' );
							break;
						case 'dismiss':
							\esc_html_e( 'Item dismissed.', 'nvoos-content-graph-ai-platform' );
							break;
						case 'delete':
							\esc_html_e( 'Item deleted.', 'nvoos-content-graph-ai-platform' );
							break;
						default:
							\esc_html_e( 'Action completed successfully.', 'nvoos-content-graph-ai-platform' );
					}
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['bulk_action'] ) ) {
			$action    = \sanitize_key( \wp_unslash( $_GET['bulk_action'] ) );
			$processed = isset( $_GET['processed'] ) ? \absint( \wp_unslash( $_GET['processed'] ) ) : 0;
			$errors    = isset( $_GET['errors'] ) ? \absint( \wp_unslash( $_GET['errors'] ) ) : 0;
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<?php
					\printf(
						/* translators: 1: number of items processed, 2: number of errors */
						\esc_html__( 'Bulk action completed: %1$d items processed, %2$d errors.', 'nvoos-content-graph-ai-platform' ),
						(int) $processed,
						(int) $errors
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['error'] ) ) {
			\sanitize_key( \wp_unslash( $_GET['error'] ) );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php \esc_html_e( 'An error occurred. Please try again.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>
			<?php
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render statistics boxes.
	 *
	 * @param array $stats Statistics array.
	 * @return void
	 */
	protected function render_statistics( $stats ): void {
		?>
		<div class="wp-mcp-ai-dlq__stats">
			<div class="wp-mcp-ai-dlq__stat">
				<div class="wp-mcp-ai-dlq__stat-label"><?php \esc_html_e( 'Total Items', 'nvoos-content-graph-ai-platform' ); ?></div>
				<div class="wp-mcp-ai-dlq__stat-value"><?php echo \esc_html( \number_format_i18n( $stats['total'] ) ); ?></div>
			</div>
			<div class="wp-mcp-ai-dlq__stat">
				<div class="wp-mcp-ai-dlq__stat-label"><?php \esc_html_e( 'Active', 'nvoos-content-graph-ai-platform' ); ?></div>
				<div class="wp-mcp-ai-dlq__stat-value"><?php echo \esc_html( \number_format_i18n( $stats['active'] ) ); ?></div>
			</div>
			<div class="wp-mcp-ai-dlq__stat">
				<div class="wp-mcp-ai-dlq__stat-label"><?php \esc_html_e( 'Dismissed', 'nvoos-content-graph-ai-platform' ); ?></div>
				<div class="wp-mcp-ai-dlq__stat-value"><?php echo \esc_html( \number_format_i18n( $stats['dismissed'] ) ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render filter form.
	 *
	 * @param string $filter_type      Current type filter.
	 * @param string $filter_dismissed Current dismissed filter.
	 * @return void
	 */
	protected function render_filters( $filter_type, $filter_dismissed ): void {
		?>
		<form method="get" class="wp-mcp-ai-dlq__filters">
			<input type="hidden" name="page" value="<?php echo \esc_attr( self::PAGE_SLUG ); ?>">

			<label>
				<?php \esc_html_e( 'Type:', 'nvoos-content-graph-ai-platform' ); ?>
				<select name="filter_type">
					<option value="all" <?php \selected( $filter_type, 'all' ); ?>><?php \esc_html_e( 'All Types', 'nvoos-content-graph-ai-platform' ); ?></option>
					<option value="webhook" <?php \selected( $filter_type, 'webhook' ); ?>><?php \esc_html_e( 'Webhooks', 'nvoos-content-graph-ai-platform' ); ?></option>
					<option value="cron_job" <?php \selected( $filter_type, 'cron_job' ); ?>><?php \esc_html_e( 'Cron Jobs', 'nvoos-content-graph-ai-platform' ); ?></option>
					<option value="async_tool" <?php \selected( $filter_type, 'async_tool' ); ?>><?php \esc_html_e( 'Async Tools', 'nvoos-content-graph-ai-platform' ); ?></option>
					<option value="job_queue" <?php \selected( $filter_type, 'job_queue' ); ?>><?php \esc_html_e( 'Job Queue', 'nvoos-content-graph-ai-platform' ); ?></option>
				</select>
			</label>

			<label>
				<?php \esc_html_e( 'Status:', 'nvoos-content-graph-ai-platform' ); ?>
				<select name="filter_dismissed">
					<option value="" <?php \selected( $filter_dismissed, '' ); ?>><?php \esc_html_e( 'All', 'nvoos-content-graph-ai-platform' ); ?></option>
					<option value="no" <?php \selected( $filter_dismissed, 'no' ); ?>><?php \esc_html_e( 'Active Only', 'nvoos-content-graph-ai-platform' ); ?></option>
					<option value="yes" <?php \selected( $filter_dismissed, 'yes' ); ?>><?php \esc_html_e( 'Dismissed Only', 'nvoos-content-graph-ai-platform' ); ?></option>
				</select>
			</label>

			<?php \submit_button( __( 'Filter', 'nvoos-content-graph-ai-platform' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render items table.
	 *
	 * @param array $items DLQ items.
	 * @return void
	 */
	protected function render_table( $items ): void {
		?>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp_mcp_ai_dlq_bulk_action">
			<?php \wp_nonce_field( 'wp_mcp_ai_dlq_bulk_action' ); ?>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<select name="action">
						<option value=""><?php \esc_html_e( 'Bulk Actions', 'nvoos-content-graph-ai-platform' ); ?></option>
						<option value="retry"><?php \esc_html_e( 'Retry', 'nvoos-content-graph-ai-platform' ); ?></option>
						<option value="dismiss"><?php \esc_html_e( 'Dismiss', 'nvoos-content-graph-ai-platform' ); ?></option>
						<option value="delete"><?php \esc_html_e( 'Delete', 'nvoos-content-graph-ai-platform' ); ?></option>
					</select>
					<?php \submit_button( __( 'Apply', 'nvoos-content-graph-ai-platform' ), 'action', '', false ); ?>
				</div>
			</div>

			<table class="wp-mcp-ai-dlq__table widefat">
				<thead>
					<tr>
						<th style="width:40px;"><input type="checkbox" id="select-all"></th>
						<th><?php \esc_html_e( 'Type', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Identifier', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Failure Reason', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Retries', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Added', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$dismissed_class = ! empty( $item['dismissed'] ) ? 'wp-mcp-ai-dlq__status--dismissed' : '';
						?>
						<tr class="<?php echo \esc_attr( $dismissed_class ); ?>">
							<td>
								<input type="checkbox" name="dlq_items[]" value="<?php echo \esc_attr( $item['id'] ); ?>">
							</td>
							<td>
								<?php echo \wp_kses_post( $this->format_type( $item['type'] ) ); ?>
							</td>
							<td>
								<code><?php echo \esc_html( $item['identifier'] ); ?></code>
								<?php if ( ! empty( $item['dismissed'] ) ) : ?>
									<br><em><?php \esc_html_e( '(Dismissed)', 'nvoos-content-graph-ai-platform' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<div class="wp-mcp-ai-dlq__error" title="<?php echo \esc_attr( $item['failure_reason'] ); ?>">
									<?php echo \esc_html( $item['failure_reason'] ); ?>
								</div>
							</td>
							<td>
								<span class="wp-mcp-ai-dlq__retry-count"><?php echo \esc_html( $item['retry_count'] ); ?></span>
							</td>
							<td>
								<?php echo \esc_html( \human_time_diff( $item['added_timestamp'], \time() ) ); ?> ago
							</td>
							<td class="wp-mcp-ai-dlq__actions">
								<?php echo \wp_kses_post( $this->render_item_actions( $item ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>

		<?php
		\ob_start();
		?>
		document.getElementById('select-all').addEventListener('change', function() {
			const checkboxes = document.querySelectorAll('input[name="dlq_items[]"]');
			checkboxes.forEach(cb => cb.checked = this.checked);
		});
		<?php
		$js = \ob_get_clean();
		\wp_print_inline_script_tag( $js );
	}

	/**
	 * Format item type as badge.
	 *
	 * @param string $type Item type.
	 * @return string HTML badge.
	 */
	protected function format_type( $type ) {
		$labels = array(
			'webhook'    => __( 'Webhook', 'nvoos-content-graph-ai-platform' ),
			'cron_job'   => __( 'Cron Job', 'nvoos-content-graph-ai-platform' ),
			'async_tool' => __( 'Async Tool', 'nvoos-content-graph-ai-platform' ),
			'job_queue'  => __( 'Job Queue', 'nvoos-content-graph-ai-platform' ),
		);

		$label = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
		$class = 'wp-mcp-ai-dlq__type wp-mcp-ai-dlq__type--' . \sanitize_html_class( $type );

		return \sprintf( '<span class="%s">%s</span>', \esc_attr( $class ), \esc_html( $label ) );
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
		$retry_url = \wp_nonce_url(
			\add_query_arg(
				array(
					'action'     => 'wp_mcp_ai_dlq_single_action',
					'item_id'    => $item_id,
					'dlq_action' => 'retry',
				),
				\admin_url( 'admin-post.php' )
			),
			'wp_mcp_ai_dlq_retry_' . $item_id
		);
		$actions[] = \sprintf(
			'<a href="%s">%s</a>',
			\esc_url( $retry_url ),
			\esc_html__( 'Retry', 'nvoos-content-graph-ai-platform' )
		);

		// Dismiss link (if not already dismissed).
		if ( empty( $item['dismissed'] ) ) {
			$dismiss_url = \wp_nonce_url(
				\add_query_arg(
					array(
						'action'     => 'wp_mcp_ai_dlq_single_action',
						'item_id'    => $item_id,
						'dlq_action' => 'dismiss',
					),
					\admin_url( 'admin-post.php' )
				),
				'wp_mcp_ai_dlq_dismiss_' . $item_id
			);
			$actions[]   = \sprintf(
				'<a href="%s">%s</a>',
				\esc_url( $dismiss_url ),
				\esc_html__( 'Dismiss', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Delete link.
		$delete_url = \wp_nonce_url(
			\add_query_arg(
				array(
					'action'     => 'wp_mcp_ai_dlq_single_action',
					'item_id'    => $item_id,
					'dlq_action' => 'delete',
				),
				\admin_url( 'admin-post.php' )
			),
			'wp_mcp_ai_dlq_delete_' . $item_id
		);
		$actions[]  = \sprintf(
			'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
			\esc_url( $delete_url ),
			\esc_js( __( 'Are you sure you want to delete this item?', 'nvoos-content-graph-ai-platform' ) ),
			\esc_html__( 'Delete', 'nvoos-content-graph-ai-platform' )
		);

		return \implode( ' | ', $actions );
	}

	/**
	 * Get page URL with query parameters.
	 *
	 * @param array $args Query arguments.
	 * @return string Page URL.
	 */
	protected function get_page_url( $args = array() ) {
		$base_args = array( 'page' => self::PAGE_SLUG );
		$args      = \array_merge( $base_args, $args );
		return \add_query_arg( $args, \admin_url( 'admin.php' ) );
	}
}
