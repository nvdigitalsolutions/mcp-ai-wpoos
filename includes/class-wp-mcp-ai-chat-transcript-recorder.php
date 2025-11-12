<?php
/**
 * Persist chat transcripts to the JetEngine Custom Content Type.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle persistence of chat requests and responses.
 */
class WP_MCP_AI_Chat_Transcript_Recorder {
	const MAX_SESSION_KEY_LENGTH = 96;

	/**
	 * Record a chat transcript when storage is enabled.
	 *
	 * @param int             $assistant_id Assistant identifier.
	 * @param array           $messages     Sanitised chat messages.
	 * @param array           $options      Prepared chat options.
	 * @param array           $response     Language model response payload.
	 * @param WP_REST_Request $request      REST request instance.
	 * @param int             $user_id      WordPress user ID.
	 * @param array           $context      Additional context such as timings and session key.
	 */
	public static function record( $assistant_id, array $messages, array $options, array $response, $request, $user_id, array $context = array() ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return;
		}

		$assistant_id = absint( $assistant_id );
		$user_id      = absint( $user_id );

		if ( ! $assistant_id || empty( $messages ) || empty( $response ) ) {
			return;
		}

		if ( ! self::should_record( $assistant_id, $messages, $options, $response, $request, $context ) ) {
			return;
		}

		$handler = self::resolve_handler( $assistant_id, $messages, $options, $response, $request, $context );

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return;
		}

		$record = self::build_record( $assistant_id, $messages, $options, $response, $request, $user_id, $context );

		if ( empty( $record ) || ! is_array( $record ) ) {
			return;
		}

		/**
		 * Allow extensions to adjust the transcript payload before storage.
		 *
		 * Returning an empty value prevents the transcript from being saved.
		 *
		 * @param array           $record       Prepared transcript payload.
		 * @param int             $assistant_id Assistant identifier.
		 * @param array           $messages     Sanitised chat messages.
		 * @param array           $options      Prepared chat options.
		 * @param array           $response     Language model response payload.
		 * @param WP_REST_Request $request      REST request instance.
		 * @param array           $context      Additional context (timings, session key, etc.).
		 */
		$record = apply_filters( 'wp_mcp_ai_chat_transcript_record', $record, $assistant_id, $messages, $options, $response, $request, $context );

		if ( empty( $record ) || ! is_array( $record ) ) {
			return;
		}

		try {
			$result = $handler->update_item( $record );

			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to persist chat transcript to JetEngine.',
					array(
						'assistant_id'  => $assistant_id,
						'user_id'       => $user_id,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);
			}
		} catch ( Throwable $exception ) {
			WP_MCP_AI_Logger::log_error(
				'Unexpected error while saving chat transcript.',
				array(
					'assistant_id' => $assistant_id,
					'user_id'      => $user_id,
					'exception'    => $exception->getMessage(),
				)
			);
		}
	}

	/**
	 * Determine whether the transcript should be persisted.
	 *
	 * @param int             $assistant_id Assistant identifier.
	 * @param array           $messages     Sanitised chat messages.
	 * @param array           $options      Prepared chat options.
	 * @param array           $response     Language model response payload.
	 * @param WP_REST_Request $request      REST request instance.
	 * @param array           $context      Additional context.
	 * @return bool
	 */
	protected static function should_record( $assistant_id, array $messages, array $options, array $response, WP_REST_Request $request, array $context ) {
		$save_transcript = true;

		if ( array_key_exists( 'save_transcript', $context ) ) {
			$save_transcript = self::to_bool( $context['save_transcript'] );
		} else {
			$param = $request->get_param( 'save_transcript' );
			if ( null !== $param ) {
				$save_transcript = self::to_bool( $param );
			}
		}

		/**
		 * Filter whether the current chat transcript should be saved.
		 *
		 * @param bool            $save_transcript Whether the transcript should be persisted.
		 * @param int             $assistant_id    Assistant identifier.
		 * @param array           $messages        Sanitised chat messages.
		 * @param array           $options         Prepared chat options.
		 * @param array           $response        Language model response payload.
		 * @param WP_REST_Request $request         REST request instance.
		 * @param array           $context         Additional context (timings, session key, etc.).
		 */
		$save_transcript = apply_filters( 'wp_mcp_ai_save_chat_transcript', $save_transcript, $assistant_id, $messages, $options, $response, $request, $context );

		return (bool) $save_transcript;
	}

	/**
	 * Resolve the JetEngine handler responsible for storing the transcript.
	 *
	 * @param int             $assistant_id Assistant identifier.
	 * @param array           $messages     Sanitised chat messages.
	 * @param array           $options      Prepared chat options.
	 * @param array           $response     Language model response payload.
	 * @param WP_REST_Request $request      REST request instance.
	 * @param array           $context      Additional context.
	 * @return object|null
	 */
	protected static function resolve_handler( $assistant_id, array $messages, array $options, array $response, WP_REST_Request $request, array $context ) {
		/**
		 * Allow integrations to provide a custom storage handler for chat transcripts.
		 *
		 * Returning a truthy value short-circuits the default JetEngine handler lookup.
		 *
		 * @param object|null     $handler       Custom handler instance.
		 * @param int             $assistant_id  Assistant identifier.
		 * @param array           $messages      Sanitised chat messages.
		 * @param array           $options       Prepared chat options.
		 * @param array           $response      Language model response payload.
		 * @param WP_REST_Request $request       REST request instance.
		 * @param array           $context       Additional context (timings, session key, etc.).
		 */
		$handler = apply_filters( 'wp_mcp_ai_chat_transcript_handler', null, $assistant_id, $messages, $options, $response, $request, $context );

		if ( $handler ) {
			return $handler;
		}

		if ( class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			return WP_MCP_AI_JetEngine_CCT::get_item_handler();
		}

		return null;
	}

	/**
	 * Build the payload sent to JetEngine for storage.
	 *
	 * @param int             $assistant_id Assistant identifier.
	 * @param array           $messages     Sanitised chat messages.
	 * @param array           $options      Prepared chat options.
	 * @param array           $response     Language model response payload.
	 * @param WP_REST_Request $request      REST request instance.
	 * @param int             $user_id      WordPress user ID.
	 * @param array           $context      Additional context.
	 * @return array
	 */
	protected static function build_record( $assistant_id, array $messages, array $options, array $response, WP_REST_Request $request, $user_id, array $context ) {
		$session_key = '';
		if ( ! empty( $context['session_key'] ) ) {
			$session_key = self::normalise_session_key( $context['session_key'] );
		}

		if ( '' === $session_key ) {
			$session_key = self::normalise_session_key( $request->get_param( 'session_key' ) );
		}

		if ( '' === $session_key ) {
			$session_key = self::generate_session_key( $assistant_id );
		}

		$model = self::determine_model( $options, $response );

		$record = array(
			'session_key'      => $session_key,
			'user_id'          => $user_id,
			'assistant_id'     => (string) $assistant_id,
			'assistant_model'  => $model,
			'request_payload'  => self::encode_json(
				array(
					'messages' => $messages,
					'options'  => $options,
				)
			),
			'response_payload' => self::encode_json( $response ),
		);

		$metadata = self::build_metadata( $options, $response, $context );
		if ( ! empty( $metadata ) ) {
			$record['metadata'] = self::encode_json( $metadata );
		}

		$latency = self::calculate_latency( $context );
		if ( null !== $latency ) {
			$record['latency_ms'] = $latency;
		}

		$started_at = self::format_timestamp_from_context( $context, 'request_started_at' );
		if ( 0 !== $started_at ) {
			$record['request_started_at'] = $started_at;
		}

		$completed_at = self::format_timestamp_from_context( $context, 'response_completed_at' );
		if ( 0 !== $completed_at ) {
			$record['response_completed_at'] = $completed_at;
		}

		return $record;
	}

	/**
	 * Determine the language model identifier used for the response.
	 *
	 * @param array $options  Prepared chat options.
	 * @param array $response Language model response payload.
	 * @return string
	 */
	protected static function determine_model( array $options, array $response ) {
		if ( isset( $response['model'] ) && is_string( $response['model'] ) ) {
			$model = sanitize_text_field( $response['model'] );
		} elseif ( isset( $options['model'] ) && is_string( $options['model'] ) ) {
			$model = sanitize_text_field( $options['model'] );
		} else {
			$model = 'unknown-model';
		}

		return $model;
	}

	/**
	 * Assemble metadata stored alongside the transcript record.
	 *
	 * @param array $options  Prepared chat options.
	 * @param array $response Language model response payload.
	 * @param array $context  Additional context.
	 * @return array
	 */
	protected static function build_metadata( array $options, array $response, array $context ) {
		$metadata = array();

		if ( isset( $response['provider'] ) && is_string( $response['provider'] ) ) {
			$metadata['provider'] = sanitize_key( $response['provider'] );
		} elseif ( isset( $options['provider'] ) && is_string( $options['provider'] ) ) {
			$metadata['provider'] = sanitize_key( $options['provider'] );
		}

		if ( isset( $response['status'] ) && is_string( $response['status'] ) ) {
			$metadata['status'] = sanitize_key( $response['status'] );
		}

		if ( isset( $response['id'] ) && is_string( $response['id'] ) ) {
			$metadata['response_id'] = sanitize_text_field( $response['id'] );
		}

		$finish_reasons = self::extract_finish_reasons( $response );
		if ( ! empty( $finish_reasons ) ) {
			$metadata['finish_reasons'] = $finish_reasons;
		}

		$usage = self::get_property( $response, 'usage' );
		if ( ! empty( $usage ) && ( is_array( $usage ) || is_object( $usage ) ) ) {
			$metadata['usage'] = self::to_array( $usage );
		}

		if ( isset( $context['session_key'] ) && '' !== $context['session_key'] ) {
			$metadata['session_key_raw'] = self::normalise_session_key( $context['session_key'] );
		}

		return $metadata;
	}

	/**
	 * Extract finish reasons from the response payload.
	 *
	 * @param array $response Language model response payload.
	 * @return array
	 */
	protected static function extract_finish_reasons( array $response ) {
		$choices = self::get_property( $response, 'choices' );

		if ( empty( $choices ) ) {
			return array();
		}

		// Handle both arrays and objects (from JSON parsing).
		if ( ! is_array( $choices ) && ! is_object( $choices ) ) {
			return array();
		}

		$choices_array = self::to_array( $choices );
		$reasons       = array();

		foreach ( $choices_array as $choice ) {
			// Handle both array and stdClass objects for individual choices.
			$finish_reason = self::get_property( $choice, 'finish_reason' );

			if ( ! empty( $finish_reason ) && is_string( $finish_reason ) ) {
				$reason = sanitize_key( $finish_reason );
				if ( '' !== $reason ) {
					$reasons[] = $reason;
				}
			}
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Calculate the response latency in milliseconds when timing data is available.
	 *
	 * @param array $context Additional context data.
	 * @return int|null
	 */
	protected static function calculate_latency( array $context ) {
		if ( empty( $context['request_started_at'] ) || empty( $context['response_completed_at'] ) ) {
			return null;
		}

		$start = (float) $context['request_started_at'];
		$end   = (float) $context['response_completed_at'];

		if ( $end <= $start ) {
			return null;
		}

		$latency = ( $end - $start ) * 1000;

		return max( 0, (int) round( $latency ) );
	}

	/**
	 * Format a timestamp stored in the context array into a Unix timestamp.
	 *
	 * JetEngine datetime-local fields with is_timestamp=true expect Unix timestamps (integers).
	 *
	 * @param array  $context Context array containing timestamp data.
	 * @param string $key     Array key that contains the timestamp.
	 * @return int Unix timestamp, or 0 if invalid.
	 */
	protected static function format_timestamp_from_context( array $context, $key ) {
		if ( empty( $context[ $key ] ) ) {
			return 0;
		}

		$timestamp = (float) $context[ $key ];

		if ( $timestamp <= 0 ) {
			return 0;
		}

		return (int) floor( $timestamp );
	}

	/**
	 * Normalise a session key into a safe identifier.
	 *
	 * @param mixed $value Raw session key value.
	 * @return string
	 */
	protected static function normalise_session_key( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = substr( $value, 0, self::MAX_SESSION_KEY_LENGTH );

		return $value;
	}

	/**
	 * Generate a fallback session key when none was provided.
	 *
	 * @param int $assistant_id Assistant identifier.
	 * @return string
	 */
	protected static function generate_session_key( $assistant_id ) {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return self::normalise_session_key( 'wp-mcp-ai-' . wp_generate_uuid4() );
		}

		return self::normalise_session_key( 'wp-mcp-ai-' . $assistant_id . '-' . uniqid() );
	}

	/**
	 * Encode data as JSON suitable for storage.
	 *
	 * @param mixed $data Arbitrary data.
	 * @return string
	 */
	protected static function encode_json( $data ) {
		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $encoded ) {
			$encoded = wp_json_encode( $data );
		}

		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Safely convert a value to boolean semantics.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return wp_validate_boolean( $value );
		}

		if ( is_numeric( $value ) ) {
			return (bool) (int) $value;
		}

		return ! empty( $value );
	}

	/**
	 * Safely get a property value from an array or object.
	 *
	 * Handles both arrays and stdClass objects that may result from JSON parsing.
	 *
	 * @param array|object $data Array or object to access.
	 * @param string       $key  Property or array key to retrieve.
	 * @return mixed Property value, or null if not found.
	 */
	protected static function get_property( $data, $key ) {
		if ( is_array( $data ) ) {
			return isset( $data[ $key ] ) ? $data[ $key ] : null;
		}

		if ( is_object( $data ) ) {
			return isset( $data->$key ) ? $data->$key : null;
		}

		return null;
	}

	/**
	 * Convert an array or object to an array.
	 *
	 * Handles stdClass objects that may result from JSON parsing without the associative flag.
	 *
	 * @param mixed $data Data to convert to array.
	 * @return array Converted array, or original data if already an array.
	 */
	protected static function to_array( $data ) {
		if ( is_array( $data ) ) {
			return $data;
		}

		if ( is_object( $data ) ) {
			return (array) $data;
		}

		return array();
	}
}
