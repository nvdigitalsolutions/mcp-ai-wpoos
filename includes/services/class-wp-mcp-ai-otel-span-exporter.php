<?php
/**
 * OpenTelemetry Exporter Service.
 *
 * Subscribes to the NV oOS lifecycle hooks and emits OTLP/HTTP spans to a
 * configurable endpoint. This service is **completely off by default** — no
 * spans are emitted until an OTLP endpoint is configured via the WordPress
 * option `wp_mcp_ai_otel_endpoint` (or the environment variable
 * `WP_MCP_AI_OTEL_ENDPOINT`).
 *
 * Spans are batched in a PHP static buffer and flushed at `shutdown` to
 * minimise latency on the hot chat path. On long SSE requests the caller
 * may also call `WP_MCP_AI_Otel_Span_Exporter::flush()` explicitly.
 *
 * ## What is traced
 *
 * | Hook                                 | Span name                      |
 * |--------------------------------------|--------------------------------|
 * | `wp_mcp_ai_before_chat_request`      | `nvoos.chat.request`           |
 * | `wp_mcp_ai_after_chat_response`      | (ends the chat span)           |
 * | `wp_mcp_ai_before_tool_execution`    | `nvoos.tool.{slug}`            |
 * | `wp_mcp_ai_after_tool_execution`     | (ends the tool span)           |
 * | `wp_mcp_ai_prompt_injection_detected`| `nvoos.security.injection`     |
 * | `wp_mcp_ai_chat_jobs_snapshot`       | `nvoos.chat.jobs.snapshot`     |
 * | `wp_mcp_ai_before_chat_jobs_stream`  | (starts `nvoos.chat.jobs.stream`) |
 * | `wp_mcp_ai_after_chat_jobs_stream`   | `nvoos.chat.jobs.stream`       |
 * | `wp_mcp_ai_chat_jobs_cancel`         | `nvoos.chat.jobs.cancel`       |
 * | `wp_mcp_ai_chat_jobs_retry`          | `nvoos.chat.jobs.retry`        |
 * | `wp_mcp_ai_chat_continuation_stored`      | `nvoos.chat.continuation.stored`      |
 * | `wp_mcp_ai_chat_continuation_ready`       | `nvoos.chat.continuation.ready`       |
 * | `wp_mcp_ai_chat_continuation_dispatched`  | `nvoos.chat.continuation.dispatched`  |
 * | `wp_mcp_ai_chat_continuation_resumed`     | `nvoos.chat.continuation.resumed`     |
 * | `wp_mcp_ai_chat_continuation_errored`     | `nvoos.chat.continuation.errored`     |
 *
 * ## OTLP/HTTP Protobuf vs JSON
 *
 * This implementation uses the OTLP/HTTP + JSON export format (Content-Type:
 * application/json) rather than Protobuf, which requires no PHP extension
 * and is supported by all modern collectors (Jaeger, Grafana Tempo, Honeycomb,
 * Datadog, New Relic, SigNoz, etc.).
 *
 * ## Filtering & extension
 *
 * - `wp_mcp_ai_otel_endpoint`         — filter the export URL.
 * - `wp_mcp_ai_otel_span_before_export` — array-filter on each span before send.
 * - `wp_mcp_ai_otel_extra_attributes`  — add site-level resource attributes.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OTLP/HTTP span exporter for NV oOS lifecycle events.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Otel_Span_Exporter {

	/**
	 * Option key for the OTLP/HTTP export endpoint URL.
	 */
	const OPTION_ENDPOINT = 'wp_mcp_ai_otel_endpoint';

	/**
	 * Option key for optional bearer token sent with each export request.
	 */
	const OPTION_TOKEN = 'wp_mcp_ai_otel_token';

	/**
	 * Maximum spans to buffer before forcing a flush. Prevents unbounded memory use.
	 */
	const MAX_BUFFERED_SPANS = 200;

	/**
	 * In-process span buffer.
	 *
	 * @var array<int, array>
	 */
	private static $spans = array();

	/**
	 * Open span stack (keyed by span_id).
	 *
	 * Stores start time (microseconds) and attributes for spans still in
	 * progress.
	 *
	 * @var array<string, array>
	 */
	private static $open_spans = array();

	/**
	 * Current trace ID for the PHP request lifecycle. Generated once per
	 * request and reused for all spans.
	 *
	 * @var string|null
	 */
	private static $trace_id = null;

	/**
	 * Whether the shutdown flush has been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	// ── Registration ─────────────────────────────────────────────────────────

	/**
	 * Register lifecycle hook subscribers.
	 *
	 * Called from harness-init.php or the plugin loader. Idempotent.
	 */
	public static function register() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'on_before_chat' ), 99, 4 );
		add_action( 'wp_mcp_ai_after_chat_response', array( __CLASS__, 'on_after_chat' ), 99, 3 );
		add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before_tool' ), 99, 3 );
		add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'on_after_tool' ), 99, 5 );
		add_action( 'wp_mcp_ai_prompt_injection_detected', array( __CLASS__, 'on_injection_detected' ), 99, 4 );
		add_action( 'wp_mcp_ai_chat_jobs_snapshot', array( __CLASS__, 'on_chat_jobs_snapshot' ), 99, 3 );
		add_action( 'wp_mcp_ai_before_chat_jobs_stream', array( __CLASS__, 'on_before_chat_jobs_stream' ), 99, 2 );
		add_action( 'wp_mcp_ai_after_chat_jobs_stream', array( __CLASS__, 'on_after_chat_jobs_stream' ), 99, 4 );
		add_action( 'wp_mcp_ai_chat_jobs_cancel', array( __CLASS__, 'on_chat_jobs_cancel' ), 99, 2 );
		add_action( 'wp_mcp_ai_chat_jobs_retry', array( __CLASS__, 'on_chat_jobs_retry' ), 99, 2 );

		// Async chat continuation lifecycle.
		add_action( 'wp_mcp_ai_chat_continuation_stored', array( __CLASS__, 'on_chat_continuation_stored' ), 99, 2 );
		add_action( 'wp_mcp_ai_chat_continuation_ready', array( __CLASS__, 'on_chat_continuation_ready' ), 99, 3 );
		add_action( 'wp_mcp_ai_chat_continuation_dispatched', array( __CLASS__, 'on_chat_continuation_dispatched' ), 99, 3 );
		add_action( 'wp_mcp_ai_chat_continuation_resumed', array( __CLASS__, 'on_chat_continuation_resumed' ), 99, 3 );
		add_action( 'wp_mcp_ai_chat_continuation_errored', array( __CLASS__, 'on_chat_continuation_errored' ), 99, 3 );

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'flush' ) );
			self::$shutdown_registered = true;
		}
	}

	// ── Hook handlers ─────────────────────────────────────────────────────────

	/**
	 * Start a chat span.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $messages     Chat messages.
	 * @param array $options      Options.
	 */
	public static function on_before_chat( $assistant_id, $messages, $options ) {
		$span_id                                     = self::generate_span_id();
		self::$open_spans[ 'chat:' . $assistant_id ] = array(
			'span_id'      => $span_id,
			'start_micros' => self::now_micros(),
			'attributes'   => array(
				'nvoos.assistant_id'  => (int) $assistant_id,
				'nvoos.message_count' => is_array( $messages ) ? count( $messages ) : 0,
				'nvoos.model'         => isset( $options['model'] ) ? (string) $options['model'] : '',
			),
		);
	}

	/**
	 * End a chat span.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param mixed $response     LLM response.
	 */
	public static function on_after_chat( $assistant_id, $response ) {
		$key = 'chat:' . $assistant_id;
		if ( ! isset( self::$open_spans[ $key ] ) ) {
			return;
		}

		$open  = self::$open_spans[ $key ];
		$attrs = $open['attributes'];

		// Add response attributes where safely available.
		if ( is_array( $response ) ) {
			if ( isset( $response['usage']['total_tokens'] ) ) {
				$attrs['llm.usage.total_tokens'] = (int) $response['usage']['total_tokens'];
			}
			if ( isset( $response['model'] ) ) {
				$attrs['llm.model'] = (string) $response['model'];
			}
		}

		self::buffer_span( 'nvoos.chat.request', $open['span_id'], $open['start_micros'], $attrs );
		unset( self::$open_spans[ $key ] );
	}

	/**
	 * Start a tool execution span.
	 *
	 * @param string $tool_slug  Tool slug.
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 */
	public static function on_before_tool( $tool_slug, $arguments, $context ) {
		$tool_slug                                = sanitize_key( (string) $tool_slug );
		$span_id                                  = self::generate_span_id();
		self::$open_spans[ 'tool:' . $tool_slug ] = array(
			'span_id'      => $span_id,
			'start_micros' => self::now_micros(),
			'attributes'   => array(
				'nvoos.tool.slug'     => $tool_slug,
				'nvoos.assistant_id'  => isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
				'nvoos.guest_request' => ! empty( $context['guest_request'] ),
			),
		);
	}

	/**
	 * End a tool execution span.
	 *
	 * @param string $tool_slug  Tool slug.
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param mixed  $result     Tool result.
	 * @param array  $descriptor Optional normalised lifecycle descriptor
	 *                           ({success, error_code, data_type, duration_ms}).
	 *                           Added in Phase P4 of the Unix-theory proposal.
	 */
	public static function on_after_tool( $tool_slug, $arguments, $context, $result, $descriptor = array() ) {
		$tool_slug = sanitize_key( (string) $tool_slug );
		$key       = 'tool:' . $tool_slug;
		if ( ! isset( self::$open_spans[ $key ] ) ) {
			return;
		}

		$open                        = self::$open_spans[ $key ];
		$attrs                       = $open['attributes'];
		$attrs['nvoos.tool.success'] = ! is_wp_error( $result );
		if ( is_wp_error( $result ) ) {
			$attrs['error.type']    = $result->get_error_code();
			$attrs['error.message'] = $result->get_error_message();
		}

		// Phase P4: enrich the span with the normalised descriptor when supplied.
		if ( is_array( $descriptor ) ) {
			if ( isset( $descriptor['data_type'] ) && '' !== $descriptor['data_type'] ) {
				$attrs['nvoos.tool.data_type'] = (string) $descriptor['data_type'];
			}
			if ( isset( $descriptor['duration_ms'] ) && is_numeric( $descriptor['duration_ms'] ) ) {
				$attrs['nvoos.tool.duration_ms'] = (float) $descriptor['duration_ms'];
			}
		}

		self::buffer_span( 'nvoos.tool.' . $tool_slug, $open['span_id'], $open['start_micros'], $attrs );
		unset( self::$open_spans[ $key ] );
	}

	/**
	 * Emit a security event span when injection is detected.
	 *
	 * @param array  $detection_result Detection result array.
	 * @param string $text             Scanned text (NOT included in span — privacy).
	 * @param int    $assistant_id     Assistant post ID.
	 */
	public static function on_injection_detected( $detection_result, $text, $assistant_id ) {
		$attrs = array(
			'nvoos.security.event'    => 'prompt_injection_detected',
			'nvoos.security.severity' => isset( $detection_result['severity'] ) ? (string) $detection_result['severity'] : 'unknown',
			'nvoos.security.family'   => isset( $detection_result['family'] ) ? (string) $detection_result['family'] : 'unknown',
			'nvoos.security.tier'     => isset( $detection_result['tier'] ) ? (string) $detection_result['tier'] : 'unknown',
			'nvoos.security.blocked'  => ! empty( $detection_result['block'] ),
			'nvoos.assistant_id'      => (int) $assistant_id,
		);
		self::buffer_span( 'nvoos.security.injection', self::generate_span_id(), self::now_micros(), $attrs );
	}

	// ── Span buffering & flushing ─────────────────────────────────────────────

	// ── Chat-jobs handlers ────────────────────────────────────────────────────

	/**
	 * Record a span for a one-shot cron-status snapshot request.
	 *
	 * @param array    $response     Snapshot payload.
	 * @param int      $user_id      Authenticated user ID.
	 * @param int|null $assistant_id Optional assistant filter.
	 */
	public static function on_chat_jobs_snapshot( $response, $user_id, $assistant_id ) {
		$job_count = isset( $response['jobs'] ) && is_array( $response['jobs'] ) ? count( $response['jobs'] ) : 0;
		$attrs     = array(
			'nvoos.chat_jobs.job_count'    => $job_count,
			'nvoos.chat_jobs.user_id'      => (int) $user_id,
			'nvoos.chat_jobs.assistant_id' => null !== $assistant_id ? (string) $assistant_id : '',
		);
		self::buffer_span( 'nvoos.chat.jobs.snapshot', self::generate_span_id(), self::now_micros(), $attrs );
	}

	/**
	 * Open a span when a cron-status SSE stream starts.
	 *
	 * @param int      $user_id      Authenticated user ID.
	 * @param int|null $assistant_id Optional assistant filter.
	 */
	public static function on_before_chat_jobs_stream( $user_id, $assistant_id ) {
		$span_id                              = self::generate_span_id();
		self::$open_spans['chat_jobs_stream'] = array(
			'span_id'      => $span_id,
			'start_micros' => self::now_micros(),
			'attributes'   => array(
				'nvoos.chat_jobs.user_id'      => (int) $user_id,
				'nvoos.chat_jobs.assistant_id' => null !== $assistant_id ? (string) $assistant_id : '',
			),
		);
	}

	/**
	 * Close the stream span when the SSE connection ends.
	 *
	 * @param int      $poll_count   Number of polls completed.
	 * @param int      $user_id      Authenticated user ID.
	 * @param int|null $assistant_id Optional assistant filter.
	 * @param int      $duration_ms  Stream duration in milliseconds.
	 */
	public static function on_after_chat_jobs_stream( $poll_count, $user_id, $assistant_id, $duration_ms ) {
		$key = 'chat_jobs_stream';
		if ( ! isset( self::$open_spans[ $key ] ) ) {
			return;
		}

		$open                                 = self::$open_spans[ $key ];
		$attrs                                = $open['attributes'];
		$attrs['nvoos.chat_jobs.poll_count']  = (int) $poll_count;
		$attrs['nvoos.chat_jobs.duration_ms'] = (int) $duration_ms;

		self::buffer_span( 'nvoos.chat.jobs.stream', $open['span_id'], $open['start_micros'], $attrs );
		unset( self::$open_spans[ $key ] );
	}

	/**
	 * Record a span when a job is cancelled.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id User who requested the cancellation.
	 */
	public static function on_chat_jobs_cancel( $job_id, $user_id ) {
		$attrs = array(
			'nvoos.chat_jobs.job_id'  => (string) $job_id,
			'nvoos.chat_jobs.user_id' => (int) $user_id,
			'nvoos.chat_jobs.action'  => 'cancel',
		);
		self::buffer_span( 'nvoos.chat.jobs.cancel', self::generate_span_id(), self::now_micros(), $attrs );
	}

	/**
	 * Record a span when a job is retried.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id User who requested the retry.
	 */
	public static function on_chat_jobs_retry( $job_id, $user_id ) {
		$attrs = array(
			'nvoos.chat_jobs.job_id'  => (string) $job_id,
			'nvoos.chat_jobs.user_id' => (int) $user_id,
			'nvoos.chat_jobs.action'  => 'retry',
		);
		self::buffer_span( 'nvoos.chat.jobs.retry', self::generate_span_id(), self::now_micros(), $attrs );
	}

	// ── Async chat continuation ───────────────────────────────────────────────

	/**
	 * Record a span when a continuation snapshot is persisted.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $snapshot Continuation snapshot payload.
	 */
	public static function on_chat_continuation_stored( $job_id, $snapshot ) {
		$attrs = array(
			'nvoos.continuation.job_id'        => (string) $job_id,
			'nvoos.continuation.session_id'    => isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '',
			'nvoos.continuation.assistant_id'  => isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0,
			'nvoos.continuation.user_id'       => isset( $snapshot['user_id'] ) ? (int) $snapshot['user_id'] : 0,
			'nvoos.continuation.tool_name'     => isset( $snapshot['tool_name'] ) ? (string) $snapshot['tool_name'] : '',
			'nvoos.continuation.message_count' => isset( $snapshot['messages'] ) && is_array( $snapshot['messages'] ) ? count( $snapshot['messages'] ) : 0,
		);
		self::buffer_span( 'nvoos.chat.continuation.stored', self::generate_span_id(), self::now_micros(), $attrs );
	}

	/**
	 * Open a span when a continuation is ready for LLM re-entry.
	 *
	 * The span is closed by `on_chat_continuation_dispatched` or
	 * `on_chat_continuation_errored`.
	 *
	 * @param array  $snapshot        Continuation snapshot.
	 * @param string $terminal_status completed|failed|cancelled.
	 * @param array  $terminal_result Job result.
	 */
	public static function on_chat_continuation_ready( $snapshot, $terminal_status, $terminal_result ) {
		$job_id                                        = isset( $snapshot['job_id'] ) ? (string) $snapshot['job_id'] : '';
		$span_id                                       = self::generate_span_id();
		self::$open_spans[ 'continuation_' . $job_id ] = array(
			'span_id'      => $span_id,
			'start_micros' => self::now_micros(),
			'attributes'   => array(
				'nvoos.continuation.job_id'       => $job_id,
				'nvoos.continuation.session_id'   => isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '',
				'nvoos.continuation.assistant_id' => isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0,
				'nvoos.continuation.status'       => (string) $terminal_status,
			),
		);
	}

	/**
	 * Close the continuation span after successful LLM re-entry.
	 *
	 * @param string $job_id          Job identifier.
	 * @param array  $snapshot        Continuation snapshot.
	 * @param string $terminal_status Terminal status.
	 */
	public static function on_chat_continuation_dispatched( $job_id, $snapshot, $terminal_status ) {
		$key = 'continuation_' . $job_id;
		if ( ! isset( self::$open_spans[ $key ] ) ) {
			// Span may have been opened by on_chat_continuation_ready; fire a point span if not.
			$attrs = array(
				'nvoos.continuation.job_id'  => (string) $job_id,
				'nvoos.continuation.status'  => (string) $terminal_status,
				'nvoos.continuation.success' => true,
			);
			self::buffer_span( 'nvoos.chat.continuation.dispatched', self::generate_span_id(), self::now_micros(), $attrs );
			return;
		}

		$open                                = self::$open_spans[ $key ];
		$attrs                               = $open['attributes'];
		$attrs['nvoos.continuation.success'] = true;
		self::buffer_span( 'nvoos.chat.continuation.dispatched', $open['span_id'], $open['start_micros'], $attrs );
		unset( self::$open_spans[ $key ] );
	}

	/**
	 * Record a span when a resumed assistant message is pushed to the session buffer.
	 *
	 * Signature matches `wp_mcp_ai_chat_continuation_resumed`:
	 * `( string $job_id, array $snapshot, string $message )`.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $snapshot Continuation snapshot.
	 * @param string $message  Assistant response text.
	 */
	public static function on_chat_continuation_resumed( $job_id, $snapshot, $message ) {
		$attrs = array(
			'nvoos.continuation.job_id'        => (string) $job_id,
			'nvoos.continuation.session_id'    => isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '',
			'nvoos.continuation.assistant_id'  => isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0,
			'nvoos.continuation.action'        => 'resumed',
			'nvoos.continuation.message_chars' => strlen( (string) $message ),
		);
		self::buffer_span( 'nvoos.chat.continuation.resumed', self::generate_span_id(), self::now_micros(), $attrs );
	}

	/**
	 * Record a span and close the open continuation span when LLM re-entry fails.
	 *
	 * Signature matches `wp_mcp_ai_chat_continuation_errored`:
	 * `( string $job_id, array $snapshot, string $error_msg )`.
	 *
	 * @param string $job_id    Job identifier.
	 * @param array  $snapshot  Continuation snapshot.
	 * @param string $error_msg Error description.
	 */
	public static function on_chat_continuation_errored( $job_id, $snapshot, $error_msg ) {
		$session_id = isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '';
		$key        = 'continuation_' . $job_id;
		$open       = isset( self::$open_spans[ $key ] ) ? self::$open_spans[ $key ] : null;
		$attrs      = array(
			'nvoos.continuation.job_id'     => (string) $job_id,
			'nvoos.continuation.session_id' => $session_id,
			'nvoos.continuation.error'      => (string) $error_msg,
			'nvoos.continuation.success'    => false,
		);

		if ( $open ) {
			$attrs = array_merge( $open['attributes'], $attrs );
			self::buffer_span( 'nvoos.chat.continuation.errored', $open['span_id'], $open['start_micros'], $attrs );
			unset( self::$open_spans[ $key ] );
		} else {
			self::buffer_span( 'nvoos.chat.continuation.errored', self::generate_span_id(), self::now_micros(), $attrs );
		}
	}

	// ── Span buffering & flushing (continued) ─────────────────────────────────

	/**
	 * Buffer a completed span. Flushes the buffer if it hits MAX_BUFFERED_SPANS.
	 *
	 * @param string $name         Span name.
	 * @param string $span_id      Hex span ID.
	 * @param int    $start_micros Start time in microseconds.
	 * @param array  $attributes   Span attributes.
	 */
	private static function buffer_span( $name, $span_id, $start_micros, array $attributes ) {
		$end_micros = self::now_micros();
		$duration   = max( 0, $end_micros - $start_micros );

		// Convert to OTLP nanoseconds.
		$start_ns = $start_micros * 1000;
		$end_ns   = $end_micros * 1000;

		// Add site-level resource attributes via filter.
		$extra = apply_filters( 'wp_mcp_ai_otel_extra_attributes', array() );

		$span = array(
			'traceId'           => self::get_trace_id(),
			'spanId'            => $span_id,
			'name'              => (string) $name,
			'kind'              => 3, // SPAN_KIND_CLIENT.
			'startTimeUnixNano' => (string) $start_ns,
			'endTimeUnixNano'   => (string) $end_ns,
			'attributes'        => self::encode_attributes( array_merge( $attributes, is_array( $extra ) ? $extra : array() ) ),
			'status'            => array( 'code' => 1 ), // STATUS_CODE_OK.
		);

		/**
		 * Filter a span before it is buffered. Return null to suppress export.
		 *
		 * @param array  $span Span data.
		 * @param string $name Span name.
		 */
		$span = apply_filters( 'wp_mcp_ai_otel_span_before_export', $span, $name );
		if ( null === $span ) {
			return;
		}

		self::$spans[] = $span;

		if ( count( self::$spans ) >= self::MAX_BUFFERED_SPANS ) {
			self::flush();
		}
	}

	/**
	 * Flush the span buffer to the OTLP endpoint.
	 *
	 * Silently no-ops if no spans are buffered or the endpoint is not set.
	 * Uses a non-blocking fire-and-forget HTTP POST.
	 */
	public static function flush() {
		if ( empty( self::$spans ) ) {
			return;
		}

		$endpoint = self::get_endpoint();
		if ( '' === $endpoint ) {
			self::$spans = array();
			return;
		}

		$spans_to_send = self::$spans;
		self::$spans   = array();

		$payload = array(
			'resourceSpans' => array(
				array(
					'resource'   => array(
						'attributes' => self::encode_attributes(
							array(
								'service.name'    => 'nvoos',
								'service.version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
								'host.name'       => wp_parse_url( home_url(), PHP_URL_HOST ),
							)
						),
					),
					'scopeSpans' => array(
						array(
							'scope' => array(
								'name'    => 'mcp-ai-wpoos',
								'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
							),
							'spans' => $spans_to_send,
						),
					),
				),
			),
		);

		$body    = wp_json_encode( $payload );
		$headers = array( 'Content-Type' => 'application/json' );

		// Token resolution: settings array (UI) first, standalone option as fallback.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$token    = isset( $settings['otel_token'] ) && '' !== $settings['otel_token']
			? $settings['otel_token']
			: (string) get_option( self::OPTION_TOKEN, '' );
		if ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		wp_remote_post(
			$endpoint,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 2,
				'blocking'  => false,
				'sslverify' => true,
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Whether the exporter is enabled (endpoint configured).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '' !== self::get_endpoint();
	}

	/**
	 * Get the OTLP endpoint URL.
	 *
	 * Resolution order:
	 *  1. Environment variable WP_MCP_AI_OTEL_ENDPOINT.
	 *  2. Settings array (wp_mcp_ai_settings['otel_endpoint']) — set via the
	 *     Tools → Connections → OpenTelemetry admin page.
	 *  3. Standalone option wp_mcp_ai_otel_endpoint — for backward
	 *     compatibility with sites that set the option directly via WP-CLI
	 *     or code before the admin UI was available.
	 *
	 * @return string
	 */
	public static function get_endpoint() {
		$env = getenv( 'WP_MCP_AI_OTEL_ENDPOINT' );
		if ( false !== $env && '' !== $env ) {
			$candidate = esc_url_raw( $env );
		} else {
			// Check the main settings array first (UI-configured value).
			$settings  = get_option( 'wp_mcp_ai_settings', array() );
			$from_ui   = isset( $settings['otel_endpoint'] ) ? (string) $settings['otel_endpoint'] : '';
			$candidate = '' !== $from_ui ? $from_ui : (string) get_option( self::OPTION_ENDPOINT, '' );
		}

		/**
		 * Filter the OTLP export endpoint URL.
		 *
		 * @param string $endpoint Default endpoint.
		 */
		$candidate = apply_filters( 'wp_mcp_ai_otel_endpoint', $candidate );

		// Validate: must be https or http (http allowed for local collectors).
		if ( '' !== $candidate && ! preg_match( '/^https?:\/\//i', $candidate ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Get or generate a trace ID for the current PHP request.
	 *
	 * @return string 32 hex characters (128-bit).
	 */
	public static function get_trace_id() {
		if ( null === self::$trace_id ) {
			self::$trace_id = self::random_hex( 32 );
		}
		return self::$trace_id;
	}

	/**
	 * Generate a new span ID.
	 *
	 * @return string 16 hex characters (64-bit).
	 */
	private static function generate_span_id() {
		return self::random_hex( 16 );
	}

	/**
	 * Return current time in microseconds.
	 *
	 * @return int
	 */
	private static function now_micros() {
		return (int) round( microtime( true ) * 1e6 );
	}

	/**
	 * Generate a random hex string of the given length.
	 *
	 * @param int $length Number of hex characters.
	 * @return string
	 */
	private static function random_hex( $length ) {
		$bytes = random_bytes( (int) ceil( $length / 2 ) );
		return substr( bin2hex( $bytes ), 0, $length );
	}

	/**
	 * Encode a key-value attribute map into OTLP attribute format.
	 *
	 * @param array $attributes Flat associative array.
	 * @return array OTLP attribute list.
	 */
	private static function encode_attributes( array $attributes ) {
		$result = array();
		foreach ( $attributes as $key => $value ) {
			$attr = array( 'key' => (string) $key );
			if ( is_bool( $value ) ) {
				$attr['value'] = array( 'boolValue' => $value );
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$attr['value'] = array( 'intValue' => (string) (int) $value );
			} else {
				$attr['value'] = array( 'stringValue' => (string) $value );
			}
			$result[] = $attr;
		}
		return $result;
	}

	/**
	 * Reset static state for unit-test isolation.
	 *
	 * Clears the span buffer, open-span stack, trace ID, and the
	 * shutdown-registered flag so `register()` can be re-entered cleanly
	 * in a test suite without leaking hooks across test cases.
	 *
	 * @since 1.9.4
	 *
	 * @return void
	 */
	public static function reset_for_tests() {
		self::$spans               = array();
		self::$open_spans          = array();
		self::$trace_id            = null;
		self::$shutdown_registered = false;

		remove_all_actions( 'wp_mcp_ai_before_chat_request' );
		remove_all_actions( 'wp_mcp_ai_after_chat_response' );
		remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		remove_all_actions( 'wp_mcp_ai_after_tool_execution' );
		remove_all_actions( 'wp_mcp_ai_prompt_injection_detected' );
		remove_all_actions( 'wp_mcp_ai_chat_jobs_snapshot' );
		remove_all_actions( 'wp_mcp_ai_before_chat_jobs_stream' );
		remove_all_actions( 'wp_mcp_ai_after_chat_jobs_stream' );
		remove_all_actions( 'wp_mcp_ai_chat_jobs_cancel' );
		remove_all_actions( 'wp_mcp_ai_chat_jobs_retry' );
		// Continuation lifecycle hooks.
		remove_all_actions( 'wp_mcp_ai_chat_continuation_stored' );
		remove_all_actions( 'wp_mcp_ai_chat_continuation_ready' );
		remove_all_actions( 'wp_mcp_ai_chat_continuation_dispatched' );
		remove_all_actions( 'wp_mcp_ai_chat_continuation_resumed' );
		remove_all_actions( 'wp_mcp_ai_chat_continuation_errored' );
	}

	/**
	 * Return the raw span buffer — **test use only**.
	 *
	 * @return array
	 */
	public static function get_test_buffer() {
		return self::$spans;
	}

	/**
	 * Return the open-spans map — **test use only**.
	 *
	 * @return array
	 */
	public static function get_test_open_spans() {
		return self::$open_spans;
	}
}
