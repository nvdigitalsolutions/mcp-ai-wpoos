<?php
/**
 * Import Gmail Emails to CRM Pipeline
 *
 * Bridge tool connecting raw Gmail inbox to the CRM SDR pipeline.
 * Fetches emails from Gmail via OAuth, then runs each through the full
 * inbound evaluation pipeline (classify intent → detect buying signals →
 * extract/upsert lead → score → qualify). Spam/non-sales emails are
 * filtered out by the classifier before reaching the CRM.
 *
 * Supports incremental sync via Gmail historyId (poll only changes since
 * last sync), idempotent processing via messageId dedup, and thread-level
 * grouping via Gmail threadId.
 *
 * Industry-standard pattern used by Copper, Streak, HubSpot, and
 * Salesflare: Gmail → SDR pipeline → CRM lead/deal records.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.4.0
 * @since 2.9.0 Added historyId incremental sync, messageId dedup,
 *              threadId tracking, and message logging via
 *              WP_MCP_AI_CRM_Message_Log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gmail → CRM pipeline bridge tool.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_Tool_Import_Gmail_To_CRM implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const GMAIL_API_BASE = 'https://gmail.googleapis.com/gmail/v1';

	/**
	 * Option key prefix for storing last historyId per connection.
	 *
	 * @since 2.9.0
	 * @var string
	 */
	const HISTORY_ID_OPTION_PREFIX = 'wp_mcp_ai_crm_gmail_history_id_';

	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	public function get_slug() {
		return 'import_gmail_to_crm'; }

	public function get_name() {
		return __( 'Import Gmail to CRM', 'mcp-ai-wpoos-pro' ); }

	public function get_description() {
		return __( 'Searches your Gmail inbox and imports matching emails into the CRM pipeline. Each email is classified for intent, scored, and upserted as a lead — spam and newsletters are automatically filtered out. Use this to turn raw inbox emails into structured CRM leads.', 'mcp-ai-wpoos-pro' ); }

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'       => array(
					'type'        => 'string',
					'description' => __( 'Gmail search query (same syntax as Gmail.com). Examples: "from:client.com", "subject:demo", "newer_than:7d is:unread".', 'mcp-ai-wpoos-pro' ),
				),
				'max_results' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum emails to process (1–25). Default 10.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 25,
					'default'     => 10,
				),
				'auto_reply'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Send auto-reply to new leads matching inquiry templates.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'query' ),
		); }

	public function get_required_capability() {
		return 'edit_posts'; }

	public function requires_base_pro() {
		return true; }

	public function get_capability_flags() {
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }

		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$query           = sanitize_text_field( $arguments['query'] ?? '' );
		$max_results     = min( 25, max( 1, absint( $arguments['max_results'] ?? 10 ) ) );
		$auto_reply      = ! empty( $arguments['auto_reply'] );
		$connection_id   = sanitize_text_field( $arguments['connection_id'] ?? '' );
		$use_history_sync = ! empty( $arguments['use_history_sync'] );

		if ( '' === $query ) {
			// Fall back to the configured default Gmail query from CRM settings.
			$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
				? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
				: array();
			$query    = $settings['integrations']['gmail_default_query'] ?? 'newer_than:7d is:unread';
		}

		if ( '' === $query ) {
			return new WP_Error( 'missing_query', __( 'A Gmail search query is required.', 'mcp-ai-wpoos-pro' ) ); }

		// Resolve Gmail credentials.
		$creds = $this->resolve_gmail_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		// Get access token.
		$access_token = $this->get_access_token( $creds['client_id'], $creds['client_secret'], $creds['refresh_token'] );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Search Gmail for matching messages (with optional historyId incremental sync).
		$gmail_user = $creds['user_email'] ?: 'me';

		if ( $use_history_sync && ! empty( $connection_id ) ) {
			$last_history_id = get_option( self::HISTORY_ID_OPTION_PREFIX . $connection_id, '' );
			if ( ! empty( $last_history_id ) ) {
				$messages = $this->list_history_changes( $gmail_user, $last_history_id, $max_results, $access_token, $connection_id );
				if ( is_wp_error( $messages ) ) {
					// Fall back to search-based if history fails.
					$messages = $this->list_and_fetch_messages( $gmail_user, $query, $max_results, $access_token, $connection_id );
				} elseif ( empty( $messages ) ) {
					// No changes — return early.
					return array(
						'success'       => true,
						'message'       => __( 'No new messages since last sync.', 'mcp-ai-wpoos-pro' ),
						'total_found'   => 0,
						'leads_created' => 0,
						'leads_updated' => 0,
						'skipped_spam'  => 0,
						'skipped_noise' => 0,
						'skipped_dupes' => 0,
						'results'       => array(),
					);
				}
			} else {
				$messages = $this->list_and_fetch_messages( $gmail_user, $query, $max_results, $access_token, $connection_id );
			}
		} else {
			$messages = $this->list_and_fetch_messages( $gmail_user, $query, $max_results, $access_token, $connection_id );
		}

		if ( is_wp_error( $messages ) ) {
			return $messages;
		}

		if ( empty( $messages ) ) {
			return array(
				'success'       => true,
				'message'       => __( 'No matching emails found in Gmail.', 'mcp-ai-wpoos-pro' ),
				'total_found'   => 0,
				'leads_created' => 0,
				'leads_updated' => 0,
				'skipped_spam'  => 0,
				'skipped_noise' => 0,
				'results'       => array(),
			);
		}

		// Load the evaluate_inbound_message tool.
		$_eval_file  = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php';
		$eval_loaded = false;
		if ( file_exists( $_eval_file ) ) {
			require_once $_eval_file;
			$eval_loaded = class_exists( 'WP_MCP_AI_Tool_Evaluate_Inbound_Message' );
		}

		if ( ! $eval_loaded ) {
			return new WP_Error( 'pipeline_unavailable', __( 'CRM inbound pipeline not available.', 'mcp-ai-wpoos-pro' ) );
		}

		// Process each email through the pipeline.
		$stats   = array(
			'total_found'   => count( $messages ),
			'leads_created' => 0,
			'leads_updated' => 0,
			'skipped_spam'  => 0,
			'skipped_noise' => 0,
			'skipped_dupes' => 0,
			'errors'        => 0,
		);
		$results = array();

		foreach ( $messages as $msg ) {
			$sender_email = $msg['from_email'] ?? '';
			$sender_name  = $msg['from_name'] ?? '';
			$subject      = $msg['subject'] ?? '';
			$body         = $msg['body'] ?? '';
			$gmail_id     = $msg['id'] ?? '';
			$thread_id    = $msg['thread_id'] ?? '';

			// ── Dedup check: skip if already imported. ──
			if ( ! empty( $gmail_id ) && class_exists( 'WP_MCP_AI_CRM_Message_Log' ) ) {
				if ( WP_MCP_AI_CRM_Message_Log::is_duplicate( 'email', $gmail_id, $connection_id ) ) {
					++$stats['skipped_dupes'];
					$results[] = array(
						'gmail_id' => $gmail_id,
						'subject'  => $subject,
						'from'     => $sender_email,
						'status'   => 'skipped_duplicate',
						'reason'   => __( 'Already imported — skipped.', 'mcp-ai-wpoos-pro' ),
					);
					continue;
				}
			}

			// ── Log raw message before pipeline processing. ──
			$message_log_id = 0;
			if ( class_exists( 'WP_MCP_AI_CRM_Message_Log' ) ) {
				$log_result = WP_MCP_AI_CRM_Message_Log::log(
					array(
						'message_id'    => $gmail_id,
						'thread_id'     => $thread_id,
						'channel'       => 'email',
						'sender_email'  => $sender_email,
						'sender_name'   => $sender_name,
						'subject'       => $subject,
						'body'          => $body,
						'source'        => 'gmail_import',
						'connection_id' => $connection_id,
					)
				);
				if ( ! is_wp_error( $log_result ) ) {
					$message_log_id = $log_result;
				}
			}

			if ( empty( $body ) && empty( $subject ) ) {
				++$stats['skipped_noise'];
				$results[] = array(
					'gmail_id' => $gmail_id,
					'subject'  => $subject,
					'from'     => $sender_email,
					'status'   => 'skipped_empty',
					'reason'   => __( 'Email has no content.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$tool        = new WP_MCP_AI_Tool_Evaluate_Inbound_Message();
			$eval_args   = array(
				'channel'         => 'email',
				'message_body'    => $body,
				'message_subject' => $subject,
				'sender_email'    => $sender_email,
				'sender_name'     => $sender_name,
				'source'          => 'gmail_import',
				'auto_reply'      => $auto_reply,
				'message_id'      => $gmail_id,
				'connection_id'   => $creds['connection_id'] ?? '',
			);
			$eval_result = $tool->execute( $eval_args, $context );

			if ( is_wp_error( $eval_result ) ) {
				++$stats['errors'];
				$results[] = array(
					'gmail_id' => $gmail_id,
					'subject'  => $subject,
					'from'     => $sender_email,
					'status'   => 'error',
					'reason'   => $eval_result->get_error_message(),
				);
				continue;
			}

			// Check if message was classified as spam.
			$classification = isset( $eval_result['pipeline']['classification'] )
				? $eval_result['pipeline']['classification']
				: array();
			$is_spam        = ! empty( $classification['is_spam'] );
			$intent         = $classification['intent'] ?? '';

			// Check if a lead was created or matched.
			$contact_id  = $eval_result['pipeline']['contact_id'] ?? 0;
			$is_new_lead = ! empty( $eval_result['pipeline']['is_new_lead'] );
			$lead_score  = $eval_result['pipeline']['lead_score'] ?? null;
			$score_label = $eval_result['pipeline']['score_label'] ?? '';

			if ( $is_spam ) {
				++$stats['skipped_spam'];
				$results[] = array(
					'gmail_id'   => $gmail_id,
					'subject'    => $subject,
					'from'       => $sender_email,
					'status'     => 'skipped_spam',
					'intent'     => $intent,
					'spam_score' => $classification['spam_probability'] ?? null,
				);
				continue;
			}

			// Check if the message was just noise (not a sales inquiry).
			$is_sales_intent = in_array( $intent, array( 'new_inquiry', 'demo_request', 'pricing_inquiry', 'support' ), true );
			if ( ! $is_sales_intent && 'general' === $intent ) {
				++$stats['skipped_noise'];
				$results[] = array(
					'gmail_id' => $gmail_id,
					'subject'  => $subject,
					'from'     => $sender_email,
					'status'   => 'skipped_noise',
					'intent'   => $intent,
					'reason'   => __( 'Not a sales inquiry — classified as general/noise.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			if ( $is_new_lead ) {
				++$stats['leads_created'];
			} elseif ( $contact_id ) {
				++$stats['leads_updated'];
			}

			// Link message log to contact.
			if ( $message_log_id && $contact_id && class_exists( 'WP_MCP_AI_CRM_Message_Log' ) ) {
				WP_MCP_AI_CRM_Message_Log::link_to_contact( $message_log_id, $contact_id );
			}

			$results[] = array(
				'gmail_id'       => $gmail_id,
				'thread_id'      => $thread_id,
				'subject'        => $subject,
				'from'           => $sender_email,
				'status'         => $is_new_lead ? 'lead_created' : 'lead_updated',
				'contact_id'     => $contact_id,
				'message_log_id' => $message_log_id,
				'intent'         => $intent,
				'lead_score'     => $lead_score,
				'score_label'    => $score_label,
				'buying_signals' => $eval_result['pipeline']['buying_signals'] ?? array(),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: total found, 2: leads created, 3: leads updated, 4: skipped spam, 5: skipped noise, 6: skipped duplicates */
				__( 'Processed %1$d emails: %2$d leads created, %3$d updated, %4$d spam filtered, %5$d noise skipped, %6$d duplicates skipped.', 'mcp-ai-wpoos-pro' ),
				$stats['total_found'],
				$stats['leads_created'],
				$stats['leads_updated'],
				$stats['skipped_spam'],
				$stats['skipped_noise'],
				$stats['skipped_dupes']
			),
			'stats'   => $stats,
			'results' => $results,
		);
	}

	/**
	 * Resolve Gmail OAuth credentials from settings or Remote Sites connections.
	 *
	 * @return array{client_id:string, client_secret:string, refresh_token:string, user_email:string}|WP_Error
	 */
	private function resolve_gmail_credentials() {
		// Try Remote Sites connections first.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $connections ) ) {
				foreach ( $connections as $cid => $conn ) {
					if ( isset( $conn['connection_type'] ) && 'gmail' === $conn['connection_type']
						&& ! empty( $conn['client_id'] ) && ! empty( $conn['refresh_token'] )
					) {
						$client_secret = isset( $conn['client_secret'] )
							? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $conn['client_secret'] )
							: '';
						$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $conn['refresh_token'] );

						return array(
							'client_id'     => trim( (string) $conn['client_id'] ),
							'client_secret' => $client_secret,
							'refresh_token' => $refresh_token,
							'user_email'    => isset( $conn['user_email'] ) ? trim( (string) $conn['user_email'] ) : '',
							'connection_id' => $cid,
						);
					}
				}
			}
		}

		// Fall back to settings-based credentials.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['gmail_client_id'] ) && ! empty( $settings['gmail_refresh_token'] ) ) {
				return array(
					'client_id'     => trim( (string) $settings['gmail_client_id'] ),
					'client_secret' => isset( $settings['gmail_client_secret'] ) ? trim( (string) $settings['gmail_client_secret'] ) : '',
					'refresh_token' => isset( $settings['gmail_refresh_token'] ) ? trim( (string) $settings['gmail_refresh_token'] ) : '',
					'user_email'    => isset( $settings['gmail_user_email'] ) ? trim( (string) $settings['gmail_user_email'] ) : '',
				);
			}
		}

		return new WP_Error(
			'gmail_not_configured',
			__( 'Gmail API credentials are not configured. Add a Gmail connection in Remote Sites or configure Gmail in NV oOS settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Exchange a refresh token for an access token.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $refresh_token OAuth refresh token.
	 * @return string|WP_Error Access token or error.
	 */
	private function get_access_token( $client_id, $client_secret, $refresh_token ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gmail_oauth_failed', $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unknown OAuth error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'gmail_oauth_failed', $err );
		}

		return $body['access_token'];
	}

	/**
	 * List messages matching the query, then fetch full details for each.
	 *
	 * @since 2.4.0
	 * @since 2.9.0 Added $connection_id param for historyId persistence.
	 *
	 * @param string $gmail_user    Gmail user ('me' or email address).
	 * @param string $query         Gmail search query.
	 * @param int    $max_results   Max messages to fetch.
	 * @param string $access_token  OAuth access token.
	 * @param string $connection_id Optional connection ID for historyId tracking.
	 * @return array<int, array>|WP_Error Array of message data or error.
	 */
	private function list_and_fetch_messages( $gmail_user, $query, $max_results, $access_token, $connection_id = '' ) {
		// List message IDs.
		$list_url = add_query_arg(
			array(
				'q'          => $query,
				'maxResults' => $max_results,
				'fields'     => 'messages(id,threadId)',
			),
			self::GMAIL_API_BASE . '/users/' . rawurlencode( $gmail_user ) . '/messages'
		);

		$response = wp_remote_get(
			$list_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'gmail_list_failed',
				sprintf( __( 'Gmail list API returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['messages'] ) || ! is_array( $body['messages'] ) ) {
			return array();
		}

		// Fetch full message details for each.
		$messages = array();
		foreach ( $body['messages'] as $msg_ref ) {
			if ( empty( $msg_ref['id'] ) ) {
				continue;
			}

			$detail = $this->fetch_message( $gmail_user, $msg_ref['id'], $access_token );
			if ( ! is_wp_error( $detail ) && ! empty( $detail ) ) {
				// Attach thread_id from list response if not in detail.
				if ( empty( $detail['thread_id'] ) && ! empty( $msg_ref['threadId'] ) ) {
					$detail['thread_id'] = $msg_ref['threadId'];
				}
				$messages[] = $detail;
			}
		}

		// Persist the latest historyId for incremental sync.
		if ( ! empty( $connection_id ) && ! empty( $body['resultSizeEstimate'] ) ) {
			$profile_response = wp_remote_get(
				self::GMAIL_API_BASE . '/users/' . rawurlencode( $gmail_user ) . '/profile',
				array(
					'timeout' => 10,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
				)
			);
			if ( ! is_wp_error( $profile_response ) && 200 === wp_remote_retrieve_response_code( $profile_response ) ) {
				$profile_body = json_decode( wp_remote_retrieve_body( $profile_response ), true );
				if ( ! empty( $profile_body['historyId'] ) ) {
					update_option(
						self::HISTORY_ID_OPTION_PREFIX . $connection_id,
						$profile_body['historyId'],
						false
					);
				}
			}
		}

		return $messages;
	}

	/**
	 * List Gmail history changes since a given historyId (incremental sync).
	 *
	 * Uses Gmail users.history.list() to fetch only messages added/modified
	 * since the last sync, then fetches full message details for each.
	 * This is the industry-standard approach used by HubSpot, Copper, and
	 * Salesforce for efficient background email import.
	 *
	 * @since 2.9.0
	 *
	 * @param string $gmail_user    Gmail user.
	 * @param string $start_history_id History ID to start from.
	 * @param int    $max_results   Max messages to fetch.
	 * @param string $access_token  OAuth access token.
	 * @param string $connection_id Connection ID for persisting historyId.
	 * @return array<int, array>|WP_Error Array of message data or error.
	 */
	private function list_history_changes( $gmail_user, $start_history_id, $max_results, $access_token, $connection_id ) {
		$history_url = add_query_arg(
			array(
				'startHistoryId' => $start_history_id,
				'maxResults'     => min( 50, $max_results * 2 ), // History can have more entries.
				'historyTypes'   => 'messageAdded',
				'fields'         => 'historyId,history(messagesAdded(message(id,threadId)))',
			),
			self::GMAIL_API_BASE . '/users/' . rawurlencode( $gmail_user ) . '/history'
		);

		$response = wp_remote_get(
			$history_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			// History ID is too old; Gmail recommends a full sync.
			delete_option( self::HISTORY_ID_OPTION_PREFIX . $connection_id );
			return new WP_Error(
				'gmail_history_expired',
				__( 'History ID expired — full re-sync required.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( 200 !== $code ) {
			return new WP_Error(
				'gmail_history_failed',
				sprintf( __( 'Gmail history API returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['history'] ) ) {
			// No changes, but update historyId.
			if ( ! empty( $body['historyId'] ) ) {
				update_option(
					self::HISTORY_ID_OPTION_PREFIX . $connection_id,
					$body['historyId'],
					false
				);
			}
			return array();
		}

		// Collect all new message IDs and thread IDs from history.
		$msg_map  = array(); // message_id => thread_id.
		$new_history_id = isset( $body['historyId'] ) ? $body['historyId'] : $start_history_id;

		foreach ( $body['history'] as $history_entry ) {
			if ( ! empty( $history_entry['historyId'] ) ) {
				$new_history_id = $history_entry['historyId'];
			}
			if ( empty( $history_entry['messagesAdded'] ) || ! is_array( $history_entry['messagesAdded'] ) ) {
				continue;
			}
			foreach ( $history_entry['messagesAdded'] as $added ) {
				if ( ! empty( $added['message']['id'] ) ) {
					$msg_map[ $added['message']['id'] ] = $added['message']['threadId'] ?? '';
				}
			}
		}

		// Limit to max_results.
		$msg_ids = array_keys( $msg_map );
		$msg_ids = array_slice( $msg_ids, 0, $max_results );

		if ( empty( $msg_ids ) ) {
			// Persist the new history ID even if no messages.
			if ( $new_history_id !== $start_history_id ) {
				update_option(
					self::HISTORY_ID_OPTION_PREFIX . $connection_id,
					$new_history_id,
					false
				);
			}
			return array();
		}

		// Fetch full details for each new message.
		$messages = array();
		foreach ( $msg_ids as $msg_id ) {
			$detail = $this->fetch_message( $gmail_user, $msg_id, $access_token );
			if ( ! is_wp_error( $detail ) && ! empty( $detail ) ) {
				// Attach thread_id.
				if ( empty( $detail['thread_id'] ) && ! empty( $msg_map[ $msg_id ] ) ) {
					$detail['thread_id'] = $msg_map[ $msg_id ];
				}
				$messages[] = $detail;
			}
		}

		// Persist the final history ID.
		update_option(
			self::HISTORY_ID_OPTION_PREFIX . $connection_id,
			$new_history_id,
			false
		);

		return $messages;
	}

	/**
	 * Fetch a single Gmail message with headers and body.
	 *
	 * @param string $gmail_user   Gmail user.
	 * @param string $message_id   Gmail message ID.
	 * @param string $access_token OAuth access token.
	 * @return array|null|WP_Error Message data with from, subject, body or null.
	 */
	private function fetch_message( $gmail_user, $message_id, $access_token ) {
		$url = self::GMAIL_API_BASE . '/users/' . rawurlencode( $gmail_user ) . '/messages/' . rawurlencode( $message_id )
			. '?format=full&fields=id,payload(headers,body,parts,parts/body,parts/parts/body,mimeType)';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['payload'] ) ) {
			return null;
		}

		$payload = $data['payload'];
		$headers = isset( $payload['headers'] ) ? (array) $payload['headers'] : array();

		// Extract From and Subject headers.
		$from_email = '';
		$from_name  = '';
		$subject    = '';

		foreach ( $headers as $header ) {
			$name  = strtolower( $header['name'] ?? '' );
			$value = $header['value'] ?? '';
			if ( 'from' === $name ) {
				if ( preg_match( '/"?([^"<]*)"?\s*<?([^>]*)>?/', $value, $fm ) ) {
					$from_name  = trim( $fm[1] );
					$from_email = strtolower( trim( $fm[2] ) );
				} else {
					$from_email = strtolower( trim( $value ) );
				}
			} elseif ( 'subject' === $name ) {
				$subject = trim( $value );
			}
		}

		// Extract body text (prefer text/plain, fall back to text/html).
		$body = $this->extract_body_from_payload( $payload );

		return array(
			'id'         => $data['id'] ?? $message_id,
			'thread_id'  => $data['threadId'] ?? '',
			'from_email' => $from_email,
			'from_name'  => $from_name,
			'subject'    => $subject,
			'body'       => $body,
		);
	}

	/**
	 * Recursively extract plain text body from a Gmail message payload.
	 *
	 * @param array $part Payload part from Gmail API.
	 * @return string Extracted body text.
	 */
	private function extract_body_from_payload( $part ) {
		// Direct body in this part.
		if ( isset( $part['body']['data'] ) && ! empty( $part['body']['data'] ) ) {
			$mime = $part['mimeType'] ?? '';
			if ( 'text/plain' === $mime ) {
				return $this->base64url_decode( $part['body']['data'] );
			}
		}

		// Check parts array.
		if ( ! empty( $part['parts'] ) && is_array( $part['parts'] ) ) {
			// Prefer text/plain.
			foreach ( $part['parts'] as $sub ) {
				if ( isset( $sub['mimeType'] ) && 'text/plain' === $sub['mimeType']
					&& isset( $sub['body']['data'] )
				) {
					return $this->base64url_decode( $sub['body']['data'] );
				}
			}

			// Fall back to text/html, then strip tags.
			foreach ( $part['parts'] as $sub ) {
				if ( isset( $sub['mimeType'] ) && 'text/html' === $sub['mimeType']
					&& isset( $sub['body']['data'] )
				) {
					$html = $this->base64url_decode( $sub['body']['data'] );
					return wp_strip_all_tags( $html );
				}
			}

			// Recurse into multipart.
			foreach ( $part['parts'] as $sub ) {
				$text = $this->extract_body_from_payload( $sub );
				if ( ! empty( $text ) ) {
					return $text;
				}
			}
		}

		// Last resort: use the first body with data.
		if ( isset( $part['body']['data'] ) && ! empty( $part['body']['data'] ) ) {
			return $this->base64url_decode( $part['body']['data'] );
		}

		return '';
	}

	/**
	 * Decode Gmail's base64url-encoded body data.
	 *
	 * @param string $data Base64url-encoded string.
	 * @return string Decoded text.
	 */
	private function base64url_decode( $data ) {
		$padded = str_replace( array( '-', '_' ), array( '+', '/' ), $data );
		// Add padding.
		$mod = strlen( $padded ) % 4;
		if ( $mod ) {
			$padded .= str_repeat( '=', 4 - $mod );
		}
		$decoded = base64_decode( $padded, true );
		return false !== $decoded ? $decoded : '';
	}
}
