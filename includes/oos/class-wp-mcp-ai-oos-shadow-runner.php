<?php
/**
 * OOS Shadow Runner — Proposal 029, Phase 4.1.
 *
 * Runs the OOS engine in parallel on sampled legacy-path chat requests
 * and serves the legacy result. Zero user exposure; the shadow run is
 * bounded by a deadline, write-class tools are suppressed (never
 * double-executed), and every run is recorded for parity analysis.
 *
 * Same-request execution is deliberate: the shadow run inherits the
 * exact authentication context of the live request, so read-tool
 * behavior matches what the legacy path would produce. The deadline cap
 * keeps the added latency bounded.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shadow Runner — sampled parallel OOS execution + parity recording.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_OOS_Shadow_Runner {

	/**
	 * Option key for the capped run store.
	 */
	public const STORE_OPTION = 'wp_mcp_ai_oos_shadow_runs';

	/**
	 * Maximum stored runs (newest first).
	 */
	public const STORE_MAX = 100;

	/**
	 * Subscriber wiring state.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the before-chat-request subscriber.
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'maybe_run' ), 1, 4 );
	}

	/**
	 * Run the shadow engine for sampled requests.
	 *
	 * Hooked into wp_mcp_ai_before_chat_request at priority 1 so the
	 * shadow run starts before the legacy flow. Guards:
	 *  - shadow mode enabled and the global OOS flag off (shadow only
	 *    shadows the legacy path);
	 *  - the 4th arg is a real REST request (the OOS path fires the same
	 *    hook with an event object — never shadow there);
	 *  - sampling decision;
	 *  - try/catch + deadline — the shadow run can never break the
	 *    legacy response.
	 *
	 * The canonical emitter shape is
	 * `( $assistant_id, $messages, $options, $request )`, but every argument
	 * is defaulted so the subscriber also tolerates the legacy 2-arg shape
	 * used by some custom emitters and unit-tests.
	 *
	 * @param mixed $assistant_id Assistant ID (int).
	 * @param mixed $messages     OpenAI-format messages.
	 * @param mixed $options      Chat options.
	 * @param mixed $request      WP_REST_Request or event object.
	 */
	public static function maybe_run( $assistant_id = null, $messages = null, $options = null, $request = null ) {
		if ( ! function_exists( 'wp_mcp_ai_oos_shadow_enabled' ) || ! wp_mcp_ai_oos_shadow_enabled() ) {
			return;
		}

		if ( ! function_exists( 'wp_mcp_ai_oos_engine_enabled' ) || wp_mcp_ai_oos_engine_enabled() ) {
			return;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return;
		}

		if ( ! is_array( $messages ) || ! is_array( $options ) ) {
			return;
		}

		// Sampling gate.
		$sample_rate = function_exists( 'wp_mcp_ai_oos_shadow_sample_rate' ) ? wp_mcp_ai_oos_shadow_sample_rate() : 0.0;
		if ( $sample_rate <= 0.0 ) {
			return;
		}
		if ( $sample_rate < 1.0 && ( mt_rand( 1, 1000000 ) / 1000000 ) > $sample_rate ) {
			return;
		}

		$started_at = microtime( true );

		try {
			$assistant_id = (int) $assistant_id;
			$config       = class_exists( 'WP_MCP_AI_Assistant_CPT' )
				? WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id )
				: array();

			$options['shadow_mode'] = true;
			unset( $options['stream'] );

			$timeout = function_exists( 'wp_mcp_ai_oos_shadow_timeout_seconds' )
				? wp_mcp_ai_oos_shadow_timeout_seconds()
				: 30;

			$token = Nvoos\Core\Domain\ValueObject\CancellationToken::withDeadline( (float) $timeout );

			$result = wp_mcp_ai_oos_orchestrator()->handleChat(
				messages: $messages,
				assistantConfig: $config,
				userId: get_current_user_id(),
				assistantId: $assistant_id,
				options: $options,
				cancellation: $token,
			);

			$record = self::build_record( $result, $started_at, $assistant_id, $request );
		} catch ( \Throwable $e ) {
			$record = array(
				'run_id'      => uniqid( 'oos_shadow_' ),
				'timestamp'   => time(),
				'assistant_id' => (int) $assistant_id,
				'user_id'     => get_current_user_id(),
				'error'       => get_class( $e ) . ': ' . $e->getMessage(),
				'duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'sampled'     => true,
			);
		}

		self::store_run( $record );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event( 'oos_shadow_run', 'OOS shadow run recorded.', $record );
		}
	}

	/**
	 * Build the parity record from a shadow result.
	 *
	 * @param array            $result       handleChat() result.
	 * @param float            $startedAt    Microtime when the run started.
	 * @param int              $assistantId  Assistant post ID.
	 * @param WP_REST_Request  $request      Originating REST request.
	 * @return array
	 */
	private static function build_record( array $result, float $startedAt, int $assistantId, WP_REST_Request $request ): array {
		$tool_results = isset( $result['tool_results'] ) && is_array( $result['tool_results'] )
			? $result['tool_results']
			: array();

		$tool_errors = 0;
		$suppressed  = 0;
		foreach ( $tool_results as $tool_result ) {
			$content = isset( $tool_result['content'] ) ? (string) $tool_result['content'] : '';

			if ( 0 === strpos( $content, 'Error:' ) ) {
				++$tool_errors;
			}
			if ( false !== strpos( $content, 'shadow: write-class tool suppressed' ) ) {
				++$suppressed;
			}
		}

		$cost     = isset( $result['cost'] ) && is_array( $result['cost'] ) ? $result['cost'] : array();
		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();

		return array(
			'run_id'       => uniqid( 'oos_shadow_' ),
			'timestamp'    => time(),
			'assistant_id' => $assistantId,
			'user_id'      => get_current_user_id(),
			'duration_ms'  => (int) round( ( microtime( true ) - $startedAt ) * 1000 ),
			'iterations'   => (int) ( $result['iterations'] ?? 0 ),
			'cancelled'    => ! empty( $result['cancelled'] ),
			'cancel_reason' => (string) ( $result['cancel_reason'] ?? '' ),
			'tool_calls'   => count( $tool_results ),
			'tool_errors'  => $tool_errors,
			'suppressed'   => $suppressed,
			'cost_usd'     => (float) ( $cost['cost_usd'] ?? 0.0 ),
			'prompt_tokens' => (int) ( $cost['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $cost['completion_tokens'] ?? 0 ),
			'has_response' => array() !== $response,
			'sampled'      => true,
		);
	}

	/**
	 * Persist one run into the capped store (newest first).
	 */
	private static function store_run( array $record ): void {
		$runs   = get_option( self::STORE_OPTION, array() );
		$runs   = is_array( $runs ) ? $runs : array();
		$runs[] = $record;

		if ( count( $runs ) > self::STORE_MAX ) {
			$runs = array_slice( $runs, -self::STORE_MAX );
		}

		update_option( self::STORE_OPTION, $runs, false );
	}

	/**
	 * All stored runs, newest first.
	 *
	 * @return array
	 */
	public static function get_runs( int $limit = 25 ): array {
		$runs = get_option( self::STORE_OPTION, array() );
		$runs = is_array( $runs ) ? array_reverse( $runs ) : array();

		return array_slice( $runs, 0, max( 1, min( 100, $limit ) ) );
	}

	/**
	 * One stored run by id.
	 *
	 * @return array|null
	 */
	public static function get_run( string $run_id ): ?array {
		foreach ( self::get_runs( self::STORE_MAX ) as $run ) {
			if ( isset( $run['run_id'] ) && $run_id === $run['run_id'] ) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * Clear the run store (testing / resets).
	 */
	public static function clear_runs(): void {
		delete_option( self::STORE_OPTION );
	}
}
