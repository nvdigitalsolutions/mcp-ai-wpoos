<?php
/**
 * Tool that schedules a Notify.lk SMS using the official PHP SDK.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

use NotifyLk\Api\SmsApi;
use NotifyLk\ApiException;

/**
 * Provides a tool for scheduling Notify.lk SMS messages.
 */
class WP_MCP_AI_Tool_Schedule_Notify_SMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const CRON_HOOK = 'wp_mcp_ai_notifylk_send_scheduled_sms';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_scheduled_sms' ), 10, 1 );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool Whether the NotifyLk SDK is available.
	 */
	public static function is_available() {
		return class_exists( 'NotifyLk\Api\SmsApi' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string Explanation message.
	 */
	public static function get_unavailable_reason() {
		return __( 'The Schedule Notify.lk SMS tool is disabled because the notifylk/notify-php package is not installed.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'schedule_notify_sms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Schedule Notify.lk SMS', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules an SMS through Notify.lk to be sent at a future time using the official PHP SDK.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'notify_user_id'  => array(
					'type'        => 'string',
					'description' => __( 'Notify.lk API user ID.', 'wp-mcp-ai' ),
				),
				'notify_api_key'  => array(
					'type'        => 'string',
					'description' => __( 'Notify.lk API key.', 'wp-mcp-ai' ),
				),
				'sender_id'       => array(
					'type'        => 'string',
					'description' => __( 'Sender ID that will appear on the SMS.', 'wp-mcp-ai' ),
				),
				'recipient'       => array(
					'type'        => 'string',
					'description' => __( 'Phone number of the recipient in international format (e.g. 9471XXXXXXX).', 'wp-mcp-ai' ),
				),
				'message'         => array(
					'type'        => 'string',
					'description' => __( 'Message body to deliver to the recipient.', 'wp-mcp-ai' ),
				),
				'schedule_time'   => array(
					'type'        => 'string',
					'description' => __( 'Future time when the SMS should be sent. Accepts ISO 8601 or natural language such as "2024-07-01 14:30".', 'wp-mcp-ai' ),
				),
				'timezone'        => array(
					'type'        => 'string',
					'description' => __( 'Optional timezone identifier for the schedule time (defaults to the site timezone).', 'wp-mcp-ai' ),
				),
				'contact_fname'   => array(
					'type'        => 'string',
					'description' => __( 'Optional first name saved alongside the contact in Notify.', 'wp-mcp-ai' ),
				),
				'contact_lname'   => array(
					'type'        => 'string',
					'description' => __( 'Optional last name saved alongside the contact in Notify.', 'wp-mcp-ai' ),
				),
				'contact_email'   => array(
					'type'        => 'string',
					'description' => __( 'Optional email saved alongside the contact in Notify.', 'wp-mcp-ai' ),
				),
				'contact_address' => array(
					'type'        => 'string',
					'description' => __( 'Optional address saved alongside the contact in Notify.', 'wp-mcp-ai' ),
				),
				'contact_group'   => array(
					'type'        => 'integer',
					'description' => __( 'Optional Notify.lk contact group ID.', 'wp-mcp-ai' ),
				),
				'unicode'         => array(
					'type'        => 'boolean',
					'description' => __( 'Send the SMS as unicode to support extended characters.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'notify_user_id', 'notify_api_key', 'sender_id', 'recipient', 'message', 'schedule_time' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_schedule_notify_sms_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to schedule Notify.lk SMS messages.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$notify_user_id = isset( $arguments['notify_user_id'] ) ? $this->sanitize_identifier( $arguments['notify_user_id'] ) : '';
		$notify_api_key = isset( $arguments['notify_api_key'] ) ? $this->sanitize_api_key( $arguments['notify_api_key'] ) : '';
		$sender_id      = isset( $arguments['sender_id'] ) ? $this->sanitize_sender_id( $arguments['sender_id'] ) : '';
		$recipient      = isset( $arguments['recipient'] ) ? $this->sanitize_recipient( $arguments['recipient'] ) : '';
		$message        = isset( $arguments['message'] ) ? $this->sanitize_message( $arguments['message'] ) : '';

		if ( '' === $notify_user_id || '' === $notify_api_key || '' === $sender_id || '' === $recipient || '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_required_field', __( 'Notify.lk credentials, sender, recipient and message must all be provided.', 'wp-mcp-ai' ) );
		}

		$timezone_input = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : '';
		$timezone       = $this->determine_timezone( $timezone_input );
		if ( is_wp_error( $timezone ) ) {
			return $timezone;
		}

		$schedule_time_input = isset( $arguments['schedule_time'] ) ? sanitize_text_field( $arguments['schedule_time'] ) : '';
		$schedule_time       = $this->resolve_schedule_time( $schedule_time_input, $timezone );
		if ( is_wp_error( $schedule_time ) ) {
			return $schedule_time;
		}

		$timestamp = $schedule_time->getTimestamp();
		$now       = time();
		if ( $timestamp <= $now ) {
			return new WP_Error( 'wp_mcp_ai_invalid_schedule_time', __( 'The schedule time must be in the future.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$payload = array(
			'user_id'   => $notify_user_id,
			'api_key'   => $notify_api_key,
			'sender_id' => $sender_id,
			'to'        => $recipient,
			'message'   => $message,
		);

		$optional_string_fields = array(
			'contact_fname',
			'contact_lname',
			'contact_address',
		);

		foreach ( $optional_string_fields as $field ) {
			if ( isset( $arguments[ $field ] ) && '' !== trim( (string) $arguments[ $field ] ) ) {
				$payload[ $field ] = sanitize_text_field( $arguments[ $field ] );
			}
		}

		if ( isset( $arguments['contact_email'] ) && '' !== trim( (string) $arguments['contact_email'] ) ) {
			$email = sanitize_email( $arguments['contact_email'] );
			if ( '' !== $email ) {
				$payload['contact_email'] = $email;
			}
		}

		if ( isset( $arguments['contact_group'] ) && '' !== $arguments['contact_group'] ) {
			$payload['contact_group'] = absint( $arguments['contact_group'] );
		}

		if ( ! empty( $arguments['unicode'] ) ) {
			$payload['type'] = 'unicode';
		}

		$schedule_args = array( $payload );

		$existing = wp_next_scheduled( self::CRON_HOOK, $schedule_args );
		if ( false !== $existing && $existing === $timestamp ) {
			return new WP_Error( 'wp_mcp_ai_duplicate_schedule', __( 'An identical Notify.lk SMS is already scheduled for that time.', 'wp-mcp-ai' ), array( 'status' => 409 ) );
		}

		$scheduled = wp_schedule_single_event( $timestamp, self::CRON_HOOK, $schedule_args );
		if ( ! $scheduled ) {
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule the Notify.lk SMS in WordPress cron.', 'wp-mcp-ai' ) );
		}

		$job_id = WP_MCP_AI_Cron_Manager::record_job( self::CRON_HOOK, $payload, 'single', $timestamp, $user_id );

		// Trigger WordPress cron immediately to ensure the SMS job runs.
		// WordPress cron is virtual and only runs on page loads by default.
		spawn_cron();

		WP_MCP_AI_Logger::log_event(
			'notifylk_schedule_sms',
			'Scheduled Notify.lk SMS.',
			array(
				'job_id'        => $job_id,
				'user_id'       => $user_id,
				'sender_id'     => $sender_id,
				'recipient'     => $recipient,
				'scheduled_for' => $schedule_time->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
			)
		);

		return array(
			'job_id'          => $job_id,
			'sender_id'       => $sender_id,
			'recipient'       => $recipient,
			'scheduled_unix'  => $timestamp,
			'scheduled_utc'   => $schedule_time->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
			'scheduled_local' => $schedule_time->format( DATE_ATOM ),
		);
	}

	/**
	 * Process the scheduled SMS when the cron event fires.
	 *
	 * @param array $payload Prepared payload for Notify.lk.
	 */
	public static function process_scheduled_sms( $payload ) {
		if ( ! is_array( $payload ) ) {
			return;
		}

		$payload = array_map( 'wp_unslash', $payload );

		$notify_user_id = isset( $payload['user_id'] ) ? (string) $payload['user_id'] : '';
		$notify_api_key = isset( $payload['api_key'] ) ? (string) $payload['api_key'] : '';
		$message        = isset( $payload['message'] ) ? (string) $payload['message'] : '';
		$recipient      = isset( $payload['to'] ) ? (string) $payload['to'] : '';
		$sender_id      = isset( $payload['sender_id'] ) ? (string) $payload['sender_id'] : '';

		$job_id = self::calculate_job_id_for_payload( $payload );

		if ( '' === $notify_user_id || '' === $notify_api_key || '' === $message || '' === $recipient || '' === $sender_id ) {
			WP_MCP_AI_Logger::log_error(
				'Scheduled Notify.lk SMS payload was incomplete.',
				array(
					'job_id'    => $job_id,
					'recipient' => $recipient,
					'sender_id' => $sender_id,
				)
			);
			if ( $job_id ) {
				WP_MCP_AI_Cron_Manager::remove_job( $job_id );
			}
			return;
		}

		$api = new SmsApi();

		$contact_fname   = isset( $payload['contact_fname'] ) ? (string) $payload['contact_fname'] : null;
		$contact_lname   = isset( $payload['contact_lname'] ) ? (string) $payload['contact_lname'] : null;
		$contact_email   = isset( $payload['contact_email'] ) ? (string) $payload['contact_email'] : null;
		$contact_address = isset( $payload['contact_address'] ) ? (string) $payload['contact_address'] : null;
		$contact_group   = isset( $payload['contact_group'] ) ? (int) $payload['contact_group'] : null;
		$type            = isset( $payload['type'] ) ? (string) $payload['type'] : null;

		try {
			$api->sendSMS(
				$notify_user_id,
				$notify_api_key,
				$message,
				$recipient,
				$sender_id,
				$contact_fname,
				$contact_lname,
				$contact_email,
				$contact_address,
				$contact_group,
				$type
			);

			WP_MCP_AI_Logger::log_event(
				'notifylk_send_sms',
				'Dispatched scheduled Notify.lk SMS.',
				array(
					'job_id'    => $job_id,
					'recipient' => $recipient,
					'sender_id' => $sender_id,
				)
			);
		} catch ( ApiException $exception ) {
			WP_MCP_AI_Logger::log_error(
				'Notify.lk API error while sending scheduled SMS.',
				array(
					'job_id'    => $job_id,
					'recipient' => $recipient,
					'sender_id' => $sender_id,
					'error'     => $exception->getMessage(),
					'status'    => $exception->getCode(),
				)
			);
		} catch ( \Exception $exception ) {
			WP_MCP_AI_Logger::log_error(
				'Unexpected error while sending scheduled Notify.lk SMS.',
				array(
					'job_id'    => $job_id,
					'recipient' => $recipient,
					'sender_id' => $sender_id,
					'error'     => $exception->getMessage(),
				)
			);
		}

		if ( $job_id ) {
			WP_MCP_AI_Cron_Manager::remove_job( $job_id );
		}
	}

	/**
	 * Resolve the schedule time into a DateTimeImmutable instance.
	 *
	 * @param string       $input    User supplied time string.
	 * @param DateTimeZone $timezone Timezone to interpret the string in.
	 * @return DateTimeImmutable|WP_Error
	 */
	protected function resolve_schedule_time( $input, DateTimeZone $timezone ) {
		if ( '' === $input ) {
			return new WP_Error( 'wp_mcp_ai_missing_schedule_time', __( 'A schedule time must be provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		try {
			$candidate = new DateTimeImmutable( $input, $timezone );
		} catch ( \Exception $exception ) {
			$candidate = false;
		}

		if ( false === $candidate ) {
			$timestamp = strtotime( $input );
			if ( false === $timestamp ) {
				return new WP_Error( 'wp_mcp_ai_unparseable_schedule_time', __( 'The provided schedule time could not be parsed.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$candidate = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
		}

		return $candidate;
	}

	/**
	 * Determine the timezone that should be used for scheduling.
	 *
	 * @param string $timezone_string Input timezone identifier.
	 * @return DateTimeZone|WP_Error
	 */
	protected function determine_timezone( $timezone_string ) {
		$timezone_string = trim( (string) $timezone_string );

		if ( '' === $timezone_string ) {
			$wp_timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
			if ( $wp_timezone instanceof DateTimeZone ) {
				return $wp_timezone;
			}

			$blog_timezone = get_option( 'timezone_string' );
			if ( $blog_timezone ) {
				try {
					return new DateTimeZone( $blog_timezone );
				} catch ( \Exception $exception ) {
					// Fall through to UTC.
				}
			}

			return new DateTimeZone( 'UTC' );
		}

		try {
			return new DateTimeZone( $timezone_string );
		} catch ( \Exception $exception ) {
			return new WP_Error( 'wp_mcp_ai_invalid_timezone', __( 'The supplied timezone is not valid.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Sanitize the Notify.lk user identifier.
	 *
	 * @param string $value Raw identifier.
	 * @return string
	 */
	protected function sanitize_identifier( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
		return trim( $value );
	}

	/**
	 * Sanitize the Notify.lk API key.
	 *
	 * @param string $value Raw API key.
	 * @return string
	 */
	protected function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/\s+/', '', $value );

		return $value;
	}

	/**
	 * Sanitize the sender ID.
	 *
	 * @param string $value Raw sender ID.
	 * @return string
	 */
	protected function sanitize_sender_id( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/[^A-Za-z0-9]/', '', $value );
		return $value;
	}

	/**
	 * Sanitize the recipient number.
	 *
	 * @param string $value Raw recipient number.
	 * @return string
	 */
	protected function sanitize_recipient( $value ) {
		$value = preg_replace( '/[^0-9+]/', '', (string) $value );
		return ltrim( $value, '+' );
	}

	/**
	 * Sanitize the SMS message body.
	 *
	 * @param string $value Raw message.
	 * @return string
	 */
	protected function sanitize_message( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = wp_strip_all_tags( $value, false );
		return trim( $value );
	}

	/**
	 * Calculate the cron job identifier for a payload.
	 *
	 * @param array $payload Scheduled payload.
	 * @return string
	 */
	protected static function calculate_job_id_for_payload( $payload ) {
		$args    = WP_MCP_AI_Cron_Manager::normalise_args( $payload );
		$encoded = wp_json_encode(
			array(
				'hook' => self::CRON_HOOK,
				'args' => $args,
			)
		);

		if ( false === $encoded ) {
			return '';
		}

		return md5( $encoded );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
