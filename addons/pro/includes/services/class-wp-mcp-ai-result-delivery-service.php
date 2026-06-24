<?php
/**
 * Pro Schedule Result Delivery Service.
 *
 * Routes successful and failed schedule run results to configured delivery
 * channels: email, chat platforms (Slack, Teams, Discord, Telegram, etc.),
 * SMS, Paper Store, WordPress posts, and external webhooks.
 *
 * Exists as a standalone service so any schedule type (task, workflow,
 * assistant_run, channel_broadcast, workflow_builder) can route its output
 * through the same delivery pipeline without duplicating back-end logic.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Result_Delivery_Service' ) ) {
	/**
	 * Result Delivery Service — static methods, no constructor state.
	 *
	 * Called from {@see WP_MCP_AI_Pro_Schedule_Manager::dispatch()} after
	 * `record_run()` completes. Iterates the schedule's `result_delivery`
	 * configuration and sends the result envelope (or failure message) to
	 * each enabled channel.
	 */
	class WP_MCP_AI_Result_Delivery_Service {

		/**
		 * Supported delivery channel slugs.
		 *
		 * @var string[]
		 */
		const SUPPORTED_CHANNELS = array(
			'email',
			'slack',
			'telegram',
			'discord',
			'teams',
			'messenger',
			'whatsapp',
			'google_chat',
			'sms',
			'paper_store',
			'webhook',
			'wordpress',
		);

		/**
		 * Valid template modes for email delivery.
		 *
		 * - `full`: response + execution log (all structured envelope data).
		 * - `summary`: summary line only (no response or execution log).
		 * - `error`: error message.
		 * - `response_only`: the substantive response only — no summary, no log.
		 *
		 * @var string[]
		 */
		const EMAIL_TEMPLATES = array( 'full', 'summary', 'error', 'response_only' );

		/**
		 * Valid template modes for chat / SMS delivery.
		 *
		 * @var string[]
		 */
		const CHAT_TEMPLATES = array( 'summary', 'error', 'response_only' );

		/**
		 * Valid template modes for SMS delivery.
		 *
		 * @var string[]
		 */
		const SMS_TEMPLATES = array( 'short', 'error' );

		// -------------------------------------------------------------------------
		// Public entry points — called from Schedule Manager
		// -------------------------------------------------------------------------

		/**
		 * Deliver a successful run result to all configured channels.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $schedule_id Schedule identifier.
		 * @param array               $envelope    Structured result envelope (from record_run).
		 * @param array               $schedule    Schedule record.
		 * @param array<string,mixed> $action_log  Raw action log from the dispatcher.
		 * @return array<string,array> Per-channel delivery status, keyed by channel slug.
		 */
		public static function deliver_success( $schedule_id, array $envelope, array $schedule, array $action_log = array() ) {
			$delivery = isset( $schedule['result_delivery']['on_success']['channels'] ) && is_array( $schedule['result_delivery']['on_success']['channels'] )
				? $schedule['result_delivery']['on_success']['channels']
				: array();

			if ( empty( $delivery ) ) {
				return array();
			}

			return self::deliver_to_channels( $schedule_id, $envelope, $delivery, $schedule, $action_log, true );
		}

		/**
		 * Deliver a failure notification to all configured channels.
		 *
		 * Falls back to legacy `notify_*` fields when `result_delivery` is absent
		 * so that existing schedules continue to function without migration.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule identifier.
		 * @param string $error_msg   Error message from the failed run.
		 * @param array  $schedule    Schedule record.
		 * @return array<string,array> Per-channel delivery status, keyed by channel slug.
		 */
		public static function deliver_failure( $schedule_id, $error_msg, array $schedule ) {
			$delivery = isset( $schedule['result_delivery']['on_failure']['channels'] ) && is_array( $schedule['result_delivery']['on_failure']['channels'] )
				? $schedule['result_delivery']['on_failure']['channels']
				: array();

			// Fallback: migrate legacy notify_* fields on the fly.
			if ( empty( $delivery ) ) {
				$delivery = self::build_legacy_failure_channels( $schedule );
			}

			if ( empty( $delivery ) ) {
				return array();
			}

			// Build a minimal failure envelope so format_for_channel has something to work with.
			$envelope = array(
				'status'       => 'failure',
				'summary'      => $error_msg,
				'generated_at' => time(),
			);

			return self::deliver_to_channels( $schedule_id, $envelope, $delivery, $schedule, array(), false );
		}

		// -------------------------------------------------------------------------
		// Delivery orchestrator
		// -------------------------------------------------------------------------

		/**
		 * Fan-out delivery to all enabled channels.
		 *
		 * Each channel send is wrapped in its own try/catch so a failure in one
		 * channel (e.g. Slack webhook timeout) never blocks delivery to another.
		 *
		 * @param string              $schedule_id  Schedule identifier.
		 * @param array               $envelope     Result envelope (or failure stub).
		 * @param array               $channel_cfgs Per-channel config keyed by slug.
		 * @param array               $schedule     Schedule record.
		 * @param array<string,mixed> $action_log   Raw action log from dispatch.
		 * @param bool                $is_success   Whether this is a success or failure delivery.
		 * @return array<string,array> Per-channel status.
		 */
		protected static function deliver_to_channels( $schedule_id, array $envelope, array $channel_cfgs, array $schedule, array $action_log, $is_success ) {
			$statuses = array();

			// Pre-compute whether the AI already created posts via its own tool
			// calls, so that each WordPress channel can decide individually
			// (based on its skip_if_ai_posted setting) whether to auto-skip.
			$ai_created_post = self::should_skip_wordpress_delivery( $action_log );

			foreach ( $channel_cfgs as $raw_channel => $config ) {
				// Normalise canonical casing for WordPress to avoid case-sensitivity
				// bugs (older data may have been stored with lowercase key).
				// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- intentional lowercase key comparison
				$channel = 'wordpress' === $raw_channel ? 'WordPress' : $raw_channel;

				if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
					continue;
				}

				if ( ! in_array( $channel, self::SUPPORTED_CHANNELS, true ) ) {
					continue;
				}

				// Skip WordPress delivery when the AI already handled post creation
				// via create_post or save_post tool calls during the run — UNLESS
				// the user explicitly opted out of this guard.
				if ( 'WordPress' === $channel && $ai_created_post ) {
					$skip_if_ai = ! isset( $config['skip_if_ai_posted'] ) || ! empty( $config['skip_if_ai_posted'] );
					if ( $skip_if_ai ) {
						$statuses['wordpress'] = self::log_delivery_skip(
							$schedule_id,
							__( 'Skipped — AI already created post via create_post/save_post tool.', 'mcp-ai-wpoos-pro' ),
							$is_success
						);
						continue;
					}
				}

				$template = isset( $config['template'] ) ? (string) $config['template'] : 'summary';
				$payload  = self::format_for_channel( $envelope, $channel, $template, $schedule, $action_log );

				try {
					$result = self::send_to_channel( $channel, $payload, $config );
				} catch ( Throwable $e ) {
					$result = new WP_Error(
						'result_delivery_exception',
						$e->getMessage()
					);
				}

				$statuses[ $channel ] = self::log_delivery( $schedule_id, $channel, $result, $is_success );
			}

			return $statuses;
		}

		/**
		 * Determine whether the WordPress delivery channel should be skipped.
		 *
		 * When an assistant_run schedule instructs the AI to call create_post
		 * or save_post, the post already exists in WordPress.  Enabling the
		 * WordPress delivery channel on top of that would produce a duplicate
		 * envelope-summary post, which is almost never the desired behaviour.
		 *
		 * This guard inspects the action_log to see whether any tool call
		 * during the run matches a post-creation slug.  It is intentionally
		 * scoped to assistant_run dispatches; workflow, task, and broadcast
		 * schedules are unaffected because they do not populate the
		 * `assistant.tool_calls` key.
		 *
		 * @since 1.0.0
		 *
		 * @param array $action_log Raw action log from the dispatcher.
		 * @return bool True if WordPress delivery should be suppressed.
		 */
		protected static function should_skip_wordpress_delivery( array $action_log ) {
			$tool_calls = isset( $action_log['assistant']['tool_calls'] ) && is_array( $action_log['assistant']['tool_calls'] )
				? $action_log['assistant']['tool_calls']
				: array();

			if ( empty( $tool_calls ) ) {
				return false;
			}

			$post_creation_tools = array( 'create_post', 'save_post', 'save_post_validated' );

			foreach ( $tool_calls as $tool_call ) {
				$name = isset( $tool_call['name'] ) ? (string) $tool_call['name'] : '';
				if ( in_array( $name, $post_creation_tools, true ) ) {
					return true;
				}
			}

			return false;
		}

		// -------------------------------------------------------------------------
		// Formatting — envelope → channel-appropriate payload
		// -------------------------------------------------------------------------

		/**
		 * Shape the raw result envelope into a format suitable for a specific channel.
		 *
		 * @param array               $envelope    Result envelope.
		 * @param string              $channel     Channel slug.
		 * @param string              $template    Template mode (full, summary, short, error).
		 * @param array               $schedule    Schedule record (for name, tags, etc.).
		 * @param array<string,mixed> $action_log  Raw action log.
		 * @return array Formatted payload keyed by channel needs (subject, body, html, etc.).
		 */
		protected static function format_for_channel( array $envelope, $channel, $template, array $schedule, array $action_log ) {
			$schedule_name = isset( $schedule['name'] ) ? (string) $schedule['name'] : '';
			$schedule_type = isset( $schedule['schedule_type'] ) ? (string) $schedule['schedule_type'] : 'task';
			$summary       = isset( $envelope['summary'] ) ? (string) $envelope['summary'] : '';
			$response      = isset( $envelope['response'] ) ? (string) $envelope['response'] : '';
			$status        = isset( $envelope['status'] ) ? (string) $envelope['status'] : '';
			$is_success    = 'failure' !== $status;
			$generated_at  = isset( $envelope['generated_at'] ) ? (int) $envelope['generated_at'] : time();

			$formatted = array(
				'schedule_name' => $schedule_name,
				'summary'       => $summary,
				'response'      => $response,
				'status'        => $status,
				'is_success'    => $is_success,
				'generated_at'  => $generated_at,
				'schedule_type' => $schedule_type,
			);

			switch ( $channel ) {
				case 'email':
					return self::format_email( $formatted, $envelope, $template );

				case 'sms':
					return self::format_sms( $formatted, $template );

				case 'paper_store':
					return self::format_paper_store( $formatted, $envelope, $schedule );

				case 'WordPress':
					return self::format_wordpress_post( $formatted, $envelope, $schedule );

				case 'webhook':
					return self::format_webhook( $formatted, $envelope, $schedule, $action_log );

				// Chat channels: slack, telegram, discord, teams, messenger, whatsapp.
				default:
					return self::format_chat( $formatted, $template );
			}
		}

		/**
		 * Format for email delivery.
		 *
		 * @param array  $shared   Common formatted fields.
		 * @param array  $envelope Full envelope.
		 * @param string $template Template mode.
		 * @return array Email payload (subject, plain, html, template_mode).
		 */
		protected static function format_email( array $shared, array $envelope, $template ) {
			$site_name = get_bloginfo( 'name' );
			$is_error  = 'error' === $template;
			$emoji     = $is_error ? "\u{26A0}\u{FE0F}" : "\u{2705}";

			$subject = sprintf(
				/* translators: 1: status emoji, 2: site name, 3: schedule name */
				__( '%1$s [%2$s] %3$s', 'mcp-ai-wpoos-pro' ),
				$emoji,
				$site_name,
				$shared['schedule_name']
			);

			$body = '';
			if ( $is_error ) {
				$body = $shared['summary'];
			} elseif ( 'response_only' === $template ) {
				// Deliver only the substantive AI/tool response — no summary, no log.
				$body = isset( $shared['response'] ) ? (string) $shared['response'] : $shared['summary'];
			} elseif ( 'full' === $template && isset( $envelope['data'] ) ) {
				// Full mode: include the response prominently, then the data structure.
				$body     = $shared['summary'];
				$response = isset( $shared['response'] ) ? (string) $shared['response'] : '';
				if ( '' !== $response ) {
					$body .= "\n\n---\n\n";
					/* translators: heading for the main result output in emails */
					$body .= __( 'Results', 'mcp-ai-wpoos-pro' ) . ":\n";
					$body .= $response;
				}
				if ( ! empty( $envelope['data'] ) ) {
					$body .= "\n\n---\n\n";
					$body .= self::envelope_data_to_text( $envelope['data'] );
				}
			} else {
				$body = $shared['summary'];
			}

			$manage_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration' );

			return array(
				'subject'       => $subject,
				'plain'         => $body,
				'summary'       => $shared['summary'],
				'is_error'      => $is_error,
				'template_mode' => $template,
				'site_name'     => $site_name,
				'manage_url'    => $manage_url,
				'generated_at'  => $shared['generated_at'],
			);
		}

		/**
		 * Format for chat channel delivery (Slack, Teams, Discord, Telegram, etc.).
		 *
		 * @param array  $shared   Common formatted fields.
		 * @param string $template Template mode.
		 * @return array Chat payload (message string).
		 */
		protected static function format_chat( array $shared, $template ) {
			$is_error = 'error' === $template;
			$emoji    = $is_error ? "\u{26A0}\u{FE0F}" : "\u{2705}";
			$site     = get_bloginfo( 'name' );

			$message  = $emoji . ' *' . esc_html( $shared['schedule_name'] ) . "*\n";
			$message .= "\u{1F3E2} " . esc_html( $site ) . '  |  ';
			$message .= "\u{1F4C5} " . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $shared['generated_at'] ) ) . "\n";

			if ( 'response_only' === $template ) {
				// Deliver only the substantive AI/tool response.
				$response = isset( $shared['response'] ) ? (string) $shared['response'] : '';
				if ( '' !== $response ) {
					$message .= "\n" . esc_html( $response );
				} else {
					$message .= "\n" . esc_html( $shared['summary'] );
				}
			} else {
				$truncated = wp_trim_words( $shared['summary'], 60, '…' );
				$message  .= "\n" . esc_html( $truncated );
			}

				// Include a response excerpt when available — this is the substantive
				// output the schedule produced and is what recipients actually want.
				// Only appended in summary/error modes; response_only already includes
				// the full response as the main content.
			if ( 'response_only' !== $template && 'error' !== $template ) {
				$response = isset( $shared['response'] ) ? (string) $shared['response'] : '';
				if ( '' !== $response ) {
					$excerpt  = wp_trim_words( $response, 80, '…' );
					$message .= "\n\n\u{1F4CB} " . esc_html( $excerpt );
				}
			}

			return array(
				'message' => $message,
			);
		}

		/**
		 * Format for SMS delivery (160-char GSM-7 limit).
		 *
		 * @param array  $shared Common formatted fields.
		 * @param string $template Template mode.
		 * @return array SMS payload.
		 */
		protected static function format_sms( array $shared, $template ) {
			$is_error = 'error' === $template;
			$prefix   = $is_error ? "\u{26A0} " : "\u{2705} ";
			$name     = mb_substr( $shared['schedule_name'], 0, 40 );
			$summary  = $is_error
				? mb_substr( $shared['summary'], 0, 100 )
				: wp_trim_words( $shared['summary'], 10, '…' );

			$message = $prefix . $name;
			if ( ! empty( $summary ) ) {
				$message .= ': ' . $summary;
			}

			// Append a response excerpt when available and not an error.
			$response = isset( $shared['response'] ) ? (string) $shared['response'] : '';
			if ( ! $is_error && '' !== $response ) {
				$excerpt  = wp_trim_words( $response, 12, '…' );
				$message .= ' - ' . $excerpt;
			}

			// Truncate to ~160 chars (GSM-7 safe).
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $message ) > 155 ) {
				$message = mb_substr( $message, 0, 152 ) . '…';
			} elseif ( strlen( $message ) > 155 ) {
				$message = substr( $message, 0, 152 ) . '…';
			}

			return array(
				'message' => $message,
			);
		}

		/**
		 * Format for Paper Store record.
		 *
		 * @param array $shared   Common formatted fields.
		 * @param array $envelope Full envelope.
		 * @param array $schedule Schedule record.
		 * @return array Paper Store payload.
		 */
		protected static function format_paper_store( array $shared, array $envelope, array $schedule ) {
			$record_id = sanitize_key(
				( isset( $schedule['id'] ) ? $schedule['id'] : 'schedule' )
				. '-' . gmdate( 'Y-m-d-His', $shared['generated_at'] )
			);

			$tags = isset( $schedule['tags'] ) && is_array( $schedule['tags'] )
				? $schedule['tags']
				: array();

			return array(
				'id'           => $record_id,
				'type'         => 'scheduled-research',
				'title'        => $shared['schedule_name'] . ' — ' . gmdate( 'Y-m-d', $shared['generated_at'] ),
				'description'  => isset( $schedule['description'] ) ? (string) $schedule['description'] : '',
				'schedule_id'  => isset( $schedule['id'] ) ? (string) $schedule['id'] : '',
				'generated_at' => $shared['generated_at'],
				'summary'      => $shared['summary'],
				'tags'         => $tags,
				'status'       => 'published',
				'body'         => array(
					'markdown' => self::envelope_to_markdown( $shared, $envelope, $schedule ),
					'json'     => $envelope,
				),
			);
		}

		/**
		 * Format for WordPress post auto-creation.
		 *
		 * @param array $shared   Common formatted fields.
		 * @param array $envelope Full envelope.
		 * @param array $schedule Schedule record.
		 * @return array WordPress post payload.
		 */
		protected static function format_wordpress_post( array $shared, array $envelope, array $schedule ) {
			$post_title   = $shared['schedule_name'] . ' — ' . gmdate( 'Y-m-d', $shared['generated_at'] );
			$post_content = self::envelope_to_markdown( $shared, $envelope, $schedule );

			// Convert markdown headings to HTML paragraphs for wp_insert_post.
			// A proper markdown→HTML conversion would use a library; simple
			// newline→<br> for now — integrators can filter.
			$post_content_html = wpautop( esc_html( $post_content ) );

			return array(
				'post_title'   => $post_title,
				'post_content' => $post_content_html,
				'post_excerpt' => wp_trim_words( $shared['summary'], 30, '…' ),
			);
		}

		/**
		 * Format for webhook delivery.
		 *
		 * @param array               $shared     Common formatted fields.
		 * @param array               $envelope   Full envelope.
		 * @param array               $schedule   Schedule record.
		 * @param array<string,mixed> $action_log Action log.
		 * @return array Webhook payload.
		 */
		protected static function format_webhook( array $shared, array $envelope, array $schedule, array $action_log ) {
			return array(
				'event'         => $shared['is_success'] ? 'schedule.run.success' : 'schedule.run.failure',
				'schedule_id'   => isset( $schedule['id'] ) ? $schedule['id'] : '',
				'schedule_name' => $shared['schedule_name'],
				'schedule_type' => $shared['schedule_type'],
				'status'        => $shared['status'],
				'summary'       => $shared['summary'],
				'response'      => isset( $shared['response'] ) ? (string) $shared['response'] : '',
				'action_log'    => $action_log,
				'timestamp'     => gmdate( 'c', $shared['generated_at'] ),
				'site_url'      => home_url(),
			);
		}

		// -------------------------------------------------------------------------
		// Credential resolution
		// -------------------------------------------------------------------------

		/**
		 * Resolve credentials for a delivery channel using a three-tier fallback.
		 *
		 * Priority:
		 *   1. connection_id → Remote Sites stored connection
		 *   2. Inline {channel}_credentials in the channel config
		 *   3. Chat Channels Toolkit global settings (via filter)
		 *
		 * @since 1.0.0
		 *
		 * @param string $channel Channel slug (slack, telegram, etc.).
		 * @param array  $config  Channel config from schedule['result_delivery'].
		 * @return array Empty array if no credentials resolved, or credential map keyed by channel.
		 */
		protected static function resolve_channel_credentials( $channel, array $config ) {
			// 1. Try Remote Sites connection reference.
			if ( ! empty( $config['connection_id'] ) ) {
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $config['connection_id'] );
					if ( is_array( $connection ) && ! empty( $connection ) ) {
						$creds = self::extract_credentials_from_connection( $channel, $connection );
						if ( ! empty( $creds ) ) {
							return $creds;
						}
					}
				}
			}

			// 2. Try inline credentials stored under the canonical key.
			if ( isset( $config[ $channel . '_credentials' ] ) && is_array( $config[ $channel . '_credentials' ] ) ) {
				return $config[ $channel . '_credentials' ];
			}

			// 3. Allow integrators to supply global defaults.
			return apply_filters( 'wp_mcp_ai_delivery_channel_default_credentials', array(), $channel, $config );
		}

		/**
		 * Extract delivery credentials from a Remote Sites connection record.
		 *
		 * Maps the connection-type-agnostic storage to the per-channel shape
		 * expected by unified_channel_broadcast.
		 *
		 * @since 1.0.0
		 *
		 * @param string $channel    Channel slug.
		 * @param array  $connection Remote Sites connection record.
		 * @return array Credential map for this channel, or empty array.
		 */
		protected static function extract_credentials_from_connection( $channel, array $connection ) {
			$map = array(
				'slack'       => array(
					'token'   => isset( $connection['slack_bot_token'] ) ? (string) $connection['slack_bot_token'] : '',
					'channel' => isset( $connection['slack_default_channel'] ) ? (string) $connection['slack_default_channel'] : '',
				),
				'telegram'    => array(
					'token'   => isset( $connection['telegram_bot_token'] ) ? (string) $connection['telegram_bot_token'] : '',
					'chat_id' => isset( $connection['telegram_chat_id'] ) ? (string) $connection['telegram_chat_id'] : '',
				),
				'discord'     => array(
					'token'      => isset( $connection['discord_bot_token'] ) ? (string) $connection['discord_bot_token'] : '',
					'channel_id' => isset( $connection['discord_channel_id'] ) ? (string) $connection['discord_channel_id'] : '',
				),
				'teams'       => array(
					'token'      => isset( $connection['teams_token'] ) ? (string) $connection['teams_token'] : '',
					'team_id'    => isset( $connection['teams_team_id'] ) ? (string) $connection['teams_team_id'] : '',
					'channel_id' => isset( $connection['teams_channel_id'] ) ? (string) $connection['teams_channel_id'] : '',
				),
				'messenger'   => array(
					'access_token' => isset( $connection['messenger_access_token'] ) ? (string) $connection['messenger_access_token'] : '',
					'recipient_id' => isset( $connection['messenger_recipient_id'] ) ? (string) $connection['messenger_recipient_id'] : '',
				),
				'whatsapp'    => array(
					'access_token'    => isset( $connection['whatsapp_access_token'] ) ? (string) $connection['whatsapp_access_token'] : '',
					'phone_number_id' => isset( $connection['whatsapp_phone_number_id'] ) ? (string) $connection['whatsapp_phone_number_id'] : '',
					'to'              => isset( $connection['whatsapp_to'] ) ? (string) $connection['whatsapp_to'] : '',
				),
				'google_chat' => array(
					'webhook_url' => isset( $connection['google_chat_webhook_url'] ) ? (string) $connection['google_chat_webhook_url'] : '',
				),
			);

			if ( isset( $map[ $channel ] ) ) {
				$filtered = array_filter(
					$map[ $channel ],
					function ( $v ) {
						return '' !== $v;
					}
				);
				if ( ! empty( $filtered ) ) {
					return $filtered;
				}
			}

			return array();
		}

		// -------------------------------------------------------------------------
		// Senders — payload → channel API
		// -------------------------------------------------------------------------

		/**
		 * Send a formatted payload to the appropriate channel backend.
		 *
		 * @param string $channel Channel slug.
		 * @param array  $payload Formatted payload.
		 * @param array  $config  Channel config from schedule['result_delivery'].
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		protected static function send_to_channel( $channel, array $payload, array $config ) {
			switch ( $channel ) {
				case 'email':
					return self::send_email( $payload, $config );

				case 'sms':
					return self::send_sms( $payload, $config );

				case 'paper_store':
					return self::send_paper_store( $payload, $config );

				case 'WordPress':
					return self::send_wordpress_post( $payload, $config );

				case 'webhook':
					return self::send_webhook( $payload, $config );

				// Chat channels route through unified_channel_broadcast.
				case 'slack':
				case 'telegram':
				case 'discord':
				case 'teams':
				case 'messenger':
				case 'whatsapp':
				case 'google_chat':
					return self::send_chat( $channel, $payload, $config );

				default:
					return new WP_Error(
						'unsupported_channel',
						/* translators: %s: channel slug */
						sprintf( __( 'Delivery channel "%s" is not supported.', 'mcp-ai-wpoos-pro' ), $channel )
					);
			}
		}

		/**
		 * Send email via Nodemailer (preferred) or wp_mail fallback.
		 *
		 * @param array $payload Formatted email payload.
		 * @param array $config  Channel config (must contain 'to').
		 * @return true|WP_Error
		 */
		protected static function send_email( array $payload, array $config ) {
			$to = isset( $config['to'] ) ? sanitize_email( $config['to'] ) : '';
			if ( '' === $to ) {
				return new WP_Error( 'missing_email_recipient', __( 'No email recipient configured.', 'mcp-ai-wpoos-pro' ) );
			}

			$subject  = isset( $payload['subject'] ) ? (string) $payload['subject'] : __( 'Schedule Result', 'mcp-ai-wpoos-pro' );
			$plain    = isset( $payload['plain'] ) ? (string) $payload['plain'] : '';
			$is_error = ! empty( $payload['is_error'] );

			// Build HTML body (MJML when available).
			$html = self::build_email_html( $payload );

			// Try Nodemailer first.
			if ( class_exists( 'WP_MCP_AI_Nodemailer_Service' ) ) {
				$nodemailer = new WP_MCP_AI_Nodemailer_Service();
				if ( $nodemailer->is_available() ) {
					$result = $nodemailer->send_email(
						array(
							'to'      => $to,
							'subject' => $subject,
							'html'    => $html,
							'text'    => $plain,
						)
					);
					if ( ! is_wp_error( $result ) ) {
						return true;
					}
					// Fall through to wp_mail on failure.
				}
			}

			// wp_mail fallback.
			$sent = wp_mail(
				$to,
				$subject,
				$html,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			return $sent ? true : new WP_Error( 'wp_mail_failed', __( 'wp_mail() returned false.', 'mcp-ai-wpoos-pro' ) );
		}

		/**
		 * Send a message to chat channels via unified_channel_broadcast tool.
		 *
		 * @param string $channel Channel slug (slack, telegram, etc.).
		 * @param array  $payload Formatted chat payload (must contain 'message').
		 * @param array  $config  Channel config.
		 * @return true|WP_Error
		 */
		protected static function send_chat( $channel, array $payload, array $config ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return new WP_Error( 'no_tool_registry', __( 'Tool registry not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'unified_channel_broadcast' );
			if ( ! $tool ) {
				return new WP_Error( 'no_broadcast_tool', __( 'Unified channel broadcast tool not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$message = isset( $payload['message'] ) ? (string) $payload['message'] : '';

			/*
			 * Resolve credentials using three-tier fallback:
			 *   1. connection_id → Remote Sites connection.
			 *   2. Inline {channel}_credentials in config.
			 *   3. Chat Channels Toolkit global settings (via filter).
			 */
			$credentials = array();
			$resolved    = self::resolve_channel_credentials( $channel, $config );
			if ( ! empty( $resolved ) ) {
				$credentials[ $channel ] = $resolved;
			} elseif ( isset( $config['credentials'] ) && is_array( $config['credentials'] ) ) {
				$credentials[ $channel ] = $config['credentials'];
			} elseif ( isset( $config[ $channel . '_credentials' ] ) ) {
				$credentials[ $channel ] = $config[ $channel . '_credentials' ];
			}

			$result = $tool->execute(
				array(
					'message'     => $message,
					'channels'    => array( $channel ),
					'credentials' => $credentials,
				),
				array( 'source' => 'pro_schedule_manager_result_delivery' )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Check partial failure.
			if ( is_array( $result ) && isset( $result['summary']['successful_channels'] ) ) {
				if ( 0 === (int) $result['summary']['successful_channels'] ) {
					return new WP_Error(
						'broadcast_failed',
						/* translators: %s: channel name */
						sprintf( __( 'Failed to deliver to %s.', 'mcp-ai-wpoos-pro' ), $channel )
					);
				}
			}

			return true;
		}

		/**
		 * Send SMS via the schedule_notify_sms tool.
		 *
		 * @param array $payload Formatted SMS payload (must contain 'message').
		 * @param array $config  Channel config (must contain 'to').
		 * @return true|WP_Error
		 */
		protected static function send_sms( array $payload, array $config ) {
			$to = isset( $config['to'] ) ? (string) $config['to'] : '';
			if ( '' === $to ) {
				return new WP_Error( 'missing_sms_recipient', __( 'No SMS recipient configured.', 'mcp-ai-wpoos-pro' ) );
			}

			$message = isset( $payload['message'] ) ? (string) $payload['message'] : '';

			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'schedule_notify_sms' );
				if ( $tool ) {
					$result = $tool->execute(
						array(
							'to'      => $to,
							'message' => $message,
						),
						array( 'source' => 'pro_schedule_manager_result_delivery' )
					);
					return is_wp_error( $result ) ? $result : true;
				}
			}

			return new WP_Error( 'sms_tool_unavailable', __( 'SMS notification tool is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		/**
		 * Write a record to the Paper Store.
		 *
		 * @param array $payload Formatted Paper Store payload.
		 * @param array $config  Channel config (collection, driver, retention, git_commit).
		 * @return true|WP_Error
		 */
		protected static function send_paper_store( array $payload, array $config ) {
			if ( ! class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
				return new WP_Error( 'paper_store_unavailable', __( 'Paper Store is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$collection = isset( $config['collection'] ) ? sanitize_key( $config['collection'] ) : 'schedule-results';
			if ( '' === $collection ) {
				return new WP_Error( 'missing_paper_collection', __( 'No Paper Store collection configured.', 'mcp-ai-wpoos-pro' ) );
			}

			try {
				$store      = WP_MCP_AI_Paper_Store_Manager::get_instance();
				$repository = $store->get_repository( $collection );

				// Enforce retention: trim oldest records beyond the configured limit.
				$retention = isset( $config['retention'] ) ? (int) $config['retention'] : 0;
				if ( $retention > 0 ) {
					$all = $repository->all();
					if ( is_array( $all ) && count( $all ) >= $retention ) {
						// Sort by generated_at descending, keep newest $retention - 1.
						usort(
							$all,
							function ( $a, $b ) {
								$ta = isset( $a['generated_at'] ) ? (int) $a['generated_at'] : 0;
								$tb = isset( $b['generated_at'] ) ? (int) $b['generated_at'] : 0;
								return $tb - $ta;
							}
						);
						$to_remove = array_slice( $all, $retention - 1 );
						foreach ( $to_remove as $old ) {
							if ( isset( $old['id'] ) ) {
								$repository->delete( $old['id'] );
							}
						}
					}
				}

				$saved = $repository->save( $payload );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}

				// Trigger Git auto-commit when configured and the Pro driver supports it.
				if ( ! empty( $config['git_commit'] ) && class_exists( 'WP_MCP_AI_Paper_Git_Sync' ) ) {
					WP_MCP_AI_Paper_Git_Sync::get_instance()->maybe_commit();
				}

				return true;
			} catch ( Throwable $e ) {
				return new WP_Error(
					'paper_store_error',
					sprintf(
						/* translators: %s: error message */
						__( 'Paper Store write failed: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					)
				);
			}
		}

		/**
		 * Auto-create a WordPress post from the result.
		 *
		 * @param array $payload Formatted WordPress post payload.
		 * @param array $config  Channel config (post_type, post_status, category).
		 * @return true|WP_Error
		 */
		protected static function send_wordpress_post( array $payload, array $config ) {
			$post_type   = isset( $config['post_type'] ) ? sanitize_key( $config['post_type'] ) : 'post';
			$post_status = isset( $config['post_status'] ) ? sanitize_key( $config['post_status'] ) : 'draft';
			$category    = isset( $config['category'] ) ? absint( $config['category'] ) : 0;

			// Validate post type.
			if ( ! post_type_exists( $post_type ) ) {
				return new WP_Error(
					'invalid_post_type',
					/* translators: %s: post type slug */
					sprintf( __( 'Post type "%s" does not exist.', 'mcp-ai-wpoos-pro' ), $post_type )
				);
			}

			$post_data = array(
				'post_title'   => isset( $payload['post_title'] ) ? (string) $payload['post_title'] : '',
				'post_content' => isset( $payload['post_content'] ) ? (string) $payload['post_content'] : '',
				'post_excerpt' => isset( $payload['post_excerpt'] ) ? (string) $payload['post_excerpt'] : '',
				'post_type'    => $post_type,
				'post_status'  => $post_status,
			);

			if ( $category > 0 ) {
				$post_data['post_category'] = array( $category );
			}

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			// Set featured image if provided in the payload.
			if ( ! empty( $payload['featured_image_id'] ) ) {
				$thumbnail_id = absint( $payload['featured_image_id'] );
				if ( $thumbnail_id > 0 && wp_attachment_is_image( $thumbnail_id ) ) {
					set_post_thumbnail( $post_id, $thumbnail_id );
				}
			}

			// Auto-generate featured image if configured.
			if ( ! empty( $config['generate_featured_image'] ) && class_exists( 'WP_MCP_AI_Featured_Image_Service' ) ) {
				$image_style  = isset( $config['image_style'] ) ? sanitize_key( $config['image_style'] ) : 'photographic';
				$image_result = WP_MCP_AI_Featured_Image_Service::generate(
					$post_data['post_title'],
					'blog post',
					array( 'style' => $image_style )
				);
				if ( ! is_wp_error( $image_result ) ) {
					set_post_thumbnail( $post_id, $image_result['attachment_id'] );
				}
			}

			return true;
		}

		/**
		 * POST result to an external webhook URL.
		 *
		 * @param array $payload Webhook payload.
		 * @param array $config  Channel config (url, secret).
		 * @return true|WP_Error
		 */
		protected static function send_webhook( array $payload, array $config ) {
			$url = isset( $config['url'] ) ? esc_url_raw( $config['url'] ) : '';
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_webhook_url', __( 'Invalid webhook URL.', 'mcp-ai-wpoos-pro' ) );
			}

			$body    = wp_json_encode( $payload );
			$headers = array( 'Content-Type' => 'application/json' );

			$secret = isset( $config['secret'] ) ? (string) $config['secret'] : '';
			if ( '' !== $secret ) {
				$ts                               = (string) time();
				$headers['X-WP-MCP-AI-Timestamp'] = $ts;
				$headers['X-WP-MCP-AI-Signature'] = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );
			}

			$response = wp_remote_post(
				$url,
				array(
					'body'    => $body,
					'headers' => $headers,
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'webhook_http_error',
					/* translators: %d: HTTP status code */
					sprintf( __( 'Webhook returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code )
				);
			}

			return true;
		}

		// -------------------------------------------------------------------------
		// Logging
		// -------------------------------------------------------------------------

		/**
		 * Log a per-channel delivery attempt and fire an action hook.
		 *
		 * @param string        $schedule_id Schedule identifier.
		 * @param string        $channel     Channel slug.
		 * @param true|WP_Error $result    Delivery result.
		 * @param bool          $is_success  Whether this was a success or failure delivery.
		 * @return array Status array with channel, success, and error keys.
		 */
		protected static function log_delivery( $schedule_id, $channel, $result, $is_success ) {
			$is_ok  = true === $result || ! is_wp_error( $result );
			$status = array(
				'channel' => $channel,
				'success' => $is_ok,
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					$is_ok ? 'info' : 'warning',
					sprintf(
						'Result delivery to %s: %s (schedule: %s, type: %s)',
						$channel,
						$is_ok ? 'OK' : 'FAILED',
						$schedule_id,
						$is_success ? 'success' : 'failure'
					),
					$status
				);
			}

			/**
			 * Fires after a schedule result delivery attempt to a specific channel.
			 *
			 * Enables OTel subscribers, external monitoring, and custom notification
			 * handlers to react to delivery outcomes without modifying the service.
			 *
			 * @since 1.0.0
			 *
			 * @param string $schedule_id Schedule identifier.
			 * @param string $channel     Channel slug (email, slack, paper_store, etc.).
			 * @param bool   $is_ok       Whether delivery succeeded.
			 * @param string $error       Error message if delivery failed.
			 * @param bool   $is_success  Whether this was a success-run or failure-run delivery.
			 */
			do_action(
				'wp_mcp_ai_pro_schedule_result_delivered',
				$schedule_id,
				$channel,
				$is_ok,
				$status['error'],
				$is_success
			);

			return $status;
		}

		/**
		 * Log an intentional WordPress channel skip (AI already created the post).
		 *
		 * Produces the same status-array shape as {@see self::log_delivery()} so
		 * callers that iterate the per-channel statuses don't need a special case.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule identifier.
		 * @param string $reason      Human-readable reason for the skip.
		 * @param bool   $is_success  Whether this was a success-run or failure-run delivery.
		 * @return array Status array with channel, success, skipped, and reason keys.
		 */
		protected static function log_delivery_skip( $schedule_id, $reason, $is_success ) {
			$status = array(
				'channel' => 'wordpress',
				'success' => true,
				'skipped' => true,
				'reason'  => $reason,
				'error'   => '',
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'info',
					sprintf(
						'Result delivery to WordPress: SKIPPED (schedule: %s, type: %s) — %s',
						$schedule_id,
						$is_success ? 'success' : 'failure',
						$reason
					),
					$status
				);
			}

			/** This action is documented in log_delivery(). */
			do_action(
				'wp_mcp_ai_pro_schedule_result_delivered',
				$schedule_id,
				'WordPress',
				true,
				$reason,
				$is_success
			);

			return $status;
		}

		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------

		/**
		 * Build a legacy failure channel config from old notify_* fields.
		 *
		 * Ensures existing schedules with only the old-style notify fields
		 * continue to receive failure notifications without requiring migration.
		 *
		 * @param array $schedule Schedule record.
		 * @return array Channel configs compatible with deliver_to_channels().
		 */
		protected static function build_legacy_failure_channels( array $schedule ) {
			$channels = array();

			// Legacy email notification.
			if ( ! empty( $schedule['notify_on_failure'] ) && ! empty( $schedule['notify_email'] ) ) {
				$channels['email'] = array(
					'enabled'  => true,
					'to'       => $schedule['notify_email'],
					'template' => 'error',
				);
			}

			// Legacy channel notification (slack, telegram, etc.).
			if ( ! empty( $schedule['notify_channels'] ) && is_array( $schedule['notify_channels'] ) ) {
				$credentials = isset( $schedule['notify_channel_credentials'] ) && is_array( $schedule['notify_channel_credentials'] )
					? $schedule['notify_channel_credentials']
					: array();

				foreach ( $schedule['notify_channels'] as $chan ) {
					if ( isset( $channels[ $chan ] ) ) {
						continue; // Already configured.
					}
					$chan_config = array(
						'enabled'  => true,
						'template' => 'error',
					);
					if ( isset( $credentials[ $chan ] ) ) {
						$chan_config[ $chan . '_credentials' ] = $credentials[ $chan ];
					}
					$channels[ $chan ] = $chan_config;
				}
			}

			return $channels;
		}

		/**
		 * Build an HTML email body from a formatted email payload.
		 *
		 * Prefers MJML compilation when the service is available; falls back
		 * to a simple inline-HTML template.
		 *
		 * @param array $payload Formatted email payload.
		 * @return string HTML.
		 */
		protected static function build_email_html( array $payload ) {
			$is_error     = ! empty( $payload['is_error'] );
			$header_color = $is_error ? '#cc1818' : '#2271b1';
			$status_label = $is_error
				? __( 'Failed', 'mcp-ai-wpoos-pro' )
				: __( 'Completed', 'mcp-ai-wpoos-pro' );
			$site_name    = isset( $payload['site_name'] ) ? $payload['site_name'] : get_bloginfo( 'name' );
			$schedule     = isset( $payload['schedule_name'] ) ? $payload['schedule_name'] : '';
			$body_text    = isset( $payload['plain'] ) ? $payload['plain'] : '';
			$manage_url   = isset( $payload['manage_url'] ) ? $payload['manage_url'] : admin_url();

			// Try MJML first.
			if ( class_exists( 'WP_MCP_AI_MJML_Service' ) ) {
				$mjml_service = new WP_MCP_AI_MJML_Service();
				if ( $mjml_service->is_available() ) {
					$mjml_src  = '<mjml><mj-head>';
					$mjml_src .= '<mj-attributes><mj-all font-family="Arial, sans-serif" /></mj-attributes>';
					$mjml_src .= '</mj-head><mj-body background-color="#f4f4f4">';
					$mjml_src .= '<mj-section background-color="' . $header_color . '" padding="20px 24px">';
					$mjml_src .= '<mj-column><mj-text font-size="20px" color="#ffffff" font-weight="bold">';
					$mjml_src .= esc_html( $schedule . ' — ' . $status_label );
					$mjml_src .= '</mj-text></mj-column></mj-section>';
					$mjml_src .= '<mj-section background-color="#ffffff" padding="20px 24px">';
					$mjml_src .= '<mj-column><mj-text font-size="14px" color="#333">';
					$mjml_src .= nl2br( esc_html( $body_text ) );
					$mjml_src .= '</mj-text></mj-column></mj-section>';
					$mjml_src .= '<mj-section background-color="#ffffff" padding="0 24px 20px">';
					$mjml_src .= '<mj-column>';
					$mjml_src .= '<mj-button background-color="#2271b1" color="#ffffff" href="' . esc_url( $manage_url ) . '">';
					$mjml_src .= esc_html__( 'View Dashboard', 'mcp-ai-wpoos-pro' );
					$mjml_src .= '</mj-button></mj-column></mj-section>';
					$mjml_src .= '</mj-body></mjml>';

					$compiled = $mjml_service->compile( $mjml_src, array( 'minify' => true ) );
					if ( ! is_wp_error( $compiled ) && ! empty( $compiled ) ) {
						return $compiled;
					}
				}
			}

			// Fallback inline HTML.
			$html  = '<html><body style="font-family:sans-serif;color:#333">';
			$html .= '<div style="background:' . $header_color . ';color:#fff;padding:16px 20px;font-size:18px;font-weight:bold">';
			$html .= esc_html( $schedule . ' — ' . $status_label );
			$html .= '</div>';
			$html .= '<div style="padding:20px;background:#fff">';
			$html .= '<p style="font-size:13px;color:#888;margin:0 0 12px">' . esc_html( $site_name ) . '</p>';
			$html .= '<div style="font-size:14px;line-height:1.6">' . nl2br( esc_html( $body_text ) ) . '</div>';
			$html .= '</div>';
			$html .= '<div style="padding:12px 20px;background:#f9f9f9">';
			$html .= '<a href="' . esc_url( $manage_url ) . '" style="color:#2271b1">' . esc_html__( 'View Dashboard', 'mcp-ai-wpoos-pro' ) . '</a>';
			$html .= '</div>';
			$html .= '</body></html>';

			return $html;
		}

		/**
		 * Convert an envelope's data section to plain text.
		 *
		 * Handles nested arrays and objects by flattening them into key: value lines.
		 *
		 * @param array  $data   Envelope data.
		 * @param int    $depth  Current indentation depth.
		 * @param string $prefix Key prefix for nested structures.
		 * @return string Plain-text representation.
		 */
		protected static function envelope_data_to_text( array $data, $depth = 0, $prefix = '' ) {
			$lines  = array();
			$indent = str_repeat( '  ', $depth );

			foreach ( $data as $key => $value ) {
				$full_key = $prefix ? $prefix . '.' . $key : $key;

				if ( is_array( $value ) ) {
					if ( isset( $value[0] ) && is_scalar( $value[0] ) ) {
						// Simple indexed array — comma-separated.
						$lines[] = $indent . $full_key . ': ' . implode( ', ', array_map( 'strval', $value ) );
					} else {
						// Nested structure — recurse.
						$lines[] = $indent . $full_key . ':';
						$lines[] = self::envelope_data_to_text( $value, $depth + 1, $full_key );
					}
				} elseif ( is_scalar( $value ) ) {
					$lines[] = $indent . $full_key . ': ' . (string) $value;
				}
			}

			return implode( "\n", $lines );
		}

		/**
		 * Convert an envelope to a Markdown document.
		 *
		 * Used by both Paper Store and WordPress post formatters.
		 *
		 * @param array $shared   Common formatted fields.
		 * @param array $envelope Full envelope.
		 * @param array $schedule Schedule record.
		 * @return string Markdown.
		 */
		protected static function envelope_to_markdown( array $shared, array $envelope, array $schedule ) {
			$md  = '# ' . $shared['schedule_name'] . "\n\n";
			$md .= '_Generated: ' . esc_html( gmdate( 'Y-m-d H:i:s T', $shared['generated_at'] ) ) . "_  \n";
			$md .= '_Status: ' . esc_html( $shared['status'] ) . "_  \n";

			if ( ! empty( $schedule['description'] ) ) {
				$md .= '_' . esc_html( $schedule['description'] ) . "_\n";
			}

			$md .= "\n---\n\n";

			// Response — the substantive output: the AI reply, tool results,
			// or final node output that the schedule produced. This is what
			// recipients actually want; the execution log follows below.
			$response = isset( $shared['response'] ) ? (string) $shared['response'] : '';
			if ( '' !== $response ) {
				$md .= "## Response\n\n";
				// Allow basic Markdown in the response (assistant runs produce
				// formatted output); escape only bare-HTML that could break layout.
				$md .= wp_kses(
					$response,
					array(
						'strong'     => array(),
						'em'         => array(),
						'code'       => array(),
						'pre'        => array(),
						'a'          => array( 'href' => array() ),
						'ul'         => array(),
						'ol'         => array(),
						'li'         => array(),
						'p'          => array(),
						'br'         => array(),
						'h1'         => array(),
						'h2'         => array(),
						'h3'         => array(),
						'h4'         => array(),
						'blockquote' => array(),
					)
				) . "\n\n";
				$md .= "---\n\n";
			}

			if ( ! empty( $shared['summary'] ) ) {
				$md .= "## Summary\n\n" . esc_html( $shared['summary'] ) . "\n\n";
			}

			if ( ! empty( $envelope['data'] ) && is_array( $envelope['data'] ) ) {
				$md .= "## Details\n\n";
				$md .= self::envelope_data_to_markdown( $envelope['data'], 2 );
			}

			if ( ! empty( $schedule['tags'] ) ) {
				$md .= "\n\n**Tags:** " . implode( ', ', array_map( 'esc_html', $schedule['tags'] ) ) . "\n";
			}

			return $md;
		}

		/**
		 * Recursively convert envelope data to Markdown sections.
		 *
		 * @param array $data       Data to convert.
		 * @param int   $depth      Heading level (2 = ##, 3 = ###, etc.).
		 * @return string Markdown.
		 */
		protected static function envelope_data_to_markdown( array $data, $depth = 2 ) {
			$md     = '';
			$hashes = str_repeat( '#', min( $depth, 6 ) );

			foreach ( $data as $key => $value ) {
				if ( is_array( $value ) ) {
					if ( isset( $value[0] ) && is_scalar( $value[0] ) ) {
						$md .= '- **' . esc_html( $key ) . ':** ' . esc_html( implode( ', ', array_map( 'strval', $value ) ) ) . "\n";
					} elseif ( isset( $value[0] ) && is_array( $value[0] ) ) {
						$md .= $hashes . '# ' . esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ) . "\n\n";
						foreach ( $value as $item ) {
							if ( is_array( $item ) ) {
								foreach ( $item as $ik => $iv ) {
									if ( is_scalar( $iv ) ) {
										$md .= '- **' . esc_html( $ik ) . ':** ' . esc_html( (string) $iv ) . "\n";
									}
								}
								$md .= "\n";
							}
						}
					} else {
						$md .= $hashes . '# ' . esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ) . "\n\n";
						$md .= self::envelope_data_to_markdown( $value, $depth + 1 );
					}
				} elseif ( is_scalar( $value ) ) {
					$md .= '- **' . esc_html( $key ) . ':** ' . esc_html( (string) $value ) . "\n";
				}
			}

			return $md;
		}
	}
}
