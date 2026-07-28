<?php
/**
 * Pro Maintenance Admin Page
 *
 * Admin page for managing maintenance windows. Provides a list view with
 * status filtering and a create/edit form with datetime pickers, service
 * multi-select, and notification channel configuration.
 *
 * Registered under the NV oOS Pro Dashboard menu.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Admin
 * @since     1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Maintenance_Page' ) ) {
	/**
	 * Pro Maintenance Admin Page class.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Pro_Maintenance_Page {

		/**
		 * Page slug.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const PAGE_SLUG = 'nvoos-pro-maintenance';

		/**
		 * Nonce action.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const NONCE_ACTION = 'wp_mcp_ai_maintenance_admin';

		/**
		 * Page hook.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		private string $page_hook = '';

		/**
		 * Constructor.
		 *
		 * @since 1.3.0
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ), 29 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the admin submenu page.
		 *
		 * Priority 29 places it after the Status Page (28).
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public function register_page(): void {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Maintenance', 'mcp-ai-wpoos-pro' ),
				__( 'Maintenance', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets.
		 *
		 * @since 1.3.0
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public function enqueue_assets( string $hook ): void {
			$is_page = ! empty( $this->page_hook ) && $hook === $this->page_hook;

			if ( ! $is_page ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$is_page = isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
			}

			if ( ! $is_page ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-pro-maintenance',
				WP_MCP_AI_PRO_URL . 'assets/css/pro-maintenance.css',
				array(),
				WP_MCP_AI_PRO_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-pro-maintenance',
				WP_MCP_AI_PRO_URL . 'assets/js/pro-maintenance.js',
				array( 'jquery' ),
				WP_MCP_AI_PRO_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-pro-maintenance',
				'wpMcpAiMaintenance',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
					'strings' => array(
						'confirmCancel' => __( 'Are you sure you want to cancel this maintenance window?', 'mcp-ai-wpoos-pro' ),
						'confirmDelete' => __( 'Are you sure you want to permanently delete this window?', 'mcp-ai-wpoos-pro' ),
						'scheduled'     => __( 'Scheduled', 'mcp-ai-wpoos-pro' ),
						'inProgress'    => __( 'In Progress', 'mcp-ai-wpoos-pro' ),
						'completed'     => __( 'Completed', 'mcp-ai-wpoos-pro' ),
						'cancelled'     => __( 'Cancelled', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		/**
		 * Render the maintenance admin page.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public function render_page(): void {
			$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
			$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

			?>
			<div class="wrap wp-mcp-ai-pro-maintenance-page">
				<h1>
					<span class="dashicons dashicons-hammer" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Maintenance Windows', 'mcp-ai-wpoos-pro' ); ?>
					<span class="pro-badge" style="display:inline-block;background:#2271b1;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;vertical-align:middle;margin-left:8px;">PRO</span>
				</h1>

				<?php
				if ( 'new' === $action || 'edit' === $action ) {
					$this->render_form( 'edit' === $action ? $id : 0 );
				} else {
					$this->render_list();
				}
				?>
			</div>
			<?php
		}

		/**
		 * Render the maintenance window list.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		private function render_list(): void {
			$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

			$args = array(
				'post_type'      => WP_MCP_AI_Maintenance_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);

			if ( '' !== $status_filter ) {
				$args['meta_key']   = '_mcp_ai_maintenance_status';
				$args['meta_value'] = $status_filter;
			}

			$windows = get_posts( $args );

			// Quick nav.
			echo '<p>';
			echo '<a href="' . esc_url( add_query_arg( 'action', 'new' ) ) . '" class="button button-primary">';
			esc_html_e( 'Schedule Maintenance', 'mcp-ai-wpoos-pro' );
			echo '</a> ';

			$statuses = array(
				'' => __( 'All', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED => __( 'Scheduled', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Maintenance_CPT::STATUS_IN_PROGRESS => __( 'In Progress', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Maintenance_CPT::STATUS_COMPLETED => __( 'Completed', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Maintenance_CPT::STATUS_CANCELLED => __( 'Cancelled', 'mcp-ai-wpoos-pro' ),
			);

			foreach ( $statuses as $value => $label ) {
				$url     = add_query_arg( 'status', $value, remove_query_arg( 'action' ) );
				$current = ( $value === $status_filter ) || ( '' === $value && '' === $status_filter );
				$class   = $current ? 'button' : 'button-link';
				echo ' <a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
			}

			echo '</p>';

			if ( empty( $windows ) ) {
				echo '<div class="notice notice-info inline"><p>';
				esc_html_e( 'No maintenance windows found.', 'mcp-ai-wpoos-pro' );
				echo '</p></div>';
				return;
			}

			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Title', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Start', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'End', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Banner', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $windows as $window ) {
				$status = get_post_meta( $window->ID, '_mcp_ai_maintenance_status', true );
				$start  = get_post_meta( $window->ID, '_mcp_ai_maintenance_start', true );
				$end    = get_post_meta( $window->ID, '_mcp_ai_maintenance_end', true );
				$banner = (bool) get_post_meta( $window->ID, '_mcp_ai_maintenance_banner_enabled', true );

				$status_label = $status;
				$status_class = '';

				switch ( $status ) {
					case WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED:
						$status_class = 'nvoos-badge--scheduled';
						$status_label = __( 'Scheduled', 'mcp-ai-wpoos-pro' );
						break;
					case WP_MCP_AI_Maintenance_CPT::STATUS_IN_PROGRESS:
						$status_class = 'nvoos-badge--active';
						$status_label = __( 'In Progress', 'mcp-ai-wpoos-pro' );
						break;
					case WP_MCP_AI_Maintenance_CPT::STATUS_COMPLETED:
						$status_class = 'nvoos-badge--completed';
						$status_label = __( 'Completed', 'mcp-ai-wpoos-pro' );
						break;
					case WP_MCP_AI_Maintenance_CPT::STATUS_CANCELLED:
						$status_class = 'nvoos-badge--cancelled';
						$status_label = __( 'Cancelled', 'mcp-ai-wpoos-pro' );
						break;
				}

				echo '<tr>';
				echo '<td><strong>' . esc_html( $window->post_title ) . '</strong></td>';
				echo '<td><span class="nvoos-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td>';
				echo '<td>' . esc_html( $start ) . '</td>';
				echo '<td>' . esc_html( $end ) . '</td>';
				echo '<td>' . ( $banner ? '&#10003;' : '&#10007;' ) . '</td>';
				echo '<td>';
				$edit_url = add_query_arg(
					array(
						'action' => 'edit',
						'id'     => $window->ID,
					)
				);
				echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit', 'mcp-ai-wpoos-pro' ) . '</a> ';
				if ( WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED === $status || WP_MCP_AI_Maintenance_CPT::STATUS_IN_PROGRESS === $status ) {
					$cancel_url = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'cancel',
								'id'     => $window->ID,
							)
						),
						self::NONCE_ACTION . '_cancel_' . $window->ID
					);
					echo '<a href="' . esc_url( $cancel_url ) . '" class="button button-small button-link-delete" onclick="return confirm(wpMcpAiMaintenance.strings.confirmCancel);">' . esc_html__( 'Cancel', 'mcp-ai-wpoos-pro' ) . '</a>';
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		/**
		 * Render the create/edit form.
		 *
		 * @since 1.3.0
		 *
		 * @param int $window_id Window ID (0 for new).
		 * @return void
		 */
		private function render_form( int $window_id ): void {
			$is_new   = 0 === $window_id;
			$post     = $is_new ? null : get_post( $window_id );
			$title    = $post ? $post->post_title : '';
			$content  = $post ? $post->post_content : '';
			$status   = $post ? get_post_meta( $window_id, '_mcp_ai_maintenance_status', true ) : '';
			$start    = $post ? get_post_meta( $window_id, '_mcp_ai_maintenance_start', true ) : '';
			$end      = $post ? get_post_meta( $window_id, '_mcp_ai_maintenance_end', true ) : '';
			$services = $post ? get_post_meta( $window_id, '_mcp_ai_maintenance_services', true ) : array();
			$channels = $post ? get_post_meta( $window_id, '_mcp_ai_maintenance_notify_channels', true ) : array();
			$notify   = $post ? (int) get_post_meta( $window_id, '_mcp_ai_maintenance_notify_before', true ) : 60;
			$banner   = $post ? (bool) get_post_meta( $window_id, '_mcp_ai_maintenance_banner_enabled', true ) : true;

			if ( ! is_array( $services ) ) {
				$services = array();
			}
			if ( ! is_array( $channels ) ) {
				$channels = array();
			}

			?>
			<h2><?php $is_new ? esc_html_e( 'Schedule New Maintenance Window', 'mcp-ai-wpoos-pro' ) : esc_html_e( 'Edit Maintenance Window', 'mcp-ai-wpoos-pro' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="wp_mcp_ai_maintenance_save">
				<input type="hidden" name="window_id" value="<?php echo esc_attr( (string) $window_id ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>">

				<table class="form-table">
					<tr>
						<th><label for="maint-title"><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="text" id="maint-title" name="title" class="regular-text" value="<?php echo esc_attr( $title ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="maint-content"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><textarea id="maint-content" name="content" class="large-text" rows="5"><?php echo esc_textarea( $content ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="maint-start"><?php esc_html_e( 'Start Time', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="datetime-local" id="maint-start" name="start" value="<?php echo esc_attr( $start ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="maint-end"><?php esc_html_e( 'End Time', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="datetime-local" id="maint-end" name="end" value="<?php echo esc_attr( $end ); ?>" required></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Affected Services', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$all_services = self::get_available_services();
							foreach ( $all_services as $slug => $label ) {
								$checked = in_array( $slug, $services, true ) ? 'checked' : '';
								echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="services[]" value="' . esc_attr( $slug ) . '" ' . esc_attr( $checked ) . '> ' . esc_html( $label ) . '</label><br>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Notify Channels', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$all_channels = array(
								'email'    => __( 'Email', 'mcp-ai-wpoos-pro' ),
								'telegram' => __( 'Telegram', 'mcp-ai-wpoos-pro' ),
								'slack'    => __( 'Slack', 'mcp-ai-wpoos-pro' ),
								'discord'  => __( 'Discord', 'mcp-ai-wpoos-pro' ),
								'webhook'  => __( 'Outbound Webhook', 'mcp-ai-wpoos-pro' ),
							);
							foreach ( $all_channels as $slug => $label ) {
								$checked = in_array( $slug, $channels, true ) ? 'checked' : '';
								echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="channels[]" value="' . esc_attr( $slug ) . '" ' . esc_attr( $checked ) . '> ' . esc_html( $label ) . '</label><br>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><label for="maint-notify"><?php esc_html_e( 'Reminder (minutes before)', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="number" id="maint-notify" name="notify_before" value="<?php echo esc_attr( (string) $notify ); ?>" min="0" step="5" style="width:80px;"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Frontend Banner', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<label><input type="checkbox" name="banner_enabled" value="1" <?php checked( $banner ); ?>> <?php esc_html_e( 'Show banner on the frontend during this window', 'mcp-ai-wpoos-pro' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php $is_new ? esc_html_e( 'Schedule Maintenance', 'mcp-ai-wpoos-pro' ) : esc_html_e( 'Update Maintenance', 'mcp-ai-wpoos-pro' ); ?></button>
					<a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?></a>
				</p>
			</form>
			<?php
		}

		/**
		 * Get available service components for the form checkboxes.
		 *
		 * @since 1.3.0
		 *
		 * @return array<string, string>
		 */
		private static function get_available_services(): array {
			if ( ! class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
				return array();
			}

			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
			$sources  = $registry->get_sources();
			$services = array();

			foreach ( $sources as $slug => $source ) {
				$services[ $slug ] = $source->get_name();
			}

			return $services;
		}
	}

	// Bootstrap.
	if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		new WP_MCP_AI_Pro_Maintenance_Page();
	}
}
