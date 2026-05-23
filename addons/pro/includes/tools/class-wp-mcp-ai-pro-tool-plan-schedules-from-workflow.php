<?php
/**
 * Pro tool: Plan Schedules From Workflow.
 *
 * Wraps create_pro_schedule and accepts a list of natural-language workflow
 * items (e.g. "Respond to emails", "Weekly sales updates"). For each item it
 * normalizes a recurrence cadence, time-of-day, priority, and tags, then
 * delegates to WP_MCP_AI_Pro_Schedule_Manager::create_schedule() so that all
 * retry, notification, and history infrastructure is reused.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Plans a batch of pro schedules from a free-form workflow list.
 */
class WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'plan_schedules_from_workflow';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Plan Schedules From Workflow', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Turns a free-form list of recurring responsibilities (e.g. "Respond to emails", "Weekly sales updates") into a batch of Pro Schedules. Each item becomes a managed schedule with inferred cadence, time, priority, and tags. Supports a dry_run preview mode.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$valid_schedules = array_keys( wp_get_schedules() );
		sort( $valid_schedules );
		$valid_schedules = array_merge( array( 'single' ), $valid_schedules );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'workflow_items'       => array(
					'type'        => 'array',
					'description' => __( 'List of workflow items. Each entry may be a plain string (one responsibility per line) or an object with title, description, suggested_cadence, suggested_time, priority, tags.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'             => array(
								'type'        => 'string',
								'description' => __( 'Short title for the schedule (max 80 chars).', 'mcp-ai-wpoos-pro' ),
							),
							'description'       => array(
								'type'        => 'string',
								'description' => __( 'Full description / instruction for the assistant or task.', 'mcp-ai-wpoos-pro' ),
							),
							'suggested_cadence' => array(
								'type'        => 'string',
								'enum'        => $valid_schedules,
								'description' => __( 'Optional cadence override for this item.', 'mcp-ai-wpoos-pro' ),
							),
							'suggested_time'    => array(
								'type'        => 'string',
								'description' => __( 'Optional preferred time-of-day in HH:MM (24h, site timezone).', 'mcp-ai-wpoos-pro' ),
							),
							'priority'          => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 10,
								'description' => __( 'Optional priority override (1 = highest).', 'mcp-ai-wpoos-pro' ),
							),
							'tags'              => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Optional extra tags for this item.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'workflow_text'        => array(
					'type'        => 'string',
					'description' => __( 'Alternative input: a single multi-line string. Each non-empty line becomes one workflow item. Lines starting with "## " set the current category for following lines.', 'mcp-ai-wpoos-pro' ),
				),
				'category'             => array(
					'type'        => 'string',
					'description' => __( 'Optional category (e.g. "marketing", "mass-market"). Used as a tag and as a fallback name prefix.', 'mcp-ai-wpoos-pro' ),
				),
				'default_assistant_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional NV oOS assistant post ID. When provided, scheduled events fire as assistant_run; when omitted they fall back to a wp_mcp_ai_workflow_reminder task hook.', 'mcp-ai-wpoos-pro' ),
				),
				'default_cadence'      => array(
					'type'        => 'string',
					'enum'        => $valid_schedules,
					'description' => __( 'Default cadence applied when an item gives no hint. Defaults to "daily".', 'mcp-ai-wpoos-pro' ),
				),
				'default_time'         => array(
					'type'        => 'string',
					'description' => __( 'Default time-of-day in HH:MM (24h, site timezone). Defaults to "09:00".', 'mcp-ai-wpoos-pro' ),
				),
				'notify_on_failure'    => array(
					'type'        => 'boolean',
					'description' => __( 'Notify the admin email on failure. Defaults to true.', 'mcp-ai-wpoos-pro' ),
				),
				'notify_email'         => array(
					'type'        => 'string',
					'description' => __( 'Email address for failure notifications. Defaults to admin email.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'              => array(
					'type'        => 'boolean',
					'description' => __( 'When true, return the parsed plan without persisting any schedules.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}


	/**

	 * Execute the tool.

	 * @param array $arguments Tool arguments.

	 *  * @param array $context   Execution context.
	 *
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to plan schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Collect workflow items from either workflow_items or workflow_text.
		$items = array();

		if ( isset( $arguments['workflow_items'] ) && is_array( $arguments['workflow_items'] ) ) {
			$items = $arguments['workflow_items'];
		}

		$current_category = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';

		if ( isset( $arguments['workflow_text'] ) && is_string( $arguments['workflow_text'] ) && '' !== trim( $arguments['workflow_text'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $arguments['workflow_text'] );
			if ( is_array( $lines ) ) {
				$active_category = $current_category;
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					// Heading marker: "## Marketing" → set current category for following lines.
					if ( 0 === strpos( $line, '## ' ) ) {
						$active_category = sanitize_text_field( trim( substr( $line, 3 ) ) );
						continue;
					}
					// Strip common list bullets / numbering.
					$line = preg_replace( '/^( ? ( :[-*•]|\d+[\.\)])\s+/', '', $line );
					$line = trim( (string) $line );
					if ( '' === $line ) {
						continue;
					}
					$items[] = array(
						'title'       => $line,
						'description' => $line,
						'tags'        => '' !== $active_category ? array( $active_category ) : array(),
					);
				}
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error(
				'no_workflow_items',
				__( 'Provide at least one workflow item via workflow_items or workflow_text.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Defaults.
		$default_cadence = isset( $arguments['default_cadence'] ) ? sanitize_key( $arguments['default_cadence'] ) : 'daily';
		$valid_cadences  = array_merge( array( 'single' ), array_keys( wp_get_schedules() ) );
		if ( ! in_array( $default_cadence, $valid_cadences, true ) ) {
			$default_cadence = 'daily';
		}

		$default_time = isset( $arguments['default_time'] ) ? sanitize_text_field( $arguments['default_time'] ) : '09:00';
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $default_time ) ) {
			$default_time = '09:00';
		}

		$default_assistant_id = isset( $arguments['default_assistant_id'] ) ? absint( $arguments['default_assistant_id'] ) : 0;
		if ( $default_assistant_id > 0 && 'publish' !== get_post_status( $default_assistant_id ) ) {
			$default_assistant_id = 0;
		}

		$notify_on_failure = isset( $arguments['notify_on_failure'] ) ? (bool) $arguments['notify_on_failure'] : true;
		$notify_email      = isset( $arguments['notify_email'] ) && '' !== $arguments['notify_email']
			? sanitize_email( $arguments['notify_email'] )
			: get_option( 'admin_email' );

		$dry_run  = ! empty( $arguments['dry_run'] );
		$category = $current_category;

		$created = array();
		$skipped = array();
		$errors  = array();
		$plan    = array();

		$base_offset = 0;

		foreach ( $items as $raw_item ) {
			$normalized = $this->normalize_item(
				$raw_item,
				array(
					'category'             => $category,
					'default_cadence'      => $default_cadence,
					'default_time'         => $default_time,
					'default_assistant_id' => $default_assistant_id,
					'notify_on_failure'    => $notify_on_failure,
					'notify_email'         => $notify_email,
					'base_offset'          => $base_offset,
				)
			);

			if ( is_wp_error( $normalized ) ) {
				$errors[] = array(
					'item'    => is_array( $raw_item ) ? $raw_item : array( 'title' => (string) $raw_item ),
					'message' => $normalized->get_error_message(),
				);
				continue;
			}

			// Spread schedules across distinct first-run timestamps so single-type.
			// schedules don't collide on the same MD5 id key.
			$base_offset += 60;

			$plan[] = $normalized;

			if ( $dry_run ) {
				continue;
			}

			$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $normalized, $user_id );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'item'    => $normalized,
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				);
				continue;
			}

			$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $result );
			$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $result );

			$created[] = array(
				'schedule_id' => $result,
				'name'        => isset( $schedule['name'] ) ? $schedule['name'] : $normalized['name'],
				'cadence'     => isset( $schedule['schedule'] ) ? $schedule['schedule'] : $normalized['schedule'],
				'next_run'    => $next_run ? wp_date( DATE_ATOM, $next_run ) : null,
				'tags'        => isset( $schedule['tags'] ) ? $schedule['tags'] : $normalized['tags'],
			);
		}

		return array(
			'plan'    => $plan,
			'created' => $created,
			'skipped' => $skipped,
			'errors'  => $errors,
			'summary' => array(
				'total'    => count( $items ),
				'planned'  => count( $plan ),
				'created'  => count( $created ),
				'errors'   => count( $errors ),
				'dry_run'  => $dry_run,
				'category' => $category,
			),
			'message' => $dry_run
				? __( 'Preview plan generated. No schedules were persisted.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: 1: created count, 2: total count */
					__( 'Created %1$d of %2$d planned schedules.', 'mcp-ai-wpoos-pro' ),
					count( $created ),
					count( $plan )
				),
		);
	}

	/**
	 * Normalize a single workflow item into a schedule payload accepted by
	 * WP_MCP_AI_Pro_Schedule_Manager::create_schedule().
	 *
	 * @param mixed $raw      Raw item (string or array).
	 * @param array $defaults Defaults (category, default_cadence, default_time, ...).
	 * @return array|WP_Error Schedule payload, or WP_Error if the item is invalid.
	 */
	protected function normalize_item( $raw, array $defaults ) {
		// Coerce to array.
		if ( is_string( $raw ) ) {
			$item = array(
				'title'       => $raw,
				'description' => $raw,
			);
		} elseif ( is_array( $raw ) ) {
			$item = $raw;
		} else {
			return new WP_Error( 'invalid_item', __( 'Workflow item must be a string or object.', 'mcp-ai-wpoos-pro' ) );
		}

		$title       = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$description = isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : $title;

		if ( '' === $title && '' !== $description ) {
			$title = $description;
		}

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'Workflow item must have a title or description.', 'mcp-ai-wpoos-pro' ) );
		}

		// Truncate name to 80 chars.
		$name = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 80 ) : substr( $title, 0, 80 );

		// Cadence: explicit > inferred > default.
		$cadence = '';
		if ( ! empty( $item['suggested_cadence'] ) ) {
			$cadence = sanitize_key( $item['suggested_cadence'] );
		}
		if ( '' === $cadence ) {
			$cadence = $this->infer_cadence( $title . ' ' . $description, $defaults['default_cadence'] );
		}
		$valid_cadences = array_merge( array( 'single' ), array_keys( wp_get_schedules() ) );
		if ( ! in_array( $cadence, $valid_cadences, true ) ) {
			$cadence = $defaults['default_cadence'];
		}

		// Time: explicit > default.
		$time = '';
		if ( ! empty( $item['suggested_time'] ) && is_string( $item['suggested_time'] )
			&& preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $item['suggested_time'] )
		) {
			$time = $item['suggested_time'];
		}
		if ( '' === $time ) {
			$time = $defaults['default_time'];
		}

		$timestamp = $this->compute_first_run_timestamp( $time );

		// Spread successive items so single-type schedule IDs (md5 of name+ts) don't collide.
		if ( ! empty( $defaults['base_offset'] ) ) {
			$timestamp += (int) $defaults['base_offset'];
		}

		// Priority: explicit > inferred > 5.
		if ( isset( $item['priority'] ) ) {
			$priority = max( 1, min( 10, (int) $item['priority'] ) );
		} else {
			$priority = $this->infer_priority( $title . ' ' . $description );
		}

		// Tags.
		$tags = array( 'planned-from-workflow' );
		if ( ! empty( $defaults['category'] ) ) {
			$tags[] = sanitize_text_field( $defaults['category'] );
		}
		if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
			foreach ( $item['tags'] as $tag ) {
				$tag = sanitize_text_field( (string) $tag );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}
		$tags = array_values( array_unique( $tags ) );

		// Schedule type: assistant_run when an assistant is configured, else generic task.
		if ( $defaults['default_assistant_id'] > 0 ) {
			$schedule_type = 'assistant_run';
			$payload       = array(
				'schedule_type'    => 'assistant_run',
				'assistant_config' => array(
					'assistant_id' => (int) $defaults['default_assistant_id'],
					'message'      => sprintf(
						/* translators: %s: workflow line */
						__( "It's time for the following recurring responsibility:\n\n%s\n\nPlease proceed and report back when complete.", 'mcp-ai-wpoos-pro' ),
						$description
					),
				),
			);
		} else {
			$schedule_type = 'task';
			$payload       = array(
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_workflow_reminder',
				'args'          => array(
					wp_json_encode(
						array(
							'title'       => $title,
							'description' => $description,
							'tags'        => $tags,
						)
					),
				),
			);
		}

		$payload['name']              = $name;
		$payload['description']       = $description;
		$payload['schedule']          = $cadence;
		$payload['timestamp']         = $timestamp;
		$payload['enabled']           = true;
		$payload['priority']          = $priority;
		$payload['tags']              = $tags;
		$payload['notify_on_failure'] = (bool) $defaults['notify_on_failure'];
		$payload['notify_email']      = $defaults['notify_email'];

		return $payload;
	}

	/**
	 * Infer a recurrence cadence from natural-language keywords.
	 *
	 * @param string $text     Combined item text.
	 * @param string $fallback Default cadence.
	 * @return string Cadence slug.
	 */
	protected function infer_cadence( $text, $fallback ) {
		$text = strtolower( (string) $text );

		$rules = array(
			'hourly'     => array( 'hourly', 'every hour' ),
			'twicedaily' => array( 'twice a day', 'twice daily' ),
			'daily'      => array( 'daily', 'each day', 'every day', 'morning routine' ),
			'weekly'     => array( 'weekly', 'each week', 'every week', 'monday', 'friday' ),
			'monthly'    => array( 'monthly', 'each month', 'every month' ),
		);

		// Match in order of specificity (more specific first).
		$valid_cadences = array_merge( array( 'single' ), array_keys( wp_get_schedules() ) );
		foreach ( array( 'hourly', 'twicedaily', 'monthly', 'weekly', 'daily' ) as $candidate ) {
			if ( ! in_array( $candidate, $valid_cadences, true ) || ! isset( $rules[ $candidate ] ) ) {
				continue;
			}
			foreach ( $rules[ $candidate ] as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					return $candidate;
				}
			}
		}

		// Heuristics for follow-up / review activities → daily.
		if ( in_array( 'daily', $valid_cadences, true )
			&& preg_match( '/\b(follow.?up|review|check|respond|monitor)\b/', $text )
		) {
			return 'daily';
		}

		return in_array( $fallback, $valid_cadences, true ) ? $fallback : 'daily';
	}

	/**
	 * Infer a numeric priority (1 = highest, 10 = lowest) from keywords.
	 *
	 * @param string $text Combined item text.
	 * @return int Priority 1-10.
	 */
	protected function infer_priority( $text ) {
		$text = strtolower( (string) $text );

		if ( preg_match( '/\b(urgent|asap|critical|emergency)\b/', $text ) ) {
			return 1;
		}
		if ( preg_match( '/\b(approval|approvals|sign.?off|escalat)\b/', $text ) ) {
			return 2;
		}
		if ( preg_match( '/\b(follow.?up|payment|invoice|deadline)\b/', $text ) ) {
			return 3;
		}
		if ( preg_match( '/\b(review|coordinate|plan|schedule)\b/', $text ) ) {
			return 5;
		}
		return 5;
	}

	/**
	 * Compute the next-occurrence timestamp for the requested HH:MM in site timezone.
	 *
	 * @param string $time HH:MM in 24h format.
	 * @return int Unix timestamp (UTC).
	 */
	protected function compute_first_run_timestamp( $time ) {
		$parts  = explode( ':', $time );
		$hour   = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 9;
		$minute = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;

		$tz       = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now_site = new DateTime( 'now', $tz );
		$target   = clone $now_site;
		$target->setTime( $hour, $minute, 0 );

		// If the requested time today has already passed (with a 60-second buffer), schedule tomorrow.
		if ( $target->getTimestamp() <= ( $now_site->getTimestamp() + 60 ) ) {
			$target->modify( '+1 day' );
		}

		return $target->getTimestamp();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'requires-capability',
			'state-changing',
			'bulk',
			'async-capable',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Extended tool definition.
	 */
	public function get_extended_definition() {
		return array(
			'name'              => $this->get_name(),
			'slug'              => $this->get_slug(),
			'description'       => $this->get_description(),
			'parameters_schema' => $this->get_parameters_schema(),
			'capability_flags'  => $this->get_capability_flags(),
			'toolkit'           => 'schedule-manager',
			'category'          => 'automation',
			'risk_level'        => 'standard',
		);
	}
}
