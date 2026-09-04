<?php
/**
 * Memory Auto-Capture Service — Phase 3 of the 2026 Memory Layer Enhancements.
 *
 * Silently observes `wp_mcp_ai_tool_executed` and `wp_mcp_ai_before_chat_request`
 * and writes "observation"-typed memory records to the existing
 * {@see WP_MCP_AI_Memory_Capture_Service}, but only after:
 *
 *  1. The master kill-switch filter `wp_mcp_ai_memory_auto_capture_enabled`
 *     returns true. **Default: false.** When the filter is false the service
 *     never registers its hooks, so a disabled deployment pays zero cost.
 *  2. The site-wide chat-memory gate (`wp_mcp_ai_chat_memory_enabled` filter)
 *     and per-user toggle (`wp_mcp_ai_chat_memory_enabled` user meta) both
 *     allow capture for the current user. Guests are skipped unless the
 *     `wp_mcp_ai_memory_auto_capture_guests_allowed` filter returns true.
 *  3. The tool is not in the denylist (default denylist excludes every memory
 *     retrieval / mutation tool — see `default_denylist()`). When the optional
 *     allowlist filter returns a non-empty array, only listed tools are kept.
 *  4. The content survives SHA-256 dedup: we normalise the candidate content
 *     via {@see WP_MCP_AI_Agent_Memory_CCT_Bridge::normalise_for_hash()},
 *     redact it via {@see WP_MCP_AI_Memory_Privacy_Filter::redact()} so that
 *     secrets are *not* part of the dedup key, and hash the result. The 32-char
 *     prefix is used as a transient key; subsequent identical observations
 *     within the configurable window (default 300s) are skipped.
 *
 * Inspired by `rohitg00/agentmemory`'s auto-capture + SHA-256 dedup loop,
 * implemented in a way that respects every existing memory-layer gate. The
 * service is purely additive — no API or REST shape changes.
 *
 * @link    https://github.com/rohitg00/agentmemory
 *
 * @package WP_MCP_AI
 * @since   1.1.20
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observes lifecycle hooks and writes dedup-gated "observation" memory records.
 *
 * Stateless beyond the singleton bootstrap flag — every public method is
 * idempotent and safe to call directly from tests.
 */
class WP_MCP_AI_Memory_Auto_Capture_Service {

	/**
	 * Transient key prefix for the SHA-256 dedup window.
	 *
	 * The full key is `wp_mcp_ai_memory_dedup_` + substr($sha, 0, 32) which
	 * keeps the resulting option key well below the 172-char limit imposed by
	 * WordPress (`_transient_timeout_` + the key).
	 */
	const DEDUP_TRANSIENT_PREFIX = 'wp_mcp_ai_memory_dedup_';

	/**
	 * Shared agent bucket for guest captures.
	 *
	 * Guests are skipped by default; when a site opts in via
	 * `wp_mcp_ai_memory_auto_capture_guests_allowed`, observations are
	 * written under this single identifier because a guest has no stable
	 * user-scoped identity (the cross-visitor risk of the shared bucket is
	 * documented on the filter itself).
	 */
	const DEFAULT_GUEST_AGENT_ID = 'guest';

	/**
	 * Default importance score for auto-captured records.
	 *
	 * Auto-captures are observations, not user-curated facts, so they start
	 * lower on the importance axis. Phase 5's decay sweep is tuned around this
	 * baseline; changing it without also updating the decay constants will
	 * cause auto-captures to either evaporate too fast or linger too long.
	 */
	const DEFAULT_IMPORTANCE = 0.3;

	/**
	 * Default dedup window in seconds.
	 */
	const DEFAULT_DEDUP_WINDOW = 300;

	/**
	 * Default wing for unscoped auto-captures.
	 *
	 * The Phase 7a Memory Health UI will let admins define scope rules that
	 * route auto-captures into specific wings; until then everything lands in
	 * a dedicated "auto" wing so it can be filtered out of recall queries
	 * if desired.
	 */
	const DEFAULT_WING = 'auto';

	/**
	 * Default room for unscoped auto-captures.
	 */
	const DEFAULT_ROOM = 'unscoped';

	/**
	 * Singleton bootstrap flag — guards against double-hook on re-include.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Wire the auto-capture hooks into the memory pipeline.
	 *
	 * Idempotent. When `wp_mcp_ai_memory_auto_capture_enabled` returns false
	 * (the default), this method is a no-op: no hooks are registered, no
	 * transients are read, and no per-event overhead is paid.
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			return;
		}

		/**
		 * Master kill-switch for the memory auto-capture service.
		 *
		 * Default: false. Auto-capture is opt-in because the durable-memory
		 * layer's decay tuning (Phase 5) has not been stress-tested in
		 * long-running production environments. Enable only after reading
		 * `docs/features/memory/auto-capture.md` and configuring the Phase 5
		 * decay knobs to match your retention policy.
		 *
		 * @since 1.1.20
		 *
		 * @param bool $enabled Defaults to false.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_auto_capture_enabled', false ) ) {
			self::$bootstrapped = true;
			return;
		}

		// Tool-execution capture — mirrors the nefarious-usage-monitor wiring
		// so the action priority and accepted-args count stay in sync with
		// the other consumer of this hook.
		add_action(
			'wp_mcp_ai_tool_executed',
			array( __CLASS__, 'on_tool_executed' ),
			20,
			4
		);

		// Chat-request capture — the canonical signature is
		// `($assistant_id, $messages, $options, $request)`, but every argument
		// is defaulted so the callback also tolerates the 2-arg shape used
		// by some custom emitters and unit-tests.
		add_action(
			'wp_mcp_ai_before_chat_request',
			array( __CLASS__, 'on_before_chat_request' ),
			20,
			4
		);

		self::$bootstrapped = true;
	}

	/**
	 * Reset the singleton bootstrap state.
	 *
	 * Exposed for tests so they can exercise the hook-registration logic in
	 * isolation. Production code never calls this.
	 *
	 * @internal
	 */
	public static function reset_for_tests() {
		remove_action( 'wp_mcp_ai_tool_executed', array( __CLASS__, 'on_tool_executed' ), 20 );
		remove_action( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'on_before_chat_request' ), 20 );
		self::$bootstrapped = false;
	}

	/**
	 * Default denylist — tools that must never be auto-captured.
	 *
	 * Every entry is a memory-retrieval, memory-mutation, or audit-trail tool.
	 * Capturing their output would cause a feedback loop where each retrieval
	 * spawns a new memory of the retrieval itself, which then surfaces in the
	 * next retrieval, etc.
	 *
	 * @return string[] List of tool slugs.
	 */
	public static function default_denylist() {
		return array(
			'recall_memory',
			'wake_up_context',
			'retrieve_agent_memory',
			'semantic_context_search',
			// Already an explicit write path — don't double-capture.
			'store_agent_context',
			'mine_agent_memory',
			'batch_manage_memory',
			'manage_context_lifecycle',
			'memory_audit_trail',
		);
	}

	// ---------------------------------------------------------------------
	// Hook callbacks.
	// ---------------------------------------------------------------------

	/**
	 * Callback for `wp_mcp_ai_tool_executed`.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param mixed  $arguments Tool arguments (usually array).
	 * @param mixed  $result    Tool result (array or WP_Error).
	 * @param mixed  $context   Execution context (array, usually with `user_id`).
	 * @return void
	 */
	public static function on_tool_executed( $tool_slug = '', $arguments = array(), $result = null, $context = array() ) {
		$tool_slug = is_string( $tool_slug ) ? $tool_slug : '';
		if ( '' === $tool_slug ) {
			return;
		}

		if ( ! self::is_tool_allowed( $tool_slug ) ) {
			return;
		}

		$context = is_array( $context ) ? $context : array();
		$user_id = self::resolve_user_id_from_context( $context );

		if ( ! self::user_can_capture( $user_id ) ) {
			return;
		}

		$content_parts = array( 'tool:' . $tool_slug );
		if ( is_array( $arguments ) && ! empty( $arguments ) ) {
			$encoded = wp_json_encode( $arguments );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$content_parts[] = 'args:' . $encoded;
			}
		}

		// We deliberately omit raw result payloads from the content body —
		// they can be very large and are recoverable from the audit trail.
		// A short status flag is enough to differentiate success / error
		// observations within the same dedup window.
		$status          = is_wp_error( $result ) ? 'error' : 'ok';
		$content_parts[] = 'status:' . $status;

		$content = implode( ' | ', $content_parts );

		self::capture(
			$content,
			array(
				'source'       => 'tool_execution',
				'user_id'      => $user_id,
				'agent_id'     => self::resolve_agent_id( $context, $user_id ),
				'tool_slug'    => $tool_slug,
				'context_meta' => $context,
			)
		);
	}

	/**
	 * Callback for `wp_mcp_ai_before_chat_request`.
	 *
	 * @param mixed $assistant_id Assistant ID (int) or — under the 2-arg shape
	 *                            used by some emitters — the messages array.
	 * @param mixed $messages     Messages array.
	 * @param mixed $options      Prepared options array (unused).
	 * @param mixed $request      WP_REST_Request instance (unused).
	 * @return void
	 */
	public static function on_before_chat_request( $assistant_id = 0, $messages = array(), $options = null, $request = null ) {
		unset( $options, $request );

		// Tolerate the 2-arg shape `($messages, $request_data)` used by some
		// custom emitters / older code paths.
		if ( is_array( $assistant_id ) && ! is_array( $messages ) ) {
			$messages     = $assistant_id;
			$assistant_id = 0;
		}

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! self::user_can_capture( $user_id ) ) {
			return;
		}

		// Extract the most recent user-role message — that's the prompt we
		// want to observe. Assistant / tool / system messages are not
		// captured here (they are produced by the model, not the user).
		$prompt = self::extract_latest_user_message( $messages );
		if ( '' === $prompt ) {
			return;
		}

		$context_meta = array(
			'assistant_id' => is_numeric( $assistant_id ) ? (int) $assistant_id : 0,
		);

		self::capture(
			'prompt: ' . $prompt,
			array(
				'source'       => 'chat_request',
				'user_id'      => $user_id,
				'agent_id'     => self::resolve_agent_id( $context_meta, $user_id ),
				'context_meta' => $context_meta,
			)
		);
	}

	// ---------------------------------------------------------------------
	// Core capture pipeline.
	// ---------------------------------------------------------------------

	/**
	 * Run the dedup + store pipeline for a single observation.
	 *
	 * Exposed publicly so other observers (and tests) can drive a capture
	 * without going through the lifecycle hooks. Returns true when the record
	 * was stored, false when it was deduped or rejected by a gate.
	 *
	 * @since 1.1.20
	 *
	 * @param string $raw_content Raw, un-redacted content. Will be normalised
	 *                            and redacted before hashing / storage.
	 * @param array  $args        {
	 *     Capture metadata.
	 *
	 *     @type string $source       Source label (e.g. 'tool_execution').
	 *                                Required.
	 *     @type int    $user_id      User the capture is attributed to.
	 *     @type mixed  $agent_id     Agent identifier (int post ID, or string
	 *                                like `user_42`).
	 *     @type string $tool_slug    Tool slug for tool-execution captures.
	 *     @type array  $context_meta Free-form metadata passed through to
	 *                                the capture envelope's `metadata` field.
	 * }
	 * @return bool True when stored, false when skipped.
	 */
	public static function capture( $raw_content, array $args ) {
		if ( ! is_string( $raw_content ) || '' === trim( $raw_content ) ) {
			return false;
		}

		$source = isset( $args['source'] ) ? (string) $args['source'] : 'unknown';

		// 1. Normalise -> redact -> hash. Redaction MUST happen before
		// hashing so two records that differ only in their embedded secret
		// values still collide on the dedup key.
		$normalised = '';
		if ( class_exists( 'WP_MCP_AI_Agent_Memory_CCT_Bridge' ) ) {
			$normalised = WP_MCP_AI_Agent_Memory_CCT_Bridge::normalise_for_hash( $raw_content );
		}
		if ( '' === $normalised ) {
			// Fallback normalisation in headless environments where the CCT
			// bridge class is not loaded — keeps the test surface tractable.
			$normalised = trim( strtolower( (string) preg_replace( '/\s+/u', ' ', $raw_content ) ) );
		}
		if ( '' === $normalised ) {
			return false;
		}

		$redacted = $normalised;
		if ( class_exists( 'WP_MCP_AI_Memory_Privacy_Filter' ) ) {
			$redacted = (string) WP_MCP_AI_Memory_Privacy_Filter::redact( $normalised );
		}
		if ( '' === $redacted ) {
			return false;
		}

		$sha256 = hash( 'sha256', $redacted );

		// 2. Dedup-window check.
		if ( self::is_dedup_hit( $sha256 ) ) {
			/**
			 * Fires when an auto-capture observation collides with a record
			 * already inside the dedup window.
			 *
			 * @since 1.1.20
			 *
			 * @param string $sha256 64-char hex hash of the redacted content.
			 * @param string $source Source label (`tool_execution`, etc.).
			 */
			do_action( 'wp_mcp_ai_memory_auto_capture_deduped', $sha256, $source );
			return false;
		}

		// 3. Resolve the identity the capture will be written under. Logged-in
		// users get a `user_{ID}` bucket; guests have no user-scoped identity
		// and are skipped unless the site explicitly opts in via
		// `wp_mcp_ai_memory_auto_capture_guests_allowed`, in which case all
		// guest observations share the `guest` bucket.
		$user_id  = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$agent_id = isset( $args['agent_id'] ) && ! empty( $args['agent_id'] )
			? $args['agent_id']
			: ( $user_id > 0 ? 'user_' . $user_id : '' );

		if ( empty( $agent_id ) ) {
			if ( $user_id <= 0 && (bool) apply_filters( 'wp_mcp_ai_memory_auto_capture_guests_allowed', false ) ) {
				$agent_id = self::DEFAULT_GUEST_AGENT_ID;
			} else {
				return false;
			}
		}

		// Only enter the dedup window once the capture is actually eligible —
		// a blocked observation must not consume the window for a later
		// legitimate capture of the same content.
		self::mark_dedup( $sha256 );

		$importance = (float) apply_filters( 'wp_mcp_ai_memory_auto_capture_importance', self::DEFAULT_IMPORTANCE );
		if ( $importance < 0.0 ) {
			$importance = 0.0;
		} elseif ( $importance > 1.0 ) {
			$importance = 1.0;
		}

		/**
		 * Filter the default wing assigned to auto-captured observations.
		 *
		 * Auto-captures are scope-less by default — there's no user-supplied
		 * wing / room when an observation is harvested from a hook. The
		 * Phase 7a admin UI will offer scope rules; until then `auto`
		 * is the convention.
		 *
		 * @since 1.1.20
		 *
		 * @param string $wing Default wing slug.
		 * @param array  $args Capture metadata.
		 */
		$wing = (string) apply_filters( 'wp_mcp_ai_memory_auto_capture_wing', self::DEFAULT_WING, $args );

		/**
		 * Filter the default room assigned to auto-captured observations.
		 *
		 * @since 1.1.20
		 *
		 * @param string $room Default room slug.
		 * @param array  $args Capture metadata.
		 */
		$room = (string) apply_filters( 'wp_mcp_ai_memory_auto_capture_room', self::DEFAULT_ROOM, $args );

		$envelope = array(
			'agent_id'      => $agent_id,
			'wing'          => '' !== $wing ? $wing : self::DEFAULT_WING,
			'room'          => '' !== $room ? $room : self::DEFAULT_ROOM,
			'tier'          => 'recall',
			'context_type'  => 'observation',
			'importance'    => $importance,
			// Content goes in as the *redacted* normalised string. The
			// capture service will re-run `wp_mcp_ai_memory_pre_store_transform`
			// (and therefore the privacy filter) for defence-in-depth, which
			// is a no-op on already-redacted text.
			'content'       => $redacted,
			'source'        => 'auto_capture:' . $source,
			'verbatim'      => false,
			'auto_captured' => true,
			'content_hash'  => $sha256,
			'tags'          => array( 'auto-capture', 'source:' . $source ),
			'metadata'      => isset( $args['context_meta'] ) && is_array( $args['context_meta'] )
				? $args['context_meta']
				: array(),
		);

		if ( ! empty( $args['tool_slug'] ) ) {
			$envelope['metadata']['tool_slug'] = (string) $args['tool_slug'];
		}

		if ( ! class_exists( 'WP_MCP_AI_Memory_Capture_Service' ) ) {
			return false;
		}

		// `WP_MCP_AI_Memory_Capture_Service::normalise_envelope()` builds a fixed
		// list of fields and drops anything outside that list — including the
		// Phase 2 CCT fields `auto_captured` and `content_hash`. Register a
		// one-shot filter at priority 9 (after the priority-5 privacy filter,
		// before the default priority-10 user transforms) to re-inject them on
		// the post-normalisation envelope so the `wp_mcp_ai_memory_stored`
		// event payload (and downstream CCT bridge) sees the right values.
		$injected_fields = array(
			'auto_captured' => true,
			'content_hash'  => $sha256,
		);
		$inject_callback = static function ( $context_data ) use ( $injected_fields ) {
			if ( is_array( $context_data ) ) {
				foreach ( $injected_fields as $key => $value ) {
					$context_data[ $key ] = $value;
				}
			}
			return $context_data;
		};
		add_filter( 'wp_mcp_ai_memory_pre_store_transform', $inject_callback, 9, 6 );

		$result = WP_MCP_AI_Memory_Capture_Service::get_instance()->store( $envelope );

		remove_filter( 'wp_mcp_ai_memory_pre_store_transform', $inject_callback, 9 );

		$context_id = isset( $result['context_id'] ) ? (string) $result['context_id'] : '';
		if ( '' === $context_id || empty( $result['success'] ) ) {
			return false;
		}

		/**
		 * Fires once after a fresh auto-capture has been persisted.
		 *
		 * @since 1.1.20
		 *
		 * @param string $context_id Stored context_id.
		 * @param string $sha256     SHA-256 hash of the redacted content.
		 * @param string $source     Source label.
		 */
		do_action( 'wp_mcp_ai_memory_auto_captured', $context_id, $sha256, $source );

		return true;
	}

	// ---------------------------------------------------------------------
	// Gate helpers.
	// ---------------------------------------------------------------------

	/**
	 * Whether the given tool slug is allowed by the allowlist + denylist gates.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return bool
	 */
	protected static function is_tool_allowed( $tool_slug ) {
		/**
		 * Filter the auto-capture denylist.
		 *
		 * Tools listed here are silently skipped. The default set blocks
		 * every memory-retrieval / mutation tool so retrievals never spawn
		 * a memory of themselves.
		 *
		 * @since 1.1.20
		 *
		 * @param string[] $denylist Default denylist (see `default_denylist()`).
		 */
		$denylist = apply_filters( 'wp_mcp_ai_memory_auto_capture_tool_denylist', self::default_denylist() );
		if ( is_array( $denylist ) && in_array( $tool_slug, $denylist, true ) ) {
			return false;
		}

		/**
		 * Filter the auto-capture allowlist.
		 *
		 * Default: empty. When non-empty, ONLY listed tools are captured —
		 * the denylist becomes a secondary filter applied after this gate.
		 *
		 * @since 1.1.20
		 *
		 * @param string[] $allowlist Default allowlist (empty).
		 */
		$allowlist = apply_filters( 'wp_mcp_ai_memory_auto_capture_tool_allowlist', array() );
		if ( is_array( $allowlist ) && ! empty( $allowlist ) && ! in_array( $tool_slug, $allowlist, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the given user is allowed to have auto-captures written.
	 *
	 * Honours the per-user `wp_mcp_ai_chat_memory_enabled` meta and the
	 * site-wide filter of the same name, and skips guests unless explicitly
	 * allowed.
	 *
	 * @param int $user_id User ID. 0 means guest.
	 * @return bool
	 */
	protected static function user_can_capture( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			/**
			 * Whether guests (user_id 0) are allowed to have auto-captures.
			 *
			 * Default: false. Guests rarely have a stable identity to attach
			 * memories to and capturing them risks leaking session data
			 * across visitors when the chat surface is public.
			 *
			 * @since 1.1.20
			 *
			 * @param bool $allowed Defaults to false.
			 */
			return (bool) apply_filters( 'wp_mcp_ai_memory_auto_capture_guests_allowed', false );
		}

		// Per-user toggle (default true if the meta key is unset).
		$meta = get_user_meta( $user_id, 'wp_mcp_ai_chat_memory_enabled', true );
		if ( '' !== $meta && ! (bool) $meta ) {
			return false;
		}

		$enabled = '' === $meta ? true : (bool) $meta;

		/**
		 * Site-wide chat-memory kill-switch. Shared with the Chat Memory
		 * Drawer + REST surface (see WP_MCP_AI_REST_Chat_Memory_Controller).
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Combined per-user + site default.
		 * @param int  $user_id User being checked.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_chat_memory_enabled', $enabled, $user_id );
	}

	/**
	 * Resolve a `user_id` from a free-form context array.
	 *
	 * Falls back to `get_current_user_id()` when the context omits an explicit
	 * `user_id`. Returns 0 for unauthenticated visitors.
	 *
	 * @param array $context Context array.
	 * @return int
	 */
	protected static function resolve_user_id_from_context( array $context ) {
		if ( isset( $context['user_id'] ) && is_numeric( $context['user_id'] ) ) {
			$candidate = absint( $context['user_id'] );
			if ( $candidate > 0 ) {
				return $candidate;
			}
		}
		return absint( get_current_user_id() );
	}

	/**
	 * Resolve an agent identifier from context, mirroring the slash-command
	 * convention used elsewhere in the plugin.
	 *
	 * @param array $context Context array (may include `assistant_id` / `agent_id`).
	 * @param int   $user_id Fallback user ID.
	 * @return int|string
	 */
	protected static function resolve_agent_id( array $context, $user_id ) {
		if ( ! empty( $context['assistant_id'] ) && is_numeric( $context['assistant_id'] ) ) {
			$assistant_id = absint( $context['assistant_id'] );
			if ( $assistant_id > 0 ) {
				return $assistant_id;
			}
		}
		if ( ! empty( $context['agent_id'] ) ) {
			return $context['agent_id'];
		}
		$user_id = absint( $user_id );
		return $user_id > 0 ? 'user_' . $user_id : 0;
	}

	// ---------------------------------------------------------------------
	// Dedup transient helpers.
	// ---------------------------------------------------------------------

	/**
	 * Build the transient key for a given SHA-256 hash.
	 *
	 * @param string $sha256 64-char hex SHA-256.
	 * @return string Transient key.
	 */
	protected static function dedup_key( $sha256 ) {
		return self::DEDUP_TRANSIENT_PREFIX . substr( (string) $sha256, 0, 32 );
	}

	/**
	 * Whether the given hash is currently inside the dedup window.
	 *
	 * @param string $sha256 SHA-256 of the redacted, normalised content.
	 * @return bool
	 */
	protected static function is_dedup_hit( $sha256 ) {
		$value = get_transient( self::dedup_key( $sha256 ) );
		return false !== $value;
	}

	/**
	 * Insert a dedup-window entry for the given hash.
	 *
	 * @param string $sha256 SHA-256 hash.
	 * @return void
	 */
	protected static function mark_dedup( $sha256 ) {
		/**
		 * Filter the dedup-window TTL in seconds.
		 *
		 * Default: 300s (5 minutes). Increasing this reduces auto-capture
		 * volume in conversational workloads where the user repeats the
		 * same phrasing; decreasing it captures more fine-grained
		 * observations at the cost of duplicate-rate noise.
		 *
		 * @since 1.1.20
		 *
		 * @param int $window Default 300.
		 */
		$window = (int) apply_filters( 'wp_mcp_ai_memory_auto_capture_dedup_window', self::DEFAULT_DEDUP_WINDOW );
		if ( $window <= 0 ) {
			$window = self::DEFAULT_DEDUP_WINDOW;
		}

		set_transient( self::dedup_key( $sha256 ), 1, $window );
	}

	// ---------------------------------------------------------------------
	// Content extraction.
	// ---------------------------------------------------------------------

	/**
	 * Pull the most recent user-role message from a chat messages array.
	 *
	 * Tolerant of both the canonical OpenAI shape (`{role, content}`) and the
	 * NV oOS internal shape where `content` may itself be an array (for
	 * multimodal turns). Non-string content blocks are JSON-encoded so they
	 * still contribute a hashable signal.
	 *
	 * @param array $messages Messages array.
	 * @return string The latest user prompt, or '' when none found.
	 */
	protected static function extract_latest_user_message( array $messages ) {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$message = $messages[ $i ];
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role = isset( $message['role'] ) ? (string) $message['role'] : '';
			if ( 'user' !== $role ) {
				continue;
			}

			$content = isset( $message['content'] ) ? $message['content'] : '';
			if ( is_array( $content ) ) {
				$encoded = wp_json_encode( $content );
				$content = is_string( $encoded ) ? $encoded : '';
			}
			if ( ! is_string( $content ) ) {
				$content = '';
			}

			$content = trim( $content );
			if ( '' !== $content ) {
				return $content;
			}
		}

		return '';
	}
}
