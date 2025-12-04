<?php
/**
 * Master Key Rotation Admin Interface
 *
 * Provides admin UI for rotating the master encryption key.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Key_Rotation' ) ) {
	/**
	 * Admin interface for master key rotation.
	 */
	class WP_MCP_AI_Admin_Key_Rotation {
		/**
		 * Initialize the admin interface.
		 */
		public static function init() {
			add_action( 'admin_post_wp_mcp_ai_rotate_master_key', array( __CLASS__, 'handle_rotation_request' ) );
			add_action( 'admin_notices', array( __CLASS__, 'show_rotation_notices' ) );
		}

		/**
		 * Handle master key rotation request.
		 */
		public static function handle_rotation_request() {
			// Verify user has permission.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die(
					esc_html__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					esc_html__( 'Permission Denied', 'wp-mcp-ai' ),
					array( 'response' => 403 )
				);
			}

			// Verify nonce.
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wp_mcp_ai_rotate_master_key' ) ) {
				wp_die(
					esc_html__( 'Security check failed. Please try again.', 'wp-mcp-ai' ),
					esc_html__( 'Security Error', 'wp-mcp-ai' ),
					array( 'response' => 403 )
				);
			}

			// Perform rotation.
			$result = WP_MCP_AI_Encryption::rotate_master_key();

			if ( is_wp_error( $result ) ) {
				// Store error for display.
				set_transient(
					'wp_mcp_ai_key_rotation_error',
					array(
						'message' => $result->get_error_message(),
						'code'    => $result->get_error_code(),
						'data'    => $result->get_error_data(),
					),
					60
				);

				$redirect_url = add_query_arg(
					array(
						'page'               => 'wp-mcp-ai-settings',
						'key_rotation_error' => '1',
					),
					admin_url( 'admin.php' )
				);
			} else {
				// Success.
				set_transient( 'wp_mcp_ai_key_rotation_success', true, 60 );

				$redirect_url = add_query_arg(
					array(
						'page'                 => 'wp-mcp-ai-settings',
						'key_rotation_success' => '1',
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		/**
		 * Show admin notices for rotation results.
		 */
		public static function show_rotation_notices() {
			// Success notice.
			if ( isset( $_GET['key_rotation_success'] ) && get_transient( 'wp_mcp_ai_key_rotation_success' ) ) {
				delete_transient( 'wp_mcp_ai_key_rotation_success' );
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Master encryption key rotated successfully.', 'wp-mcp-ai' ); ?></strong>
						<?php esc_html_e( 'All encrypted secrets have been re-encrypted with the new key.', 'wp-mcp-ai' ); ?>
					</p>
				</div>
				<?php
			}

			// Error notice.
			if ( isset( $_GET['key_rotation_error'] ) ) {
				$error = get_transient( 'wp_mcp_ai_key_rotation_error' );
				if ( $error ) {
					delete_transient( 'wp_mcp_ai_key_rotation_error' );
					?>
					<div class="notice notice-error is-dismissible">
						<p>
							<strong><?php esc_html_e( 'Master key rotation failed:', 'wp-mcp-ai' ); ?></strong>
							<?php echo esc_html( $error['message'] ); ?>
						</p>
						<?php if ( ! empty( $error['data'] ) ) : ?>
							<p>
								<code><?php echo esc_html( wp_json_encode( $error['data'] ) ); ?></code>
							</p>
						<?php endif; ?>
						<p>
							<?php esc_html_e( 'All changes have been rolled back. No data was lost.', 'wp-mcp-ai' ); ?>
						</p>
					</div>
					<?php
				}
			}
		}

		/**
		 * Render the rotation UI section.
		 */
		public static function render_rotation_section() {
			$master_key_exists = get_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );
			?>
			<div class="wp-mcp-ai-key-rotation-section">
				<h3><?php esc_html_e( 'Master Encryption Key Rotation', 'wp-mcp-ai' ); ?></h3>
				
				<?php if ( $master_key_exists ) : ?>
					<p>
						<?php esc_html_e( 'Rotating the master encryption key will re-encrypt all stored secrets with a new key. This operation is atomic and will automatically roll back if any errors occur.', 'wp-mcp-ai' ); ?>
					</p>
					
					<p class="description">
						<strong><?php esc_html_e( 'Important:', 'wp-mcp-ai' ); ?></strong>
						<?php esc_html_e( 'Always create a database backup before rotating encryption keys. While the rotation process includes automatic rollback, a backup provides additional safety.', 'wp-mcp-ai' ); ?>
					</p>

					<?php self::render_rotation_stats(); ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( '<?php esc_attr_e( 'Are you sure you want to rotate the master encryption key? This will re-encrypt all secrets.', 'wp-mcp-ai' ); ?>' );">
						<?php wp_nonce_field( 'wp_mcp_ai_rotate_master_key' ); ?>
						<input type="hidden" name="action" value="wp_mcp_ai_rotate_master_key">
						
						<p>
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Rotate Master Key', 'wp-mcp-ai' ); ?>
							</button>
						</p>
					</form>
				<?php else : ?>
					<p>
						<?php esc_html_e( 'No master encryption key has been generated yet. A key will be automatically created when you first encrypt data.', 'wp-mcp-ai' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render rotation statistics.
		 */
		private static function render_rotation_stats() {
			global $wpdb;

			// Count encrypted secrets.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
					WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY
				)
			);

			if ( $count > 0 ) {
				?>
				<p>
					<strong><?php esc_html_e( 'Encrypted secrets:', 'wp-mcp-ai' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of encrypted secrets */
							_n( '%d secret will be re-encrypted', '%d secrets will be re-encrypted', $count, 'wp-mcp-ai' ),
							$count
						)
					);
					?>
				</p>
				<?php
			}
		}
	}
}
