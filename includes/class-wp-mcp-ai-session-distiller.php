<?php
/**
 * Session Distiller — turn finished chat sessions into durable memory.
 *
 * Subscribes to `wp_mcp_ai_chat_transcript_recorded` and, when enabled,
 * distills the finished session into the MemPalace memory pipeline by
 * emitting the canonical `wp_mcp_ai_memory_stored` event with
 * `context_type=session`, `memory_tier=episodic`, `wing=session` and
 * `room=<assistant_id>`. The existing CCT bridge, Graphify bridge and
 * `recall_memory` hydration then surface the summary at the next session
 * start — no new storage code.
 *
 * The default distillation is deterministic and extractive (user intents,
 * assistant outcome, tool-call count) and therefore costs **zero provider
 * calls**. A filter seam (`wp_mcp_ai_session_distill_summarizer`) lets Pro
 * and advanced setups substitute an LLM summarizer.
 *
 * Safety rails (all filterable):
 *  - Opt-in: `wp_mcp_ai_session_distill_enabled` defaults to false.
 *  - Minimum message count: `wp_mcp_ai_session_distill_min_messages`
 *    (default 4) — trivial sessions are not distilled.
 *  - Dedupe: one distillation per session key per 12 hours (transient).
 *  - A bounded `wp_mcp_ai_session_summaries` option records the same
 *    summaries as a JetEngine-free fallback and for the Phase B
 *    workflow-suggestion miner.
 *
 * @credit  Session-summary memory concept inspired by affaan-m/ECC
 *          session persistence hooks (MIT).
 * @package WP_MCP_AI
 * @since   1.1.97
 * @author  NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session distiller.
 *
 * @since 1.1.97
 */
class WP_MCP_AI_Session_Distiller {

	/**
	 * Option holding the bounded fallback summary list.
	 *
	 * @var string
	 */
	const OPTION_SUMMARIES = 'wp_mcp_ai_session_summaries';

	/**
	 * Default cap on stored fallback summaries.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_SUMMARIES = 100;

	/**
	 * Dedupe transient TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	const DEDUPE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Subscribe to the transcript lifecycle.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'wp_mcp_ai_chat_transcript_recorded', array( __CLASS__, 'on_transcript_recorded' ), 20, 7 );
	}

	/**
	 * Whether session distillation is enabled.
	 *
	 * Opt-in via the `wp_mcp_ai_session_distill_enabled` filter (default
	 * false) so the feature never surprises a site with extra memory rows
	 * (or provider calls once an LLM summarizer is wired).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filters whether finished sessions are distilled into memory.
		 *
		 * @since 1.1.97
		 *
		 * @param bool $enabled Whether distillation runs. Default false.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_session_distill_enabled', false );
	}

	/**
	 * Transcript-recording handler.
	 *
	 * Tolerates the legacy 2-arg emitter shape defensively: when the first
	 * argument is an array the payload does not match the canonical 7-arg
	 * contract and the handler bails instead of misreading fields.
	 *
	 * @param mixed $session_key  Session identifier.
	 * @param mixed $assistant_id Assistant post ID.
	 * @param mixed $user_id      User ID (0 for guests).
	 * @param mixed $messages     Conversation messages.
	 * @param mixed $response     Final assistant response.
	 * @param mixed $model        Model slug.
	 * @param mixed $provider     Provider slug.
	 * @return void
	 */
	public static function on_transcript_recorded( $session_key, $assistant_id = 0, $user_id = 0, $messages = array(), $response = '', $model = '', $provider = '' ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Legacy dual-shape tolerance (test-suite pattern 30).
		if ( ! is_scalar( $session_key ) ) {
			return;
		}

		$session_key  = (string) $session_key;
		$assistant_id = absint( $assistant_id );

		if ( '' === $session_key ) {
			return;
		}

		$messages = is_array( $messages ) ? $messages : array();

		/**
		 * Filters the minimum message count a session needs before it is
		 * distilled.
		 *
		 * @since 1.1.97
		 *
		 * @param int    $min_messages Minimum message count. Default 4.
		 * @param string $session_key  Session identifier.
		 * @param int    $assistant_id Assistant post ID.
		 */
		$min_messages = (int) apply_filters( 'wp_mcp_ai_session_distill_min_messages', 4, $session_key, $assistant_id );
		if ( count( $messages ) < max( 1, $min_messages ) ) {
			return;
		}

		if ( self::already_distilled( $session_key ) ) {
			return;
		}

		$summary = self::build_extractive_summary( $messages, $response );

		/**
		 * Filters the distilled summary before it is stored.
		 *
		 * Replace this filter with an LLM summarizer to upgrade from the
		 * deterministic extractive default to a semantic summary. The
		 * returned value is treated as untrusted text and escaped at the
		 * storage boundary by the memory pipeline.
		 *
		 * @since 1.1.97
		 *
		 * @param string $summary      Extractive summary text.
		 * @param string $session_key  Session identifier.
		 * @param int    $assistant_id Assistant post ID.
		 * @param array  $messages     Conversation messages.
		 * @param mixed  $response     Final assistant response.
		 */
		$summary = (string) apply_filters( 'wp_mcp_ai_session_distill_summarizer', $summary, $session_key, $assistant_id, $messages, $response );

		self::store( $session_key, $assistant_id, $messages, $summary, $model, $provider );
		self::mark_distilled( $session_key );
	}

	/**
	 * Build a deterministic extractive summary.
	 *
	 * Zero provider cost: first user intents, final assistant outcome, and
	 * the tool-call count, each truncated to a safe length.
	 *
	 * @param array $messages Conversation messages.
	 * @param mixed $response Final assistant response (string or segments).
	 * @return string Summary text.
	 */
	public static function build_extractive_summary( $messages, $response = '' ) {
		$intents    = array();
		$tool_calls = 0;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? (string) $message['role'] : '';
			$content = self::flatten_content( isset( $message['content'] ) ? $message['content'] : '' );

			if ( 'user' === $role && '' !== $content && count( $intents ) < 3 ) {
				$intents[] = self::truncate( $content, 200 );
			}

			if ( 'assistant' === $role && ! empty( $message['tool_calls'] ) ) {
				$tool_calls += is_array( $message['tool_calls'] ) ? count( $message['tool_calls'] ) : 1;
			}
		}

		$outcome = self::truncate( self::flatten_content( $response ), 400 );

		$parts = array();

		if ( ! empty( $intents ) ) {
			/* translators: %s: semicolon-joined user intents. */
			$parts[] = sprintf( __( 'User intents: %s', 'mcp-ai-wpoos' ), implode( '; ', $intents ) );
		}

		if ( '' !== $outcome ) {
			/* translators: %s: final assistant response excerpt. */
			$parts[] = sprintf( __( 'Outcome: %s', 'mcp-ai-wpoos' ), $outcome );
		}

		/* translators: %d: number of tool calls in the session. */
		$parts[] = sprintf( __( 'Tool calls: %d', 'mcp-ai-wpoos' ), $tool_calls );

		return implode( "\n", $parts );
	}

	/**
	 * Emit the canonical memory event and record the bounded fallback list.
	 *
	 * @param string $session_key  Session identifier.
	 * @param int    $assistant_id Assistant post ID.
	 * @param array  $messages     Conversation messages.
	 * @param string $summary      Distilled summary text.
	 * @param mixed  $model        Model slug (recorded as a tag).
	 * @param mixed  $provider     Provider slug (recorded as a tag).
	 * @return void
	 */
	private static function store( $session_key, $assistant_id, $messages, $summary, $model = '', $provider = '' ) {
		$message_count = count( $messages );
		if ( $message_count >= 10 ) {
			$importance = 'high';
		} elseif ( $message_count >= 6 ) {
			$importance = 'medium';
		} else {
			$importance = 'low';
		}

		$title = '';
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && 'user' === ( isset( $message['role'] ) ? (string) $message['role'] : '' ) ) {
				$content = self::flatten_content( isset( $message['content'] ) ? $message['content'] : '' );
				if ( '' !== $content ) {
					$title = self::truncate( $content, 120 );
					break;
				}
			}
		}

		$tags = array( 'session-summary' );
		if ( is_string( $model ) && '' !== $model ) {
			$tags[] = 'model:' . sanitize_key( $model );
		}
		if ( is_string( $provider ) && '' !== $provider ) {
			$tags[] = 'provider:' . sanitize_key( $provider );
		}

		/**
		 * Fires when a session summary is distilled into memory.
		 *
		 * The payload matches the canonical `wp_mcp_ai_memory_stored` event
		 * shape so the CCT bridge, Graphify bridge and recall hydration all
		 * pick it up without changes.
		 *
		 * @since 1.1.97
		 *
		 * @param array $event Memory event payload.
		 */
		do_action(
			'wp_mcp_ai_memory_stored',
			array(
				'context_id'   => 'session_' . substr( md5( $session_key ), 0, 16 ),
				'agent_id'     => (string) $assistant_id,
				'context_type' => 'session',
				'memory_tier'  => 'episodic',
				'wing'         => 'session',
				'room'         => (string) $assistant_id,
				'title'        => $title,
				'content'      => $summary,
				'tags'         => $tags,
				'importance'   => $importance,
				'source'       => 'session_distiller',
				'stored_at'    => current_time( 'mysql' ),
				'expires_at'   => '',
			)
		);

		self::record_fallback( $session_key, $assistant_id, $title, $summary );
	}

	/**
	 * Record the summary in the bounded fallback option.
	 *
	 * @param string $session_key  Session identifier.
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $title        Summary title.
	 * @param string $summary      Summary text.
	 * @return void
	 */
	private static function record_fallback( $session_key, $assistant_id, $title, $summary ) {
		$summaries = get_option( self::OPTION_SUMMARIES, array() );
		if ( ! is_array( $summaries ) ) {
			$summaries = array();
		}

		$entry = array(
			'assistant_id' => $assistant_id,
			'title'        => $title,
			'content'      => $summary,
			'distilled_at' => current_time( 'mysql' ),
		);

		$summaries[ $session_key ] = $entry;

		/**
		 * Filters the maximum number of retained fallback summaries.
		 *
		 * @since 1.1.97
		 *
		 * @param int $max Maximum entries. Default 100.
		 */
		$max = max( 1, (int) apply_filters( 'wp_mcp_ai_session_summaries_max', self::DEFAULT_MAX_SUMMARIES ) );

		if ( count( $summaries ) > $max ) {
			$summaries = array_slice( $summaries, -$max, null, true );
		}

		update_option( self::OPTION_SUMMARIES, $summaries, false );
	}

	/**
	 * Whether this session key was already distilled within the dedupe window.
	 *
	 * @param string $session_key Session identifier.
	 * @return bool
	 */
	private static function already_distilled( $session_key ) {
		return false !== get_transient( self::dedupe_key( $session_key ) );
	}

	/**
	 * Mark a session key as distilled.
	 *
	 * @param string $session_key Session identifier.
	 * @return void
	 */
	private static function mark_distilled( $session_key ) {
		set_transient( self::dedupe_key( $session_key ), 1, self::DEDUPE_TTL );
	}

	/**
	 * Build the dedupe transient key for a session.
	 *
	 * @param string $session_key Session identifier.
	 * @return string
	 */
	private static function dedupe_key( $session_key ) {
		return 'wp_mcp_ai_session_distilled_' . md5( $session_key );
	}

	/**
	 * Flatten message content (string or OpenAI segments) to a string.
	 *
	 * @param mixed $content Message content.
	 * @return string
	 */
	public static function flatten_content( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $segment ) {
				if ( is_array( $segment ) && isset( $segment['text'] ) ) {
					$parts[] = (string) $segment['text'];
				} elseif ( is_scalar( $segment ) ) {
					$parts[] = (string) $segment;
				}
			}
			return implode( "\n", $parts );
		}

		return '';
	}

	/**
	 * Truncate a string to a character budget on a word boundary.
	 *
	 * Multibyte-aware when `mb_*` functions are available; falls back to
	 * byte-safe `substr` otherwise.
	 *
	 * @param string $text  Text to truncate.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function truncate( $text, $limit ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) <= $limit ) {
				return $text;
			}

			$short = mb_substr( $text, 0, $limit );
			$space = mb_strrpos( $short, ' ' );
			if ( false !== $space ) {
				$short = mb_substr( $short, 0, $space );
			}

			return rtrim( $short, " \t\n\r\0\x0B,.;:-" ) . '…';
		}

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		$short = substr( $text, 0, $limit );
		$space = strrpos( $short, ' ' );
		if ( false !== $space ) {
			$short = substr( $short, 0, $space );
		}

		return rtrim( $short, " \t\n\r\0\x0B,.;:-" ) . '…';
	}
}

WP_MCP_AI_Session_Distiller::bootstrap();
