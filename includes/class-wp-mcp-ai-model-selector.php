<?php
/**
 * Model selector for intelligent routing between models.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intelligently selects between gpt-4o-mini and gpt-4o based on task complexity.
 */
class WP_MCP_AI_Model_Selector {

	/**
	 * Token threshold for considering a task "long-form".
	 * Tasks with more input tokens than this will use gpt-4o.
	 */
	const LONG_FORM_TOKEN_THRESHOLD = 4000;

	/**
	 * Keywords that indicate complex tasks requiring gpt-4o.
	 *
	 * @var array
	 */
	protected static $complex_keywords = array(
		'analyze',
		'analyze',
		'detailed analysis',
		'comprehensive',
		'in-depth',
		'thorough',
		'research',
		'complex',
		'sophisticated',
		'advanced',
		'expert',
		'professional',
		'write a long',
		'write an article',
		'write a detailed',
		'create a comprehensive',
	);

	/**
	 * Select the appropriate model based on task complexity.
	 *
	 * @param array  $messages   Messages array.
	 * @param array  $options    Request options.
	 * @param string $base_model Default/base model if specified.
	 *
	 * @return string Selected model identifier.
	 */
	public static function select_model( array $messages, array $options = array(), $base_model = '' ) {
		// If a specific model is already set and it's not a routing placeholder, use it.
		if ( ! empty( $options['model'] ) && ! self::is_routing_placeholder( $options['model'] ) ) {
			return sanitize_text_field( $options['model'] );
		}

		// If base_model is provided and not a routing placeholder, use it.
		if ( ! empty( $base_model ) && ! self::is_routing_placeholder( $base_model ) ) {
			return sanitize_text_field( $base_model );
		}

		// Check if auto-routing is disabled.
		if ( isset( $options['disable_auto_routing'] ) && $options['disable_auto_routing'] ) {
			return self::get_default_light_model();
		}

		// Determine complexity based on various factors.
		$is_complex = self::is_complex_task( $messages, $options );

		if ( $is_complex ) {
			WP_MCP_AI_Logger::log_event(
				'model_routing_complex',
				'Routing to gpt-4o for complex/long-form task.',
				array(
					'reason' => self::get_complexity_reason( $messages, $options ),
				)
			);
			return 'gpt-4o';
		}

		WP_MCP_AI_Logger::log_event(
			'model_routing_light',
			'Routing to gpt-4o-mini for light task.',
			array()
		);

		return self::get_default_light_model();
	}

	/**
	 * Check if the provided model is a routing placeholder.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return bool True if it's a routing placeholder.
	 */
	protected static function is_routing_placeholder( $model ) {
		$model              = strtolower( sanitize_text_field( $model ) );
		$routing_keywords   = array( 'auto', 'smart', 'intelligent', 'adaptive' );
		
		foreach ( $routing_keywords as $keyword ) {
			if ( false !== strpos( $model, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine if a task is complex and requires gpt-4o.
	 *
	 * @param array $messages Messages array.
	 * @param array $options  Request options.
	 *
	 * @return bool True if task is complex.
	 */
	protected static function is_complex_task( array $messages, array $options ) {
		// Check token count - long-form content needs gpt-4o.
		$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens(
			wp_json_encode( $messages )
		);

		if ( $token_count > self::LONG_FORM_TOKEN_THRESHOLD ) {
			return true;
		}

		// Check for explicit complexity flag.
		if ( isset( $options['use_advanced_model'] ) && $options['use_advanced_model'] ) {
			return true;
		}

		// Check for complex keywords in the latest user message.
		$latest_user_message = self::get_latest_user_message( $messages );
		if ( $latest_user_message && self::contains_complex_keywords( $latest_user_message ) ) {
			return true;
		}

		// Check if tools are involved (may indicate complex workflow).
		if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) && count( $options['tools'] ) > 3 ) {
			return true;
		}

		// Check if response_format is structured (indicates complex output).
		if ( ! empty( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the latest user message from messages array.
	 *
	 * @param array $messages Messages array.
	 *
	 * @return string|null Latest user message content or null.
	 */
	protected static function get_latest_user_message( array $messages ) {
		// Iterate in reverse to find the most recent user message.
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$message = $messages[ $i ];

			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}

			if ( 'user' === $message['role'] && isset( $message['content'] ) ) {
				if ( is_string( $message['content'] ) ) {
					return $message['content'];
				}

				if ( is_array( $message['content'] ) ) {
					$text_parts = array();
					foreach ( $message['content'] as $segment ) {
						if ( is_array( $segment ) && isset( $segment['text'] ) ) {
							$text_parts[] = $segment['text'];
						}
					}
					return implode( ' ', $text_parts );
				}
			}
		}

		return null;
	}

	/**
	 * Check if message contains complex task keywords.
	 *
	 * @param string $message Message content.
	 *
	 * @return bool True if contains complex keywords.
	 */
	protected static function contains_complex_keywords( $message ) {
		$message = strtolower( $message );

		foreach ( self::$complex_keywords as $keyword ) {
			if ( false !== strpos( $message, strtolower( $keyword ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get reason for complexity determination (for logging).
	 *
	 * @param array $messages Messages array.
	 * @param array $options  Request options.
	 *
	 * @return string Reason description.
	 */
	protected static function get_complexity_reason( array $messages, array $options ) {
		$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens(
			wp_json_encode( $messages )
		);

		if ( $token_count > self::LONG_FORM_TOKEN_THRESHOLD ) {
			return sprintf( 'high_token_count (%d tokens)', $token_count );
		}

		if ( isset( $options['use_advanced_model'] ) && $options['use_advanced_model'] ) {
			return 'explicit_advanced_flag';
		}

		$latest_user_message = self::get_latest_user_message( $messages );
		if ( $latest_user_message && self::contains_complex_keywords( $latest_user_message ) ) {
			return 'complex_keywords_detected';
		}

		if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) && count( $options['tools'] ) > 3 ) {
			return sprintf( 'multiple_tools (%d tools)', count( $options['tools'] ) );
		}

		if ( ! empty( $options['response_format'] ) ) {
			return 'structured_output_requested';
		}

		return 'unknown';
	}

	/**
	 * Get default light model for simple tasks.
	 *
	 * @return string Model identifier.
	 */
	protected static function get_default_light_model() {
		/**
		 * Filter the default light model.
		 *
		 * @param string $model Default light model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_default_light_model', 'gpt-4o-mini' );
	}

	/**
	 * Get default advanced model for complex tasks.
	 *
	 * @return string Model identifier.
	 */
	protected static function get_default_advanced_model() {
		/**
		 * Filter the default advanced model.
		 *
		 * @param string $model Default advanced model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_default_advanced_model', 'gpt-4o' );
	}
}
