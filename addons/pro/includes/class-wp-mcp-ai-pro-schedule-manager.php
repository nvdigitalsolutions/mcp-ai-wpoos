<?php
/**
 * Pro Schedule Manager for NV oOS.
 *
 * Extends the base cron manager with pro-grade scheduling features:
 * - Named schedules with descriptions and tags
 * - Per-schedule enable/disable toggle
 * - Execution history (ring buffer, last 50 runs per schedule)
 * - Retry logic with configurable attempts and delay
 * - Admin email notifications on failure (wp_mail + Nodemailer HTML when available)
 * - Channel notifications via unified_channel_broadcast tool (Slack, Teams, Discord, Telegram, etc.)
 * - Priority ordering for schedule creation UI
 * - Central dispatcher hook for auditable execution
 * - channel_broadcast schedule type: send a message to chat channels on a schedule
 * - Execution History CCT integration when JetEngine is available
 * - Per-step tool-execution logging via WP_MCP_AI_Logger::log_tool_execution()
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
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
		 * Central dispatcher cron hook.
		 */
		const DISPATCH_HOOK = 'wp_mcp_ai_pro_schedule_exec';

		/**
		 * Maximum history entries stored per schedule.
		 */
		const MAX_HISTORY_PER_SCHEDULE = 50;

		/**
		 * Supported schedule types.
		 */
		const TYPE_TASK              = 'task';
		const TYPE_WORKFLOW          = 'workflow';
		const TYPE_ASSISTANT_RUN     = 'assistant_run';
		const TYPE_CHANNEL_BROADCAST = 'channel_broadcast';

		/**
		 * Bootstrap hooks for the schedule manager.
		 */
		public static function init() {
			// Central dispatcher: all managed schedules call this hook with schedule ID as argument.
			add_action( self::DISPATCH_HOOK, array( __CLASS__, 'dispatch' ), 10, 1 );

			// Register custom cron intervals used by pro schedules.
			add_filter( 'cron_schedules', array( __CLASS__, 'register_custom_intervals' ) );

			// Prune stale schedules on init.
			add_action( 'init', array( __CLASS__, 'maybe_prune_history' ) );
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
			$valid_types   = array( self::TYPE_TASK, self::TYPE_WORKFLOW, self::TYPE_ASSISTANT_RUN, self::TYPE_CHANNEL_BROADCAST );
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
					'assistant_id' => absint( $assistant_config['assistant_id'] ),
					'message'      => sanitize_textarea_field( $assistant_config['message'] ),
					'context'      => isset( $assistant_config['context'] ) && is_array( $assistant_config['context'] )
						? $assistant_config['context']
						: array(),
				);
				$hook = 'wp_mcp_ai_pro_assistant_run';
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
				$hook = 'wp_mcp_ai_pro_channel_broadcast';
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

			$args         = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
			$enabled      = isset( $data['enabled'] ) ? (bool) $data['enabled'] : true;
			$priority     = isset( $data['priority'] ) ? max( 1, min( 10, (int) $data['priority'] ) ) : 5;
			$max_retries  = isset( $data['max_retries'] ) ? max( 0, min( 5, (int) $data['max_retries'] ) ) : 0;
			$retry_delay  = isset( $data['retry_delay'] ) ? max( 60, (int) $data['retry_delay'] ) : 300;
			$name         = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $hook;
			$description  = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';
			$notify          = isset( $data['notify_on_failure'] ) ? (bool) $data['notify_on_failure'] : false;
			$notify_email    = isset( $data['notify_email'] ) ? sanitize_email( $data['notify_email'] ) : get_option( 'admin_email' );
			// notify_channels: array of channel slugs (telegram, slack, etc.) to send failure alerts via unified_channel_broadcast.
			$notify_channels             = isset( $data['notify_channels'] ) && is_array( $data['notify_channels'] )
				? array_map( 'sanitize_key', $data['notify_channels'] )
				: array();
			// notify_channel_credentials: credentials object keyed by channel slug, passed to unified_channel_broadcast.
			$notify_channel_credentials  = isset( $data['notify_channel_credentials'] ) && is_array( $data['notify_channel_credentials'] )
				? $data['notify_channel_credentials']
				: array();
			$tags            = isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_map( 'sanitize_text_field', $data['tags'] ) : array();

			// Use a unique ID that incorporates schedule type for workflow/assistant to avoid collisions.
			$id_key      = self::TYPE_TASK === $schedule_type
				? array( 'hook' => $hook, 'args' => $args )
				: array( 'type' => $schedule_type, 'name' => $name, 'ts' => $timestamp );
			$schedule_id = md5( wp_json_encode( $id_key ) );

			$existing = self::get_schedule( $schedule_id );

			$record = array(
				'id'                => $schedule_id,
				'name'              => $name,
				'description'       => $description,
				'schedule_type'     => $schedule_type,
				'hook'              => $hook,
				'args'              => $args,
				'workflow_steps'    => isset( $data['workflow_steps'] ) ? $data['workflow_steps'] : array(),
				'assistant_config'  => isset( $data['assistant_config'] ) ? $data['assistant_config'] : array(),
				'broadcast_config'  => isset( $data['broadcast_config'] ) ? $data['broadcast_config'] : array(),
				'schedule'          => $schedule,
				'timestamp'         => $timestamp,
				'enabled'           => $enabled,
				'priority'          => $priority,
				'tags'              => $tags,
				'notify_on_failure'  => $notify,
				'notify_email'       => $notify_email,
				'notify_channels'             => $notify_channels,
				'notify_channel_credentials'  => $notify_channel_credentials,
				'max_retries'       => $max_retries,
				'retry_delay'       => $retry_delay,
				'retry_count'       => 0,
				'last_run_status'   => $existing ? $existing['last_run_status'] : 'never',
				'last_run_time'     => $existing ? $existing['last_run_time'] : 0,
				'last_run_duration' => $existing ? $existing['last_run_duration'] : 0,
				'last_error'        => $existing ? $existing['last_error'] : '',
				'run_count'         => $existing ? $existing['run_count'] : 0,
				'created_at'        => $existing ? $existing['created_at'] : time(),
				'created_by'        => $existing ? $existing['created_by'] : (int) $user_id,
				'updated_at'        => time(),
				'updated_by'        => (int) $user_id,
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
					'info',
					'Pro schedule created: ' . $name,
					array(
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
				WP_MCP_AI_Logger::log_event( 'info', 'Pro schedule deleted: ' . $schedule_id );
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
					'info',
					'Pro schedule manually triggered: ' . $schedule['name'],
					array(
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
			$schedule    = self::get_schedule( $schedule_id );

			if ( ! $schedule ) {
				return false;
			}

			if ( empty( $schedule['enabled'] ) ) {
				return false;
			}

			$schedule_type = isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : self::TYPE_TASK;
			$start         = microtime( true );
			$error_msg     = '';
			$success       = true;

			try {
				switch ( $schedule_type ) {
					case self::TYPE_WORKFLOW:
						$result = self::dispatch_workflow( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						}
						break;

					case self::TYPE_ASSISTANT_RUN:
						$result = self::dispatch_assistant_run( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						}
						break;

					case self::TYPE_CHANNEL_BROADCAST:
						$result = self::dispatch_channel_broadcast( $schedule, $schedule_id );
						if ( is_wp_error( $result ) ) {
							$success   = false;
							$error_msg = $result->get_error_message();
						}
						break;

					case self::TYPE_TASK:
					default:
						$hook = (string) $schedule['hook'];
						$args = isset( $schedule['args'] ) && is_array( $schedule['args'] ) ? $schedule['args'] : array();
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is user-supplied and sanitized with sanitize_key() during schedule creation. Only users with manage_options can create task schedules.
						do_action_ref_array( $hook, $args );
						break;
				}
			} catch ( Throwable $e ) {
				$success   = false;
				$error_msg = $e->getMessage();

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Pro schedule exception: ' . $schedule['name'],
						array(
							'schedule_id'   => $schedule_id,
							'schedule_type' => $schedule_type,
							'error'         => $error_msg,
						)
					);
				}
			}

			$end      = microtime( true );
			$duration = round( $end - $start, 3 );

			// Record run result.
			self::record_run( $schedule_id, $success, $duration, $error_msg );

			// Handle retry logic on failure.
			if ( ! $success ) {
				self::handle_failure( $schedule_id, $schedule, $error_msg );
			} else {
				// Success: reset retry counter.
				$schedules                                    = self::load_schedules();
				$schedules[ $schedule_id ]['retry_count']    = 0;
				$schedules[ $schedule_id ]['last_run_status'] = 'success';
				self::save_schedules( $schedules );
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
		 * @return true|WP_Error
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
				'schedule_id'      => $schedule_id,
				'schedule_name'    => $schedule['name'],
				'source'           => 'pro_schedule_manager',
				'user_id'          => isset( $schedule['created_by'] ) ? (int) $schedule['created_by'] : 0,
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

				$step_context                      = $context;
				$step_context['workflow_step']     = $step_index;
				$step_context['previous_results']  = $previous_results;

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
					'result'    => $result,
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

			return true;
		}

		/**
		 * Execute an assistant_run schedule.
		 *
		 * Fires the `wp_mcp_ai_pro_scheduled_assistant_run` action so that listeners
		 * can process the configured assistant and message asynchronously. The action
		 * passes the full schedule config; integrators are responsible for hooking in.
		 *
		 * @param array  $schedule    Schedule record.
		 * @param string $schedule_id Schedule ID.
		 * @return true|WP_Error
		 */
		protected static function dispatch_assistant_run( array $schedule, $schedule_id ) {
			$config = isset( $schedule['assistant_config'] ) && is_array( $schedule['assistant_config'] )
				? $schedule['assistant_config']
				: array();

			if ( empty( $config['assistant_id'] ) || empty( $config['message'] ) ) {
				return new WP_Error( 'invalid_assistant_config', __( 'Assistant run is missing assistant_id or message.', 'mcp-ai-wpoos-pro' ) );
			}

			/**
			 * Fires when a scheduled assistant run is dispatched.
			 *
			 * @param int    $assistant_id Assistant post ID.
			 * @param string $message      Message to send.
			 * @param array  $context      Additional context (schedule_id, schedule_name, extra context array).
			 */
			do_action(
				'wp_mcp_ai_pro_scheduled_assistant_run',
				(int) $config['assistant_id'],
				(string) $config['message'],
				array(
					'schedule_id'   => $schedule_id,
					'schedule_name' => $schedule['name'],
					'context'       => isset( $config['context'] ) ? $config['context'] : array(),
					'user_id'       => isset( $schedule['created_by'] ) ? (int) $schedule['created_by'] : 0,
				)
			);

			return true;
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
		 * @return true|WP_Error
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
					}

					return true;
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

			return true;
		}

		// -------------------------------------------------------------------------
		// Internal helpers
		// -------------------------------------------------------------------------

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
		 */
		protected static function record_run( $schedule_id, $success, $duration, $error_msg = '' ) {
			$history = self::load_history();

			if ( ! isset( $history[ $schedule_id ] ) || ! is_array( $history[ $schedule_id ] ) ) {
				$history[ $schedule_id ] = array();
			}

			$history[ $schedule_id ][] = array(
				'status'     => $success ? 'success' : 'failure',
				'start_time' => time(),
				'duration'   => $duration,
				'error'      => $error_msg,
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
			$schedules                                   = self::load_schedules();
			$schedules[ $schedule_id ]['last_run_status'] = 'failure';

			if ( $max_retries > 0 && $retry_count < $max_retries ) {
				// Schedule a retry.
				$schedules[ $schedule_id ]['retry_count'] = $retry_count + 1;
				self::save_schedules( $schedules );

				$retry_at = time() + $retry_delay;
				self::schedule_wp_cron( $schedule_id, 'single', $retry_at );

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'warning',
						sprintf(
							'Pro schedule retry %d/%d scheduled: %s',
							$retry_count + 1,
							$max_retries,
							$schedule['name']
						),
						array(
							'schedule_id' => $schedule_id,
							'retry_at'    => $retry_at,
						)
					);
				}
			} else {
				// Max retries reached or no retries configured.
				$schedules[ $schedule_id ]['retry_count'] = 0;
				self::save_schedules( $schedules );

				// Send failure notification (email and/or channel).
				if ( ! empty( $schedule['notify_on_failure'] ) ) {
					if ( ! empty( $schedule['notify_email'] ) ) {
						self::send_failure_notification( $schedule, $error_msg );
					}
					if ( ! empty( $schedule['notify_channels'] ) && is_array( $schedule['notify_channels'] ) ) {
						self::send_channel_failure_notification( $schedule, $error_msg );
					}
				}
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
			$plain  = sprintf( __( 'The scheduled task "%s" has failed.', 'mcp-ai-wpoos-pro' ), $schedule['name'] );
			$plain .= "\n\n";
			$plain .= sprintf( __( 'Type: %s', 'mcp-ai-wpoos-pro' ), $type_label );
			$plain .= "\n";
			$plain .= sprintf( __( 'Error: %s', 'mcp-ai-wpoos-pro' ), $error_msg );
			$plain .= "\n\n";
			$plain .= sprintf( __( 'Manage schedules: %s', 'mcp-ai-wpoos-pro' ), $manage_url );

			// Try Nodemailer for a richer HTML email when the service is available.
			if ( class_exists( 'WP_MCP_AI_Nodemailer_Service' ) ) {
				$nodemailer = new WP_MCP_AI_Nodemailer_Service();
				if ( $nodemailer->is_available() ) {
					$html = '<html><body style="font-family:sans-serif;color:#333">';
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

					// Log that Nodemailer failed and fall through to wp_mail.
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event( 'warning', 'Nodemailer failed for schedule notification, falling back to wp_mail.', array( 'error' => $result->get_error_message() ) );
					}
				}
			}

			// Fallback: plain-text wp_mail().
			wp_mail( $to, $subject, $plain );
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

			/* translators: 1: schedule name, 2: site name, 3: error message */
			$message = sprintf(
				__( '\u26a0\ufe0f [%2$s] Scheduled Task Failed: *%1$s*\nError: %3$s', 'mcp-ai-wpoos-pro' ),
				$schedule['name'],
				get_bloginfo( 'name' ),
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
				'cct_slug'     => WP_MCP_AI_Execution_History_CCT::SLUG,
				'session_id'   => $schedule_id,
				'tool_name'    => 'pro_schedule_manager',
				'success'      => $success ? '1' : '0',
				'error_message' => (string) $error_msg,
				'duration_ms'  => (int) round( $duration * 1000 ),
				'executed_at'  => current_time( 'mysql' ),
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
			return md5( wp_json_encode( array( 'hook' => $hook, 'args' => $args ) ) );
		}

		/**
		 * Load all schedules from the options table.
		 *
		 * @return array Schedules keyed by ID.
		 */
		protected static function load_schedules() {
			$data = get_option( self::SCHEDULES_OPTION, array() );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Persist schedules to the options table.
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
		}

		/**
		 * Load execution history from the options table.
		 *
		 * @return array History keyed by schedule ID.
		 */
		protected static function load_history() {
			$data = get_option( self::HISTORY_OPTION, array() );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Persist execution history to the options table.
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
		 * Unschedule all pro managed cron events (for plugin deactivation).
		 */
		public static function deactivate() {
			$schedules = self::load_schedules();
			foreach ( array_keys( $schedules ) as $schedule_id ) {
				self::unschedule_wp_cron( $schedule_id );
			}
		}
	}

	WP_MCP_AI_Pro_Schedule_Manager::init();
}
