<?php
/**
 * Memory Capture Service — single entry point for per-toolkit capture tools.
 *
 * Phase A of the MemPalace-aligned Memory Capture Framework. Every per-toolkit
 * capture tool (Healthcare `health_capture_encounter`, Law
 * `law_capture_matter_event`, CRM `crm_capture_interaction`, etc.) calls this
 * service's {@see store()} method instead of writing memory records directly.
 *
 * The service performs four jobs:
 *
 *  1. **Envelope normalisation** — applies MemPalace-aligned defaults: `wing`
 *     and `room` are required, `tier` defaults to `recall`, `verbatim` defaults
 *     to `true`, `importance` defaults to `0.5`, and bi-temporal validity
 *     (`valid_from` / `valid_until`) is derived from `recorded_at` + `ttl`
 *     when not supplied.
 *  2. **Per-wing retention overrides** — looks up the per-wing TTL / sensitivity
 *     ceiling / tier ceiling map maintained by Phase A4. The strictest of
 *     (caller-supplied, wing default, site default) wins.
 *  3. **Redaction hand-off** — runs the `wp_mcp_ai_memory_pre_store_transform`
 *     filter exactly once on the payload, *before* persistence, so verbatim
 *     records never include secrets that the redaction pipeline catches.
 *  4. **Hand-off to the existing transient store** — the actual persistence
 *     flow (transient, dual-write CCT bridge, audit trail) is unchanged. The
 *     service writes through {@see WP_MCP_AI_Agent_Context_Manager::store_context()}
 *     and emits the standard `wp_mcp_ai_memory_stored` event payload, enriched
 *     with every Phase A envelope field. Every existing subscriber — the
 *     CCT bridge, the Graphify Memory Palace bridge, `wake_up_context`,
 *     `recall_memory` — therefore sees Phase A captures with no glue code.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single API surface for per-toolkit MemPalace memory captures.
 */
class WP_MCP_AI_Memory_Capture_Service {

	const TIER_CORE     = 'core';
	const TIER_RECALL   = 'recall';
	const TIER_ARCHIVAL = 'archival';

	/**
	 * Site option storing per-wing retention + sensitivity overrides.
	 *
	 * Shape:
	 *   array(
	 *     'patient/jane-doe' => array(
	 *       'ttl'                  => YEAR_IN_SECONDS * 7, // HIPAA-style retention
	 *       'tier_ceiling'         => 'core',
	 *       'sensitivity_ceiling'  => 'phi',
	 *       'consent_basis_default'=> 'consent',
	 *     ),
	 *     'marketing/q3' => array(
	 *       'ttl'                  => 90 * DAY_IN_SECONDS,
	 *       'tier_ceiling'         => 'recall',
	 *       'sensitivity_ceiling'  => 'internal',
	 *     ),
	 *   )
	 */
	const RETENTION_OPTION = 'wp_mcp_ai_wing_retention_overrides';

	/**
	 * Allowed tier values, ordered weakest → strongest.
	 *
	 * @var string[]
	 */
	const TIERS = array( self::TIER_ARCHIVAL, self::TIER_RECALL, self::TIER_CORE );

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Memory_Capture_Service|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return WP_MCP_AI_Memory_Capture_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Store a MemPalace-aligned capture record.
	 *
	 * Required envelope fields: `agent_id`, `wing`, `room`, `content` (or
	 * `data` / `text`). Other fields default per MemPalace / Letta / Zep / mem0
	 * conventions.
	 *
	 * @param array $envelope Capture envelope.
	 * @return array {
	 *   'success'    => bool,
	 *   'context_id' => string|null,
	 *   'tier'       => string,
	 *   'wing'       => string,
	 *   'room'       => string,
	 *   'message'    => string,
	 * }
	 */
	public function store( array $envelope ) {
		$normalised = $this->normalise_envelope( $envelope );

		if ( is_wp_error( $normalised ) ) {
			return new WP_Error( 'wp_mcp_ai_error', $normalised->get_error_message(), $normalised->get_error_code() );
		}

		// Apply redaction / pre-store transform filter exactly once. Verbatim
		// records run through here too — a redactor is the only sanctioned way
		// to drop PHI / secrets BEFORE the verbatim discipline kicks in.
		$transformed = apply_filters(
			'wp_mcp_ai_memory_pre_store_transform',
			$normalised,
			$normalised
		);

		if ( ! is_array( $transformed ) ) {
			$transformed = $normalised;
		}

		// Persist via the existing context manager (transient store +
		// dual-write CCT bridge + audit trail) when available. Headless
		// unit tests can disable the transient leg via the
		// `wp_mcp_ai_memory_capture_skip_transient` filter and consume the
		// emitted event directly.
		$context_id = isset( $transformed['context_id'] ) ? (string) $transformed['context_id'] : '';
		$store_ttl  = isset( $transformed['ttl'] ) ? absint( $transformed['ttl'] ) : DAY_IN_SECONDS;

		$skip_transient = (bool) apply_filters( 'wp_mcp_ai_memory_capture_skip_transient', false, $transformed );

		if ( ! $skip_transient && class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
			$result  = $manager->store_context(
				$transformed['agent_id'],
				$transformed['context_type'],
				array(
					'content'       => $transformed['content'],
					'title'         => $transformed['title'],
					'wing'          => $transformed['wing'],
					'room'          => $transformed['room'],
					'tags'          => $transformed['tags'],
					'sensitivity'   => $transformed['sensitivity'],
					'consent_basis' => $transformed['consent_basis'],
					'subject_refs'  => $transformed['subject_refs'],
					'attachments'   => $transformed['attachments'],
					'importance'    => $transformed['importance'],
					'verbatim'      => $transformed['verbatim'],
					'memory_tier'   => $transformed['memory_tier'],
					'tier'          => $transformed['tier'],
					'source'        => $transformed['source'],
				),
				$store_ttl
			);

			if ( ! empty( $result['context_id'] ) ) {
				$context_id                = (string) $result['context_id'];
				$transformed['stored_at']  = isset( $result['stored_at'] ) ? (string) $result['stored_at'] : current_time( 'mysql', true );
				$transformed['expires_at'] = isset( $result['expires_at'] ) ? (string) $result['expires_at'] : $transformed['expires_at'];
			}
		}

		if ( '' === $context_id ) {
			$context_id = 'ctx_' . wp_generate_password( 12, false );
		}

		$transformed['context_id'] = $context_id;

		// Emit the canonical event so the CCT bridge, Graphify bridge,
		// wake-up-context, audit trail, etc. all see the new capture.
		do_action( 'wp_mcp_ai_memory_stored', $transformed );

		return array(
			'success'    => true,
			'context_id' => $context_id,
			'tier'       => $transformed['tier'],
			'wing'       => $transformed['wing'],
			'room'       => $transformed['room'],
			'expires_at' => isset( $transformed['expires_at'] ) ? $transformed['expires_at'] : '',
			'message'    => __( 'Memory captured.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Look up the per-wing retention / sensitivity / tier overrides.
	 *
	 * Filterable via `wp_mcp_ai_wing_retention_overrides` so deployments can
	 * source overrides from a configuration provider instead of an option.
	 *
	 * @param string $wing Wing slug (e.g. 'patient/jane-doe').
	 * @return array Overrides for the wing (may be empty).
	 */
	public function get_wing_overrides( $wing ) {
		$wing = is_string( $wing ) ? trim( $wing ) : '';
		if ( '' === $wing ) {
			return array();
		}

		$all = get_option( self::RETENTION_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		/**
		 * Filter the full per-wing retention map.
		 *
		 * @param array $all Map of wing slug => overrides.
		 */
		$all = apply_filters( 'wp_mcp_ai_wing_retention_overrides', $all );

		$exact = isset( $all[ $wing ] ) && is_array( $all[ $wing ] ) ? $all[ $wing ] : array();

		// Allow prefix-based defaults — `patient/*` matches every patient wing.
		if ( false !== strpos( $wing, '/' ) ) {
			$prefix = substr( $wing, 0, strpos( $wing, '/' ) ) . '/*';
			if ( isset( $all[ $prefix ] ) && is_array( $all[ $prefix ] ) ) {
				$exact = array_merge( $all[ $prefix ], $exact );
			}
		}

		return $exact;
	}

	/**
	 * Normalise a raw envelope, applying defaults, validation, and per-wing
	 * retention ceilings.
	 *
	 * @param array $envelope Raw envelope.
	 * @return array|WP_Error Normalised envelope or error on validation failure.
	 */
	protected function normalise_envelope( array $envelope ) {
		$agent_id = isset( $envelope['agent_id'] ) ? $envelope['agent_id'] : '';
		if ( '' === $agent_id || ( ! is_scalar( $agent_id ) ) ) {
			return new WP_Error( 'mempalace_capture_missing_agent', __( 'agent_id is required.', 'mcp-ai-wpoos' ) );
		}

		$wing = isset( $envelope['wing'] ) ? sanitize_text_field( (string) $envelope['wing'] ) : '';
		$room = isset( $envelope['room'] ) ? sanitize_text_field( (string) $envelope['room'] ) : '';

		if ( '' === $wing ) {
			return new WP_Error( 'mempalace_capture_missing_wing', __( 'wing is required (e.g. "patient/jane-doe", "matter/123").', 'mcp-ai-wpoos' ) );
		}
		if ( '' === $room ) {
			return new WP_Error( 'mempalace_capture_missing_room', __( 'room is required (sub-scope within the wing, e.g. "vitals", "covenants").', 'mcp-ai-wpoos' ) );
		}

		// Pull content from any of the conventional aliases.
		$content = '';
		foreach ( array( 'content', 'text', 'data', 'body' ) as $key ) {
			if ( isset( $envelope[ $key ] ) ) {
				$candidate = $envelope[ $key ];
				if ( is_array( $candidate ) || is_object( $candidate ) ) {
					$candidate = wp_json_encode( $candidate );
				}
				if ( is_string( $candidate ) && '' !== $candidate ) {
					$content = $candidate;
					break;
				}
			}
		}
		if ( '' === $content ) {
			return new WP_Error( 'mempalace_capture_missing_content', __( 'content is required.', 'mcp-ai-wpoos' ) );
		}

		$tier = isset( $envelope['tier'] ) ? sanitize_key( (string) $envelope['tier'] ) : self::TIER_RECALL;
		if ( ! in_array( $tier, self::TIERS, true ) ) {
			$tier = self::TIER_RECALL;
		}

		// Apply per-wing tier ceiling (admin can prevent a wing from ever
		// promoting to `core`, e.g. for marketing-grade wings).
		$overrides = $this->get_wing_overrides( $wing );
		if ( ! empty( $overrides['tier_ceiling'] ) && in_array( $overrides['tier_ceiling'], self::TIERS, true ) ) {
			$tier = $this->cap_tier( $tier, (string) $overrides['tier_ceiling'] );
		}

		$verbatim = ! isset( $envelope['verbatim'] ) || (bool) $envelope['verbatim'];

		$importance = isset( $envelope['importance'] ) ? (float) $envelope['importance'] : 0.5;
		if ( $importance < 0 ) {
			$importance = 0.0;
		} elseif ( $importance > 1 ) {
			$importance = 1.0;
		}

		// TTL — caller value, capped by per-wing override, capped by site default.
		$ttl = isset( $envelope['ttl'] ) ? absint( $envelope['ttl'] ) : DAY_IN_SECONDS * 30;
		if ( ! empty( $overrides['ttl'] ) ) {
			// Per-wing TTL is the ceiling — caller can be shorter, never longer.
			$ttl = min( $ttl, absint( $overrides['ttl'] ) );
		}
		if ( $ttl <= 0 ) {
			$ttl = DAY_IN_SECONDS;
		}

		$now         = current_time( 'mysql', true );
		$recorded_at = isset( $envelope['recorded_at'] ) ? sanitize_text_field( (string) $envelope['recorded_at'] ) : $now;
		$valid_from  = isset( $envelope['valid_from'] ) ? sanitize_text_field( (string) $envelope['valid_from'] ) : $recorded_at;
		$valid_until = isset( $envelope['valid_until'] ) ? sanitize_text_field( (string) $envelope['valid_until'] ) : '';
		// Use GMT throughout so `expires_at`, `valid_from`, and `recorded_at`
		// share one time source (avoids "expires before it was stored" bugs
		// on sites where the WP timezone differs from UTC).
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		if ( '' === $valid_until ) {
			$valid_until = $expires_at;
		}

		$sensitivity = isset( $envelope['sensitivity'] ) ? sanitize_key( (string) $envelope['sensitivity'] ) : 'internal';
		if ( ! empty( $overrides['sensitivity_ceiling'] ) ) {
			$sensitivity = $this->cap_sensitivity( $sensitivity, (string) $overrides['sensitivity_ceiling'] );
		}

		$consent_basis = isset( $envelope['consent_basis'] ) ? sanitize_key( (string) $envelope['consent_basis'] ) : '';
		if ( '' === $consent_basis && ! empty( $overrides['consent_basis_default'] ) ) {
			$consent_basis = sanitize_key( (string) $overrides['consent_basis_default'] );
		}

		$subject_refs = array();
		if ( isset( $envelope['subject_refs'] ) && is_array( $envelope['subject_refs'] ) ) {
			foreach ( $envelope['subject_refs'] as $ref ) {
				if ( is_scalar( $ref ) && '' !== (string) $ref ) {
					$subject_refs[] = sanitize_text_field( (string) $ref );
				}
			}
		}

		$attachments = array();
		if ( isset( $envelope['attachments'] ) && is_array( $envelope['attachments'] ) ) {
			foreach ( $envelope['attachments'] as $att ) {
				if ( is_array( $att ) ) {
					$attachments[] = array(
						'attachment_id' => isset( $att['attachment_id'] ) ? absint( $att['attachment_id'] ) : 0,
						'sha256'        => isset( $att['sha256'] ) ? sanitize_text_field( (string) $att['sha256'] ) : '',
						'mime'          => isset( $att['mime'] ) ? sanitize_text_field( (string) $att['mime'] ) : '',
						'url'           => isset( $att['url'] ) ? esc_url_raw( (string) $att['url'] ) : '',
					);
				}
			}
		}

		$tags = array();
		if ( isset( $envelope['tags'] ) && is_array( $envelope['tags'] ) ) {
			foreach ( $envelope['tags'] as $tag ) {
				if ( is_scalar( $tag ) && '' !== (string) $tag ) {
					$tags[] = sanitize_text_field( (string) $tag );
				}
			}
		}

		$context_type = isset( $envelope['context_type'] ) ? sanitize_key( (string) $envelope['context_type'] ) : 'capture';
		$source       = isset( $envelope['source'] ) ? sanitize_text_field( (string) $envelope['source'] ) : 'memory_capture_service';
		$title        = isset( $envelope['title'] ) ? sanitize_text_field( (string) $envelope['title'] ) : '';

		// Letta-style memory_tier (working/episodic/semantic/procedural) is
		// retained for the existing CCT bridge classifier, while `tier`
		// (core/recall/archival) is the MemPalace-aligned promotion axis.
		$memory_tier = isset( $envelope['memory_tier'] ) ? sanitize_key( (string) $envelope['memory_tier'] ) : '';

		return array(
			'agent_id'      => $agent_id,
			'context_id'    => isset( $envelope['context_id'] ) ? sanitize_text_field( (string) $envelope['context_id'] ) : '',
			'context_type'  => $context_type,
			'wing'          => $wing,
			'room'          => $room,
			'tier'          => $tier,
			'memory_tier'   => $memory_tier,
			'verbatim'      => $verbatim,
			'importance'    => $importance,
			'recorded_at'   => $recorded_at,
			'stored_at'     => $now,
			'valid_from'    => $valid_from,
			'valid_until'   => $valid_until,
			'expires_at'    => $expires_at,
			'ttl'           => $ttl,
			'title'         => $title,
			'content'       => $content,
			'tags'          => $tags,
			'source'        => $source,
			'sensitivity'   => $sensitivity,
			'consent_basis' => $consent_basis,
			'subject_refs'  => $subject_refs,
			'attachments'   => $attachments,
			'metadata'      => isset( $envelope['metadata'] ) && is_array( $envelope['metadata'] ) ? $envelope['metadata'] : array(),
			'embedding_id'  => isset( $envelope['embedding_id'] ) ? sanitize_text_field( (string) $envelope['embedding_id'] ) : '',
			'graph_node_id' => isset( $envelope['graph_node_id'] ) ? sanitize_text_field( (string) $envelope['graph_node_id'] ) : '',
		);
	}

	/**
	 * Reduce a tier to a ceiling — never raise.
	 *
	 * @param string $tier    Requested tier.
	 * @param string $ceiling Maximum allowed tier.
	 * @return string
	 */
	protected function cap_tier( $tier, $ceiling ) {
		$ranks = array(
			self::TIER_ARCHIVAL => 0,
			self::TIER_RECALL   => 1,
			self::TIER_CORE     => 2,
		);
		if ( ! isset( $ranks[ $tier ] ) || ! isset( $ranks[ $ceiling ] ) ) {
			return $tier;
		}
		return $ranks[ $tier ] > $ranks[ $ceiling ] ? $ceiling : $tier;
	}

	/**
	 * Raise a sensitivity to a ceiling — never lower.
	 *
	 * The ceiling models "this wing is at least PHI" semantics: a caller may
	 * request `internal`, but if the wing override is `phi`, the record must
	 * be treated as `phi`.
	 *
	 * @param string $sensitivity Requested sensitivity.
	 * @param string $ceiling     Minimum sensitivity for this wing.
	 * @return string
	 */
	protected function cap_sensitivity( $sensitivity, $ceiling ) {
		$ranks = array(
			'public'       => 0,
			'internal'     => 1,
			'confidential' => 2,
			'pii'          => 3,
			'privileged'   => 4,
			'phi'          => 5,
		);
		if ( ! isset( $ranks[ $sensitivity ] ) || ! isset( $ranks[ $ceiling ] ) ) {
			return $sensitivity;
		}
		return $ranks[ $sensitivity ] < $ranks[ $ceiling ] ? $ceiling : $sensitivity;
	}
}
