<?php
/**
 * Privacy controls for chat transcript recording and data deletion.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles user privacy controls for WP oOS.
 */
class WP_MCP_AI_Privacy_Controls {

	/**
	 * User meta key for opt-out preference.
	 */
	const META_OPT_OUT_TRANSCRIPTS = '_wp_mcp_ai_opt_out_transcripts';

	/**
	 * User meta key for consent timestamp.
	 */
	const META_CONSENT_TIMESTAMP = '_wp_mcp_ai_consent_timestamp';

	/**
	 * User meta key for consent version.
	 */
	const META_CONSENT_VERSION = '_wp_mcp_ai_consent_version';

	/**
	 * Current consent version.
	 */
	const CONSENT_VERSION = '1.0';

	/**
	 * Initialize privacy controls.
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_privacy_settings' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_privacy_settings' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_privacy_settings' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_privacy_settings' ) );

		// Add privacy policy content.
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );

		// Register personal data exporters.
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );

		// Register personal data erasers.
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	/**
	 * Render privacy settings on user profile page.
	 *
	 * @param WP_User $user User object.
	 */
	public static function render_privacy_settings( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$opt_out         = get_user_meta( $user->ID, self::META_OPT_OUT_TRANSCRIPTS, true );
		$consent_time    = get_user_meta( $user->ID, self::META_CONSENT_TIMESTAMP, true );
		$consent_version = get_user_meta( $user->ID, self::META_CONSENT_VERSION, true );

		?>
		<h2><?php esc_html_e( 'AI Chat Privacy Settings', 'wp-mcp-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Chat Transcript Recording', 'wp-mcp-ai' ); ?></th>
				<td>
					<fieldset>
						<label for="wp_mcp_ai_opt_out_transcripts">
							<input 
								type="checkbox" 
								name="wp_mcp_ai_opt_out_transcripts" 
								id="wp_mcp_ai_opt_out_transcripts"
								value="1" 
								<?php checked( $opt_out, '1' ); ?> 
							/>
							<?php esc_html_e( 'Do not save my chat conversations on the server', 'wp-mcp-ai' ); ?>
						</label>
						<p class="description">
							<?php
							esc_html_e(
								'When enabled, your conversations will only be stored temporarily in your browser (24 hours) and will not be saved to the server permanently.',
								'wp-mcp-ai'
							);
							?>
						</p>
						<?php if ( $consent_time && $consent_version ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: consent date, 2: consent version */
									esc_html__( 'Consent given on %1$s (version %2$s)', 'wp-mcp-ai' ),
									esc_html( gmdate( 'F j, Y g:i a', absint( $consent_time ) ) ),
									esc_html( $consent_version )
								);
								?>
							</p>
						<?php endif; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete My Chat Data', 'wp-mcp-ai' ); ?></th>
				<td>
					<button 
						type="button" 
						class="button" 
						id="wp-mcp-ai-delete-chat-data"
						data-user-id="<?php echo esc_attr( $user->ID ); ?>"
					>
						<?php esc_html_e( 'Delete All My Chat Transcripts', 'wp-mcp-ai' ); ?>
					</button>
					<p class="description">
						<?php esc_html_e( 'This will permanently delete all your saved chat conversations from the server. This action cannot be undone.', 'wp-mcp-ai' ); ?>
					</p>
					<div id="wp-mcp-ai-delete-result" style="display:none; margin-top: 10px;"></div>
				</td>
			</tr>
		</table>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#wp-mcp-ai-delete-chat-data').on('click', function() {
				var button = $(this);
				var userId = button.data('user-id');
				var resultDiv = $('#wp-mcp-ai-delete-result');
				
				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete all your chat transcripts? This cannot be undone.', 'wp-mcp-ai' ) ); ?>')) {
					return;
				}
				
				button.prop('disabled', true).text('<?php echo esc_js( __( 'Deleting...', 'wp-mcp-ai' ) ); ?>');
				resultDiv.hide();
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wp_mcp_ai_delete_user_chat_data',
						user_id: userId,
						nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_delete_chat_data_' . $user->ID ) ); ?>'
					},
					success: function(response) {
						button.prop('disabled', false).text('<?php echo esc_js( __( 'Delete All My Chat Transcripts', 'wp-mcp-ai' ) ); ?>');
						if (response.success) {
							resultDiv
								.removeClass('notice-error')
								.addClass('notice notice-success')
								.html('<p>' + response.data.message + '</p>')
								.show();
						} else {
							resultDiv
								.removeClass('notice-success')
								.addClass('notice notice-error')
								.html('<p>' + response.data.message + '</p>')
								.show();
						}
					},
					error: function() {
						button.prop('disabled', false).text('<?php echo esc_js( __( 'Delete All My Chat Transcripts', 'wp-mcp-ai' ) ); ?>');
						resultDiv
							.removeClass('notice-success')
							.addClass('notice notice-error')
							.html('<p><?php echo esc_js( __( 'An error occurred. Please try again.', 'wp-mcp-ai' ) ); ?></p>')
							.show();
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Save privacy settings.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_privacy_settings( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Check nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-user_' . $user_id ) ) {
			return;
		}

		$opt_out = isset( $_POST['wp_mcp_ai_opt_out_transcripts'] ) ? '1' : '0';
		update_user_meta( $user_id, self::META_OPT_OUT_TRANSCRIPTS, $opt_out );

		// If user opted in, record consent.
		if ( '0' === $opt_out ) {
			update_user_meta( $user_id, self::META_CONSENT_TIMESTAMP, time() );
			update_user_meta( $user_id, self::META_CONSENT_VERSION, self::CONSENT_VERSION );
		}
	}

	/**
	 * Check if user has opted out of transcript recording.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if user opted out.
	 */
	public static function has_user_opted_out( $user_id ) {
		$opt_out = get_user_meta( absint( $user_id ), self::META_OPT_OUT_TRANSCRIPTS, true );
		return '1' === $opt_out;
	}

	/**
	 * Add suggested privacy policy content.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = self::get_privacy_policy_content();

		wp_add_privacy_policy_content(
			__( 'WP Open Operator System (WP oOS)', 'wp-mcp-ai' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}

	/**
	 * Get privacy policy content.
	 *
	 * @return string Privacy policy content.
	 */
	private static function get_privacy_policy_content() {
		return __(
			'<h2>AI Chat Assistants</h2>

<p>Our website uses AI-powered chat assistants to provide automated support and information. When you interact with our AI assistants, the following data practices apply:</p>

<h3>Data Collection</h3>

<p>We collect and process:</p>
<ul>
<li>Your messages sent to the AI assistant</li>
<li>AI-generated responses</li>
<li>Conversation timestamps</li>
<li>Your WordPress user account (if logged in)</li>
</ul>

<h3>Data Storage</h3>

<p><strong>Browser Storage (Temporary)</strong></p>
<ul>
<li>Conversations are temporarily stored in your browser for 24 hours</li>
<li>This data is stored locally on your device only</li>
<li>You can clear this data through your browser settings</li>
</ul>

<p><strong>Server Storage (Optional)</strong></p>
<ul>
<li>With your consent, conversations may be saved to our server</li>
<li>Saved conversations are linked to your user account</li>
<li>You can view, export, or delete saved conversations in your Account Settings</li>
</ul>

<h3>Third-Party Processing</h3>

<p>Depending on the AI assistant, your messages may be processed by external AI providers. When using cloud-based providers, your messages are transmitted to their servers for processing. Please review their privacy policies:</p>
<ul>
<li>OpenAI Privacy Policy: https://openai.com/policies/privacy-policy</li>
<li>Google Privacy Policy: https://policies.google.com/privacy</li>
</ul>

<p>Some assistants use local processing only, meaning your data never leaves our server.</p>

<h3>Your Rights</h3>

<p>You have the right to:</p>
<ul>
<li>Access your stored conversations</li>
<li>Delete your conversations at any time</li>
<li>Export your data in a portable format</li>
<li>Opt out of server-side storage (conversations only stored in browser)</li>
<li>Request information about third-party processing</li>
</ul>

<p>To exercise these rights, visit your Account Settings or contact us.</p>

<h3>Data Retention</h3>

<ul>
<li>Browser storage: 24 hours (automatic expiration)</li>
<li>Server storage: Until you delete or request deletion</li>
<li>Usage logs: 30 days</li>
</ul>

<h3>Security</h3>

<p>We protect your data using encrypted HTTPS transmission, WordPress security best practices, and access controls.</p>',
			'wp-mcp-ai'
		);
	}

	/**
	 * Register personal data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters.
	 */
	public static function register_exporters( $exporters ) {
		$exporters['wp-mcp-ai-chat-transcripts'] = array(
			'exporter_friendly_name' => __( 'AI Chat Transcripts', 'wp-mcp-ai' ),
			'callback'               => array( __CLASS__, 'export_chat_data' ),
		);
		return $exporters;
	}

	/**
	 * Export user's chat data for GDPR export.
	 *
	 * @param string $email_address User email address.
	 * @param int    $page          Page number.
	 * @return array Export data.
	 */
	public static function export_chat_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data_to_export = array();

		// Export privacy settings.
		$opt_out         = get_user_meta( $user->ID, self::META_OPT_OUT_TRANSCRIPTS, true );
		$consent_time    = get_user_meta( $user->ID, self::META_CONSENT_TIMESTAMP, true );
		$consent_version = get_user_meta( $user->ID, self::META_CONSENT_VERSION, true );

		$settings_data = array(
			array(
				'name'  => __( 'Transcript Recording Opt-Out', 'wp-mcp-ai' ),
				'value' => '1' === $opt_out ? __( 'Yes', 'wp-mcp-ai' ) : __( 'No', 'wp-mcp-ai' ),
			),
		);

		if ( $consent_time ) {
			$settings_data[] = array(
				'name'  => __( 'Consent Given', 'wp-mcp-ai' ),
				'value' => gmdate( 'Y-m-d H:i:s', absint( $consent_time ) ),
			);
		}

		if ( $consent_version ) {
			$settings_data[] = array(
				'name'  => __( 'Consent Version', 'wp-mcp-ai' ),
				'value' => $consent_version,
			);
		}

		$data_to_export[] = array(
			'group_id'    => 'wp-mcp-ai-privacy-settings',
			'group_label' => __( 'AI Chat Privacy Settings', 'wp-mcp-ai' ),
			'item_id'     => 'user-' . $user->ID,
			'data'        => $settings_data,
		);

		// Export chat transcripts if JetEngine is available.
		if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$transcripts = self::get_user_transcripts( $user->ID, $page );

			foreach ( $transcripts as $transcript ) {
				$transcript_data = array(
					array(
						'name'  => __( 'Session Key', 'wp-mcp-ai' ),
						'value' => isset( $transcript['session_key'] ) ? $transcript['session_key'] : '',
					),
					array(
						'name'  => __( 'Assistant ID', 'wp-mcp-ai' ),
						'value' => isset( $transcript['assistant_id'] ) ? $transcript['assistant_id'] : '',
					),
					array(
						'name'  => __( 'Timestamp', 'wp-mcp-ai' ),
						'value' => isset( $transcript['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', absint( $transcript['timestamp'] ) ) : '',
					),
					array(
						'name'  => __( 'Provider', 'wp-mcp-ai' ),
						'value' => isset( $transcript['provider'] ) ? $transcript['provider'] : '',
					),
					array(
						'name'  => __( 'Model', 'wp-mcp-ai' ),
						'value' => isset( $transcript['model'] ) ? $transcript['model'] : '',
					),
					array(
						'name'  => __( 'Messages', 'wp-mcp-ai' ),
						'value' => isset( $transcript['messages'] ) ? wp_json_encode( $transcript['messages'] ) : '',
					),
				);

				$data_to_export[] = array(
					'group_id'    => 'wp-mcp-ai-chat-transcripts',
					'group_label' => __( 'AI Chat Transcripts', 'wp-mcp-ai' ),
					'item_id'     => 'transcript-' . ( isset( $transcript['_ID'] ) ? $transcript['_ID'] : '' ),
					'data'        => $transcript_data,
				);
			}
		}

		return array(
			'data' => $data_to_export,
			'done' => true,
		);
	}

	/**
	 * Register personal data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers.
	 */
	public static function register_erasers( $erasers ) {
		$erasers['wp-mcp-ai-chat-transcripts'] = array(
			'eraser_friendly_name' => __( 'AI Chat Transcripts', 'wp-mcp-ai' ),
			'callback'             => array( __CLASS__, 'erase_chat_data' ),
		);
		return $erasers;
	}

	/**
	 * Erase user's chat data for GDPR erasure.
	 *
	 * @param string $email_address User email address.
	 * @param int    $page          Page number.
	 * @return array Erasure result.
	 */
	public static function erase_chat_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		// Delete chat transcripts if JetEngine is available.
		if ( function_exists( 'jet_engine' ) ) {
			$deleted_count = self::delete_user_transcripts( $user->ID );

			if ( $deleted_count > 0 ) {
				$items_removed = true;
				$messages[]    = sprintf(
					/* translators: %d: number of transcripts deleted */
					__( 'Deleted %d chat transcript(s).', 'wp-mcp-ai' ),
					$deleted_count
				);
			}
		}

		// Remove privacy settings.
		delete_user_meta( $user->ID, self::META_OPT_OUT_TRANSCRIPTS );
		delete_user_meta( $user->ID, self::META_CONSENT_TIMESTAMP );
		delete_user_meta( $user->ID, self::META_CONSENT_VERSION );

		if ( ! $items_removed ) {
			$messages[] = __( 'No AI chat data found for this user.', 'wp-mcp-ai' );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Get user's chat transcripts from JetEngine CCT.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page number.
	 * @return array Transcripts.
	 */
	private static function get_user_transcripts( $user_id, $page = 1 ) {
		if ( ! function_exists( 'jet_engine' ) ) {
			return array();
		}

		$per_page = 10;

		// Try to get transcripts from JetEngine CCT.
		try {
			$args = array(
				'user_id' => absint( $user_id ),
				'number'  => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
			);

			$items = jet_engine()->cct->get_cct_items( 'chat_transcripts', $args );

			return is_array( $items ) ? $items : array();
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Delete user's chat transcripts from JetEngine CCT.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of transcripts deleted.
	 */
	private static function delete_user_transcripts( $user_id ) {
		if ( ! function_exists( 'jet_engine' ) ) {
			return 0;
		}

		$deleted_count = 0;

		try {
			// Get all transcripts for user.
			$transcripts = jet_engine()->cct->get_cct_items(
				'chat_transcripts',
				array(
					'user_id' => absint( $user_id ),
					'number'  => -1,
				)
			);

			if ( ! is_array( $transcripts ) ) {
				return 0;
			}

			// Delete each transcript.
			foreach ( $transcripts as $transcript ) {
				if ( isset( $transcript->_ID ) ) {
					$result = jet_engine()->cct->delete_cct_item( 'chat_transcripts', $transcript->_ID );
					if ( ! is_wp_error( $result ) ) {
						++$deleted_count;
					}
				}
			}
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to delete user chat transcripts',
				array(
					'user_id'   => $user_id,
					'exception' => $e->getMessage(),
				)
			);
		}

		return $deleted_count;
	}

	/**
	 * AJAX handler to delete user's chat data.
	 */
	public static function ajax_delete_user_chat_data() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! isset( $_POST['user_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-mcp-ai' ) ) );
		}

		$user_id = absint( $_POST['user_id'] );

		if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wp_mcp_ai_delete_chat_data_' . $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wp-mcp-ai' ) ) );
		}

		// Verify user can edit their own profile or is admin.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		// Delete transcripts.
		$deleted_count = self::delete_user_transcripts( $user_id );

		if ( $deleted_count > 0 ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: number of transcripts deleted */
						__( 'Successfully deleted %d chat transcript(s).', 'wp-mcp-ai' ),
						$deleted_count
					),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'message' => __( 'No chat transcripts found to delete.', 'wp-mcp-ai' ),
				)
			);
		}
	}
}

// Initialize privacy controls.
add_action( 'init', array( 'WP_MCP_AI_Privacy_Controls', 'init' ) );

// Register AJAX handler.
add_action( 'wp_ajax_wp_mcp_ai_delete_user_chat_data', array( 'WP_MCP_AI_Privacy_Controls', 'ajax_delete_user_chat_data' ) );
