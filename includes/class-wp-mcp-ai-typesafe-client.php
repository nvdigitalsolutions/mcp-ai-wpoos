<?php
/**
 * TypeSafe API client wrapper.
 *
 * TypeSafe exposes the "System One" decision API at
 * https://api.typesafe.ai/v1/systemone.  Its Jev model does not generate
 * prose — it evaluates supplied state against typed questions and returns
 * constrained, probabilistic decisions (Choice / Score / Noul) in a single
 * parallel pass.
 *
 * Wire format (flat JSON body):
 *
 *   {
 *     "model":     "jev-1.13.0",
 *     "state":     <string|object|array>,
 *     "questions": {
 *       "<name>": {
 *         "type":         "choice|score|noul",
 *         "instructions": "...",
 *         "criteria":     <choice: map|score: ordered list>
 *       }
 *     }
 *   }
 *
 * Response: { "model": "<versioned-id>", "answers": { "<name>": {...} },
 * "usage": { ... } }.  Billing is input-only (output tokens are free), and
 * the concrete versioned model id that answered is always reported so
 * callers can log it (pin-hygiene: `jev-latest` is a moving alias).
 *
 * @link    https://docs.typesafe.ai/api
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';

if ( ! class_exists( 'WP_MCP_AI_Typesafe_Client' ) ) {
	/**
	 * Provides a wrapper around the TypeSafe System One decision API.
	 *
	 * This client implements {@see Interface_WP_MCP_AI_Decision_Client} —
	 * the decision contract — and must never be used as a chat provider:
	 * Jev cannot generate text, stream, or call tools.
	 */
	class WP_MCP_AI_Typesafe_Client implements Interface_WP_MCP_AI_Decision_Client {

		/**
		 * Default base URL for the TypeSafe API (no trailing slash).
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://api.typesafe.ai';

		/**
		 * System One endpoint path relative to the base URL.
		 *
		 * @var string
		 */
		const API_ENDPOINT = '/v1/systemone';

		/**
		 * User-Agent string sent with every request.
		 *
		 * @var string
		 */
		const USER_AGENT = 'WP-MCP-AI-Typesafe-Client/1.0';

		/**
		 * Default decision model when none is configured.
		 *
		 * `jev-latest` follows TypeSafe's newest release.  Operators are
		 * encouraged to pin an explicit version (e.g. `jev-1.13.0`) in the
		 * settings screen because answers can change under a moving alias.
		 *
		 * @var string
		 */
		const DEFAULT_MODEL = 'jev-latest';

		/**
		 * Question types understood by the System One API.
		 *
		 * @var string[]
		 */
		const QUESTION_TYPES = array( 'choice', 'score', 'noul' );

		/**
		 * Defensive cap on questions per request.  The API evaluates
		 * questions in parallel over the same state; the real budget is the
		 * 64k-token input window, so this only guards against absurd payloads.
		 *
		 * @var int
		 */
		const MAX_QUESTIONS = 64;

		/**
		 * Default maximum HTTP attempts per decision request (429/5xx only).
		 *
		 * @var int
		 */
		const DEFAULT_MAX_ATTEMPTS = 2;

		/**
		 * HTTP status codes that are safe to retry.
		 *
		 * @var int[]
		 */
		const RETRYABLE_STATUS_CODES = array( 429, 500, 502, 503, 504 );

		/**
		 * Transient key prefix for cached decision responses.
		 *
		 * @var string
		 */
		const CACHE_KEY_PREFIX = 'wp_mcp_ai_ts_dec_';

		/**
		 * Default TTL (seconds) for cached decision responses.
		 *
		 * @var int
		 */
		const DEFAULT_CACHE_TTL = 300;

		/**
		 * In-memory API key override. Set via set_api_key().
		 *
		 * @since 2026.09
		 * @var string|null
		 */
		private $api_key_override = null;

		// -------------------------------------------------------------------------
		// Accessors.
		// -------------------------------------------------------------------------

		/**
		 * Retrieve the configured TypeSafe API key.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_api_key() {
			// If a transient API key was set via set_api_key(), use it instead
			// of the persisted setting. This prevents TOCTOU race conditions
			// when testing a key before saving it.
			if ( isset( $this->api_key_override ) && is_string( $this->api_key_override ) ) {
				return $this->api_key_override;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$key      = isset( $settings['typesafe_api_key'] ) ? $settings['typesafe_api_key'] : '';

			if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
				$key = WP_MCP_AI_Credential_Resolver::get_api_key( 'typesafe' ) ?? '';
			}

			return $key;
		}

		/**
		 * Override the API key for the lifetime of this instance only.
		 *
		 * Use this when testing a key before persisting it, instead of
		 * temporarily writing it to wp_options (which creates a TOCTOU
		 * race condition).
		 *
		 * @since 2026.09
		 * @param string $api_key The API key to use for this instance.
		 */
		public function set_api_key( $api_key ) {
			$this->api_key_override = $api_key;
		}

		/**
		 * Retrieve the configured default model.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['typesafe_model'] ) ? $settings['typesafe_model'] : '';
		}

		/**
		 * Retrieve the configured base URL.
		 *
		 * Supports custom proxies via the `typesafe_base_url` setting.
		 * Falls back to {@see DEFAULT_BASE_URL}.
		 *
		 * @return string Base URL without trailing slash.
		 */
		public function get_base_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$base_url = isset( $settings['typesafe_base_url'] ) ? trim( $settings['typesafe_base_url'] ) : '';

			if ( '' === $base_url ) {
				$base_url = self::DEFAULT_BASE_URL;
			}

			return untrailingslashit( $base_url );
		}

		/**
		 * Retrieve the System One endpoint path.
		 *
		 * Supports gateway / reseller routes via the `typesafe_endpoint`
		 * setting (e.g. third parties serving Jev on `/v1/decisions`). Falls
		 * back to {@see API_ENDPOINT}. Filterable via
		 * `wp_mcp_ai_typesafe_endpoint` for code-level overrides.
		 *
		 * @return string Endpoint path with a leading slash.
		 */
		public function get_endpoint() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$endpoint = isset( $settings['typesafe_endpoint'] ) ? trim( (string) $settings['typesafe_endpoint'] ) : '';

			if ( '' === $endpoint ) {
				$endpoint = self::API_ENDPOINT;
			}

			/**
			 * Filter the TypeSafe System One endpoint path.
			 *
			 * @since 2026.09
			 *
			 * @param string $endpoint Endpoint path (leading slash).
			 */
			$endpoint = apply_filters( 'wp_mcp_ai_typesafe_endpoint', $endpoint );

			return '/' . ltrim( $endpoint, '/' );
		}

		// -------------------------------------------------------------------------
		// Core methods.
		// -------------------------------------------------------------------------

		/**
		 * Resolve the model from $options, falling back to the configured default.
		 *
		 * @param array $options Request options.
		 * @return string
		 */
		protected function resolve_model( array $options ) {
			if ( ! empty( $options['model'] ) ) {
				return sanitize_text_field( $options['model'] );
			}

			$model = $this->get_model();

			return ! empty( $model ) ? $model : self::DEFAULT_MODEL;
		}

		/**
		 * Validate a single question definition.
		 *
		 * Enforces the documented question contract:
		 *  - `type` must be one of choice|score|noul.
		 *  - `instructions` must be a non-empty string or structured
		 *    (object/array) per the TypeSafe "Advanced: structure" EntryType.
		 *  - `choice` requires a non-empty criteria map of name => description.
		 *  - `score` requires 2–10 ordered level descriptions.
		 *  - `noul` takes optional criteria with true/false descriptions.
		 *
		 * @param string $name     Question name (used in error messages only).
		 * @param mixed  $question Question definition.
		 * @return true|WP_Error
		 */
		protected function validate_question( $name, $question ) {
			if ( ! is_array( $question ) ) {
				return new WP_Error(
					'wp_mcp_ai_typesafe_invalid_question',
					sprintf(
					/* translators: %s: question name */
						__( 'Question "%s" must be an object with type, instructions, and criteria.', 'mcp-ai-wpoos' ),
						$name
					)
				);
			}

			$type = isset( $question['type'] ) ? sanitize_key( $question['type'] ) : '';
			if ( ! in_array( $type, self::QUESTION_TYPES, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_typesafe_invalid_question_type',
					sprintf(
					/* translators: 1: question name, 2: supported types */
						__( 'Question "%1$s" has an invalid type. Supported types: %2$s.', 'mcp-ai-wpoos' ),
						$name,
						implode( ', ', self::QUESTION_TYPES )
					)
				);
			}

			$instructions = isset( $question['instructions'] ) ? $question['instructions'] : '';
			$is_valid     = ( is_string( $instructions ) && '' !== trim( $instructions ) )
			|| ( is_array( $instructions ) && ! empty( $instructions ) );

			if ( ! $is_valid ) {
				return new WP_Error(
					'wp_mcp_ai_typesafe_missing_instructions',
					sprintf(
					/* translators: %s: question name */
						__( 'Question "%s" is missing a non-empty instructions string or structure.', 'mcp-ai-wpoos' ),
						$name
					)
				);
			}

			if ( 'noul' === $type ) {
				// Optional criteria with true/false boundary descriptions.
				if ( isset( $question['criteria'] ) && ( ! is_array( $question['criteria'] ) || empty( $question['criteria'] ) ) ) {
					return new WP_Error(
						'wp_mcp_ai_typesafe_invalid_criteria',
						sprintf(
						/* translators: %s: question name */
							__( 'Noul question "%s" criteria must be a non-empty map of true/false descriptions.', 'mcp-ai-wpoos' ),
							$name
						)
					);
				}

				return true;
			}

			$criteria = isset( $question['criteria'] ) ? $question['criteria'] : array();

			if ( 'choice' === $type ) {
				if ( ! is_array( $criteria ) || empty( $criteria ) ) {
					return new WP_Error(
						'wp_mcp_ai_typesafe_invalid_criteria',
						sprintf(
							/* translators: %s: question name */
							__( 'Choice question "%s" requires a non-empty criteria map of option => description.', 'mcp-ai-wpoos' ),
							$name
						)
					);
				}
				if ( count( $criteria ) > 255 ) {
					return new WP_Error(
						'wp_mcp_ai_typesafe_too_many_options',
						sprintf(
							/* translators: %s: question name */
							__( 'Choice question "%s" exceeds the 255-option limit.', 'mcp-ai-wpoos' ),
							$name
						)
					);
				}
			}

			if ( 'score' === $type ) {
				if ( ! is_array( $criteria ) || count( $criteria ) < 2 || count( $criteria ) > 10 ) {
					return new WP_Error(
						'wp_mcp_ai_typesafe_invalid_criteria',
						sprintf(
							/* translators: %s: question name */
							__( 'Score question "%s" requires an ordered list of 2 to 10 level descriptions.', 'mcp-ai-wpoos' ),
							$name
						)
					);
				}
			}

			return true;
		}

		/**
		 * Build the flat request payload for the System One endpoint.
		 *
		 * @param mixed  $state     Content to evaluate.
		 * @param array  $questions Question map.
		 * @param string $model    Resolved model id.
		 * @return array|WP_Error Payload or WP_Error when validation fails.
		 */
		protected function build_payload( $state, array $questions, $model ) {
			if ( empty( $questions ) ) {
				return new WP_Error(
					'wp_mcp_ai_typesafe_no_questions',
					__( 'At least one question is required for a TypeSafe decision request.', 'mcp-ai-wpoos' )
				);
			}

			if ( count( $questions ) > self::MAX_QUESTIONS ) {
				return new WP_Error(
					'wp_mcp_ai_typesafe_too_many_questions',
					sprintf(
						/* translators: %d: maximum questions per request */
						__( 'A single TypeSafe request supports at most %d questions.', 'mcp-ai-wpoos' ),
						self::MAX_QUESTIONS
					)
				);
			}

			$validated = array();
			foreach ( $questions as $name => $question ) {
				$name     = sanitize_key( $name );
				$is_valid = $this->validate_question( $name, $question );
				if ( is_wp_error( $is_valid ) ) {
					return $is_valid;
				}

				$entry = array(
					'type'         => $question['type'],
					'instructions' => $this->sanitize_entry_value( $question['instructions'] ),
				);

				// Noul criteria (true/false descriptions) are optional but must
				// survive the payload when supplied (Advanced: structure).
				if ( isset( $question['criteria'] ) ) {
					$entry['criteria'] = $this->sanitize_entry_value( $question['criteria'] );
				}

				$validated[ $name ] = $entry;
			}

			return array(
				'model'     => $model,
				'state'     => $state,
				'questions' => $validated,
			);
		}

		/**
		 * Sanitise a structured EntryType value (two-gate rule, gate one).
		 *
		 * TypeSafe accepts string|object|array|null for instructions, Choice
		 * option values, Score level descriptions, and Noul criteria. Strings
		 * are sanitised with sanitize_text_field (plain text only — no HTML),
		 * arrays are walked recursively with sanitize_key keys, and scalar
		 * values pass through unchanged.
		 *
		 * @param mixed $value EntryType value.
		 * @return mixed Sanitised value.
		 */
		protected function sanitize_entry_value( $value ) {
			if ( is_string( $value ) ) {
				return sanitize_text_field( $value );
			}

			if ( is_object( $value ) ) {
				$value = (array) $value;
			}

			if ( is_array( $value ) ) {
				$sanitized = array();
				foreach ( $value as $key => $item ) {
					$key               = is_string( $key ) ? sanitize_key( $key ) : $key;
					$sanitized[ $key ] = $this->sanitize_entry_value( $item );
				}

				return $sanitized;
			}

			return $value;
		}

		/**
		 * Send a decision request against the TypeSafe System One API.
		 *
		 * @param mixed $state     The content to evaluate: a plain string or
		 *                         structured data (object/array). Text only.
		 * @param array $questions Map of question name => definition (type,
		 *                         instructions, criteria).
		 * @param array $options   Options: model (string), timeout (int).
		 * @return array|WP_Error Normalised decision response or WP_Error.
		 */
		public function decide( $state, $questions, $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_typesafe_api_key',
					__( 'No TypeSafe API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_typesafe_api_key' => __( 'Add a TypeSafe API key in the NV oOS settings (Providers → TypeSafe), or configure an OpenRouter key and use the openrouter transport.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( ! is_array( $questions ) ) {
				$questions = array();
			}

			$model = $this->resolve_model( is_array( $options ) ? $options : array() );

			$payload = $this->build_payload( $state, $questions, $model );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$url     = $this->get_base_url() . $this->get_endpoint();
			$timeout = isset( $options['timeout'] ) && is_numeric( $options['timeout'] ) ? max( 10, absint( $options['timeout'] ) ) : 30;

			// Opt-in advisory cache: identical (model, state, questions)
			// requests inside the TTL are served at zero cost. The key embeds
			// the endpoint/base/model so any settings change invalidates it.
			$cache_key = $this->get_cache_key( $model, $payload );
			if ( '' !== $cache_key ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'typesafe_cache_hit',
							'TypeSafe decision served from cache.',
							array( 'model' => $model )
						);
					}

					// A cache hit bills nothing and must never hide its origin.
					$cached['cached'] = true;
					$cached['usage']  = array(
						'input_tokens'  => 0,
						'output_tokens' => 0,
					);

					return $cached;
				}
			}

			$request_args = array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'User-Agent'    => self::USER_AGENT,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'typesafe_request',
					'Sending decision request to TypeSafe.',
					array(
						'model'          => $model,
						'question_count' => count( $payload['questions'] ),
					)
				);
			}

			// Bounded retry on transient failures (429/5xx), honouring
			// `retry-after` with exponential backoff. Transport errors and
			// 4xx responses are never retried.
			$max_attempts = apply_filters( 'wp_mcp_ai_typesafe_retry_attempts', self::DEFAULT_MAX_ATTEMPTS );
			$max_attempts = max( 1, absint( $max_attempts ) );

			$response = null;
			$code     = 0;

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$response = wp_remote_post( $url, $request_args );

				if ( is_wp_error( $response ) ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_error( 'TypeSafe request failed.', array( 'error' => $response->get_error_message() ) );
					}

					if ( class_exists( 'WP_MCP_AI_HTTP' ) ) {
						return WP_MCP_AI_HTTP::prepare_transport_error(
							$response,
							'wp_mcp_ai_http_error',
							__( 'The TypeSafe API request failed to complete.', 'mcp-ai-wpoos' ),
							__( 'TypeSafe', 'mcp-ai-wpoos' )
						);
					}

					return $response;
				}

				$code = wp_remote_retrieve_response_code( $response );

				if ( $code >= 200 && $code < 300 ) {
					break;
				}

				if ( ! in_array( $code, self::RETRYABLE_STATUS_CODES, true ) || $attempt >= $max_attempts ) {
					break;
				}

				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$sleep       = ! empty( $retry_after ) ? max( 1, absint( $retry_after ) ) : (int) pow( 2, $attempt - 1 );

				/**
				 * Filter the sleep (seconds) before retrying a TypeSafe request.
				 *
				 * @since 2026.09
				 *
				 * @param int $sleep   Seconds to sleep.
				 * @param int $code    HTTP status code that triggered the retry.
				 * @param int $attempt Current attempt number (1-based).
				 */
				$sleep = apply_filters( 'wp_mcp_ai_typesafe_retry_sleep', $sleep, $code, $attempt );

				if ( $sleep > 0 ) {
					sleep( $sleep );
				}
			}

			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'Failed to decode TypeSafe response.', array( 'body' => $body ) );
				}

				return new WP_Error( 'wp_mcp_ai_typesafe_invalid_response', __( 'The TypeSafe API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, is_array( $decoded ) ? $decoded : array(), $response );
			}

			$normalized = self::normalize_response_payload( is_array( $decoded ) ? $decoded : array() );

			if ( empty( $normalized['model'] ) && ! empty( $model ) ) {
				$normalized['model'] = $model;
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'typesafe_response', 'TypeSafe decision request completed.', array( 'model' => $normalized['model'] ) );
			}

			if ( '' !== $cache_key ) {
				/**
				 * Filter the TTL (seconds) for cached TypeSafe decisions.
				 *
				 * @since 2026.09
				 *
				 * @param int $ttl Cache TTL in seconds (0 disables caching).
				 */
				$ttl = apply_filters( 'wp_mcp_ai_typesafe_cache_ttl', self::DEFAULT_CACHE_TTL );

				if ( $ttl > 0 ) {
					set_transient( $cache_key, $normalized, $ttl );
				}
			}

			return $normalized;
		}

		/**
		 * Whether opt-in decision caching is enabled.
		 *
		 * @return bool
		 */
		public function cache_enabled() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return ! empty( $settings['enable_typesafe_cache'] );
		}

		/**
		 * Build the content-addressed cache key for a decision request.
		 *
		 * The key embeds the base URL, endpoint, model, and full payload, so
		 * any settings change or question change naturally invalidates it.
		 *
		 * @param string $model   Resolved model id.
		 * @param array  $payload Validated request payload.
		 * @return string Cache key, or empty string when caching is disabled.
		 */
		protected function get_cache_key( $model, array $payload ) {
			if ( ! $this->cache_enabled() ) {
				return '';
			}

			$context = array(
				'v'        => 1,
				'base'     => $this->get_base_url(),
				'endpoint' => $this->get_endpoint(),
				'model'    => $model,
				'payload'  => $payload,
			);

			return self::CACHE_KEY_PREFIX . md5( wp_json_encode( $context ) );
		}

		/**
		 * Normalise a raw System One response payload.
		 *
		 * Shared static helper so the OpenRouter decisions bridge can reuse
		 * the exact same answer shape without depending on this instance.
		 *
		 * @param array $decoded Decoded JSON response.
		 * @return array Normalised array with model, answers, usage, and
		 *               request_id keys.
		 */
		public static function normalize_response_payload( array $decoded ) {
			$answers = array();

			if ( isset( $decoded['answers'] ) && is_array( $decoded['answers'] ) ) {
				foreach ( $decoded['answers'] as $name => $answer ) {
					if ( ! is_array( $answer ) ) {
						continue;
					}

					$normalized = array();

					// Type is inferred from which typed value is present so
					// both the native and gateway response shapes normalise.
					if ( isset( $answer['choice'] ) ) {
						$normalized['type']   = 'choice';
						$normalized['choice'] = sanitize_text_field( (string) $answer['choice'] );
					} elseif ( isset( $answer['score'] ) ) {
						$normalized['type']  = 'score';
						$normalized['score'] = floatval( $answer['score'] );
					} elseif ( isset( $answer['noul'] ) ) {
						$normalized['type'] = 'noul';
						$normalized['noul'] = floatval( $answer['noul'] );
					} else {
						// Unknown shape — keep the raw answer for debugging.
						$normalized['type'] = 'unknown';
					}

					if ( isset( $answer['probabilities'] ) && is_array( $answer['probabilities'] ) ) {
						$normalized['probabilities'] = array_map( 'floatval', $answer['probabilities'] );
					}

					if ( isset( $answer['confidence'] ) ) {
						$normalized['confidence'] = floatval( $answer['confidence'] );
					}

					$answers[ sanitize_key( (string) $name ) ] = $normalized;
				}
			}

			$usage = array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			);

			if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
				$usage['input_tokens']  = isset( $decoded['usage']['input_tokens'] ) ? absint( $decoded['usage']['input_tokens'] ) : 0;
				$usage['output_tokens'] = isset( $decoded['usage']['output_tokens'] ) ? absint( $decoded['usage']['output_tokens'] ) : 0;
			}

			$normalized = array(
				'model'   => isset( $decoded['model'] ) ? sanitize_text_field( $decoded['model'] ) : '',
				'answers' => $answers,
				'usage'   => $usage,
			);

			if ( isset( $decoded['id'] ) ) {
				$normalized['request_id'] = sanitize_text_field( $decoded['id'] );
			}

			return $normalized;
		}

		/**
		 * Convert a non-2xx API response into a WP_Error.
		 *
		 * @param int   $code    HTTP status code.
		 * @param array $decoded Decoded error body.
		 * @param array $response Raw wp_remote_post response (for headers).
		 * @return WP_Error
		 */
		protected function handle_api_error( $code, array $decoded, $response ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from TypeSafe.', 'mcp-ai-wpoos' );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_typesafe_api_error';

			if ( 401 === $code || 403 === $code ) {
				$error_code            = 'wp_mcp_ai_typesafe_auth_error';
				$error_data['actions'] = array(
					'auth_info' => __( 'Verify your TypeSafe API key in NV oOS → Providers → TypeSafe. Early access keys are issued at console.typesafe.ai.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The TypeSafe API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'TypeSafe returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);
			}

			return new WP_Error( $error_code, $error_message, $error_data );
		}

		/**
		 * Probe connectivity to the TypeSafe API.
		 *
		 * @return array|WP_Error Result array or WP_Error.
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_typesafe_api_key',
					__( 'No TypeSafe API key has been configured.', 'mcp-ai-wpoos' )
				);
			}

			$response = wp_remote_post(
				$this->get_base_url() . $this->get_endpoint(),
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
						'User-Agent'    => self::USER_AGENT,
					),
					'body'    => wp_json_encode(
						array(
							'model'     => $this->get_model(),
							'state'     => 'Connection test.',
							'questions' => array(
								'connected' => array(
									'type'         => 'noul',
									'instructions' => 'This is a connectivity test question.',
								),
							),
						)
					),
					'timeout' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				return $this->handle_api_error( $code, is_array( $decoded ) ? $decoded : array(), $response );
			}

			return array(
				'success' => true,
				'message' => __( 'TypeSafe API connection successful.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Return the known Jev model identifiers.
		 *
		 * TypeSafe does not document a public model-listing endpoint, so this
		 * returns the pinned + alias ids the client supports. Kept so the CLI
		 * `provider models` surface treats TypeSafe like any other provider.
		 *
		 * @return array|WP_Error Array of model identifier strings.
		 */
		public function list_models() {
			$configured = $this->get_model();
			$models     = array( 'jev-latest', 'jev-preview', 'jev-1.13.0' );

			if ( '' !== $configured && ! in_array( $configured, $models, true ) ) {
				$models[] = $configured;
			}

			return $models;
		}

		/**
		 * Return the provider slug.
		 *
		 * @return string
		 */
		public function get_provider_slug() {
			return 'typesafe';
		}
	}
}
