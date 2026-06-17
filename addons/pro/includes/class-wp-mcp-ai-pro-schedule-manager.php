<?php
/**
 * Pro Schedule Manager for NV oOS.
 *
 * Extends the base cron manager with pro-grade scheduling features:
 * - Named schedules with descriptions and tags
 * - Per-schedule enable/disable toggle
 * - Execution history (ring buffer, last 50 runs per schedule)
 * - Retry logic with configurable attempts and delay
 * - Per-schedule timeout enforcement (industry-standard hung-task protection)
 * - Webhook callback URL (POST run results to external systems on completion/failure)
 * - Admin email notifications on failure (wp_mail + Nodemailer HTML when available)
 * - Channel notifications via unified_channel_broadcast tool (Slack, Teams, Discord, Telegram, etc.)
 * - Priority ordering for schedule creation UI
 * - Central dispatcher hook for auditable execution
 * - assistant_run schedule type: sends message to AI assistant via internal REST chat endpoint
 * - channel_broadcast schedule type: send a message to chat channels on a schedule
 * - Execution History CCT integration when JetEngine is available
 * - Per-step tool-execution logging via WP_MCP_AI_Logger::log_tool_execution()
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

if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
	/**
	 * Manages pro-level scheduled tasks with extended tracking and control.
	 */
	class WP_MCP_AI_Pro_Schedule_Manager {

		/**
		 * Option key for storing pro schedules.
		 */
		const SCHEDULES_OPTION = 'wp_mcp_ai_pro_schedules';

		/**
		 * Option key for storing execution history (ring buffer per schedule).
		 */
		const HISTORY_OPTION = 'wp_mcp_ai_pro_schedule_history';

		/**
		 * Option key for storing per-run structured result envelopes.
		 *
		 * Kept separate from {@see self::HISTORY_OPTION} so that the cheap
		 * status/duration ring buffer can remain compact while consumer-facing
		 * payloads (used by the Scheduled Result widget/block) can be larger
		 * and retain a different retention window.
		 *
		 * @since 1.0.0
		 */
		const RESULTS_OPTION = 'wp_mcp_ai_pro_schedule_results';

		/**
		 * Central dispatcher cron hook.
		 */
		const DISPATCH_HOOK = 'wp_mcp_ai_pro_schedule_exec';

		/**
		 * Maximum history entries stored per schedule.
		 */
		const MAX_HISTORY_PER_SCHEDULE = 50;

		/**
		 * Default per-schedule retention for result envelopes.
		 *
		 * @since 1.0.0
		 */
		const DEFAULT_RESULT_RETENTION = 10;

		/**
		 * Supported schedule types.
		 */
		const TYPE_TASK              = 'task';
		const TYPE_WORKFLOW          = 'workflow';
		const TYPE_ASSISTANT_RUN     = 'assistant_run';
		const TYPE_CHANNEL_BROADCAST = 'channel_broadcast';
		const TYPE_WORKFLOW_BUILDER  = 'workflow_builder';

		/**
		 * Bootstrap hooks for the schedule manager.
		 */
		public static function init() {
			// Central dispatcher: all managed schedules call this hook with schedule ID as argument.
			add_action( self::DISPATCH_HOOK, array( __CLASS__, 'dispatch' ), 10, 1 );

			// Register custom cron intervals used by pro schedules.
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5-minute intervals are intentional for real-time scheduling use cases; the plugin also supports Action Scheduler for production deployments.
			add_filter( 'cron_schedules', array( __CLASS__, 'register_custom_intervals' ) );

			// Prune stale schedules on init.
			add_action( 'init', array( __CLASS__, 'maybe_prune_history' ) );

			// Diagnostic: confirm hooks are registered (visible in error log when WP_DEBUG is on).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging guarded by WP_DEBUG.
				error_log( 'WP_MCP_AI_Pro_Schedule_Manager::init() - dispatch hook registered for: ' . self::DISPATCH_HOOK );
			}
		}

		// -------------------------------------------------------------------------
		// Custom intervals
		// -------------------------------------------------------------------------

		/**
		 * Add custom cron intervals to WordPress.
		 *
		 * @param array $schedules Existing registered schedules.
		 * @return array Modified schedules with pro intervals.
		 */
		public static function register_custom_intervals( $schedules ) {
			$custom = array(
				'wp_mcp_ai_every_5_minutes'  => array(
					'interval' => 5 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 5 Minutes', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_every_15_minutes' => array(
					'interval' => 15 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 15 Minutes', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_every_30_minutes' => array(
					'interval' => 30 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 30 Minutes', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_every_2_hours'    => array(
					'interval' => 2 * HOUR_IN_SECONDS,
					'display'  => __( 'Every 2 Hours', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_every_6_hours'    => array(
					'interval' => 6 * HOUR_IN_SECONDS,
					'display'  => __( 'Every 6 Hours', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_every_12_hours'   => array(
					'interval' => 12 * HOUR_IN_SECONDS,
					'display'  => __( 'Every 12 Hours', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_weekly'           => array(
					'interval' => WEEK_IN_SECONDS,
					'display'  => __( 'Once Weekly', 'mcp-ai-wpoos-pro' ),
				),
				'wp_mcp_ai_monthly'          => array(
					'interval' => 30 * DAY_IN_SECONDS,
					'display'  => __( 'Once Monthly (30 days)', 'mcp-ai-wpoos-pro' ),
				),
			);

			foreach ( $custom as $key => $config ) {
				if ( ! isset( $schedules[ $key ] ) ) {
					$schedules[ $key ] = $config;
				}
			}

			return $schedules;
		}

		// -------------------------------------------------------------------------
		// CRUD
		// -------------------------------------------------------------------------

		/**
		 * Create or update a named schedule.
		 *
		 * @param array $data  Schedule data. Required keys depend on schedule_type:
		 *                     - task:              hook (string, required)
		 *                     - workflow:          workflow_steps (array, required)
		 *                     - assistant_run:     assistant_config (array with assistant_id + message, required)
		 *                     - channel_broadcast: broadcast_config (array with message + channels + credentials, required)
		 *                     Optional for all: name, description, schedule, timestamp, enabled, priority, tags,
		 *                     notify_on_failure, notify_email, notify_channels, max_retries, retry_delay.
		 * @param int   $user_id WordPress user performing the action.
		 * @return string|WP_Error Schedule ID on success, WP_Error on failure.
		 */
		public static function create_schedule( array $data, $user_id = 0 ) {
			// Determine schedule type.
			$valid_types   = array( self::TYPE_TASK, self::TYPE_WORKFLOW, self::TYPE_ASSISTANT_RUN, self::TYPE_CHANNEL_BROADCAST, self::TYPE_WORKFLOW_BUILDER );
			$schedule_type = isset( $data['schedule_type'] ) && in_array( $data['schedule_type'], $valid_types, true )
				? $data['schedule_type']
				: self::TYPE_TASK;

			// For tasks, a hook is required. For workflows/assistant_run, we use a synthetic hook.
			if ( self::TYPE_TASK === $schedule_type ) {
				$hook = isset( $data['hook'] ) ? sanitize_key( $data['hook'] ) : '';
				if ( '' === $hook ) {
					return new WP_Error( 'missing_hook', __( 'A hook name is required for task-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
			} elseif ( self::TYPE_WORKFLOW === $schedule_type ) {
				// Validate workflow steps.
				$workflow_steps = isset( $data['workflow_steps'] ) && is_array( $data['workflow_steps'] )
					? $data['workflow_steps']
					: array();
				if ( empty( $workflow_steps ) ) {
					return new WP_Error( 'missing_workflow_steps', __( 'At least one workflow step is required for workflow-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
				// Sanitize each step.
				$sanitized_steps = array();
				foreach ( $workflow_steps as $step ) {
					if ( ! is_array( $step ) || empty( $step['tool_slug'] ) ) {
						continue;
					}
					$sanitized_steps[] = array(
						'tool_slug' => sanitize_key( $step['tool_slug'] ),
						'arguments' => isset( $step['arguments'] ) && is_array( $step['arguments'] ) ? $step['arguments'] : array(),
						'label'     => isset( $step['label'] ) ? sanitize_text_field( $step['label'] ) : '',
					);
				}
				if ( empty( $sanitized_steps ) ) {
					return new WP_Error( 'invalid_workflow_steps', __( 'No valid workflow steps were provided.', 'mcp-ai-wpoos-pro' ) );
				}
				$data['workflow_steps'] = $sanitized_steps;
				$hook                   = 'wp_mcp_ai_pro_workflow_run';
			} elseif ( self::TYPE_ASSISTANT_RUN === $schedule_type ) {
				// Validate assistant config.
				$assistant_config = isset( $data['assistant_config'] ) && is_array( $data['assistant_config'] )
					? $data['assistant_config']
					: array();
				if ( empty( $assistant_config['assistant_id'] ) ) {
					return new WP_Error( 'missing_assistant_id', __( 'An assistant_id is required for assistant_run-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
				if ( empty( $assistant_config['message'] ) ) {
					return new WP_Error( 'missing_assistant_message', __( 'A message is required for assistant_run-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
				$data['assistant_config'] = array(
					'assistant_id'           => absint( $assistant_config['assistant_id'] ),
					'message'                => sanitize_textarea_field( $assistant_config['message'] ),
					'context'                => isset( $assistant_config['context'] ) && is_array( $assistant_config['context'] )
						? $assistant_config['context']
						: array(),
					'max_agentic_iterations' => isset( $assistant_config['max_agentic_iterations'] )
						? max( 0, absint( $assistant_config['max_agentic_iterations'] ) )
						: 0,
				);
				$hook                     = 'wp_mcp_ai_pro_assistant_run';
			} elseif ( self::TYPE_CHANNEL_BROADCAST === $schedule_type ) {
				// Validate channel broadcast config.
				$broadcast_config = isset( $data['broadcast_config'] ) && is_array( $data['broadcast_config'] )
					? $data['broadcast_config']
					: array();

				if ( empty( $broadcast_config['message'] ) ) {
					return new WP_Error( 'missing_broadcast_message', __( 'A message is required for channel_broadcast-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
				if ( empty( $broadcast_config['channels'] ) || ! is_array( $broadcast_config['channels'] ) ) {
					return new WP_Error( 'missing_broadcast_channels', __( 'At least one channel is required for channel_broadcast-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
				if ( empty( $broadcast_config['credentials'] ) || ! is_array( $broadcast_config['credentials'] ) ) {
					return new WP_Error( 'missing_broadcast_credentials', __( 'Channel credentials are required for channel_broadcast-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}

				$allowed_channels = array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' );
				$sanitized_chans  = array();
				foreach ( $broadcast_config['channels'] as $chan ) {
					$chan = sanitize_key( $chan );
					if ( in_array( $chan, $allowed_channels, true ) ) {
						$sanitized_chans[] = $chan;
					}
				}
				if ( empty( $sanitized_chans ) ) {
					return new WP_Error( 'invalid_broadcast_channels', __( 'No valid channels were provided. Supported: telegram, slack, discord, teams, messenger, whatsapp.', 'mcp-ai-wpoos-pro' ) );
				}

				$data['broadcast_config'] = array(
					'message'     => sanitize_textarea_field( $broadcast_config['message'] ),
					'channels'    => $sanitized_chans,
					'credentials' => $broadcast_config['credentials'],
				);
				$hook                     = 'wp_mcp_ai_pro_channel_broadcast';
			} elseif ( self::TYPE_WORKFLOW_BUILDER === $schedule_type ) {
				// Validate workflow builder reference.
				$workflow_builder_id = isset( $data['workflow_builder_id'] ) ? sanitize_key( $data['workflow_builder_id'] ) : '';
				if ( '' === $workflow_builder_id ) {
					return new WP_Error( 'missing_workflow_builder_id', __( 'A workflow_builder_id is required for workflow_builder-type schedules.', 'mcp-ai-wpoos-pro' ) );
				}
				// Verify the workflow exists in the Pro Workflow Builder option store.
				$saved_workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
				if ( ! is_array( $saved_workflows ) || ! isset( $saved_workflows[ $workflow_builder_id ] ) ) {
					return new WP_Error( 'workflow_builder_not_found', __( 'The specified Workflow Builder workflow was not found.', 'mcp-ai-wpoos-pro' ) );
				}
				$data['workflow_builder_id'] = $workflow_builder_id;
				$hook                        = 'wp_mcp_ai_pro_workflow_builder_run';
			}

			$schedule = isset( $data['schedule'] ) ? sanitize_key( $data['schedule'] ) : 'single';

			// Validate interval unless single.
			if ( 'single' !== $schedule ) {
				$valid_schedules = array_keys( wp_get_schedules() );
				if ( ! in_array( $schedule, $valid_schedules, true ) ) {
					return new WP_Error(
						'invalid_schedule',
						/* translators: %s: schedule interval slug */
						sprintf( __( 'The schedule interval "%s" is not registered.', 'mcp-ai-wpoos-pro' ), $schedule )
					);
				}
			}

			// Capture current time once for consistent comparisons throughout this method.
			$now       = time();
			$timestamp = isset( $data['timestamp'] ) ? absint( $data['timestamp'] ) : $now + 60;

			if ( $timestamp < $now ) {
				return new WP_Error( 'past_timestamp', __( 'Schedule timestamp must be in the future.', 'mcp-ai-wpoos-pro' ) );
			}

			$args        = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
			$enabled     = isset( $data['enabled'] ) ? (bool) $data['enabled'] : true;
			$priority    = isset( $data['priority'] ) ? max( 1, min( 10, (int) $data['priority'] ) ) : 5;
			$max_retries = isset( $data['max_retries'] ) ? max( 0, min( 5, (int) $data['max_retries'] ) ) : 0;
			$retry_delay = isset( $data['retry_delay'] ) ? max( 60, (int) $data['retry_delay'] ) : 300;
			$name        = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $hook;
			$description = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';

			$notify       = isset( $data['notify_on_failure'] ) ? (bool) $data['notify_on_failure'] : false;
			$notify_email = isset( $data['notify_email'] ) ? sanitize_email( $data['notify_email'] ) : get_option( 'admin_email' );

			// Symfony Validator: enforce RFC-compliant email when a custom address is supplied.
			if ( $notify && isset( $data['notify_email'] ) && '' !== $data['notify_email']
				&& class_exists( 'Symfony\Component\Validator\Validation' )
			) {
				$validator  = \Symfony\Component\Validator\Validation::createValidator();
				$violations = $validator->validate(
					$notify_email,
					array(
						new \Symfony\Component\Validator\Constraints\Email(
							array( 'message' => 'The notify_email "{{ value }}" is not a valid email address.' )
						),
					)
				);
				if ( count( $violations ) > 0 ) {
					return new WP_Error(
						'invalid_notify_email',
						(string) $violations->get( 0 )->getMessage()
					);
				}
			}
			// notify_channels: array of channel slugs (telegram, slack, etc.) to send failure alerts via unified_channel_broadcast.
			$notify_channels = isset( $data['notify_channels'] ) && is_array( $data['notify_channels'] )
				? array_map( 'sanitize_key', $data['notify_channels'] )
				: array();
			// notify_channel_credentials: credentials object keyed by channel slug, passed to unified_channel_broadcast.
			$notify_channel_credentials = isset( $data['notify_channel_credentials'] ) && is_array( $data['notify_channel_credentials'] )
				? $data['notify_channel_credentials']
				: array();
			$tags                       = isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_map( 'sanitize_text_field', $data['tags'] ) : array();
			// result_delivery: unified on-success and on-failure delivery channel configuration.
			$result_delivery = isset( $data['result_delivery'] ) && is_array( $data['result_delivery'] )
				? self::sanitize_result_delivery( $data['result_delivery'] )
				: self::get_default_result_delivery();

			// timeout: maximum execution time in seconds (0 = no limit). Industry-standard safeguard against hung tasks.
			$timeout = isset( $data['timeout'] ) ? max( 0, (int) $data['timeout'] ) : 0;

			// callback_url: external webhook URL that receives a POST with run results on completion/failure.
			// Validate with filter_var() rather than wp_http_validate_url() because
			// the latter performs DNS resolution, which fails for intranet hosts and
			// in CI/test environments where DNS is unavailable.
			$callback_url = isset( $data['callback_url'] ) ? esc_url_raw( $data['callback_url'] ) : '';
			if ( $callback_url && ! filter_var( $callback_url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_callback_url', __( 'The callback URL is not a valid HTTP(S) URL.', 'mcp-ai-wpoos-pro' ) );
			}
			$callback_secret = isset( $data['callback_secret'] ) ? sanitize_text_field( $data['callback_secret'] ) : '';

			// Display / widget binding fields — power the Scheduled Result block/widget.
			$display_fields = self::sanitize_display_fields( isset( $data['display'] ) && is_array( $data['display'] ) ? $data['display'] : array() );

			// Use a unique ID that incorporates schedule type for workflow/assistant to avoid collisions.
			$id_key      = self::TYPE_TASK === $schedule_type
				? array(
					'hook' => $hook,
					'args' => $args,
				)
				: array(
					'type' => $schedule_type,
					'name' => $name,
					'ts'   => $timestamp,
				);
			$schedule_id = md5( wp_json_encode( $id_key ) );

			$existing = self::get_schedule( $schedule_id );

			$record = array(
				'id'                         => $schedule_id,
				'name'                       => $name,
				'description'                => $description,
				'schedule_type'              => $schedule_type,
				'hook'                       => $hook,
				'args'                       => $args,
				'workflow_steps'             => isset( $data['workflow_steps'] ) ? $data['workflow_steps'] : array(),
				'assistant_config'           => isset( $data['assistant_config'] ) ? $data['assistant_config'] : array(),
				'broadcast_config'           => isset( $data['broadcast_config'] ) ? $data['broadcast_config'] : array(),
				'workflow_builder_id'        => isset( $data['workflow_builder_id'] ) ? $data['workflow_builder_id'] : '',
				'display'                    => $display_fields,
				'schedule'                   => $schedule,
				'timestamp'                  => $timestamp,
				'enabled'                    => $enabled,
				'priority'                   => $priority,
				'tags'                       => $tags,
				'timeout'                    => $timeout,
				'callback_url'               => $callback_url,
				'callback_secret'            => $callback_secret,
				'notify_on_failure'          => $notify,
				'notify_email'               => $notify_email,
				'notify_channels'            => $notify_channels,
				'notify_channel_credentials' => $notify_channel_credentials,
				'result_delivery'            => $result_delivery,
				'max_retries'                => $max_retries,
				'retry_delay'                => $retry_delay,
				'retry_count'                => 0,
				'last_run_status'            => $existing ? $existing['last_run_status'] : 'never',
				'last_run_time'              => $existing ? $existing['last_run_time'] : 0,
				'last_run_duration'          => $existing ? $existing['last_run_duration'] : 0,
				'last_error'                 => $existing ? $existing['last_error'] : '',
				'run_count'                  => $existing ? $existing['run_count'] : 0,
				'created_at'                 => $existing ? $existing['created_at'] : time(),
				'created_by'                 => $existing ? $existing['created_by'] : (int) $user_id,
				'updated_at'                 => time(),
				'updated_by'                 => (int) $user_id,
			);

			// Unschedule any existing WP cron event for this schedule.
			self::unschedule_wp_cron( $schedule_id );

			// Schedule the new WP cron event if enabled.
			if ( $enabled ) {
				$scheduled = self::schedule_wp_cron( $schedule_id, $schedule, $timestamp );
				if ( is_wp_error( $scheduled ) ) {
					return $scheduled;
				}
			}

			$schedules                 = self::load_schedules();
			$schedules[ $schedule_id ] = $record;
			self::save_schedules( $schedules );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'schedule_run',
					'Pro schedule created: ' . $name,
					array(
						'event'       => 'schedule_created',
						'schedule_id' => $schedule_id,
						'hook'        => $hook,
						'schedule'    => $schedule,
						'enabled'     => $enabled,
						'user_id'     => $user_id,
					)
				);
			}

			return $schedule_id;
		}

		/**
		 * Update an existing named schedule.
		 *
		 * @param string $schedule_id Schedule ID to update.
		 * @param array  $data        Fields to update.
		 * @param int    $user_id     WordPress user performing the action.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public static function update_schedule( $schedule_id, array $data, $user_id = 0 ) {
			$schedule_id = (string) $schedule_id;
			$existing    = self::get_schedule( $schedule_id );

			if ( ! $existing ) {
				return new WP_Error(
					'not_found',
					/* translators: %s: schedule ID */
					sprintf( __( 'Schedule "%s" not found.', 'mcp-ai-wpoos-pro' ), $schedule_id )
				);
			}

			// Merge updated fields.
			$updated = $existing;

			if ( isset( $data['name'] ) ) {
				$updated['name'] = sanitize_text_field( $data['name'] );
			}
			if ( isset( $data['description'] ) ) {
				$updated['description'] = sanitize_textarea_field( $data['description'] );
			}
			if ( isset( $data['enabled'] ) ) {
				$updated['enabled'] = (bool) $data['enabled'];
			}
			if ( isset( $data['priority'] ) ) {
				$updated['priority'] = max( 1, min( 10, (int) $data['priority'] ) );
			}
			if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
				$updated['tags'] = array_map( 'sanitize_text_field', $data['tags'] );
			}
			if ( isset( $data['notify_on_failure'] ) ) {
				$updated['notify_on_failure'] = (bool) $data['notify_on_failure'];
			}
			if ( isset( $data['notify_email'] ) ) {
				$updated['notify_email'] = sanitize_email( $data['notify_email'] );
			}
			if ( isset( $data['notify_channels'] ) && is_array( $data['notify_channels'] ) ) {
				$updated['notify_channels'] = array_map( 'sanitize_key', $data['notify_channels'] );
			}
			if ( isset( $data['notify_channel_credentials'] ) && is_array( $data['notify_channel_credentials'] ) ) {
				$updated['notify_channel_credentials'] = $data['notify_channel_credentials'];
			}
			if ( isset( $data['broadcast_config'] ) && is_array( $data['broadcast_config'] ) ) {
				// Allow updating the broadcast message / channels in update_schedule.
				$updated['broadcast_config'] = $data['broadcast_config'];
			}
			if ( isset( $data['max_retries'] ) ) {
				$updated['max_retries'] = max( 0, min( 5, (int) $data['max_retries'] ) );
			}
			if ( isset( $data['retry_delay'] ) ) {
				$updated['retry_delay'] = max( 60, (int) $data['retry_delay'] );
			}
			if ( isset( $data['timeout'] ) ) {
				$updated['timeout'] = max( 0, (int) $data['timeout'] );
			}
			if ( isset( $data['callback_url'] ) ) {
				$url = esc_url_raw( $data['callback_url'] );
				if ( '' !== $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					return new WP_Error( 'invalid_callback_url', __( 'The callback URL is not a valid HTTP(S) URL.', 'mcp-ai-wpoos-pro' ) );
				}
				$updated['callback_url'] = $url;
			}
			if ( isset( $data['callback_secret'] ) ) {
				$updated['callback_secret'] = sanitize_text_field( $data['callback_secret'] );
			}
			if ( isset( $data['display'] ) && is_array( $data['display'] ) ) {
				$existing_display   = isset( $existing['display'] ) && is_array( $existing['display'] ) ? $existing['display'] : array();
				$updated['display'] = self::sanitize_display_fields( array_merge( $existing_display, $data['display'] ) );
			}
			if ( isset( $data['result_delivery'] ) && is_array( $data['result_delivery'] ) ) {
				$existing_rd                = isset( $existing['result_delivery'] ) && is_array( $existing['result_delivery'] )
					? $existing['result_delivery']
					: self::get_default_result_delivery();
				$updated['result_delivery'] = self::sanitize_result_delivery( array_replace_recursive( $existing_rd, $data['result_delivery'] ) );
			}
			if ( isset( $data['schedule'] ) ) {
				$new_schedule = sanitize_key( $data['schedule'] );
				if ( 'single' !== $new_schedule ) {
					$valid = array_keys( wp_get_schedules() );
					if ( ! in_array( $new_schedule, $valid, true ) ) {
						return new WP_Error(
							'invalid_schedule',
							/* translators: %s: schedule interval slug */
							sprintf( __( 'The schedule interval "%s" is not registered.', 'mcp-ai-wpoos-pro' ), $new_schedule )
						);
					}
				}
				$updated['schedule'] = $new_schedule;
			}
			if ( isset( $data['timestamp'] ) ) {
				$ts = absint( $data['timestamp'] );
				if ( $ts < time() ) {
					return new WP_Error( 'past_timestamp', __( 'Schedule timestamp must be in the future.', 'mcp-ai-wpoos-pro' ) );
				}
				$updated['timestamp'] = $ts;
			}

			$updated['updated_at'] = time();
			$updated['updated_by'] = (int) $user_id;

			// Re-schedule WP cron.
			self::unschedule_wp_cron( $schedule_id );

			if ( $updated['enabled'] ) {
				$ts        = isset( $updated['timestamp'] ) ? $updated['timestamp'] : time() + 60;
				$scheduled = self::schedule_wp_cron( $schedule_id, $updated['schedule'], $ts );
				if ( is_wp_error( $scheduled ) ) {
					return $scheduled;
				}
			}

			$schedules                 = self::load_schedules();
			$schedules[ $schedule_id ] = $updated;
			self::save_schedules( $schedules );

			return true;
		}

		/**
		 * Delete a named schedule and its WP cron event.
		 *
		 * @param string $schedule_id Schedule ID to remove.
		 * @return bool Whether the schedule was found and removed.
		 */
		public static function delete_schedule( $schedule_id ) {
			$schedule_id = (string) $schedule_id;
			$schedules   = self::load_schedules();

			if ( ! isset( $schedules[ $schedule_id ] ) ) {
				return false;
			}

			self::unschedule_wp_cron( $schedule_id );

			unset( $schedules[ $schedule_id ] );
			self::save_schedules( $schedules );

			// Clean up history.
			$history = self::load_history();
			unset( $history[ $schedule_id ] );
			self::save_history( $history );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'schedule_run', 'Pro schedule deleted: ' . $schedule_id, array( 'event' => 'schedule_deleted' ) );
			}

			return true;
		}

		/**
		 * Get a single named schedule by ID.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @return array|null Schedule record or null if not found.
		 */
		public static function get_schedule( $schedule_id ) {
			$schedules = self::load_schedules();
			$id        = (string) $schedule_id;
			return isset( $schedules[ $id ] ) ? $schedules[ $id ] : null;
		}

		/**
		 * Get all named schedules.
		 *
		 * @param array $filters Optional. 'enabled' (bool), 'tag' (string), 'hook' (string).
		 * @return array Keyed by schedule ID.
		 */
		public static function get_schedules( array $filters = array() ) {
			$schedules = self::load_schedules();

			if ( isset( $filters['enabled'] ) ) {
				$want_enabled = (bool) $filters['enabled'];
				$schedules    = array_filter(
					$schedules,
					function ( $s ) use ( $want_enabled ) {
						return (bool) $s['enabled'] === $want_enabled;
					}
				);
			}

			if ( ! empty( $filters['tag'] ) ) {
				$tag       = sanitize_text_field( $filters['tag'] );
				$schedules = array_filter(
					$schedules,
					function ( $s ) use ( $tag ) {
						return in_array( $tag, (array) $s['tags'], true );
					}
				);
			}

			if ( ! empty( $filters['hook'] ) ) {
				$hook      = sanitize_key( $filters['hook'] );
				$schedules = array_filter(
					$schedules,
					function ( $s ) use ( $hook ) {
						return $s['hook'] === $hook;
					}
				);
			}

			// Sort by priority (lower number = higher priority) then by name.
			uasort(
				$schedules,
				function ( $a, $b ) {
					$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 5;
					$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 5;
					if ( $pa !== $pb ) {
						return $pa - $pb;
					}
					return strcmp( (string) $a['name'], (string) $b['name'] );
				}
			);

			return $schedules;
		}

		/**
		 * Toggle a schedule's enabled state.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param bool   $enabled     Whether the schedule should be enabled.
		 * @param int    $user_id     User performing the action.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public static function toggle_schedule( $schedule_id, $enabled, $user_id = 0 ) {
			return self::update_schedule( $schedule_id, array( 'enabled' => $enabled ), $user_id );
		}

		/**
		 * Manually trigger a named schedule (bypasses cron, runs synchronously).
		 *
		 * @param string $schedule_id Schedule ID to trigger.
		 * @param int    $user_id     User performing the action.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public static function trigger_now( $schedule_id, $user_id = 0 ) {
			$schedule = self::get_schedule( $schedule_id );

			if ( ! $schedule ) {
				return new WP_Error(
					'not_found',
					/* translators: %s: schedule ID */
					sprintf( __( 'Schedule "%s" not found.', 'mcp-ai-wpoos-pro' ), $schedule_id )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'schedule_run',
					'Pro schedule manually triggered: ' . $schedule['name'],
					array(
						'event'       => 'schedule_triggered',
						'schedule_id' => $schedule_id,
						'user_id'     => $user_id,
					)
				);
			}

			return self::dispatch( $schedule_id );
		}

		// -------------------------------------------------------------------------
		// Execution history
		// -------------------------------------------------------------------------

		/**
		 * Get execution history for a schedule.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param int    $limit       Maximum entries to return (default: 20).
		 * @return array Array of run records, newest first.
		 */
		public static function get_run_history( $schedule_id, $limit = 20 ) {
			$history = self::load_history();
			$id      = (string) $schedule_id;

			if ( ! isset( $history[ $id ] ) || ! is_array( $history[ $id ] ) ) {
				return array();
			}

			$runs = $history[ $id ];

			// Newest first.
			$runs = array_reverse( $runs );

			if ( $limit > 0 ) {
				$runs = array_slice( $runs, 0, (int) $limit );
			}

			return $runs;
		}

		/**
		 * Export all enabled schedules as an RFC 5545 iCalendar (.ics) string.
		 *
		 * Each enabled schedule becomes a VEVENT whose DTSTART is the next WP-cron
		 * run time (or now() for single/one-shot schedules that have already fired).
		 * Uses the `wp_mcp_ai_ics_generate_calendar` filter so the Pro ical-generator
		 * Node.js service can produce a fully compliant file when available.
		 *
		 * @param array $filters Optional. Same filter keys as get_schedules().
		 * @return string ICS content string.
		 */
		public static function get_schedules_ical( array $filters = array() ) {
			$filters['enabled'] = true;
			$schedules          = self::get_schedules( $filters );

			$events = array();
			foreach ( $schedules as $schedule ) {
				$next = self::get_next_run_time( $schedule['id'] );
				$ts   = $next ? (int) $next : time();

				$events[] = array(
					'title'       => $schedule['name'],
					'description' => isset( $schedule['description'] ) ? $schedule['description'] : '',
					'start'       => $ts,
					'end'         => $ts + 300, // 5-minute duration placeholder.
					'uid'         => 'nvoos-schedule-' . $schedule['id'] . '@' . wp_parse_url( get_home_url(), PHP_URL_HOST ),
					'type'        => isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : 'task',
				);
			}

			// Allow the ical-generator Node.js service to produce a compliant ICS.
			$result = apply_filters(
				'wp_mcp_ai_ics_generate_calendar',
				false,
				array(
					'calendar_name' => get_bloginfo( 'name' ) . ' — Schedules',
					'events'        => $events,
				)
			);

			if ( is_array( $result ) && ! empty( $result['content'] ) ) {
				return $result['content'];
			}

			// Pure-PHP RFC 5545 fallback.
			$crlf = "\r\n";
			$ics  = 'BEGIN:VCALENDAR' . $crlf;
			$ics .= 'VERSION:2.0' . $crlf;
			$ics .= 'PRODID:-//NV Digital Solutions//NV oOS Schedule Manager//EN' . $crlf;
			$ics .= 'CALSCALE:GREGORIAN' . $crlf;
			$ics .= 'X-WR-CALNAME:' . str_replace( array( "\r", "\n" ), ' ', get_bloginfo( 'name' ) ) . ' Schedules' . $crlf;

			foreach ( $events as $event ) {
				$dt_start = gmdate( 'Ymd\THis\Z', $event['start'] );
				$dt_end   = gmdate( 'Ymd\THis\Z', $event['end'] );
				$dt_stamp = gmdate( 'Ymd\THis\Z' );
				$summary  = str_replace( array( "\r", "\n", ',' ), array( ' ', ' ', '\,' ), $event['title'] );
				$desc_raw = trim( $event['description'] . ' [' . strtoupper( $event['type'] ) . ']' );
				$desc     = str_replace( array( "\r", "\n", ',' ), array( ' ', '\n', '\,' ), $desc_raw );

				$ics .= 'BEGIN:VEVENT' . $crlf;
				$ics .= 'UID:' . $event['uid'] . $crlf;
				$ics .= 'DTSTAMP:' . $dt_stamp . $crlf;
				$ics .= 'DTSTART:' . $dt_start . $crlf;
				$ics .= 'DTEND:' . $dt_end . $crlf;
				$ics .= 'SUMMARY:' . $summary . $crlf;
				if ( $desc ) {
					$ics .= 'DESCRIPTION:' . $desc . $crlf;
				}
				$ics .= 'END:VEVENT' . $crlf;
			}

			$ics .= 'END:VCALENDAR' . $crlf;

			return $ics;
		}

		/**
		 * Export run history for a schedule as a CSV string.
		 *
		 * Uses WP_MCP_AI_Contact_Importer_Service (csv-stringify NPM package) when
		 * available; falls back to a pure-PHP fputcsv implementation.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param int    $limit       Maximum rows (0 = all). Default 50.
		 * @return string CSV content.
		 */
		public static function get_history_csv( $schedule_id, $limit = 50 ) {
			$schedule = self::get_schedule( $schedule_id );
			$runs     = self::get_run_history( $schedule_id, $limit );

			$rows = array();
			foreach ( $runs as $run ) {
				$rows[] = array(
					'schedule_name' => $schedule ? $schedule['name'] : $schedule_id,
					'schedule_id'   => $schedule_id,
					'status'        => $run['status'],
					'start_time'    => isset( $run['start_time'] ) ? wp_date( 'Y-m-d H:i:s', $run['start_time'] ) : '',
					'duration_s'    => isset( $run['duration'] ) ? $run['duration'] : '',
					'error'         => isset( $run['error'] ) ? $run['error'] : '',
					'action_log'    => isset( $run['action_log'] ) && is_array( $run['action_log'] ) ? wp_json_encode( $run['action_log'] ) : '',
				);
			}

			// Try csv-stringify via WP_MCP_AI_Contact_Importer_Service.
			$service_path = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-contact-importer-service.php'
				: '';
			if ( $service_path && file_exists( $service_path ) && ! class_exists( 'WP_MCP_AI_Contact_Importer_Service' ) ) {
				require_once $service_path;
			}

			if ( class_exists( 'WP_MCP_AI_Contact_Importer_Service' ) ) {
				$svc    = new WP_MCP_AI_Contact_Importer_Service();
				$result = $svc->generate_csv(
					$rows,
					array(
						'header'    => true,
						'delimiter' => ',',
					)
				);
				if ( ! is_wp_error( $result ) && is_string( $result ) ) {
					return $result;
				}
			}

			// Pure-PHP fputcsv fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem file.
			$handle = fopen( 'php://temp', 'r+' );
			fputcsv( $handle, array( 'schedule_name', 'schedule_id', 'status', 'start_time', 'duration_s', 'error', 'action_log' ) );
			foreach ( $rows as $row ) {
				fputcsv( $handle, array_values( $row ) );
			}
			rewind( $handle );
			$csv = stream_get_contents( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			return $csv;
		}

		/**
		 * Clear run history for a schedule.
		 *
		 * @param string $schedule_id Schedule ID.
		 */
		public static function clear_run_history( $schedule_id ) {
			$history = self::load_history();
			unset( $history[ (string) $schedule_id ] );
			self::save_history( $history );
		}

		/**
		 * Prune old history entries to stay within the ring-buffer limit.
		 *
		 * Rate-limited to run at most once per hour via a transient to avoid
		 * iterating all history on every page load.
		 */
		public static function maybe_prune_history() {
			// Run at most once per hour.
			if ( get_transient( 'wp_mcp_ai_pro_sm_prune_lock' ) ) {
				return;
			}

			$history = self::load_history();
			$changed = false;

			foreach ( $history as $id => $runs ) {
				if ( is_array( $runs ) && count( $runs ) > self::MAX_HISTORY_PER_SCHEDULE ) {
					$history[ $id ] = array_slice( $runs, - self::MAX_HISTORY_PER_SCHEDULE );
					$changed        = true;
				}
			}

			if ( $changed ) {
				self::save_history( $history );
			}

			set_transient( 'wp_mcp_ai_pro_sm_prune_lock', 1, HOUR_IN_SECONDS );
		}

		// -------------------------------------------------------------------------
		// Dispatcher
		// -------------------------------------------------------------------------

		/**
		 * Central dispatcher called by WP cron.
		 *
		 * Routes to the correct execution method based on schedule_type:
		 * - task:          fires the configured WP action hook
		 * - workflow:      executes a sequence of tool calls via the tool registry
		 * - assistant_run: fires a scheduled AI assistant with a configured message
		 *
		 * Records the result, handles retry logic, and sends failure notifications.
		 *
		 * @param string $schedule_id Schedule ID to dispatch.
		 * @return bool True on success, false on failure.
		 */
		public static function dispatch( $schedule_id ) {
			$schedule_id = (string) $schedule_id;

			self::debug_log( sprintf( '[dispatch] Received schedule_id=%s', $schedule_id ) );

			$schedule = self::get_schedule( $schedule_id );

			if ( ! $schedule ) {
				self::debug_log( sprintf( '[dispatch] Schedule not found: %s — returning false', $schedule_id ) );
				return false;
			}

			if ( empty( $schedule['enabled'] ) ) {
				self::debug_log( sprintf( '[dispatch] Schedule disabled: %s (%s) — returning false', $schedule_id, $schedule['name'] ) );
				return false;
			}

			$schedule_type = isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : self::TYPE_TASK;
			$start         = microtime( true );
			$error_msg     = '';
			$success       = true;
			$action_log    = array( 'type' => $schedule_type );

			self::debug_log( sprintf( '[dispatch] Dispatching %s schedule: %s (%s)', $schedule_type, $schedule['name'], $schedule_id ) );

			// Timeout: set a PHP time limit for this dispatch if configured (0 = unlimited).
			// Note: set_time_limit() may be disabled on shared hosting environments.
			// As a fallback, the duration is also checked post-execution and the run is
			// marked as failed if it exceeded the timeout (best-effort enforcement).
			$timeout = isset( $schedule['timeout'] ) ? (int) $schedule['timeout'] : 0;
			if ( $timeout > 0 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled on some hosts.
				@set_time_limit( $timeout );
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_schedule_run(
					'schedule_run_start',
					$schedule_id,
					$schedule['name'],
					$schedule_type
				);
			}

			try {
				switch ( $schedule_type ) {
					case self::TYPE_WORKFLOW:
						$result = self::dispatch_workflow( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						} else {
							$action_log['steps'] = is_array( $result ) ? $result : array();
						}
						break;

					case self::TYPE_ASSISTANT_RUN:
						$result = self::dispatch_assistant_run( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						} else {
							$action_log['assistant'] = is_array( $result ) ? $result : array();
						}
						break;

					case self::TYPE_CHANNEL_BROADCAST:
						$result = self::dispatch_channel_broadcast( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						} else {
							$action_log['broadcast'] = is_array( $result ) ? $result : array();
						}
						break;

					case self::TYPE_WORKFLOW_BUILDER:
						$result = self::dispatch_workflow_builder( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						} else {
							$action_log['workflow_builder_id'] = isset( $schedule['workflow_builder_id'] ) ? $schedule['workflow_builder_id'] : '';
						}
						break;

					case self::TYPE_TASK:
					default:
						$hook = (string) $schedule['hook'];
						$args = isset( $schedule['args'] ) && is_array( $schedule['args'] ) ? $schedule['args'] : array();
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is user-supplied and sanitized with sanitize_key() during schedule creation. Only users with manage_options can create task schedules.
						do_action_ref_array( $hook, $args );
						$action_log['hook'] = $hook;
						$action_log['args'] = $args;
						break;
				}
			} catch ( Throwable $e ) {
				$success   = false;
				$error_msg = sprintf(
					'%s in %s:%d',
					$e->getMessage(),
					str_replace( ABSPATH, '', $e->getFile() ),
					$e->getLine()
				);

				self::debug_log( sprintf( '[dispatch] Exception in %s schedule %s: %s', $schedule_type, $schedule_id, $error_msg ) );

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Pro schedule exception: ' . $schedule['name'],
						array(
							'schedule_id'   => $schedule_id,
							'schedule_type' => $schedule_type,
							'error'         => $error_msg,
							'trace'         => $e->getTraceAsString(),
						)
					);
				}
			}

			$end      = microtime( true );
			$duration = round( $end - $start, 3 );

			// Timeout enforcement: if the run exceeded the configured timeout, mark as failed.
			if ( $success && $timeout > 0 && $duration > $timeout ) {
				$success   = false;
				$error_msg = sprintf(
					/* translators: 1: actual duration, 2: allowed timeout */
					__( 'Schedule execution exceeded timeout (%1$.1fs > %2$ds).', 'mcp-ai-wpoos-pro' ),
					$duration,
					$timeout
				);
			}

			// Record run result.
			self::record_run( $schedule_id, $success, $duration, $error_msg, $action_log );

			/**
			 * Fires after every Pro schedule run completes, regardless of success.
			 *
			 * Mirrors the action surfaced by the Pro workflow / assistant pipelines so
			 * observability layers (OTel, dashboards, notifications) can subscribe to
			 * a single canonical "run completed" event.
			 *
			 * @since 1.x
			 *
			 * @param string $schedule_id Schedule identifier.
			 * @param array  $result      {
			 *     Result summary.
			 *
			 *     @type bool   $success    Whether the run finished without error.
			 *     @type float  $duration   Execution time in seconds.
			 *     @type string $error      Last error message ('' on success).
			 *     @type array  $action_log Type-specific structured log of what ran.
			 *     @type array  $schedule   The schedule record at dispatch time.
			 * }
			 */
			do_action(
				'wp_mcp_ai_pro_schedule_run_completed',
				$schedule_id,
				array(
					'success'    => (bool) $success,
					'duration'   => (float) $duration,
					'error'      => (string) $error_msg,
					'action_log' => $action_log,
					'schedule'   => $schedule,
				)
			);

			self::debug_log(
				sprintf(
					'[dispatch] Completed %s schedule %s (%s): %s in %.3fs%s',
					$schedule_type,
					$schedule_id,
					$schedule['name'],
					$success ? 'SUCCESS' : 'FAILURE',
					$duration,
					$error_msg ? ' — ' . $error_msg : ''
				)
			);

			// Log the run completion to the Logger service.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				if ( $success ) {
					WP_MCP_AI_Logger::log_schedule_run(
						'schedule_run_complete',
						$schedule_id,
						$schedule['name'],
						$schedule_type,
						array(
							'duration'   => $duration,
							'action_log' => $action_log,
						)
					);
				} else {
					WP_MCP_AI_Logger::log_schedule_run(
						'schedule_run_failed',
						$schedule_id,
						$schedule['name'],
						$schedule_type,
						array(
							'duration' => $duration,
							'error'    => $error_msg,
						)
					);
				}
			}

			// Handle retry logic on failure.
			if ( ! $success ) {
				self::handle_failure( $schedule_id, $schedule, $error_msg );
			} else {
				// Success: reset retry counter.
				$schedules                                    = self::load_schedules();
				$schedules[ $schedule_id ]['retry_count']     = 0;
				$schedules[ $schedule_id ]['last_run_status'] = 'success';
				self::save_schedules( $schedules );

				// Deliver successful result to configured channels.
				self::deliver_result( $schedule_id, $schedule, true, '', $action_log );
			}

			// Webhook callback: POST run results to the external callback URL if configured.
			$callback_url = isset( $schedule['callback_url'] ) ? $schedule['callback_url'] : '';
			if ( '' !== $callback_url ) {
				self::fire_webhook_callback( $callback_url, $schedule_id, $schedule, $success, $duration, $error_msg, $action_log );
			}

			return $success;
		}

		/**
		 * Execute a workflow schedule: runs each tool step in sequence.
		 *
		 * Each step's result is available to subsequent steps via a shared context
		 * array under the `previous_results` key. If any step returns a WP_Error,
		 * execution stops and the error is returned.
		 *
		 * @param array  $schedule    Schedule record.
		 * @param string $schedule_id Schedule ID (for context).
		 * @return array|WP_Error Step results keyed by step index on success, WP_Error on failure.
		 */
		protected static function dispatch_workflow( array $schedule, $schedule_id ) {
			$steps = isset( $schedule['workflow_steps'] ) ? $schedule['workflow_steps'] : array();

			if ( empty( $steps ) ) {
				return new WP_Error( 'no_workflow_steps', __( 'No workflow steps defined.', 'mcp-ai-wpoos-pro' ) );
			}

			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return new WP_Error( 'no_registry', __( 'Tool registry is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$registry         = WP_MCP_AI_Tool_Registry::get_instance();
			$previous_results = array();

			$context = array(
				'schedule_id'   => $schedule_id,
				'schedule_name' => $schedule['name'],
				'source'        => 'pro_schedule_manager',
				'user_id'       => isset( $schedule['created_by'] ) ? (int) $schedule['created_by'] : 0,
			);

			foreach ( $steps as $step_index => $step ) {
				$tool_slug = isset( $step['tool_slug'] ) ? (string) $step['tool_slug'] : '';
				$arguments = isset( $step['arguments'] ) && is_array( $step['arguments'] ) ? $step['arguments'] : array();
				$label     = isset( $step['label'] ) && $step['label'] ? $step['label'] : $tool_slug;

				if ( '' === $tool_slug ) {
					return new WP_Error(
						'empty_tool_slug',
						/* translators: %d: step number */
						sprintf( __( 'Workflow step %d has no tool_slug.', 'mcp-ai-wpoos-pro' ), $step_index + 1 )
					);
				}

				$step_context                     = $context;
				$step_context['workflow_step']    = $step_index;
				$step_context['previous_results'] = $previous_results;

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'info',
						sprintf( 'Workflow step %d starting: %s', $step_index + 1, $label ),
						array( 'schedule_id' => $schedule_id )
					);
				}

				$step_start = microtime( true );
				$result     = $registry->execute_tool( $tool_slug, $arguments, $step_context );
				$step_dur   = round( microtime( true ) - $step_start, 3 );

				// Log individual tool execution using the dedicated logger method.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_tool_execution(
						$tool_slug,
						$arguments,
						$result,
						array_merge(
							$step_context,
							array(
								'step_label'    => $label,
								'step_duration' => $step_dur,
							)
						)
					);
				}

				if ( is_wp_error( $result ) ) {
					return new WP_Error(
						'workflow_step_failed',
						sprintf(
							/* translators: 1: step number, 2: tool slug, 3: error message */
							__( 'Workflow step %1$d (%2$s) failed: %3$s', 'mcp-ai-wpoos-pro' ),
							$step_index + 1,
							$tool_slug,
							$result->get_error_message()
						)
					);
				}

				$previous_results[ $step_index ] = array(
					'tool_slug' => $tool_slug,
					'label'     => $label,
					'result'    => is_string( $result ) ? wp_trim_words( $result, 80, '…' ) : $result,
					'duration'  => $step_dur,
				);
			}

			/**
			 * Fires after all workflow steps complete successfully.
			 *
			 * @param string $schedule_id      Schedule ID.
			 * @param array  $schedule         Schedule record.
			 * @param array  $previous_results All step results keyed by step index.
			 */
			do_action( 'wp_mcp_ai_pro_workflow_completed', $schedule_id, $schedule, $previous_results );

			return $previous_results;
		}

		/**
		 * Default maximum agentic loop iterations for scheduled assistant runs.
		 *
		 * Matches the Telegram auto-reply default so tool-calling assistants can
		 * complete multi-step workflows (search → analyse → respond, etc.).
		 */
		const DEFAULT_MAX_AGENTIC_ITERATIONS = 10;

		/**
		 * Execute an assistant_run schedule.
		 *
		 * Sends the configured message to the assistant via the internal REST chat
		 * endpoint (`/mcp-ai/v1/chat`) and returns the AI response. Falls back to
		 * firing the `wp_mcp_ai_pro_scheduled_assistant_run` action when the REST
		 * infrastructure is unavailable so that custom listeners can still react.
		 *
		 * @param array  $schedule    Schedule record.
		 * @param string $schedule_id Schedule ID.
		 * @return array|WP_Error Result array with assistant response on success, WP_Error on failure.
		 */
		protected static function dispatch_assistant_run( array $schedule, $schedule_id ) {
			$config = isset( $schedule['assistant_config'] ) && is_array( $schedule['assistant_config'] )
				? $schedule['assistant_config']
				: array();

			if ( empty( $config['assistant_id'] ) || empty( $config['message'] ) ) {
				self::debug_log( sprintf( '[assistant_run] Invalid config for schedule %s — missing assistant_id or message', $schedule_id ) );
				return new WP_Error( 'invalid_assistant_config', __( 'Assistant run is missing assistant_id or message.', 'mcp-ai-wpoos-pro' ) );
			}

			$assistant_id = (int) $config['assistant_id'];
			$message      = (string) $config['message'];
			$user_id      = isset( $schedule['created_by'] ) ? (int) $schedule['created_by'] : 0;

			self::debug_log(
				sprintf(
					'[assistant_run] schedule=%s assistant_id=%d user_id=%d message="%s"',
					$schedule_id,
					$assistant_id,
					$user_id,
					wp_trim_words( $message, 15, '…' )
				)
			);

			// Switch to the schedule creator so the REST request inherits their capabilities.
			$previous_user = get_current_user_id();
			if ( $user_id > 0 && $user_id !== $previous_user ) {
				wp_set_current_user( $user_id );
				self::debug_log( sprintf( '[assistant_run] Switched user context from %d to %d', $previous_user, $user_id ) );
			}

			$result_log = array(
				'assistant_id' => $assistant_id,
				'message'      => wp_trim_words( $message, 60, '…' ),
			);

			// Build the internal REST request to the chat endpoint.
			if ( function_exists( 'rest_do_request' ) ) {
				self::debug_log( '[assistant_run] Building internal REST request to /mcp-ai/v1/chat' );

				$messages = array(
					array(
						'role'    => 'user',
						'content' => $message,
					),
				);

				$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
				// Nonce ensures the REST permissions_check succeeds for internal requests
				// (same pattern as WP_MCP_AI_Pro_CPT_AI_Integration::send_to_ai).
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				$request->set_body_params(
					array(
						'assistant_id' => $assistant_id,
						'messages'     => $messages,
						'stream'       => false,
						'context'      => array(
							'source'        => 'pro_schedule_manager',
							'schedule_id'   => $schedule_id,
							'schedule_name' => isset( $schedule['name'] ) ? $schedule['name'] : '',
						),
					)
				);

				// Raise the agentic-loop iteration cap so that multi-step tool
				// workflows (search → analyse → respond, etc.) can run to completion.
				// Without this, the /mcp-ai/v1/chat endpoint defaults to 5 iterations
				// and the final content remains null when a second tool round is needed.
				// Same pattern used by the Telegram auto-reply handler.
				//
				// Per-schedule max_agentic_iterations takes highest priority, then the
				// per-assistant config, then the admin setting, then the default (10).
				$schedule_max_iterations = isset( $config['max_agentic_iterations'] ) ? absint( $config['max_agentic_iterations'] ) : 0;
				if ( $schedule_max_iterations > 0 ) {
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter callback signature requires the parameter.
					$schedule_iterations_filter = function ( $default_max ) use ( $schedule_max_iterations ) {
						return $schedule_max_iterations;
					};
					add_filter( 'wp_mcp_ai_max_agentic_iterations', $schedule_iterations_filter, 15 );
				}
				add_filter( 'wp_mcp_ai_max_agentic_iterations', array( __CLASS__, 'get_scheduled_run_max_agentic_iterations' ), 10, 2 );

				// Pre-flight: verify the REST server is initialised so that
				// rest_do_request() does not throw a fatal error on a null server.
				$rest_server = rest_get_server();
				if ( ! $rest_server ) {
					self::debug_log( '[assistant_run] REST server not available — rest_get_server() returned null' );

					// Restore user context before returning.
					if ( $user_id > 0 && $user_id !== $previous_user ) {
						wp_set_current_user( $previous_user );
					}

					// Clean up filters.
					remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( __CLASS__, 'get_scheduled_run_max_agentic_iterations' ), 10 );
					if ( $schedule_max_iterations > 0 ) {
						remove_filter( 'wp_mcp_ai_max_agentic_iterations', $schedule_iterations_filter, 15 );
					}

					$result_log['status']   = 'error';
					$result_log['response'] = __( 'REST API server is not available.', 'mcp-ai-wpoos-pro' );
					return $result_log;
				}

				try {
					$response = rest_do_request( $request );
				} catch ( \Throwable $e ) {
					self::debug_log(
						sprintf(
							'[assistant_run] rest_do_request threw %s: %s in %s:%d',
							get_class( $e ),
							$e->getMessage(),
							str_replace( ABSPATH, '', $e->getFile() ),
							$e->getLine()
						)
					);

					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_error(
							'Pro schedule assistant run: rest_do_request fatal error',
							array(
								'schedule_id'  => $schedule_id,
								'assistant_id' => $assistant_id,
								'error'        => $e->getMessage(),
								'file'         => str_replace( ABSPATH, '', $e->getFile() ),
								'line'         => $e->getLine(),
							)
						);
					}

					// Restore user context before returning.
					if ( $user_id > 0 && $user_id !== $previous_user ) {
						wp_set_current_user( $previous_user );
					}

					// Clean up filters.
					remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( __CLASS__, 'get_scheduled_run_max_agentic_iterations' ), 10 );
					if ( $schedule_max_iterations > 0 ) {
						remove_filter( 'wp_mcp_ai_max_agentic_iterations', $schedule_iterations_filter, 15 );
					}

					$result_log['status']   = 'error';
					$result_log['response'] = sprintf(
						/* translators: %s: error message */
						__( 'Internal REST dispatch error: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					);
					return $result_log;
				}

				remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( __CLASS__, 'get_scheduled_run_max_agentic_iterations' ), 10 );
				if ( $schedule_max_iterations > 0 ) {
					remove_filter( 'wp_mcp_ai_max_agentic_iterations', $schedule_iterations_filter, 15 );
				}

				self::debug_log( sprintf( '[assistant_run] REST response status=%d', $response->get_status() ) );

				// Restore the previous user context.
				if ( $user_id > 0 && $user_id !== $previous_user ) {
					wp_set_current_user( $previous_user );
				}

				if ( $response->is_error() ) {
					$error_data  = $response->get_data();
					$status_code = $response->get_status();
					$error_code  = isset( $error_data['code'] ) ? $error_data['code'] : 'unknown';
					$error_msg   = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Chat API request failed.', 'mcp-ai-wpoos-pro' );

					self::debug_log(
						sprintf(
							'[assistant_run] Chat API error: HTTP %d, code=%s, message=%s',
							$status_code,
							$error_code,
							$error_msg
						)
					);

					$result_log['status']   = 'error';
					$result_log['response'] = sprintf(
						/* translators: 1: HTTP status code, 2: error code, 3: error message */
						__( 'Chat API error (HTTP %1$d, %2$s): %3$s', 'mcp-ai-wpoos-pro' ),
						$status_code,
						$error_code,
						$error_msg
					);
				} else {

					$data = $response->get_data();

					// Extract the assistant reply from the response.
					// Uses the industry-standard two-pass extraction (finish_reason
					// preference + agentic_tool_messages fallback) matching every
					// channel webhook controller (Telegram, Slack, Discord, etc.).
					$reply = self::extract_content_from_chat_response( $data );

					// Capture agentic workflow metadata from the response.
					// When the assistant uses tools (agent workflow), the chat endpoint
					// includes tool_results and agentic_tool_messages in the response
					// data so consumers can inspect what the agent did.
					$llm_data         = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
					$tool_results     = isset( $llm_data['tool_results'] ) && is_array( $llm_data['tool_results'] )
					? $llm_data['tool_results']
					: array();
					$agentic_messages = isset( $llm_data['agentic_tool_messages'] ) && is_array( $llm_data['agentic_tool_messages'] )
					? $llm_data['agentic_tool_messages']
					: array();

					$tool_results_count     = count( $tool_results );
					$agentic_messages_count = count( $agentic_messages );

					self::debug_log(
						sprintf(
							'[assistant_run] Extracted reply (%d chars), tool_results=%d, agentic_messages=%d: %s',
							strlen( (string) $reply ),
							$tool_results_count,
							$agentic_messages_count,
							wp_trim_words( (string) $reply, 20, '…' )
						)
					);

					$result_log['response'] = wp_trim_words( (string) $reply, 120, '…' );
					$result_log['status']   = 'completed';

					// Record agentic workflow details in the action log.
					$result_log['tool_results_count']     = $tool_results_count;
					$result_log['agentic_messages_count'] = $agentic_messages_count;
					$result_log['is_agentic']             = $tool_results_count > 0 || $agentic_messages_count > 0;

					// Store a trimmed summary of tool calls for the action log.
					if ( $tool_results_count > 0 ) {
						$tool_summary = array();
						foreach ( $tool_results as $tr ) {
							if ( ! is_array( $tr ) ) {
								continue;
							}
							$tool_summary[] = array(
								'tool_call_id' => isset( $tr['tool_call_id'] ) ? (string) $tr['tool_call_id'] : '',
								'name'         => isset( $tr['name'] ) ? (string) $tr['name'] : '',
							);
						}
						$result_log['tool_calls'] = $tool_summary;
					}

					// Log the agentic workflow to the Logger service.
					if ( $result_log['is_agentic'] && class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'info',
							sprintf(
								'Scheduled assistant run completed as agentic workflow: %d tool result(s), %d intermediate message(s)',
								$tool_results_count,
								$agentic_messages_count
							),
							array(
								'schedule_id'  => $schedule_id,
								'assistant_id' => $assistant_id,
								'tool_results' => $tool_results_count,
								'agentic_msgs' => $agentic_messages_count,
							)
						);
					}
				}
			} else {
				self::debug_log( '[assistant_run] rest_do_request() not available — falling back to action hook' );

				// Restore the previous user context before falling back.
				if ( $user_id > 0 && $user_id !== $previous_user ) {
					wp_set_current_user( $previous_user );
				}

				$result_log['status'] = 'delegated';
			}

			/**
			 * Fires after a scheduled assistant run has been dispatched.
			 *
			 * Integrators can hook here to perform post-processing on the assistant
			 * response (e.g. forwarding the reply to a channel or storing results).
			 *
			 * @param int    $assistant_id Assistant post ID.
			 * @param string $message      Message that was sent.
			 * @param array  $context      Context including schedule_id, schedule_name, response,
			 *                              user_id, and agentic workflow data (tool_results_count,
			 *                              agentic_messages_count, is_agentic, tool_calls).
			 */
			do_action(
				'wp_mcp_ai_pro_scheduled_assistant_run',
				$assistant_id,
				$message,
				array(
					'schedule_id'            => $schedule_id,
					'schedule_name'          => isset( $schedule['name'] ) ? $schedule['name'] : '',
					'context'                => isset( $config['context'] ) ? $config['context'] : array(),
					'user_id'                => $user_id,
					'response'               => isset( $result_log['response'] ) ? $result_log['response'] : '',
					'tool_results_count'     => isset( $result_log['tool_results_count'] ) ? $result_log['tool_results_count'] : 0,
					'agentic_messages_count' => isset( $result_log['agentic_messages_count'] ) ? $result_log['agentic_messages_count'] : 0,
					'is_agentic'             => isset( $result_log['is_agentic'] ) ? $result_log['is_agentic'] : false,
					'tool_calls'             => isset( $result_log['tool_calls'] ) ? $result_log['tool_calls'] : array(),
				)
			);

			return $result_log;
		}

		/**
		 * Filter callback: raise the agentic iteration cap for scheduled assistant runs.
		 *
		 * Priority order:
		 * 1. Per-assistant config (highest priority).
		 * 2. Admin setting (filter_max_agentic_iterations) applied by an earlier filter.
		 * 3. Schedule manager default (self::DEFAULT_MAX_AGENTIC_ITERATIONS).
		 *
		 * @param int   $default_max      Current maximum (may include admin setting).
		 * @param array $assistant_config Assistant configuration array.
		 * @return int Maximum iterations to allow.
		 */
		public static function get_scheduled_run_max_agentic_iterations( $default_max, $assistant_config = array() ) {
			// Per-assistant override takes highest priority.
			if ( ! empty( $assistant_config['max_agentic_iterations'] ) ) {
				return absint( $assistant_config['max_agentic_iterations'] );
			}

			// If an admin setting or earlier filter has already raised the cap above
			// the hard-coded /chat endpoint base of 5, honour that value.
			if ( $default_max > 5 ) {
				return $default_max;
			}

			return self::DEFAULT_MAX_AGENTIC_ITERATIONS;
		}

		/**
		 * Resolve an LLM message content value to a plain-text string.
		 *
		 * Handles both plain string content (OpenAI/Anthropic) and array-segment
		 * content (Gemini/Ollama). This matches the resolve_content_to_string()
		 * pattern used by the channel webhook controllers (Telegram, Slack, etc.).
		 *
		 * @param mixed $content Raw value of message['content'] from the chat response.
		 * @return string Plain-text string, or empty string when no text can be extracted.
		 */
		public static function resolve_content_to_string( $content ) {
			if ( is_string( $content ) ) {
				return trim( $content );
			}

			if ( ! is_array( $content ) ) {
				return '';
			}

			// Array of content segments (Gemini / Ollama normalised format).
			$parts = array();
			foreach ( $content as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? (string) $segment['type'] : '';

				if ( 'text' === $type && isset( $segment['text'] ) && is_string( $segment['text'] ) ) {
					$text = trim( $segment['text'] );
					if ( '' !== $text ) {
						$parts[] = $text;
					}
				}
			}

			return implode( "\n", $parts );
		}

		/**
		 * Extract the assistant reply text from a /mcp-ai/v1/chat REST response.
		 *
		 * Industry-standard agentic workflow extraction: uses a two-pass algorithm
		 * matching the pattern used by every channel webhook controller (Telegram,
		 * WhatsApp, Slack, Discord, Google Chat, Teams, Twitter, etc.):
		 *
		 * 1. **Pass 1 — Choices scan**: Iterates all choices, preferring those with
		 *    `finish_reason = 'stop'` (the definitive final answer) over choices with
		 *    `finish_reason = 'tool_calls'` (intermediate agentic steps). Handles both
		 *    plain string content (OpenAI/Anthropic) and array-segment content
		 *    (Gemini/Ollama) via {@see resolve_content_to_string()}.
		 *
		 * 2. **Pass 2 — Agentic fallback**: When all choices have null/empty content
		 *    (e.g. the agentic loop exhausted its iteration cap before the model
		 *    produced a final text reply), falls back to `agentic_tool_messages` —
		 *    intermediate assistant messages attached to the response by the chat
		 *    service — and returns the last one with non-empty text.
		 *
		 * This ensures scheduled assistant runs that perform agent workflows (tool
		 * calling with no final text response) still capture meaningful output.
		 *
		 * @param mixed $response_data Data returned by WP_REST_Response::get_data().
		 * @return string Assistant reply text, or empty string if not found.
		 */
		public static function extract_content_from_chat_response( $response_data ) {
			if ( ! is_array( $response_data ) ) {
				return '';
			}

			// Normalise: the endpoint wraps the raw LLM response under 'data'.
			$llm_data = isset( $response_data['data'] ) && is_array( $response_data['data'] )
				? $response_data['data']
				: $response_data;

			$choices = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] )
				? $llm_data['choices']
				: array();

			// --- Pass 1: scan every choice for a non-empty content value.
			// Prefer choices whose finish_reason is 'stop' over 'tool_calls'.
			$best_content = '';
			foreach ( $choices as $choice ) {
				$msg     = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
				$content = isset( $msg['content'] ) ? self::resolve_content_to_string( $msg['content'] ) : '';

				if ( '' === $content ) {
					continue;
				}

				$finish = isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '';

				// A 'stop' finish is the definitive final answer — return immediately.
				if ( 'stop' === $finish ) {
					return $content;
				}

				// Keep as a candidate in case no 'stop' choice is found.
				if ( '' === $best_content ) {
					$best_content = $content;
				}
			}

			if ( '' !== $best_content ) {
				return $best_content;
			}

			// --- Pass 2: fall back to agentic_tool_messages.
			// When all choices have null/empty content (e.g. the agentic loop exhausted
			// its iteration cap before the model produced a final text reply), the chat
			// service attaches intermediate assistant messages to the response under
			// `agentic_tool_messages`. Return the last one that contains text so the
			// schedule log at least captures the most recent partial answer.
			$agentic_messages = isset( $llm_data['agentic_tool_messages'] ) && is_array( $llm_data['agentic_tool_messages'] )
				? $llm_data['agentic_tool_messages']
				: array();

			foreach ( array_reverse( $agentic_messages ) as $msg ) {
				if ( ! is_array( $msg ) ) {
					continue;
				}
				$content = isset( $msg['content'] ) ? self::resolve_content_to_string( $msg['content'] ) : '';
				if ( '' !== $content ) {
					return $content;
				}
			}

			return '';
		}

		/**
		 * Execute a channel_broadcast schedule.
		 *
		 * Calls the unified_channel_broadcast tool (when the Chat Channels Toolkit is
		 * active) to deliver the configured message to one or more chat platforms.
		 * Falls back to firing the `wp_mcp_ai_pro_channel_broadcast` action so that
		 * integrators can handle the broadcast themselves when the tool is not loaded.
		 *
		 * @param array  $schedule    Schedule record.
		 * @param string $schedule_id Schedule ID.
		 * @return array|WP_Error Broadcast summary array on success, WP_Error on failure.
		 */
		protected static function dispatch_channel_broadcast( array $schedule, $schedule_id ) {
			$config = isset( $schedule['broadcast_config'] ) && is_array( $schedule['broadcast_config'] )
				? $schedule['broadcast_config']
				: array();

			if ( empty( $config['message'] ) || empty( $config['channels'] ) || empty( $config['credentials'] ) ) {
				return new WP_Error( 'invalid_broadcast_config', __( 'Channel broadcast is missing message, channels, or credentials.', 'mcp-ai-wpoos-pro' ) );
			}

			$context = array(
				'schedule_id'   => $schedule_id,
				'schedule_name' => $schedule['name'],
				'source'        => 'pro_schedule_manager',
				'user_id'       => isset( $schedule['created_by'] ) ? (int) $schedule['created_by'] : 0,
			);

			$broadcast_log = array(
				'channels' => (array) $config['channels'],
				'message'  => wp_trim_words( (string) $config['message'], 60, '…' ),
			);

			// Attempt to use the registered unified_channel_broadcast tool when available.
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'unified_channel_broadcast' );
				if ( $tool ) {
					$result = $tool->execute(
						array(
							'message'     => $config['message'],
							'channels'    => $config['channels'],
							'credentials' => $config['credentials'],
						),
						$context
					);

					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_tool_execution(
							'unified_channel_broadcast',
							array(
								'message'  => $config['message'],
								'channels' => $config['channels'],
							),
							$result,
							$context
						);
					}

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					// Partial failure: if all channels failed, treat as error.
					if ( is_array( $result ) && isset( $result['summary'] ) ) {
						$summary = $result['summary'];
						if ( 0 === (int) $summary['successful_channels'] ) {
							return new WP_Error(
								'broadcast_all_failed',
								sprintf(
									/* translators: %d: number of failed channels */
									__( 'Channel broadcast failed on all %d channel(s).', 'mcp-ai-wpoos-pro' ),
									(int) $summary['total_channels']
								)
							);
						}
						$broadcast_log['summary'] = $summary;
					}

					return $broadcast_log;
				}
			}

			/**
			 * Fires when a scheduled channel broadcast is dispatched but the
			 * unified_channel_broadcast tool is not available (e.g. Chat Channels
			 * Toolkit is disabled). Integrators can hook here to handle the send.
			 *
			 * @param string $message     Message text to broadcast.
			 * @param array  $channels    Channel slugs to broadcast to.
			 * @param array  $credentials Channel credentials keyed by channel slug.
			 * @param array  $context     Schedule context (schedule_id, schedule_name, user_id).
			 */
			do_action(
				'wp_mcp_ai_pro_channel_broadcast',
				(string) $config['message'],
				(array) $config['channels'],
				(array) $config['credentials'],
				$context
			);

			return $broadcast_log;
		}

		/**
		 * Execute a workflow_builder schedule: loads a saved Pro Workflow Builder
		 * workflow by ID and runs its node DAG server-side.
		 *
		 * Walks the workflow graph from trigger nodes to leaf nodes, executing
		 * action, tool, and agent nodes through the Tool Registry and filters.
		 * Each completed node's result is available to downstream nodes via a
		 * shared context map keyed by node ID.
		 *
		 * @param array  $schedule    Schedule record.
		 * @param string $schedule_id Schedule ID.
		 * @return array|WP_Error Node results on success, WP_Error on failure.
		 */
		protected static function dispatch_workflow_builder( array $schedule, $schedule_id ) {
			$workflow_builder_id = isset( $schedule['workflow_builder_id'] ) ? (string) $schedule['workflow_builder_id'] : '';

			if ( '' === $workflow_builder_id ) {
				return new WP_Error( 'missing_workflow_builder_id', __( 'No workflow_builder_id configured.', 'mcp-ai-wpoos-pro' ) );
			}

			self::debug_log( sprintf( '[workflow_builder] Loading workflow %s for schedule %s', $workflow_builder_id, $schedule_id ) );

			$saved_workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
			if ( ! is_array( $saved_workflows ) || ! isset( $saved_workflows[ $workflow_builder_id ] ) ) {
				self::debug_log( sprintf( '[workflow_builder] Workflow %s not found in option store', $workflow_builder_id ) );
				return new WP_Error(
					'workflow_builder_not_found',
					/* translators: %s: workflow builder ID */
					sprintf( __( 'Workflow Builder workflow "%s" not found.', 'mcp-ai-wpoos-pro' ), $workflow_builder_id )
				);
			}

			$workflow = $saved_workflows[ $workflow_builder_id ];
			$nodes    = isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : array();
			$edges    = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

			if ( empty( $nodes ) ) {
				return new WP_Error( 'empty_workflow', __( 'Workflow has no nodes to execute.', 'mcp-ai-wpoos-pro' ) );
			}

			self::debug_log( sprintf( '[workflow_builder] Workflow "%s" has %d nodes, %d edges', $workflow_builder_id, count( $nodes ), count( $edges ) ) );

			// Build adjacency list from edges: source -> list of target node IDs.
			$adjacency      = array();
			$incoming_count = array();
			$nodes_by_id    = array();

			foreach ( $nodes as $node ) {
				$nid = isset( $node['id'] ) ? (string) $node['id'] : '';
				if ( '' === $nid ) {
					continue;
				}
				$nodes_by_id[ $nid ]    = $node;
				$adjacency[ $nid ]      = array();
				$incoming_count[ $nid ] = 0;
			}

			foreach ( $edges as $edge ) {
				$src = isset( $edge['source'] ) ? (string) $edge['source'] : '';
				$tgt = isset( $edge['target'] ) ? (string) $edge['target'] : '';
				if ( '' !== $src && '' !== $tgt && isset( $nodes_by_id[ $src ] ) && isset( $nodes_by_id[ $tgt ] ) ) {
					$adjacency[ $src ][] = $tgt;
					++$incoming_count[ $tgt ];
				}
			}

			// Topological sort (Kahn's algorithm) to determine execution order.
			$queue = array();
			foreach ( $incoming_count as $nid => $count ) {
				if ( 0 === $count ) {
					$queue[] = $nid;
				}
			}

			$execution_order = array();
			while ( ! empty( $queue ) ) {
				$current           = array_shift( $queue );
				$execution_order[] = $current;

				foreach ( $adjacency[ $current ] as $neighbor ) {
					--$incoming_count[ $neighbor ];
					if ( 0 === $incoming_count[ $neighbor ] ) {
						$queue[] = $neighbor;
					}
				}
			}

			// If we didn't visit all nodes, there's a cycle.
			if ( count( $execution_order ) !== count( $nodes_by_id ) ) {
				return new WP_Error( 'workflow_cycle', __( 'Workflow contains a cycle and cannot be executed.', 'mcp-ai-wpoos-pro' ) );
			}

			// Execute nodes in topological order, collecting results.
			$context      = array(
				'schedule_id'         => $schedule_id,
				'schedule_name'       => $schedule['name'],
				'workflow_builder_id' => $workflow_builder_id,
				'source'              => 'pro_schedule_manager',
				'user_id'             => isset( $schedule['created_by'] ) ? (int) $schedule['created_by'] : 0,
			);
			$node_results = array();
			$registry     = class_exists( 'WP_MCP_AI_Tool_Registry' ) ? WP_MCP_AI_Tool_Registry::get_instance() : null;

			foreach ( $execution_order as $node_id ) {
				$node      = $nodes_by_id[ $node_id ];
				$node_type = isset( $node['type'] ) ? (string) $node['type'] : '';
				$node_data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
				$config    = isset( $node_data['config'] ) && is_array( $node_data['config'] ) ? $node_data['config'] : array();
				$label     = isset( $node_data['label'] ) ? (string) $node_data['label'] : $node_id;

				self::debug_log( sprintf( '[workflow_builder] Executing node %s (type=%s, label=%s)', $node_id, $node_type, $label ) );

				$step_start = microtime( true );
				$result     = null;

				switch ( $node_type ) {
					case 'trigger':
						// Trigger nodes simply start the workflow — no execution needed.
						$result = array(
							'type'   => 'trigger',
							'status' => 'completed',
						);
						break;

					case 'tool':
						if ( ! $registry ) {
							$result = new WP_Error( 'no_registry', __( 'Tool registry not available.', 'mcp-ai-wpoos-pro' ) );
							break;
						}
						$tool_name = isset( $config['tool_name'] ) ? (string) $config['tool_name'] : '';
						$arguments = isset( $config['arguments'] ) && is_array( $config['arguments'] ) ? $config['arguments'] : array();

						if ( '' === $tool_name ) {
							/* translators: %s: node label */
							$result = new WP_Error( 'missing_tool', sprintf( __( 'Tool node "%s" has no tool_name.', 'mcp-ai-wpoos-pro' ), $label ) );
							break;
						}

						$tool = $registry->get_tool( $tool_name );
						if ( ! $tool ) {
							/* translators: %s: tool name */
							$result = new WP_Error( 'tool_not_found', sprintf( __( 'Tool "%s" not found.', 'mcp-ai-wpoos-pro' ), $tool_name ) );
							break;
						}

						$step_context                 = $context;
						$step_context['node_id']      = $node_id;
						$step_context['node_results'] = $node_results;

						$result = $tool->execute( $arguments, $step_context );
						break;

					case 'action':
						$command = isset( $config['command'] ) ? (string) $config['command'] : '';
						$params  = isset( $config['params'] ) && is_array( $config['params'] ) ? $config['params'] : array();

						$action_result = apply_filters( 'wp_mcp_ai_workflow_execute_action', null, $command, $params, array_merge( $context, array( 'node_results' => $node_results ) ) );
						$result        = null !== $action_result ? $action_result : array(
							'type'    => 'action',
							'command' => $command,
							'status'  => 'completed',
						);
						break;

					case 'agent':
						$prompt   = isset( $config['prompt'] ) ? (string) $config['prompt'] : '';
						$agent_id = isset( $config['agent_id'] ) ? $config['agent_id'] : 'default';

						$agent_result = apply_filters( 'wp_mcp_ai_workflow_execute_agent', null, $agent_id, $prompt, array_merge( $context, array( 'node_results' => $node_results ) ) );
						$result       = null !== $agent_result ? $agent_result : array(
							'type'   => 'agent',
							'prompt' => wp_trim_words( $prompt, 20, '…' ),
							'status' => 'completed',
						);
						break;

					case 'condition':
					case 'delay':
					case 'parallel':
					case 'merge':
					case 'loop':
					case 'approval':
						// Control-flow nodes: pass through in server-side linear execution.
						$result = array(
							'type'   => $node_type,
							'status' => 'completed',
						);
						break;

					default:
						$result = array(
							'type'   => $node_type,
							'status' => 'skipped',
						);
						break;
				}

				$step_dur = round( microtime( true ) - $step_start, 3 );

				if ( is_wp_error( $result ) ) {
					self::debug_log( sprintf( '[workflow_builder] Node %s failed: %s', $node_id, $result->get_error_message() ) );
					return new WP_Error(
						'workflow_builder_node_failed',
						sprintf(
							/* translators: 1: node label/ID, 2: error message */
							__( 'Workflow node "%1$s" failed: %2$s', 'mcp-ai-wpoos-pro' ),
							$label,
							$result->get_error_message()
						)
					);
				}

				$node_results[ $node_id ] = array(
					'node_id'  => $node_id,
					'type'     => $node_type,
					'label'    => $label,
					'result'   => $result,
					'duration' => $step_dur,
				);
			}

			self::debug_log( sprintf( '[workflow_builder] Workflow %s completed: %d nodes executed', $workflow_builder_id, count( $node_results ) ) );

			/**
			 * Fires after all workflow builder nodes complete successfully.
			 *
			 * @param string $schedule_id        Schedule ID.
			 * @param array  $schedule           Schedule record.
			 * @param string $workflow_builder_id Workflow Builder workflow ID.
			 * @param array  $node_results       All node results keyed by node ID.
			 */
			do_action( 'wp_mcp_ai_pro_workflow_builder_completed', $schedule_id, $schedule, $workflow_builder_id, $node_results );

			return $node_results;
		}

		// -------------------------------------------------------------------------
		// Internal helpers
		// -------------------------------------------------------------------------

		/**
		 * Write a diagnostic log message for Schedule Manager troubleshooting.
		 *
		 * Always logs to WP_MCP_AI_Logger (when available) at 'debug' level so
		 * that entries appear in the plugin's activity log UI regardless of the
		 * WP_DEBUG constant. Additionally writes to the PHP error log via
		 * error_log() when WP_DEBUG is enabled, ensuring server-side log files,
		 * WP-CLI output, and Docker stdout also capture the messages.
		 *
		 * @param string $message Human-readable diagnostic message.
		 */
		protected static function debug_log( $message ) {
			$prefixed = 'WP_MCP_AI_Pro_Schedule_Manager: ' . $message;

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'debug', $prefixed );
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging guarded by WP_DEBUG.
				error_log( $prefixed );
			}
		}

		/**
		 * POST schedule run results to an external webhook callback URL.
		 *
		 * The payload follows a common webhook structure used by industry-standard
		 * task schedulers (Airflow, Temporal, AWS Step Functions) so that external
		 * systems can integrate easily.
		 *
		 * @param string $callback_url External URL to POST to.
		 * @param string $schedule_id  Schedule ID.
		 * @param array  $schedule     Schedule record.
		 * @param bool   $success      Whether the run succeeded.
		 * @param float  $duration     Run duration in seconds.
		 * @param string $error_msg    Error message if failed.
		 * @param array  $action_log   Structured action log from the run.
		 */
		protected static function fire_webhook_callback( $callback_url, $schedule_id, array $schedule, $success, $duration, $error_msg, array $action_log ) {
			$payload = array(
				'event'         => $success ? 'schedule.run.success' : 'schedule.run.failure',
				'schedule_id'   => $schedule_id,
				'schedule_name' => isset( $schedule['name'] ) ? $schedule['name'] : '',
				'schedule_type' => isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : 'task',
				'status'        => $success ? 'success' : 'failure',
				'duration'      => $duration,
				'error'         => $error_msg,
				'action_log'    => $action_log,
				'timestamp'     => gmdate( 'c' ),
				'site_url'      => home_url(),
			);

			$body    = wp_json_encode( $payload );
			$headers = array( 'Content-Type' => 'application/json' );

			$secret = isset( $schedule['callback_secret'] ) ? (string) $schedule['callback_secret'] : '';
			if ( '' !== $secret ) {
				$ts                               = (string) time();
				$headers['X-WP-MCP-AI-Timestamp'] = $ts;
				$headers['X-WP-MCP-AI-Signature'] = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );
			}

			$response = wp_remote_post(
				$callback_url,
				array(
					'body'      => $body,
					'headers'   => $headers,
					'timeout'   => 15,
					'blocking'  => false,
					'sslverify' => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				$err_msg = sprintf(
					'Pro schedule webhook callback failed for %s to %s: %s',
					$schedule_id,
					$callback_url,
					$response->get_error_message()
				);

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Pro schedule webhook callback failed',
						array(
							'schedule_id'  => $schedule_id,
							'callback_url' => $callback_url,
							'error'        => $response->get_error_message(),
						)
					);
				} else {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when Logger is unavailable.
					error_log( $err_msg );
				}
			}
		}

		/**
		 * Schedule a WP cron event for the central dispatcher.
		 *
		 * @param string $schedule_id Schedule ID (passed as arg to dispatcher).
		 * @param string $interval    WP cron interval slug, or 'single'.
		 * @param int    $timestamp   Unix timestamp for first execution.
		 * @return true|WP_Error
		 */
		protected static function schedule_wp_cron( $schedule_id, $interval, $timestamp ) {
			$args = array( $schedule_id );

			if ( 'single' === $interval ) {
				$result = wp_schedule_single_event( $timestamp, self::DISPATCH_HOOK, $args );
			} else {
				// Check not already scheduled.
				if ( wp_next_scheduled( self::DISPATCH_HOOK, $args ) ) {
					return true; // Already scheduled, no-op.
				}
				$result = wp_schedule_event( $timestamp, $interval, self::DISPATCH_HOOK, $args );
			}

			if ( false === $result ) {
				return new WP_Error(
					'schedule_failed',
					/* translators: %s: hook name */
					sprintf( __( 'Failed to schedule WP cron event for "%s".', 'mcp-ai-wpoos-pro' ), $schedule_id )
				);
			}

			return true;
		}

		/**
		 * Remove any existing WP cron events for a schedule.
		 *
		 * @param string $schedule_id Schedule ID.
		 */
		protected static function unschedule_wp_cron( $schedule_id ) {
			$args = array( (string) $schedule_id );

			// Clear recurring events.
			wp_clear_scheduled_hook( self::DISPATCH_HOOK, $args );

			// Also remove any pending one-time events.
			$timestamp = wp_next_scheduled( self::DISPATCH_HOOK, $args );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::DISPATCH_HOOK, $args );
			}
		}

		/**
		 * Record a single run result into the history ring buffer.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param bool   $success     Whether the run succeeded.
		 * @param float  $duration    Run duration in seconds.
		 * @param string $error_msg   Error message if failed.
		 * @param array  $action_log  Structured log of the action taken during this run.
		 */
		protected static function record_run( $schedule_id, $success, $duration, $error_msg = '', $action_log = array() ) {
			$history = self::load_history();

			if ( ! isset( $history[ $schedule_id ] ) || ! is_array( $history[ $schedule_id ] ) ) {
				$history[ $schedule_id ] = array();
			}

			$history[ $schedule_id ][] = array(
				'status'     => $success ? 'success' : 'failure',
				'start_time' => time(),
				'duration'   => $duration,
				'error'      => $error_msg,
				'action_log' => is_array( $action_log ) ? $action_log : array(),
			);

			// Trim to ring buffer limit.
			if ( count( $history[ $schedule_id ] ) > self::MAX_HISTORY_PER_SCHEDULE ) {
				$history[ $schedule_id ] = array_slice( $history[ $schedule_id ], - self::MAX_HISTORY_PER_SCHEDULE );
			}

			self::save_history( $history );

			// Persist to JetEngine Execution History CCT when available.
			self::maybe_persist_cct_history( $schedule_id, $success, $duration, $error_msg );

			// Update the schedule record with last run meta.
			$schedules = self::load_schedules();
			if ( isset( $schedules[ $schedule_id ] ) ) {
				$schedules[ $schedule_id ]['last_run_status']   = $success ? 'success' : 'failure';
				$schedules[ $schedule_id ]['last_run_time']     = time();
				$schedules[ $schedule_id ]['last_run_duration'] = $duration;
				$schedules[ $schedule_id ]['last_error']        = $success ? '' : $error_msg;
				$schedules[ $schedule_id ]['run_count']         = ( (int) $schedules[ $schedule_id ]['run_count'] ) + 1;
				self::save_schedules( $schedules );
			}

			// Build and persist a structured result envelope when capture is enabled.
			$schedule = isset( $schedules[ $schedule_id ] ) ? $schedules[ $schedule_id ] : self::get_schedule( $schedule_id );
			if ( is_array( $schedule ) ) {
				$capture = isset( $schedule['display']['result_capture'] ) ? $schedule['display']['result_capture'] : 'summary';
				if ( 'disabled' !== $capture ) {
					$log_for_envelope     = 'summary' === $capture
						? self::trim_action_log_for_summary( $action_log )
						: $action_log;
					$envelope             = self::build_result_envelope( $schedule, is_array( $log_for_envelope ) ? $log_for_envelope : array(), (bool) $success, (string) $error_msg );
					$envelope['duration'] = (float) $duration;
					self::store_result_envelope( $schedule_id, $envelope, $schedule );
				}
			}
		}

		/**
		 * Trim an action log down to the "summary" capture level.
		 *
		 * Keeps high-signal fields (status, hook, response excerpt) while dropping
		 * verbose nested structures so the stored envelope stays small for the
		 * `summary` capture mode.
		 *
		 * @since 1.0.0
		 *
		 * @param array $action_log Raw action log.
		 * @return array Trimmed action log.
		 */
		protected static function trim_action_log_for_summary( array $action_log ) {
			$trimmed = array();
			if ( isset( $action_log['type'] ) ) {
				$trimmed['type'] = $action_log['type'];
			}
			if ( isset( $action_log['hook'] ) ) {
				$trimmed['hook'] = $action_log['hook'];
			}
			if ( isset( $action_log['assistant']['response'] ) ) {
				$trimmed['assistant'] = array(
					'response'     => wp_trim_words( (string) $action_log['assistant']['response'], 80, '…' ),
					'assistant_id' => isset( $action_log['assistant']['assistant_id'] ) ? (int) $action_log['assistant']['assistant_id'] : 0,
					'is_agentic'   => ! empty( $action_log['assistant']['is_agentic'] ),
				);
			}
			if ( isset( $action_log['steps'] ) && is_array( $action_log['steps'] ) ) {
				$trimmed['steps'] = array();
				foreach ( $action_log['steps'] as $idx => $step ) {
					$trimmed['steps'][ $idx ] = array(
						'tool_slug' => isset( $step['tool_slug'] ) ? $step['tool_slug'] : '',
						'label'     => isset( $step['label'] ) ? $step['label'] : '',
						'duration'  => isset( $step['duration'] ) ? $step['duration'] : 0,
					);
				}
			}
			if ( isset( $action_log['broadcast'] ) && is_array( $action_log['broadcast'] ) ) {
				$trimmed['broadcast'] = $action_log['broadcast'];
			}
			return $trimmed;
		}

		/**
		 * Handle a failed schedule execution: retry or notify.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param array  $schedule    Schedule record.
		 * @param string $error_msg   Error message.
		 */
		protected static function handle_failure( $schedule_id, array $schedule, $error_msg ) {
			$max_retries = (int) $schedule['max_retries'];
			$retry_count = (int) $schedule['retry_count'];
			$retry_delay = (int) $schedule['retry_delay'];

			// Update status to failure.
			$schedules                                    = self::load_schedules();
			$schedules[ $schedule_id ]['last_run_status'] = 'failure';

			if ( $max_retries > 0 && $retry_count < $max_retries ) {
				// Schedule a retry.
				$schedules[ $schedule_id ]['retry_count'] = $retry_count + 1;
				self::save_schedules( $schedules );

				$retry_at = time() + $retry_delay;
				self::schedule_wp_cron( $schedule_id, 'single', $retry_at );

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'schedule_run',
						sprintf(
							'Pro schedule retry %d/%d scheduled: %s',
							$retry_count + 1,
							$max_retries,
							$schedule['name']
						),
						array(
							'event'       => 'schedule_retry',
							'schedule_id' => $schedule_id,
							'retry_at'    => $retry_at,
						)
					);
				}
			} else {
				// Max retries reached or no retries configured.
				$schedules[ $schedule_id ]['retry_count'] = 0;
				self::save_schedules( $schedules );

				// Delegate to the Result Delivery Service (handles both legacy notify_* fields
				// and new result_delivery config).
				self::deliver_result( $schedule_id, $schedule, false, $error_msg, array() );
			}
		}

		/**
		 * Send an admin failure notification email.
		 *
		 * Prefers the pro Nodemailer service for HTML email when available;
		 * falls back to plain-text wp_mail().
		 *
		 * @param array  $schedule  Schedule record.
		 * @param string $error_msg Error message.
		 */
		protected static function send_failure_notification( array $schedule, $error_msg ) {
			$to      = $schedule['notify_email'];
			$subject = sprintf(
				/* translators: 1: site name, 2: schedule name */
				__( '[%1$s] Scheduled Task Failed: %2$s', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' ),
				$schedule['name']
			);

			$manage_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration' );
			$type_label = isset( $schedule['schedule_type'] ) ? ucfirst( str_replace( '_', ' ', $schedule['schedule_type'] ) ) : 'Task';

			// Build plain-text body (always used as fallback).
			/* translators: %s: schedule name */
			$plain  = sprintf( __( 'The scheduled task "%s" has failed.', 'mcp-ai-wpoos-pro' ), $schedule['name'] );
			$plain .= "\n\n";
			/* translators: %s: schedule type label */
			$plain .= sprintf( __( 'Type: %s', 'mcp-ai-wpoos-pro' ), $type_label );
			$plain .= "\n";
			/* translators: %s: error message */
			$plain .= sprintf( __( 'Error: %s', 'mcp-ai-wpoos-pro' ), $error_msg );
			$plain .= "\n\n";
			/* translators: %s: manage schedules URL */
			$plain .= sprintf( __( 'Manage schedules: %s', 'mcp-ai-wpoos-pro' ), $manage_url );

			// ── MJML: compile a responsive HTML email template ───────────────────
			$html = null;
			if ( class_exists( 'WP_MCP_AI_MJML_Service' ) ) {
				$mjml_service = new WP_MCP_AI_MJML_Service();
				if ( $mjml_service->is_available() ) {
					$desc_row = '';
					if ( ! empty( $schedule['description'] ) ) {
						$desc_row = '<mj-section padding="0 24px"><mj-column><mj-text font-size="14px" color="#555">'
							. '<strong>' . esc_html__( 'Description', 'mcp-ai-wpoos-pro' ) . ':</strong> '
							. esc_html( $schedule['description'] )
							. '</mj-text></mj-column></mj-section>';
					}

					$mjml_src  = '<mjml><mj-head>';
					$mjml_src .= '<mj-attributes><mj-all font-family="Arial, sans-serif" /></mj-attributes>';
					$mjml_src .= '</mj-head><mj-body background-color="#f4f4f4">';

					// Header.
					$mjml_src .= '<mj-section background-color="#cc1818" padding="20px 24px">';
					$mjml_src .= '<mj-column><mj-text font-size="20px" color="#ffffff" font-weight="bold">';
					/* translators: %s: schedule name */
					$mjml_src .= esc_html( sprintf( __( '⚠ %s — Failed', 'mcp-ai-wpoos-pro' ), $schedule['name'] ) );
					$mjml_src .= '</mj-text></mj-column></mj-section>';

					// Details table.
					$mjml_src .= '<mj-section background-color="#ffffff" padding="20px 24px">';
					$mjml_src .= '<mj-column>';
					$mjml_src .= '<mj-table font-size="14px" cell-padding="6px 10px">';
					$mjml_src .= '<tr style="background:#f9f9f9"><td><strong>' . esc_html__( 'Site', 'mcp-ai-wpoos-pro' ) . '</strong></td><td>' . esc_html( get_bloginfo( 'name' ) ) . '</td></tr>';
					$mjml_src .= '<tr><td><strong>' . esc_html__( 'Type', 'mcp-ai-wpoos-pro' ) . '</strong></td><td>' . esc_html( $type_label ) . '</td></tr>';
					$mjml_src .= '<tr style="background:#f9f9f9;color:#cc1818"><td><strong>' . esc_html__( 'Error', 'mcp-ai-wpoos-pro' ) . '</strong></td><td>' . esc_html( $error_msg ) . '</td></tr>';
					$mjml_src .= '</mj-table>';
					$mjml_src .= '</mj-column></mj-section>';

					$mjml_src .= $desc_row;

					// CTA button.
					$mjml_src .= '<mj-section background-color="#ffffff" padding="0 24px 20px">';
					$mjml_src .= '<mj-column>';
					$mjml_src .= '<mj-button background-color="#2271b1" color="#ffffff" href="' . esc_url( $manage_url ) . '">';
					$mjml_src .= esc_html__( 'Manage Schedules', 'mcp-ai-wpoos-pro' );
					$mjml_src .= '</mj-button></mj-column></mj-section>';

					$mjml_src .= '</mj-body></mjml>';

					$compiled = $mjml_service->compile( $mjml_src, array( 'minify' => true ) );
					if ( ! is_wp_error( $compiled ) && ! empty( $compiled ) ) {
						$html = $compiled;
					}
				}
			}

			// ── Fallback HTML (no MJML) ──────────────────────────────────────────
			if ( null === $html ) {
				$html  = '<html><body style="font-family:sans-serif;color:#333">';
				$html .= '<h2 style="color:#cc1818">' . esc_html( $schedule['name'] ) . ' — Failed</h2>';
				$html .= '<table cellpadding="6" style="border-collapse:collapse;width:100%">';
				$html .= '<tr><td><strong>' . esc_html__( 'Site', 'mcp-ai-wpoos-pro' ) . '</strong></td><td>' . esc_html( get_bloginfo( 'name' ) ) . '</td></tr>';
				$html .= '<tr><td><strong>' . esc_html__( 'Type', 'mcp-ai-wpoos-pro' ) . '</strong></td><td>' . esc_html( $type_label ) . '</td></tr>';
				if ( ! empty( $schedule['description'] ) ) {
					$html .= '<tr><td><strong>' . esc_html__( 'Description', 'mcp-ai-wpoos-pro' ) . '</strong></td><td>' . esc_html( $schedule['description'] ) . '</td></tr>';
				}
				$html .= '<tr><td><strong>' . esc_html__( 'Error', 'mcp-ai-wpoos-pro' ) . '</strong></td><td style="color:#cc1818">' . esc_html( $error_msg ) . '</td></tr>';
				$html .= '</table>';
				$html .= '<p><a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Manage Schedules', 'mcp-ai-wpoos-pro' ) . '</a></p>';
				$html .= '</body></html>';
			}

			// ── Nodemailer (HTML + plain-text) ───────────────────────────────────
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
						return;
					}

					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'warning',
							'Nodemailer failed for schedule notification, falling back to wp_mail.',
							array( 'error' => $result->get_error_message() )
						);
					}
				}
			}

			// ── Final fallback: wp_mail with HTML headers ────────────────────────
			wp_mail(
				$to,
				$subject,
				$html,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}

		/**
		 * Send a failure notification to one or more chat channels via
		 * the unified_channel_broadcast tool.
		 *
		 * The schedule must have a `notify_channels` array of slugs (e.g. ['telegram','slack'])
		 * and a `notify_channel_credentials` map of credentials keyed by channel slug.
		 *
		 * @param array  $schedule  Schedule record.
		 * @param string $error_msg Error message.
		 */
		protected static function send_channel_failure_notification( array $schedule, $error_msg ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return;
			}

			$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'unified_channel_broadcast' );
			if ( ! $tool ) {
				return;
			}

			$channels    = $schedule['notify_channels'];
			$credentials = isset( $schedule['notify_channel_credentials'] ) && is_array( $schedule['notify_channel_credentials'] )
				? $schedule['notify_channel_credentials']
				: array();

			if ( empty( $credentials ) ) {
				return;
			}

			$message = sprintf(
				/* translators: 1: site name, 2: schedule name, 3: error message */
				__( '\u26a0\ufe0f [%1$s] Scheduled Task Failed: *%2$s*\nError: %3$s', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' ),
				$schedule['name'],
				$error_msg
			);

			$tool->execute(
				array(
					'message'     => $message,
					'channels'    => $channels,
					'credentials' => $credentials,
				),
				array( 'source' => 'pro_schedule_manager_notification' )
			);
		}

		/**
		 * Persist a run record to the JetEngine Execution History CCT when available.
		 *
		 * Uses the same CCT as the Ralph orchestration layer so that schedule runs
		 * appear in the execution history JetEngine listing alongside autonomous sessions.
		 *
		 * @param string $schedule_id Schedule ID (used as session_id in CCT).
		 * @param bool   $success     Whether the run succeeded.
		 * @param float  $duration    Duration in seconds.
		 * @param string $error_msg   Error message on failure.
		 */
		protected static function maybe_persist_cct_history( $schedule_id, $success, $duration, $error_msg ) {
			if ( ! class_exists( 'WP_MCP_AI_Execution_History_CCT' ) ) {
				return;
			}

			// Rely on the JetEngine CCT module being available.
			if ( ! function_exists( 'jet_engine' ) ) {
				return;
			}

			$engine = jet_engine();
			if ( empty( $engine->cct ) || empty( $engine->cct->manager ) ) {
				return;
			}

			$item_data = array(
				'cct_slug'      => WP_MCP_AI_Execution_History_CCT::SLUG,
				'session_id'    => $schedule_id,
				'tool_name'     => 'pro_schedule_manager',
				'success'       => $success ? '1' : '0',
				'error_message' => (string) $error_msg,
				'duration_ms'   => (int) round( $duration * 1000 ),
				'executed_at'   => current_time( 'mysql' ),
			);

			$engine->cct->manager->insert_item( $item_data );
		}

		/**
		 * Generate a stable ID for a schedule.
		 *
		 * @param string $hook Hook name.
		 * @param array  $args Arguments.
		 * @return string MD5 hash identifier.
		 */
		protected static function generate_id( $hook, array $args ) {
			return md5(
				wp_json_encode(
					array(
						'hook' => $hook,
						'args' => $args,
					)
				)
			);
		}

		/**
		 * Load all schedules from the options table.
		 *
		 * @return array Schedules keyed by ID.
		 */
		protected static function load_schedules() {
			// Try Symfony-backed WP_MCP_AI_Cache_Helper (APCu / Redis / Filesystem).
			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				$cached = WP_MCP_AI_Cache_Helper::get( 'pro_schedules' );
				if ( false !== $cached && is_array( $cached ) ) {
					return $cached;
				}
			}

			$data      = get_option( self::SCHEDULES_OPTION, array() );
			$schedules = is_array( $data ) ? $data : array();

			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				WP_MCP_AI_Cache_Helper::set( 'pro_schedules', $schedules, 300 );
			}

			return $schedules;
		}

		/**
		 * Persist schedules to the options table and invalidate the cache.
		 *
		 * @param array $schedules Schedules array to store.
		 */
		protected static function save_schedules( array $schedules ) {
			$existing = get_option( self::SCHEDULES_OPTION, null );

			if ( null === $existing ) {
				add_option( self::SCHEDULES_OPTION, $schedules, '', 'no' );
			} else {
				update_option( self::SCHEDULES_OPTION, $schedules );
			}

			// Invalidate Symfony-backed cache so the next read re-fetches from DB.
			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
				WP_MCP_AI_Cache_Helper::delete( 'pro_schedules' );
			}
		}

		/**
		 * Load execution history from the options table.
		 *
		 * @return array History keyed by schedule ID.
		 */
		protected static function load_history() {
			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				$cached = WP_MCP_AI_Cache_Helper::get( 'pro_schedule_history' );
				if ( false !== $cached && is_array( $cached ) ) {
					return $cached;
				}
			}

			$data    = get_option( self::HISTORY_OPTION, array() );
			$history = is_array( $data ) ? $data : array();

			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				// Short TTL: history updates every run, so 60 s avoids stale data.
				WP_MCP_AI_Cache_Helper::set( 'pro_schedule_history', $history, 60 );
			}

			return $history;
		}

		/**
		 * Persist execution history to the options table and invalidate the cache.
		 *
		 * @param array $history History array to store.
		 */
		protected static function save_history( array $history ) {
			$existing = get_option( self::HISTORY_OPTION, null );

			if ( null === $existing ) {
				add_option( self::HISTORY_OPTION, $history, '', 'no' );
			} else {
				update_option( self::HISTORY_OPTION, $history );
			}

			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
				WP_MCP_AI_Cache_Helper::delete( 'pro_schedule_history' );
			}
		}

		/**
		 * Get the next scheduled time for a schedule.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @return int|false Next timestamp or false if not scheduled.
		 */
		public static function get_next_run_time( $schedule_id ) {
			return wp_next_scheduled( self::DISPATCH_HOOK, array( (string) $schedule_id ) );
		}

		/**
		 * Project the next N run timestamps for a schedule.
		 *
		 * Combines the next WP-cron event (which only knows about the upcoming
		 * single trigger) with the schedule's registered interval to extrapolate
		 * subsequent runs. For one-shot ("single") schedules, returns at most one
		 * timestamp.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param int    $count       Maximum number of run times to return (default 10).
		 * @return int[] Sorted ascending list of timestamps.
		 */
		public static function get_next_run_times( $schedule_id, $count = 10 ) {
			$count = max( 1, (int) $count );
			$next  = self::get_next_run_time( $schedule_id );
			if ( ! $next ) {
				return array();
			}

			$schedule = self::get_schedule( $schedule_id );
			$cadence  = ( $schedule && isset( $schedule['schedule'] ) ) ? (string) $schedule['schedule'] : 'single';

			if ( 'single' === $cadence ) {
				return array( (int) $next );
			}

			$schedules = wp_get_schedules();
			$interval  = isset( $schedules[ $cadence ]['interval'] ) ? (int) $schedules[ $cadence ]['interval'] : 0;

			if ( $interval <= 0 ) {
				return array( (int) $next );
			}

			$times = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$times[] = (int) $next + ( $i * $interval );
			}
			return $times;
		}

		/**
		 * Unschedule all pro managed cron events (for plugin deactivation).
		 */
		public static function deactivate() {
			$schedules = self::load_schedules();
			foreach ( array_keys( $schedules ) as $schedule_id ) {
				self::unschedule_wp_cron( $schedule_id );
			}
		}

		// -------------------------------------------------------------------------
		// Result envelope (Scheduled Result widget/block)
		// -------------------------------------------------------------------------

		/**
		 * Sanitize a `display` settings sub-array attached to a schedule record.
		 *
		 * Display settings power the Scheduled Result block/Elementor widget and
		 * control how the latest run's structured output is surfaced. They are
		 * intentionally separate from the dispatch payload — they describe the
		 * widget's *binding*, not the run itself.
		 *
		 * @since 1.0.0
		 *
		 * @param array $display Raw display settings.
		 * @return array Sanitized display settings with all expected keys.
		 */
		public static function sanitize_display_fields( array $display ) {
			$allowed_capture = array( 'disabled', 'summary', 'full' );
			$capture         = isset( $display['result_capture'] ) ? sanitize_key( $display['result_capture'] ) : 'summary';
			if ( ! in_array( $capture, $allowed_capture, true ) ) {
				$capture = 'summary';
			}

			$public_render = ! empty( $display['public_render'] );

			$public_fields = array();
			if ( isset( $display['public_fields'] ) && is_array( $display['public_fields'] ) ) {
				foreach ( $display['public_fields'] as $field ) {
					if ( ! is_string( $field ) ) {
						continue;
					}
					// Allow dotted JSON paths like "data.items" and "summary".
					$field = preg_replace( '/[^a-zA-Z0-9_.\[\]\-]/', '', $field );
					if ( '' !== $field ) {
						$public_fields[] = $field;
					}
				}
			}

			$retention = isset( $display['result_retention'] ) ? (int) $display['result_retention'] : self::DEFAULT_RESULT_RETENTION;
			$retention = max( 1, min( 100, $retention ) );

			$widget_defaults = array(
				'render_mode'      => 'summary-card',
				'title'            => '',
				'refresh_interval' => 0,
			);
			if ( isset( $display['widget_defaults'] ) && is_array( $display['widget_defaults'] ) ) {
				$raw = $display['widget_defaults'];
				if ( isset( $raw['render_mode'] ) && in_array(
					$raw['render_mode'],
					array( 'summary-card', 'list', 'table', 'metric', 'timeline', 'raw' ),
					true
				) ) {
					$widget_defaults['render_mode'] = $raw['render_mode'];
				}
				if ( isset( $raw['title'] ) ) {
					$widget_defaults['title'] = sanitize_text_field( (string) $raw['title'] );
				}
				if ( isset( $raw['refresh_interval'] ) ) {
					$widget_defaults['refresh_interval'] = max( 0, min( 3600, (int) $raw['refresh_interval'] ) );
				}
			}

			return array(
				'result_capture'   => $capture,
				'public_render'    => (bool) $public_render,
				'public_fields'    => $public_fields,
				'result_retention' => $retention,
				'widget_defaults'  => $widget_defaults,
			);
		}

		/**
		 * Sanitize result_delivery configuration for a schedule.
		 *
		 * @since 1.0.0
		 *
		 * @param array $delivery Raw result_delivery array.
		 * @return array Sanitized result_delivery with on_success and on_failure keys.
		 */
		public static function sanitize_result_delivery( array $delivery ) {
			$default = self::get_default_result_delivery();

			$sanitized = array(
				'on_success' => isset( $delivery['on_success']['channels'] ) && is_array( $delivery['on_success']['channels'] )
					? self::sanitize_delivery_channels( $delivery['on_success']['channels'] )
					: $default['on_success']['channels'],
				'on_failure' => isset( $delivery['on_failure']['channels'] ) && is_array( $delivery['on_failure']['channels'] )
					? self::sanitize_delivery_channels( $delivery['on_failure']['channels'] )
					: $default['on_failure']['channels'],
			);

			return $sanitized;
		}

		/**
		 * Sanitize a set of delivery channel configs.
		 *
		 * @since 1.0.0
		 *
		 * @param array $channels Raw channel configs keyed by slug.
		 * @return array Sanitized channel configs.
		 */
		protected static function sanitize_delivery_channels( array $channels ) {
			$allowed         = array( 'email', 'slack', 'telegram', 'discord', 'teams', 'messenger', 'whatsapp', 'sms', 'paper_store', 'webhook', 'wordpress' );
			$email_templates = array( 'full', 'summary', 'error' );
			$chat_templates  = array( 'summary', 'error' );

			$sanitized = array();
			foreach ( $channels as $channel => $config ) {
				$channel = sanitize_key( $channel );
				if ( ! in_array( $channel, $allowed, true ) || ! is_array( $config ) ) {
					continue;
				}

				$entry = array(
					'enabled'  => ! empty( $config['enabled'] ),
					'template' => 'email' === $channel
						? ( isset( $config['template'] ) && in_array( $config['template'], $email_templates, true ) ? $config['template'] : 'summary' )
						: ( isset( $config['template'] ) && in_array( $config['template'], $chat_templates, true ) ? $config['template'] : 'summary' ),
				);

				// Channel-specific fields.
				if ( 'email' === $channel && isset( $config['to'] ) ) {
					$entry['to'] = sanitize_email( $config['to'] );
				}
				if ( 'sms' === $channel && isset( $config['to'] ) ) {
					$entry['to'] = sanitize_text_field( $config['to'] );
				}
				if ( in_array( $channel, array( 'slack', 'telegram', 'discord', 'teams', 'messenger', 'whatsapp' ), true ) && isset( $config[ $channel . '_credentials' ] ) ) {
					$entry[ $channel . '_credentials' ] = $config[ $channel . '_credentials' ];
				}
				if ( 'paper_store' === $channel ) {
					if ( isset( $config['collection'] ) ) {
						$entry['collection'] = sanitize_key( $config['collection'] );
					}
					if ( isset( $config['driver'] ) ) {
						$entry['driver'] = in_array( $config['driver'], array( 'json', 'markdown_yaml' ), true ) ? $config['driver'] : 'json';
					}
					$entry['retention']  = isset( $config['retention'] ) ? max( 0, min( 100, (int) $config['retention'] ) ) : 0;
					$entry['git_commit'] = ! empty( $config['git_commit'] );
				}
				if ( 'webhook' === $channel && isset( $config['url'] ) ) {
					$url = esc_url_raw( $config['url'] );
					if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
						$entry['url'] = $url;
					}
					if ( isset( $config['secret'] ) ) {
						$entry['secret'] = sanitize_text_field( $config['secret'] );
					}
				}
				if ( 'WordPress' === $channel ) {
					$entry['post_type']   = isset( $config['post_type'] ) ? sanitize_key( $config['post_type'] ) : 'post';
					$entry['post_status'] = isset( $config['post_status'] ) ? sanitize_key( $config['post_status'] ) : 'draft';
					$entry['category']    = isset( $config['category'] ) ? absint( $config['category'] ) : 0;
				}

				$sanitized[ $channel ] = $entry;
			}

			return $sanitized;
		}

		/**
		 * Return the default result_delivery structure (all channels off).
		 *
		 * @since 1.0.0
		 *
		 * @return array Default result_delivery array.
		 */
		public static function get_default_result_delivery() {
			return array(
				'on_success' => array(
					'channels' => array(),
				),
				'on_failure' => array(
					'channels' => array(),
				),
			);
		}

		/**
		 * Deliver a schedule result (success or failure) to configured channels.
		 *
		 * Bridges the Schedule Manager to {@see WP_MCP_AI_Result_Delivery_Service}.
		 * Called from {@see dispatch()} after record_run() completes.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $schedule_id Schedule identifier.
		 * @param array               $schedule    Schedule record.
		 * @param bool                $success     Whether the run succeeded.
		 * @param string              $error_msg   Error message (only relevant on failure).
		 * @param array<string,mixed> $action_log  Raw action log from the dispatcher.
		 */
		protected static function deliver_result( $schedule_id, array $schedule, $success, $error_msg, array $action_log ) {
			if ( ! class_exists( 'WP_MCP_AI_Result_Delivery_Service' ) ) {
				// Fall back to legacy notification if service is not loaded.
				if ( ! $success ) {
					if ( ! empty( $schedule['notify_on_failure'] ) && ! empty( $schedule['notify_email'] ) ) {
						self::send_failure_notification( $schedule, $error_msg );
					}
					if ( ! empty( $schedule['notify_channels'] ) && is_array( $schedule['notify_channels'] ) ) {
						self::send_channel_failure_notification( $schedule, $error_msg );
					}
				}
				return;
			}

			$delivery_statuses = array();
			if ( $success ) {
				$envelope = self::get_latest_result( $schedule_id );
				if ( $envelope ) {
					$delivery_statuses = WP_MCP_AI_Result_Delivery_Service::deliver_success( $schedule_id, $envelope, $schedule, $action_log );
				}
			} else {
				$delivery_statuses = WP_MCP_AI_Result_Delivery_Service::deliver_failure( $schedule_id, $error_msg, $schedule );
			}

			// Append delivery status to the most recent run history entry.
			if ( ! empty( $delivery_statuses ) ) {
				self::append_delivery_to_history( $schedule_id, $delivery_statuses );
				self::append_delivery_to_results( $schedule_id, $delivery_statuses );
			}
		}

		/**
		 * Append per-channel delivery status to the most recent run history entry.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id       Schedule identifier.
		 * @param array  $delivery_statuses Per-channel delivery statuses from the service.
		 */
		protected static function append_delivery_to_history( $schedule_id, array $delivery_statuses ) {
			$history = self::load_history();
			$id      = (string) $schedule_id;

			if ( empty( $history[ $id ] ) || ! is_array( $history[ $id ] ) ) {
				return;
			}

			// Get the most recent entry.
			$last_key = array_key_last( $history[ $id ] );
			if ( null === $last_key || ! isset( $history[ $id ][ $last_key ] ) ) {
				return;
			}

			$history[ $id ][ $last_key ]['delivery'] = $delivery_statuses;
			self::save_history( $history );
		}

		/**
		 * Append per-channel delivery status to the most recent result envelope.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id       Schedule identifier.
		 * @param array  $delivery_statuses Per-channel delivery statuses.
		 */
		protected static function append_delivery_to_results( $schedule_id, array $delivery_statuses ) {
			$results = self::load_results();
			$id      = (string) $schedule_id;

			if ( empty( $results[ $id ] ) || ! is_array( $results[ $id ] ) ) {
				return;
			}

			// Get the most recent envelope.
			$last_key = array_key_last( $results[ $id ] );
			if ( null === $last_key || ! isset( $results[ $id ][ $last_key ] ) ) {
				return;
			}

			$results[ $id ][ $last_key ]['delivery'] = $delivery_statuses;
			self::save_results( $results );
		}

		/**
		 * Build a structured result envelope from a dispatcher's raw action log.
		 *
		 * The envelope shape — summary / data / render — is the contract consumed
		 * by the Scheduled Result widget and block, the REST controller, and the
		 * `get_schedule_latest_result` / `render_schedule_result` tools.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $schedule      Schedule record (post-sanitize).
		 * @param array  $action_log    Action log produced by the dispatcher.
		 * @param bool   $success       Whether the run succeeded.
		 * @param string $error_msg     Error message if the run failed.
		 * @return array Envelope: { summary, data, render, generated_at, status }.
		 */
		public static function build_result_envelope( array $schedule, array $action_log, $success, $error_msg ) {
			$schedule_type = isset( $schedule['schedule_type'] ) ? (string) $schedule['schedule_type'] : self::TYPE_TASK;
			$widget_mode   = isset( $schedule['display']['widget_defaults']['render_mode'] )
				? (string) $schedule['display']['widget_defaults']['render_mode']
				: 'summary-card';

			$summary = '';
			$data    = array();
			$render  = 'text';

			if ( ! $success ) {
				$summary = $error_msg ? (string) $error_msg : __( 'Schedule run failed.', 'mcp-ai-wpoos-pro' );
				$render  = 'text';
			} else {
				switch ( $schedule_type ) {
					case self::TYPE_ASSISTANT_RUN:
						$response = isset( $action_log['assistant']['response'] ) ? (string) $action_log['assistant']['response'] : '';
						$summary  = $response ? wp_trim_words( wp_strip_all_tags( $response ), 25, '…' ) : __( 'Assistant run completed.', 'mcp-ai-wpoos-pro' );
						$data     = array(
							'response'     => $response,
							'assistant_id' => isset( $action_log['assistant']['assistant_id'] ) ? (int) $action_log['assistant']['assistant_id'] : 0,
							'is_agentic'   => ! empty( $action_log['assistant']['is_agentic'] ),
						);
						$render   = 'markdown';
						break;

					case self::TYPE_WORKFLOW:
						$steps   = isset( $action_log['steps'] ) && is_array( $action_log['steps'] ) ? $action_log['steps'] : array();
						$summary = sprintf(
							/* translators: %d: step count */
							_n( '%d workflow step completed.', '%d workflow steps completed.', count( $steps ), 'mcp-ai-wpoos-pro' ),
							count( $steps )
						);
						$data   = array(
							'steps' => $steps,
						);
						$render = 'list';
						break;

					case self::TYPE_CHANNEL_BROADCAST:
						$broadcast = isset( $action_log['broadcast'] ) && is_array( $action_log['broadcast'] ) ? $action_log['broadcast'] : array();
						$channels  = isset( $broadcast['channels'] ) && is_array( $broadcast['channels'] ) ? $broadcast['channels'] : array();
						$summary   = sprintf(
							/* translators: %d: channel count */
							_n( 'Broadcast delivered to %d channel.', 'Broadcast delivered to %d channels.', count( $channels ), 'mcp-ai-wpoos-pro' ),
							count( $channels )
						);
						$data   = $broadcast;
						$render = 'list';
						break;

					case self::TYPE_WORKFLOW_BUILDER:
						$summary = __( 'Workflow builder run completed.', 'mcp-ai-wpoos-pro' );
						$data    = array( 'workflow_builder_id' => isset( $action_log['workflow_builder_id'] ) ? (string) $action_log['workflow_builder_id'] : '' );
						$render  = 'text';
						break;

					case self::TYPE_TASK:
					default:
						$summary = isset( $action_log['hook'] )
							/* translators: %s: WordPress action hook */
							? sprintf( __( 'Hook fired: %s', 'mcp-ai-wpoos-pro' ), (string) $action_log['hook'] )
							: __( 'Task completed.', 'mcp-ai-wpoos-pro' );
						$data   = $action_log;
						$render = 'text';
						break;
				}
			}

			// If the widget defaults indicate a specific mode, honour it as the canonical render hint.
			if ( $widget_mode ) {
				$mode_to_render = array(
					'summary-card' => $render,
					'list'         => 'list',
					'table'        => 'table',
					'metric'       => 'metric',
					'timeline'     => 'timeline',
					'raw'          => 'text',
				);
				if ( isset( $mode_to_render[ $widget_mode ] ) ) {
					$render = $mode_to_render[ $widget_mode ];
				}
			}

			$envelope = array(
				'summary'      => (string) $summary,
				'data'         => is_array( $data ) ? $data : array(),
				'render'       => $render,
				'status'       => $success ? 'success' : 'failure',
				'error'        => $success ? '' : (string) $error_msg,
				'generated_at' => time(),
			);

			/**
			 * Filter the structured result envelope before it is stored.
			 *
			 * Integrators can shape the envelope per schedule type — for instance,
			 * an assistant_run that returns JSON can populate `data.items` so the
			 * Scheduled Result widget renders a list.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $envelope      The envelope about to be stored.
			 * @param array  $schedule      Schedule record.
			 * @param array  $action_log    Dispatcher's structured action log.
			 * @param bool   $success       Whether the run succeeded.
			 */
			return (array) apply_filters( 'wp_mcp_ai_pro_schedule_result_envelope', $envelope, $schedule, $action_log, $success );
		}

		/**
		 * Persist a result envelope for a schedule.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param array  $envelope    Sanitized envelope.
		 * @param array  $schedule    Schedule record (for retention).
		 */
		protected static function store_result_envelope( $schedule_id, array $envelope, array $schedule ) {
			$results = self::load_results();

			if ( ! isset( $results[ $schedule_id ] ) || ! is_array( $results[ $schedule_id ] ) ) {
				$results[ $schedule_id ] = array();
			}

			$results[ $schedule_id ][] = $envelope;

			$retention = isset( $schedule['display']['result_retention'] )
				? (int) $schedule['display']['result_retention']
				: self::DEFAULT_RESULT_RETENTION;
			/** This filter is documented above. */
			$retention = (int) apply_filters( 'wp_mcp_ai_pro_schedule_result_retention', $retention, $schedule_id, $schedule );
			$retention = max( 1, min( 100, $retention ) );

			if ( count( $results[ $schedule_id ] ) > $retention ) {
				$results[ $schedule_id ] = array_slice( $results[ $schedule_id ], - $retention );
			}

			self::save_results( $results );

			/**
			 * Fires after a result envelope is stored.
			 *
			 * Observability / cache-bumping subscribers should listen here.
			 *
			 * @since 1.0.0
			 *
			 * @param string $schedule_id Schedule ID.
			 * @param array  $envelope    The envelope that was stored.
			 * @param array  $schedule    Schedule record at the time of recording.
			 */
			do_action( 'wp_mcp_ai_pro_schedule_result_recorded', $schedule_id, $envelope, $schedule );
		}

		/**
		 * Return the latest result envelope for a schedule.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule ID.
		 * @return array|null Envelope or null if none recorded yet.
		 */
		public static function get_latest_result( $schedule_id ) {
			$results = self::load_results();
			$id      = (string) $schedule_id;
			if ( ! isset( $results[ $id ] ) || ! is_array( $results[ $id ] ) || empty( $results[ $id ] ) ) {
				return null;
			}
			$envelope = end( $results[ $id ] );
			return is_array( $envelope ) ? $envelope : null;
		}

		/**
		 * Return the last N result envelopes for a schedule (newest first).
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param int    $limit       Maximum number of envelopes to return.
		 * @return array Envelopes, newest first.
		 */
		public static function get_results( $schedule_id, $limit = 10 ) {
			$results = self::load_results();
			$id      = (string) $schedule_id;
			if ( ! isset( $results[ $id ] ) || ! is_array( $results[ $id ] ) ) {
				return array();
			}
			$slice = array_reverse( $results[ $id ] );
			$limit = max( 1, min( 100, (int) $limit ) );
			return array_slice( $slice, 0, $limit );
		}

		/**
		 * Clear the result store for a schedule.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule ID.
		 */
		public static function clear_results( $schedule_id ) {
			$results = self::load_results();
			unset( $results[ (string) $schedule_id ] );
			self::save_results( $results );
		}

		/**
		 * Trigger a synchronous "preview" run that only updates the result store,
		 * never the history ring buffer. Used by the block editor's preview button.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule ID.
		 * @return array|WP_Error Envelope on success, WP_Error otherwise.
		 */
		public static function trigger_preview( $schedule_id ) {
			$schedule = self::get_schedule( $schedule_id );
			if ( ! $schedule ) {
				return new WP_Error(
					'not_found',
					/* translators: %s: schedule ID */
					sprintf( __( 'Schedule "%s" not found.', 'mcp-ai-wpoos-pro' ), $schedule_id )
				);
			}

			$schedule_type = isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : self::TYPE_TASK;
			$start         = microtime( true );
			$action_log    = array(
				'type'    => $schedule_type,
				'preview' => true,
			);
			$success       = true;
			$error_msg     = '';

			try {
				switch ( $schedule_type ) {
					case self::TYPE_WORKFLOW:
						$result = self::dispatch_workflow( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						} else {
							$action_log['steps'] = is_array( $result ) ? $result : array();
						}
						break;
					case self::TYPE_ASSISTANT_RUN:
						$result = self::dispatch_assistant_run( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						} else {
							$action_log['assistant'] = is_array( $result ) ? $result : array();
						}
						break;
					default:
						$success   = false;
						$error_msg = __( 'Preview is only supported for workflow and assistant_run schedules.', 'mcp-ai-wpoos-pro' );
						break;
				}
			} catch ( Throwable $e ) {
				$success   = false;
				$error_msg = $e->getMessage();
			}

			$duration               = round( microtime( true ) - $start, 3 );
			$action_log['duration'] = $duration;

			$envelope             = self::build_result_envelope( $schedule, $action_log, $success, $error_msg );
			$envelope['preview']  = true;
			$envelope['duration'] = $duration;

			self::store_result_envelope( $schedule_id, $envelope, $schedule );

			return $envelope;
		}

		/**
		 * Public-facing redaction: trim a stored envelope to allow-listed fields.
		 *
		 * Used by the REST controller and the renderer when surfacing results to
		 * unauthenticated visitors. Fields not on the allow-list are removed.
		 *
		 * @since 1.0.0
		 *
		 * @param array $envelope      The full stored envelope.
		 * @param array $schedule      Schedule record.
		 * @return array Redacted envelope.
		 */
		public static function redact_envelope_for_public( array $envelope, array $schedule ) {
			$public_render = ! empty( $schedule['display']['public_render'] );
			if ( ! $public_render ) {
				return array(
					'summary'      => '',
					'data'         => array(),
					'render'       => 'text',
					'status'       => 'forbidden',
					'error'        => '',
					'generated_at' => isset( $envelope['generated_at'] ) ? (int) $envelope['generated_at'] : 0,
				);
			}

			$allowed = isset( $schedule['display']['public_fields'] ) && is_array( $schedule['display']['public_fields'] )
				? $schedule['display']['public_fields']
				: array();

			// summary + render hint + generated_at are always public when public_render is on.
			$redacted = array(
				'summary'      => isset( $envelope['summary'] ) ? (string) $envelope['summary'] : '',
				'data'         => array(),
				'render'       => isset( $envelope['render'] ) ? (string) $envelope['render'] : 'text',
				'status'       => isset( $envelope['status'] ) ? (string) $envelope['status'] : 'success',
				'error'        => '',
				'generated_at' => isset( $envelope['generated_at'] ) ? (int) $envelope['generated_at'] : 0,
			);

			// Apply allow-list to the data tree. Each allowed entry is a dotted path; we copy that path through.
			foreach ( $allowed as $path ) {
				if ( 'summary' === $path ) {
					continue; // Already included.
				}
				$value = self::extract_path( $envelope, $path );
				if ( null !== $value ) {
					self::assign_path( $redacted, $path, $value );
				}
			}

			/**
			 * Last-chance filter to redact the public envelope.
			 *
			 * @since 1.0.0
			 *
			 * @param array $redacted Redacted envelope.
			 * @param array $envelope Full envelope.
			 * @param array $schedule Schedule record.
			 */
			return (array) apply_filters( 'wp_mcp_ai_pro_schedule_public_result', $redacted, $envelope, $schedule );
		}

		/**
		 * Walk a dotted path into a nested array. Returns null when any segment is missing.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $source Source array.
		 * @param string $path   Dotted path (e.g. "data.items").
		 * @return mixed Value at the path, or null if not present.
		 */
		protected static function extract_path( array $source, $path ) {
			$segments = explode( '.', (string) $path );
			$current  = $source;
			foreach ( $segments as $segment ) {
				if ( '' === $segment ) {
					return null;
				}
				if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
					return null;
				}
				$current = $current[ $segment ];
			}
			return $current;
		}

		/**
		 * Assign a value into a dotted path within a nested array (creating sub-arrays as needed).
		 *
		 * @since 1.0.0
		 *
		 * @param array  $target Reference to the array to mutate.
		 * @param string $path   Dotted path.
		 * @param mixed  $value  Value to assign.
		 */
		protected static function assign_path( array &$target, $path, $value ) {
			$segments = explode( '.', (string) $path );
			$ref      = &$target;
			foreach ( $segments as $segment ) {
				if ( '' === $segment ) {
					return;
				}
				if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
					$ref[ $segment ] = array();
				}
				$ref = &$ref[ $segment ];
			}
			$ref = $value;
		}

		/**
		 * Load the result-store from options (with cache).
		 *
		 * @since 1.0.0
		 *
		 * @return array Results keyed by schedule ID.
		 */
		protected static function load_results() {
			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				$cached = WP_MCP_AI_Cache_Helper::get( 'pro_schedule_results' );
				if ( false !== $cached && is_array( $cached ) ) {
					return $cached;
				}
			}
			$data    = get_option( self::RESULTS_OPTION, array() );
			$results = is_array( $data ) ? $data : array();
			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				WP_MCP_AI_Cache_Helper::set( 'pro_schedule_results', $results, 30 );
			}
			return $results;
		}

		/**
		 * Persist the result-store and invalidate the cache.
		 *
		 * @since 1.0.0
		 *
		 * @param array $results Results array to store.
		 */
		protected static function save_results( array $results ) {
			$existing = get_option( self::RESULTS_OPTION, null );
			if ( null === $existing ) {
				add_option( self::RESULTS_OPTION, $results, '', 'no' );
			} else {
				update_option( self::RESULTS_OPTION, $results );
			}
			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
				WP_MCP_AI_Cache_Helper::delete( 'pro_schedule_results' );
			}
		}
	}

	WP_MCP_AI_Pro_Schedule_Manager::init();
}
