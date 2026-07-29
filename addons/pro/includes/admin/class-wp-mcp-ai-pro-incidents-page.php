<?php
/**
 * Pro Incidents Admin Page
 *
 * Admin page for managing operational incidents. Provides a list view with
 * phase/severity filtering and a detail/edit view with phase transition
 * buttons and an append-only timeline.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Admin
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Incidents_Page' ) ) {
	/**
	 * Pro Incidents Admin Page class.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Pro_Incidents_Page {

		/**
		 * Page slug.
		 *
		 * @since 1.4.0
		 * @var string
		 */
		const PAGE_SLUG = 'nvoos-pro-incidents';

		/**
		 * Nonce action.
		 *
		 * @since 1.4.0
		 * @var string
		 */
		const NONCE_ACTION = 'wp_mcp_ai_incidents_admin';

		/**
		 * Page hook.
		 *
		 * @since 1.4.0
		 * @var string
		 */
		private string $page_hook = '';

		/**
		 * Constructor.
		 *
		 * @since 1.4.0
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ), 30 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the admin submenu page.
		 *
		 * Priority 30 places it after Maintenance (29).
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function register_page(): void {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Incidents', 'mcp-ai-wpoos-pro' ),
				__( 'Incidents', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets.
		 *
		 * @since 1.4.0
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public function enqueue_assets( string $hook ): void {
			$is_page = ! empty( $this->page_hook ) && $hook === $this->page_hook;

			if ( ! $is_page ) {
				$is_page = isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
			}

			if ( ! $is_page ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-pro-incidents',
				WP_MCP_AI_PRO_URL . 'assets/css/pro-incidents.css',
				array(),
				WP_MCP_AI_PRO_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-pro-incidents',
				WP_MCP_AI_PRO_URL . 'assets/js/pro-incidents.js',
				array( 'jquery' ),
				WP_MCP_AI_PRO_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-pro-incidents',
				'wpMcpAiIncidents',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
					'strings' => array(
						'detected'      => __( 'Detected', 'mcp-ai-wpoos-pro' ),
						'investigating' => __( 'Investigating', 'mcp-ai-wpoos-pro' ),
						'identified'    => __( 'Identified', 'mcp-ai-wpoos-pro' ),
						'monitoring'    => __( 'Monitoring', 'mcp-ai-wpoos-pro' ),
						'resolved'      => __( 'Resolved', 'mcp-ai-wpoos-pro' ),
						'minor'         => __( 'Minor', 'mcp-ai-wpoos-pro' ),
						'major'         => __( 'Major', 'mcp-ai-wpoos-pro' ),
						'critical'      => __( 'Critical', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		/**
		 * Render the incidents admin page.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function render_page(): void {
			$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
			$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

			?>
			<div class="wrap wp-mcp-ai-pro-incidents-page">
				<h1>
					<span class="dashicons dashicons-warning" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#dc3545;"></span>
					<?php esc_html_e( 'Incidents', 'mcp-ai-wpoos-pro' ); ?>
					<span class="pro-badge" style="display:inline-block;background:#2271b1;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;vertical-align:middle;margin-left:8px;">PRO</span>
				</h1>
				<?php
				if ( 'new' === $action ) {
					$this->render_form( 0 );
				} elseif ( 'edit' === $action && $id > 0 ) {
					$this->render_detail( $id );
				} else {
					$this->render_list();
				}
				?>
			</div>
			<?php
		}

		/**
		 * Render the incident list.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		private function render_list(): void {
			$phase_filter    = isset( $_GET['phase'] ) ? sanitize_text_field( wp_unslash( $_GET['phase'] ) ) : '';
			$severity_filter = isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : '';

			$meta_query = array();
			if ( '' !== $phase_filter ) {
				$meta_query[] = array(
					'key'   => '_mcp_ai_incident_phase',
					'value' => $phase_filter,
				);
			}
			if ( '' !== $severity_filter ) {
				$meta_query[] = array(
					'key'   => '_mcp_ai_incident_severity',
					'value' => $severity_filter,
				);
			}

			$args = array(
				'post_type'      => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);

			if ( ! empty( $meta_query ) ) {
				$args['meta_query'] = $meta_query;
			}

			$incidents = get_posts( $args );

			// Quick nav.
			echo '<p>';
			echo '<a href="' . esc_url( add_query_arg( 'action', 'new' ) ) . '" class="button button-primary">';
			esc_html_e( 'Report Incident', 'mcp-ai-wpoos-pro' );
			echo '</a> ';

			// Phase filter.
			$phases = array(
				''                                       => __( 'All Phases', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_DETECTED   => __( 'Detected', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_INVESTIGATING => __( 'Investigating', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_IDENTIFIED => __( 'Identified', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_MONITORING => __( 'Monitoring', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_RESOLVED   => __( 'Resolved', 'mcp-ai-wpoos-pro' ),
			);

			echo ' | <strong>' . esc_html__( 'Phase:', 'mcp-ai-wpoos-pro' ) . '</strong> ';
			foreach ( $phases as $value => $label ) {
				$url     = add_query_arg( 'phase', $value, remove_query_arg( 'action' ) );
				$current = ( $value === $phase_filter ) || ( '' === $value && '' === $phase_filter );
				echo '<a href="' . esc_url( $url ) . '" class="' . ( $current ? 'button button-small' : 'button-link' ) . '">' . esc_html( $label ) . '</a> ';
			}

			echo '</p>';

			if ( empty( $incidents ) ) {
				echo '<div class="notice notice-info inline"><p>';
				esc_html_e( 'No incidents found.', 'mcp-ai-wpoos-pro' );
				echo '</p></div>';
				return;
			}

			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Incident', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Phase', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Severity', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Services', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Created', 'mcp-ai-wpoos-pro' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $incidents as $incident ) {
				$phase        = get_post_meta( $incident->ID, '_mcp_ai_incident_phase', true );
				$severity     = get_post_meta( $incident->ID, '_mcp_ai_incident_severity', true );
				$services_raw = get_post_meta( $incident->ID, '_mcp_ai_incident_services', true );
				$services     = is_array( $services_raw ) ? $services_raw : array();

				$edit_url = add_query_arg(
					array(
						'action' => 'edit',
						'id'     => $incident->ID,
					)
				);

				$phase_class = '';
				switch ( $phase ) {
					case WP_MCP_AI_Incident_CPT::PHASE_DETECTED:
					case WP_MCP_AI_Incident_CPT::PHASE_INVESTIGATING:
						$phase_class = 'nvoos-badge--error';
						break;
					case WP_MCP_AI_Incident_CPT::PHASE_IDENTIFIED:
					case WP_MCP_AI_Incident_CPT::PHASE_MONITORING:
						$phase_class = 'nvoos-badge--warning';
						break;
					case WP_MCP_AI_Incident_CPT::PHASE_RESOLVED:
						$phase_class = 'nvoos-badge--completed';
						break;
				}

				echo '<tr>';
				echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $incident->post_title ) . '</a></strong></td>';
				echo '<td><span class="nvoos-badge ' . esc_attr( $phase_class ) . '">' . esc_html( WP_MCP_AI_Incident_CPT::get_phase_label( $phase ) ) . '</span></td>';
				echo '<td>' . esc_html( $severity ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $services ) ) . '</td>';
				echo '<td>' . esc_html( $incident->post_date ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		/**
		 * Render the create form.
		 *
		 * @since 1.4.0
		 *
		 * @param int $incident_id Incident ID (0 for new).
		 * @return void
		 */
		private function render_form( int $incident_id ): void {
			$is_new = 0 === $incident_id;
			?>
			<h2><?php $is_new ? esc_html_e( 'Report New Incident', 'mcp-ai-wpoos-pro' ) : esc_html_e( 'Edit Incident', 'mcp-ai-wpoos-pro' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="wp_mcp_ai_incident_save">
				<input type="hidden" name="incident_id" value="<?php echo esc_attr( (string) $incident_id ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>">

				<table class="form-table">
					<tr>
						<th><label for="inc-title"><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><input type="text" id="inc-title" name="title" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="inc-severity"><?php esc_html_e( 'Severity', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td>
							<select id="inc-severity" name="severity">
								<option value="minor"><?php esc_html_e( 'Minor', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="major"><?php esc_html_e( 'Major', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="critical"><?php esc_html_e( 'Critical', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Affected Services', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							if ( class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
								$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
								$sources  = $registry->get_sources();
								foreach ( $sources as $slug => $source ) {
									echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="services[]" value="' . esc_attr( $slug ) . '"> ' . esc_html( $source->get_name() ) . '</label><br>';
								}
							}
							?>
						</td>
					</tr>
					<tr>
						<th><label for="inc-message"><?php esc_html_e( 'Initial Message', 'mcp-ai-wpoos-pro' ); ?></label></th>
						<td><textarea id="inc-message" name="message" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Notify Channels', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$channels = array(
								'email'    => __( 'Email', 'mcp-ai-wpoos-pro' ),
								'telegram' => __( 'Telegram', 'mcp-ai-wpoos-pro' ),
								'slack'    => __( 'Slack', 'mcp-ai-wpoos-pro' ),
								'webhook'  => __( 'Outbound Webhook', 'mcp-ai-wpoos-pro' ),
							);
							foreach ( $channels as $slug => $label ) {
								echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="notify_channels[]" value="' . esc_attr( $slug ) . '"> ' . esc_html( $label ) . '</label><br>';
							}
							?>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Report Incident', 'mcp-ai-wpoos-pro' ); ?></button>
					<a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?></a>
				</p>
			</form>
			<?php
		}

		/**
		 * Render the incident detail/edit view.
		 *
		 * @since 1.4.0
		 *
		 * @param int $incident_id Incident post ID.
		 * @return void
		 */
		private function render_detail( int $incident_id ): void {
			$post = get_post( $incident_id );

			if ( ! $post || WP_MCP_AI_Incident_CPT::POST_TYPE !== $post->post_type ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Incident not found.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
				return;
			}

			$phase        = get_post_meta( $incident_id, '_mcp_ai_incident_phase', true );
			$severity     = get_post_meta( $incident_id, '_mcp_ai_incident_severity', true );
			$services_raw = get_post_meta( $incident_id, '_mcp_ai_incident_services', true );
			$services     = is_array( $services_raw ) ? $services_raw : array();
			$timeline     = get_post_meta( $incident_id, '_mcp_ai_incident_timeline', true );
			$resolved     = get_post_meta( $incident_id, '_mcp_ai_incident_resolved_at', true );
			$lesson_id    = (int) get_post_meta( $incident_id, '_mcp_ai_incident_lesson_id', true );

			if ( ! is_array( $timeline ) ) {
				$timeline = array();
			}

			$is_resolved = WP_MCP_AI_Incident_CPT::PHASE_RESOLVED === $phase;

			?>
			<p>
				<a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to incidents', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>

			<div class="wp-mcp-ai-incident-detail">
				<h2><?php echo esc_html( $post->post_title ); ?></h2>

				<div class="wp-mcp-ai-incident-meta">
					<div class="wp-mcp-ai-incident-meta-item">
						<strong><?php esc_html_e( 'Phase:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<span><?php echo esc_html( WP_MCP_AI_Incident_CPT::get_phase_label( $phase ) ); ?></span>
					</div>
					<div class="wp-mcp-ai-incident-meta-item">
						<strong><?php esc_html_e( 'Severity:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<span class="nvoos-severity nvoos-severity--<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( $severity ); ?></span>
					</div>
					<?php if ( ! empty( $services ) ) : ?>
						<div class="wp-mcp-ai-incident-meta-item">
							<strong><?php esc_html_e( 'Services:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<span><?php echo esc_html( implode( ', ', $services ) ); ?></span>
						</div>
					<?php endif; ?>
					<?php if ( $is_resolved && ! empty( $resolved ) ) : ?>
						<div class="wp-mcp-ai-incident-meta-item">
							<strong><?php esc_html_e( 'Resolved:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<span><?php echo esc_html( $resolved ); ?></span>
						</div>
					<?php endif; ?>
					<?php if ( $lesson_id > 0 ) : ?>
						<div class="wp-mcp-ai-incident-meta-item">
							<strong><?php esc_html_e( 'Lesson:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<a href="<?php echo esc_url( get_edit_post_link( $lesson_id ) ); ?>"><?php esc_html_e( 'View Post-Mortem', 'mcp-ai-wpoos-pro' ); ?></a>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( ! $is_resolved ) : ?>
					<div class="wp-mcp-ai-incident-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<input type="hidden" name="action" value="wp_mcp_ai_incident_transition">
							<input type="hidden" name="incident_id" value="<?php echo esc_attr( (string) $incident_id ); ?>">
							<input type="hidden" name="redirect_to" value="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'action' => 'edit',
										'id'     => $incident_id,
									)
								)
							);
							?>
																			">

							<?php
							$transitions = WP_MCP_AI_Incident_CPT::VALID_TRANSITIONS[ $phase ] ?? array();

							foreach ( $transitions as $target ) {
								$button_class = WP_MCP_AI_Incident_CPT::PHASE_RESOLVED === $target ? 'button-primary' : 'button';
								$label        = WP_MCP_AI_Incident_CPT::get_phase_label( $target );

								echo '<button type="submit" name="new_phase" value="' . esc_attr( $target ) . '" class="button ' . esc_attr( $button_class ) . '" style="margin-right:6px;">';
								if ( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED === $target ) {
									echo '&#10003; ';
								}
								printf(
									/* translators: %s: phase label */
									esc_html__( 'Move to %s', 'mcp-ai-wpoos-pro' ),
									esc_html( $label )
								);
								echo '</button> ';
							}
							?>
							<input type="text" name="message" placeholder="<?php esc_attr_e( 'Update message...', 'mcp-ai-wpoos-pro' ); ?>" class="regular-text" style="vertical-align:middle;">
						</form>
					</div>
				<?php endif; ?>

				<!-- Timeline -->
				<div class="wp-mcp-ai-incident-timeline">
					<h3><?php esc_html_e( 'Timeline', 'mcp-ai-wpoos-pro' ); ?></h3>
					<?php if ( empty( $timeline ) ) : ?>
						<p class="description"><?php esc_html_e( 'No updates yet.', 'mcp-ai-wpoos-pro' ); ?></p>
					<?php else : ?>
						<?php foreach ( array_reverse( $timeline ) as $entry ) : ?>
							<div class="wp-mcp-ai-incident-timeline-entry">
								<div class="wp-mcp-ai-incident-timeline-time">
									<?php echo esc_html( gmdate( 'Y-m-d H:i:s', $entry['timestamp'] ?? 0 ) ); ?>
								</div>
								<div class="wp-mcp-ai-incident-timeline-phase">
									<span class="nvoos-badge"><?php echo esc_html( WP_MCP_AI_Incident_CPT::get_phase_label( $entry['phase'] ?? '' ) ); ?></span>
								</div>
								<div class="wp-mcp-ai-incident-timeline-message">
									<?php echo esc_html( $entry['message'] ?? '' ); ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
	}

	// Bootstrap.
	if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		new WP_MCP_AI_Pro_Incidents_Page();
	}
}
