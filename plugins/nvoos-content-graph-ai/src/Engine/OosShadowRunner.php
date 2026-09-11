<?php
/**
 * OOS shadow runner (Wave E6, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_OOS_Shadow_Runner`
 * (`includes/oos/`): byte-identical store constants
 * (`wp_mcp_ai_oos_shadow_runs` option, 100-run cap, newest first,
 * non-autoloaded), the `wp_mcp_ai_before_chat_request` priority-1
 * subscriber, the gate chain (shadow enabled → OOS engine flag off →
 * real REST request → array payloads → sampling), same-request
 * execution with a deadline `CancellationToken`, the
 * `shadow_mode`/no-stream option mutation, the record shape
 * (`run_id`, timestamps, iteration/tool/cost fields, the
 * `shadow: write-class tool suppressed` counter, `has_response`), the
 * contained-failure error record, and the `oos_shadow_run` audit log.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - The engine gates resolve through `OosEngineFlags` (which defers to
 *    the base's global functions monolith and carries byte-identical
 *    standalone bodies).
	 *  - The orchestrator resolves per install mode via the `orchestrator()`
	 *    seam: base `wp_mcp_ai_oos_orchestrator()` monolith, this addon's
	 *    `CoreBridge::instance()->chat` standalone (both are the same
	 *    `ChatOrchestrator` class, so the named-argument call surface is
	 *    identical). The seam is deliberately untyped — the base factory
	 *    function declares no return type, and the characterization suite
	 *    substitutes a fake orchestrator. A null orchestrator is recorded
	 *    as a contained error run instead of fataling (documented
	 *    hardening of the containment invariant).
 *  - The assistant configuration resolves per install mode via the
 *    `assistant_configuration()` seam: base
 *    `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` monolith;
 *    standalone returns `array()` — the same degradation the base makes
 *    when the assistant CPT is absent (the standalone chat path is not
 *    assistant-post-bound).
 *  - The audit log is monolith-only (`WP_MCP_AI_Logger`), dormant
 *    standalone.
 *  - The `wp_mcp_ai_before_chat_request` hook is dormant standalone —
 *    no standalone surface emits it yet (byte-identical dormancy).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine;

/**
 * Shadow Runner — sampled parallel OOS execution + parity recording.
 *
 * @since 1.1.0
 */
class OosShadowRunner {

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
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		\add_action( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'maybe_run' ), 1, 4 );
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
	 * @return void
	 */
	public static function maybe_run( $assistant_id = null, $messages = null, $options = null, $request = null ): void {
		if ( ! OosEngineFlags::shadow_enabled() ) {
			return;
		}

		if ( OosEngineFlags::engine_enabled() ) {
			return;
		}

		if ( ! $request instanceof \WP_REST_Request ) {
			return;
		}

		if ( ! \is_array( $messages ) || ! \is_array( $options ) ) {
			return;
		}

		// Sampling gate.
		$sample_rate = OosEngineFlags::shadow_sample_rate();
		if ( $sample_rate <= 0.0 ) {
			return;
		}
		if ( $sample_rate < 1.0 && ( \mt_rand( 1, 1000000 ) / 1000000 ) > $sample_rate ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Byte-identical sampling gate (the base uses mt_rand).
			return;
		}

		$started_at = \microtime( true );

		try {
			$assistant_id = (int) $assistant_id;
			$config       = static::assistant_configuration( $assistant_id );

			$options['shadow_mode'] = true;
			unset( $options['stream'] );

			$timeout = OosEngineFlags::shadow_timeout_seconds();

			$token = \Nvoos\Core\Domain\ValueObject\CancellationToken::withDeadline( (float) $timeout );

			$orchestrator = static::orchestrator();
			if ( null === $orchestrator ) {
				throw new \RuntimeException( 'The OOS orchestrator is unavailable in this install mode.' );
			}

			$result = $orchestrator->handleChat(
				messages: $messages,
				assistantConfig: $config,
				userId: \get_current_user_id(),
				assistantId: $assistant_id,
				options: $options,
				cancellation: $token,
			);

			$record = self::build_record( $result, $started_at, $assistant_id, $request );
		} catch ( \Throwable $e ) {
			$record = array(
				'run_id'       => \uniqid( 'oos_shadow_' ),
				'timestamp'    => \time(),
				'assistant_id' => (int) $assistant_id,
				'user_id'      => \get_current_user_id(),
				'error'        => \get_class( $e ) . ': ' . $e->getMessage(),
				'duration_ms'  => (int) \round( ( \microtime( true ) - $started_at ) * 1000 ),
				'sampled'      => true,
			);
		}

		self::store_run( $record );

		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( 'oos_shadow_run', 'OOS shadow run recorded.', $record );
		}
	}

	/**
	 * Build the parity record from a shadow result.
	 *
	 * @param array           $result      handleChat() result.
	 * @param float           $startedAt   Microtime when the run started.
	 * @param int             $assistantId Assistant post ID.
	 * @param \WP_REST_Request $request     Originating REST request.
	 * @return array
	 */
	private static function build_record( array $result, float $startedAt, int $assistantId, \WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Byte-identical record-builder signature (the base keeps the request parameter).
		$tool_results = isset( $result['tool_results'] ) && \is_array( $result['tool_results'] )
			? $result['tool_results']
			: array();

		$tool_errors = 0;
		$suppressed  = 0;
		foreach ( $tool_results as $tool_result ) {
			$content = isset( $tool_result['content'] ) ? (string) $tool_result['content'] : '';

			if ( 0 === \strpos( $content, 'Error:' ) ) {
				++$tool_errors;
			}
			if ( false !== \strpos( $content, 'shadow: write-class tool suppressed' ) ) {
				++$suppressed;
			}
		}

		$cost     = isset( $result['cost'] ) && \is_array( $result['cost'] ) ? $result['cost'] : array();
		$response = isset( $result['response'] ) && \is_array( $result['response'] ) ? $result['response'] : array();

		return array(
			'run_id'            => \uniqid( 'oos_shadow_' ),
			'timestamp'         => \time(),
			'assistant_id'      => $assistantId,
			'user_id'           => \get_current_user_id(),
			'duration_ms'       => (int) \round( ( \microtime( true ) - $startedAt ) * 1000 ),
			'iterations'        => (int) ( $result['iterations'] ?? 0 ),
			'cancelled'         => ! empty( $result['cancelled'] ),
			'cancel_reason'     => (string) ( $result['cancel_reason'] ?? '' ),
			'tool_calls'        => \count( $tool_results ),
			'tool_errors'       => $tool_errors,
			'suppressed'        => $suppressed,
			'cost_usd'          => (float) ( $cost['cost_usd'] ?? 0.0 ),
			'prompt_tokens'     => (int) ( $cost['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $cost['completion_tokens'] ?? 0 ),
			'has_response'      => array() !== $response,
			'sampled'           => true,
		);
	}

	/**
	 * Persist one run into the capped store (newest first).
	 *
	 * @param array $record Run record.
	 * @return void
	 */
	private static function store_run( array $record ): void {
		$runs   = \get_option( self::STORE_OPTION, array() );
		$runs   = \is_array( $runs ) ? $runs : array();
		$runs[] = $record;

		if ( \count( $runs ) > self::STORE_MAX ) {
			$runs = \array_slice( $runs, -self::STORE_MAX );
		}

		\update_option( self::STORE_OPTION, $runs, false );
	}

	/**
	 * All stored runs, newest first.
	 *
	 * @param int $limit Max runs to return (clamped 1–100).
	 * @return array
	 */
	public static function get_runs( int $limit = 25 ): array {
		$runs = \get_option( self::STORE_OPTION, array() );
		$runs = \is_array( $runs ) ? \array_reverse( $runs ) : array();

		return \array_slice( $runs, 0, \max( 1, \min( 100, $limit ) ) );
	}

	/**
	 * One stored run by id.
	 *
	 * @param string $run_id Run identifier.
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
	 *
	 * @return void
	 */
	public static function clear_runs(): void {
		\delete_option( self::STORE_OPTION );
	}

	/**
	 * Resolve the OOS orchestrator for this install mode.
	 *
	 * Monolith installs use the base bridge's factory function; standalone
	 * installs use this addon's `CoreBridge` chat orchestrator (the same
	 * `ChatOrchestrator` class, so the named-argument handleChat call is
	 * mode-independent). The discriminator is `function_exists()` on the
	 * base factory — never bare `defined()` — because the factory only
	 * exists when the base bridge file loaded. Deliberately untyped: the
	 * base factory function declares no return type, and test doubles
	 * stand in for the orchestrator in the characterization suite.
	 *
	 * @return object|null ChatOrchestrator (or compatible test double), or null when unavailable.
	 */
	protected static function orchestrator() {
		if ( \function_exists( 'wp_mcp_ai_oos_orchestrator' ) ) {
			return \wp_mcp_ai_oos_orchestrator();
		}

		if ( \class_exists( \NvoosContentGraphAi\CoreBridge::class ) ) {
			return \NvoosContentGraphAi\CoreBridge::instance()->chat;
		}

		return null;
	}

	/**
	 * Resolve the assistant configuration for this install mode.
	 *
	 * Monolith installs use the base assistant CPT; standalone installs
	 * return an empty configuration — the same degradation the base makes
	 * when its assistant CPT class is absent (the standalone chat path is
	 * not assistant-post-bound).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected static function assistant_configuration( int $assistant_id ): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		}

		return array();
	}
}
