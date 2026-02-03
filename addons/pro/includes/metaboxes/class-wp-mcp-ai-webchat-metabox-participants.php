<?php
/**
 * WebChat Participants Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the WebChat Participants metabox for webchat posts.
 *
 * Displays and manages active participants in the chat room.
 */
class WP_MCP_AI_WebChat_Metabox_Participants extends WP_MCP_AI_WebChat_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_webchat_participants';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Active Participants', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		// Enqueue scripts and styles for participant management.
		wp_enqueue_script(
			'wp-mcp-ai-webchat-participants',
			WP_MCP_AI_PRO_URL . 'assets/js/webchat-participants.js',
			array( 'jquery' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-webchat-participants',
			'wpMcpAiWebChatParticipants',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_webchat_participants_refresh' ),
				'postId'  => $post->ID,
			)
		);

		wp_enqueue_style(
			'wp-mcp-ai-webchat-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/webchat-admin.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Get active participants.
		$participants = $this->get_participants( $post->ID );
		?>
		<div class="wp-mcp-ai-webchat-participants-wrapper">
			<div class="participants-header">
				<p class="participants-count">
					<?php
					printf(
						/* translators: %d: Number of active participants */
						esc_html__( 'Current participants: %d', 'mcp-ai-wpoos-pro' ),
						count( $participants )
					);
					?>
				</p>
				<button type="button" id="wp-mcp-ai-refresh-participants" class="button button-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</div>

			<div id="wp-mcp-ai-participants-container" class="participants-container">
				<?php $this->render_participants_table( $participants ); ?>
			</div>

			<?php $this->render_documentation_link(); ?>
		</div>
		<?php
	}

	/**
	 * Render the participants table.
	 *
	 * @param array $participants Array of participant data.
	 * @return void
	 */
	protected function render_participants_table( $participants ) {
		if ( empty( $participants ) ) {
			?>
			<div class="no-participants-message">
				<p><?php esc_html_e( 'No participants currently connected to this room.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<table class="widefat striped participants-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Peer ID', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'User', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Join Time', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Last Seen', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $participants as $participant ) : ?>
					<tr>
						<td>
							<code><?php echo esc_html( $participant['peer_id'] ); ?></code>
						</td>
						<td>
							<?php
							if ( ! empty( $participant['user_id'] ) ) {
								$user = get_userdata( $participant['user_id'] );
								if ( $user ) {
									echo esc_html( $user->display_name );
								} else {
									esc_html_e( 'Unknown User', 'mcp-ai-wpoos-pro' );
								}
							} else {
								echo '<em>' . esc_html__( 'Anonymous', 'mcp-ai-wpoos-pro' ) . '</em>';
							}
							?>
						</td>
						<td>
							<?php echo esc_html( $this->format_timestamp( $participant['join_time'] ) ); ?>
						</td>
						<td>
							<?php echo esc_html( $this->format_relative_time( $participant['last_seen'] ) ); ?>
						</td>
						<td>
							<?php
							$status = $this->get_participant_status( $participant['last_seen'] );
							echo '<span class="participant-status participant-status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Get active participants for a room.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of participant data.
	 */
	protected function get_participants( $post_id ) {
		$participants = get_post_meta( $post_id, '_mcp_ai_webchat_participants', true );
		
		if ( ! is_array( $participants ) ) {
			return array();
		}

		// Filter out stale participants (not seen in 5 minutes).
		$current_time = time();
		$participants = array_filter(
			$participants,
			function( $participant ) use ( $current_time ) {
				return isset( $participant['last_seen'] ) && ( $current_time - $participant['last_seen'] ) < 300;
			}
		);

		// Update the filtered list.
		update_post_meta( $post_id, '_mcp_ai_webchat_participants', $participants );

		return $participants;
	}

	/**
	 * Format timestamp for display.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Formatted date and time.
	 */
	protected function format_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return __( 'Unknown', 'mcp-ai-wpoos-pro' );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Format relative time (e.g., "2 minutes ago").
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Relative time string.
	 */
	protected function format_relative_time( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return __( 'Unknown', 'mcp-ai-wpoos-pro' );
		}
		
		$time_diff = time() - $timestamp;
		
		if ( $time_diff < 60 ) {
			return __( 'Just now', 'mcp-ai-wpoos-pro' );
		} elseif ( $time_diff < 3600 ) {
			$minutes = floor( $time_diff / 60 );
			/* translators: %d: Number of minutes */
			return sprintf( _n( '%d minute ago', '%d minutes ago', $minutes, 'mcp-ai-wpoos-pro' ), $minutes );
		} elseif ( $time_diff < 86400 ) {
			$hours = floor( $time_diff / 3600 );
			/* translators: %d: Number of hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'mcp-ai-wpoos-pro' ), $hours );
		} else {
			return $this->format_timestamp( $timestamp );
		}
	}

	/**
	 * Get participant status based on last seen time.
	 *
	 * @param int $last_seen Unix timestamp of last activity.
	 * @return string Status: 'online', 'idle', or 'offline'.
	 */
	protected function get_participant_status( $last_seen ) {
		if ( empty( $last_seen ) ) {
			return 'offline';
		}

		$time_diff = time() - $last_seen;

		if ( $time_diff < 60 ) {
			return 'online';
		} elseif ( $time_diff < 300 ) {
			return 'idle';
		} else {
			return 'offline';
		}
	}

	/**
	 * Save metabox data.
	 *
	 * This metabox is read-only, so no save operation is needed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// No save operation needed - participant data is managed via AJAX.
	}
}
