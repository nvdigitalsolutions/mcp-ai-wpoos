<?php
/**
 * Abstract base class for per-toolkit MemPalace capture tools (Phase B).
 *
 * Every per-toolkit capture tool — `crm_capture_interaction`,
 * `pm_capture_decision`, `health_capture_encounter`, etc. — extends this
 * class and supplies five things:
 *
 *   1. {@see get_slug()} / {@see get_name()} / {@see get_description()}.
 *   2. {@see get_wing_prefix()} — the toolkit-specific wing prefix
 *      (`patient/`, `matter/`, `account/`, ...).
 *   3. {@see get_wing_key_name()} — the parameter name the user supplies for
 *      the wing identifier (`member_id`, `account_id`, `project_id`, ...).
 *   4. {@see get_room_enum()} — the closed list of room values for this
 *      toolkit (e.g. `[ 'vitals', 'allergies', 'prescriptions', 'imaging',
 *      'notes' ]` for Healthcare).
 *   5. {@see get_capture_defaults()} — toolkit-specific defaults: tier,
 *      importance, sensitivity, consent_basis, verbatim, ttl, source.
 *
 * The base class then handles every common concern:
 *   - schema generation (with `wing_key` substituted into the JSON),
 *   - argument validation (required wing_key, required room, room ∈ enum),
 *   - wing-prefix sprawl prevention (Risk: wing/room sprawl),
 *   - delegation to {@see WP_MCP_AI_Memory_Capture_Service::store()}.
 *
 * Subclasses are typically ~80 lines; this base is the load-bearing piece.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared scaffolding for per-toolkit MemPalace capture tools.
 */
abstract class WP_MCP_AI_Pro_Capture_Tool_Base implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Toolkit-specific wing prefix (e.g. `patient/`, `account/`, `matter/`).
	 *
	 * @return string
	 */
	abstract protected function get_wing_prefix();

	/**
	 * Name of the parameter the LLM supplies for the wing identifier.
	 *
	 * @return string E.g. `member_id`, `account_id`, `project_id`.
	 */
	abstract protected function get_wing_key_name();

	/**
	 * Closed list of allowed `room` values for this toolkit.
	 *
	 * Refusing free-text room values is the toolkit's contribution to the
	 * "wing/room sprawl" mitigation called out in the plan.
	 *
	 * @return string[]
	 */
	abstract protected function get_room_enum();

	/**
	 * Toolkit-specific capture defaults.
	 *
	 * Recognised keys:
	 *   - `tier`              core|recall|archival   (default: recall)
	 *   - `importance`        0.0..1.0               (default: 0.5)
	 *   - `sensitivity`       public|internal|confidential|pii|privileged|phi (default: internal)
	 *   - `consent_basis`     legitimate_interest|consent|legal_obligation|contract|... (default: '')
	 *   - `verbatim`          bool                   (default: true)
	 *   - `allow_summarisation` bool                 (default: false — only Doc Gen / Multilingual flip this)
	 *   - `ttl`               seconds                (default: 30 days)
	 *   - `source`            string                 (default: tool slug)
	 *   - `default_tags`      string[]               (default: [])
	 *
	 * @return array
	 */
	abstract protected function get_capture_defaults();

	/**
	 * Optional friendly explanation of what the wing key represents.
	 *
	 * @return string
	 */
	protected function get_wing_key_description() {
		return sprintf(
			/* translators: %s: wing key name (e.g. account_id, project_id) */
			__( 'Identifier appended to the wing prefix to form the MemPalace wing slug (%s).', 'mcp-ai-wpoos-pro' ),
			$this->get_wing_key_name()
		);
	}

	/**
	 * Pro tools all participate in the Pro tier.
	 *
	 * @return string[]
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'pii-data' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Subclasses can override but most do not need to.
	 */
	public function get_parameters_schema() {
		$wing_key  = $this->get_wing_key_name();
		$room_enum = $this->get_room_enum();
		$defaults  = $this->get_capture_defaults();

		$properties = array(
			$wing_key      => array(
				'type'        => 'string',
				'description' => $this->get_wing_key_description(),
			),
			'room'         => array(
				'type'        => 'string',
				'description' => __( 'MemPalace room (sub-scope) within the wing. Must be one of the toolkit-specific values.', 'mcp-ai-wpoos-pro' ),
				'enum'        => $room_enum,
			),
			'content'      => array(
				'type'        => 'string',
				'description' => __( 'Verbatim text to capture. Redaction runs before storage so secrets never reach the verbatim record.', 'mcp-ai-wpoos-pro' ),
			),
			'title'        => array(
				'type'        => 'string',
				'description' => __( 'Optional short label for the captured record.', 'mcp-ai-wpoos-pro' ),
			),
			'importance'   => array(
				'type'        => 'number',
				'description' => __( 'Importance 0.0–1.0; drives MemPalace tier promotion/demotion. Defaults to the toolkit-specific value.', 'mcp-ai-wpoos-pro' ),
				'minimum'     => 0,
				'maximum'     => 1,
			),
			'tier'         => array(
				'type'        => 'string',
				'description' => __( 'MemPalace tier override. Subject to per-wing tier ceiling.', 'mcp-ai-wpoos-pro' ),
				'enum'        => array( 'core', 'recall', 'archival' ),
			),
			'tags'         => array(
				'type'        => 'array',
				'description' => __( 'Free-form tags for downstream filtering.', 'mcp-ai-wpoos-pro' ),
				'items'       => array( 'type' => 'string' ),
			),
			'subject_refs' => array(
				'type'        => 'array',
				'description' => __( 'External subject references (URLs, document IDs, FHIR resource ids, etc.) for provenance + replay.', 'mcp-ai-wpoos-pro' ),
				'items'       => array( 'type' => 'string' ),
			),
			'attachments'  => array(
				'type'        => 'array',
				'description' => __( 'Optional attachments with sha256 / url / mime / attachment_id keys.', 'mcp-ai-wpoos-pro' ),
				'items'       => array( 'type' => 'object' ),
			),
			'recorded_at'  => array(
				'type'        => 'string',
				'description' => __( 'ISO 8601 / MySQL datetime when the event actually occurred (Zep recorded_at). Defaults to now.', 'mcp-ai-wpoos-pro' ),
			),
			'valid_from'   => array(
				'type'        => 'string',
				'description' => __( 'Bi-temporal validity start. Defaults to recorded_at.', 'mcp-ai-wpoos-pro' ),
			),
			'valid_until'  => array(
				'type'        => 'string',
				'description' => __( 'Bi-temporal validity end. When supersession arrives, prefer setting this on the prior record rather than overwriting it.', 'mcp-ai-wpoos-pro' ),
			),
		);

		// Toolkits whose plan explicitly allows summarisation (Doc Gen, Multilingual).
		// expose `summary` + `verbatim` parameters.
		if ( ! empty( $defaults['allow_summarisation'] ) ) {
			$properties['summary']  = array(
				'type'        => 'string',
				'description' => __( 'Optional summary captured at tier=recall. The original content is always kept verbatim at tier=archival; the summary becomes the recall-tier representative.', 'mcp-ai-wpoos-pro' ),
			);
			$properties['verbatim'] = array(
				'type'        => 'boolean',
				'description' => __( 'Set to false when capturing a summarised record. The original verbatim content is still preserved at tier=archival.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( $wing_key, 'room', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Subclasses normally do not override this — the heavy lifting happens in
	 * {@see WP_MCP_AI_Memory_Capture_Service::store()}.
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Memory_Capture_Service' ) ) {
			return new WP_Error(
				'capture_service_missing',
				__( 'Memory Capture Service is not loaded; the MemPalace framework is unavailable.', 'mcp-ai-wpoos-pro' )
			);
		}

		$wing_key_name = $this->get_wing_key_name();
		$wing_key      = isset( $arguments[ $wing_key_name ] ) ? sanitize_key( (string) $arguments[ $wing_key_name ] ) : '';
		if ( '' === $wing_key ) {
			return new WP_Error(
				'capture_missing_wing_key',
				sprintf(
					/* translators: %s: wing key name */
					__( '%s is required.', 'mcp-ai-wpoos-pro' ),
					$wing_key_name
				)
			);
		}

		$room      = isset( $arguments['room'] ) ? sanitize_key( (string) $arguments['room'] ) : '';
		$room_enum = $this->get_room_enum();
		if ( '' === $room || ! in_array( $room, $room_enum, true ) ) {
			return new WP_Error(
				'capture_invalid_room',
				sprintf(
					/* translators: 1: room slug, 2: comma-separated allowed values */
					__( 'room "%1$s" is not allowed for this toolkit. Allowed: %2$s.', 'mcp-ai-wpoos-pro' ),
					$room,
					implode( ', ', $room_enum )
				)
			);
		}

		$defaults = $this->get_capture_defaults();
		$wing     = rtrim( $this->get_wing_prefix(), '/' ) . '/' . $wing_key;

		// Resolve agent_id from the execution context — the chat layer passes.
		// the active assistant ID; tests can pass it explicitly.
		$agent_id = isset( $context['assistant_id'] ) ? $context['assistant_id'] : '';
		if ( '' === $agent_id && isset( $context['agent_id'] ) ) {
			$agent_id = $context['agent_id'];
		}
		if ( '' === $agent_id && isset( $arguments['agent_id'] ) ) {
			$agent_id = $arguments['agent_id'];
		}
		if ( '' === $agent_id ) {
			$agent_id = 'capture:' . $this->get_slug();
		}

		// Build the envelope — defaults are toolkit-specific, caller can.
		// override importance / tier / tags / etc. via tool arguments.
		$envelope = array(
			'agent_id'      => $agent_id,
			'context_type'  => $this->get_slug(),
			'wing'          => $wing,
			'room'          => $room,
			'content'       => isset( $arguments['content'] ) ? (string) $arguments['content'] : '',
			'title'         => isset( $arguments['title'] ) ? (string) $arguments['title'] : '',
			'tier'          => isset( $arguments['tier'] ) ? sanitize_key( (string) $arguments['tier'] ) : ( isset( $defaults['tier'] ) ? $defaults['tier'] : 'recall' ),
			'importance'    => isset( $arguments['importance'] ) ? (float) $arguments['importance'] : ( isset( $defaults['importance'] ) ? (float) $defaults['importance'] : 0.5 ),
			'sensitivity'   => isset( $arguments['sensitivity'] ) ? sanitize_key( (string) $arguments['sensitivity'] ) : ( isset( $defaults['sensitivity'] ) ? $defaults['sensitivity'] : 'internal' ),
			'consent_basis' => isset( $arguments['consent_basis'] ) ? sanitize_key( (string) $arguments['consent_basis'] ) : ( isset( $defaults['consent_basis'] ) ? $defaults['consent_basis'] : '' ),
			'verbatim'      => isset( $arguments['verbatim'] ) ? (bool) $arguments['verbatim'] : ( ! isset( $defaults['verbatim'] ) || (bool) $defaults['verbatim'] ),
			'ttl'           => isset( $arguments['ttl'] ) ? absint( $arguments['ttl'] ) : ( isset( $defaults['ttl'] ) ? absint( $defaults['ttl'] ) : 30 * DAY_IN_SECONDS ),
			'source'        => isset( $defaults['source'] ) ? (string) $defaults['source'] : $this->get_slug(),
			'tags'          => $this->merge_string_lists(
				isset( $defaults['default_tags'] ) ? $defaults['default_tags'] : array(),
				isset( $arguments['tags'] ) ? $arguments['tags'] : array()
			),
			'subject_refs'  => isset( $arguments['subject_refs'] ) && is_array( $arguments['subject_refs'] ) ? $arguments['subject_refs'] : array(),
			'attachments'   => isset( $arguments['attachments'] ) && is_array( $arguments['attachments'] ) ? $arguments['attachments'] : array(),
			'recorded_at'   => isset( $arguments['recorded_at'] ) ? (string) $arguments['recorded_at'] : '',
			'valid_from'    => isset( $arguments['valid_from'] ) ? (string) $arguments['valid_from'] : '',
			'valid_until'   => isset( $arguments['valid_until'] ) ? (string) $arguments['valid_until'] : '',
		);

		// Verbatim discipline: only toolkits that explicitly opt in via.
		// `allow_summarisation` may flip `verbatim` to false. Even there we
		// keep the original at tier=archival per mem0/MemPalace, by storing.
		// the verbatim record FIRST, then the summary as a separate record.
		if ( empty( $defaults['allow_summarisation'] ) ) {
			$envelope['verbatim'] = true;
		}

		// Doc Gen / Multilingual two-record discipline.
		if ( ! empty( $defaults['allow_summarisation'] ) && ! empty( $arguments['summary'] ) && false === (bool) $envelope['verbatim'] ) {
			$service = WP_MCP_AI_Memory_Capture_Service::get_instance();

			// Step 1 — write the original verbatim content at tier=archival.
			$verbatim_envelope             = $envelope;
			$verbatim_envelope['verbatim'] = true;
			$verbatim_envelope['tier']     = WP_MCP_AI_Memory_Capture_Service::TIER_ARCHIVAL;
			$verbatim_envelope['source']   = $envelope['source'] . '#verbatim';
			$verbatim_result               = $service->store( $verbatim_envelope );

			// Step 2 — write the summary at the requested tier (default recall).
			$summary_envelope                 = $envelope;
			$summary_envelope['content']      = (string) $arguments['summary'];
			$summary_envelope['verbatim']     = false;
			$summary_envelope['tier']         = isset( $arguments['tier'] ) ? sanitize_key( (string) $arguments['tier'] ) : WP_MCP_AI_Memory_Capture_Service::TIER_RECALL;
			$summary_envelope['source']       = $envelope['source'] . '#summary';
			$summary_envelope['subject_refs'] = array_merge(
				$summary_envelope['subject_refs'],
				isset( $verbatim_result['context_id'] ) && '' !== $verbatim_result['context_id']
					? array( 'verbatim:' . $verbatim_result['context_id'] )
					: array()
			);
			$summary_result                   = $service->store( $summary_envelope );

			return array(
				'success'             => ! empty( $verbatim_result['success'] ) && ! empty( $summary_result['success'] ),
				'wing'                => $wing,
				'room'                => $room,
				'verbatim_context_id' => isset( $verbatim_result['context_id'] ) ? $verbatim_result['context_id'] : '',
				'summary_context_id'  => isset( $summary_result['context_id'] ) ? $summary_result['context_id'] : '',
				'message'             => __( 'Memory captured (verbatim archived, summary at recall tier).', 'mcp-ai-wpoos-pro' ),
			);
		}

		return WP_MCP_AI_Memory_Capture_Service::get_instance()->store( $envelope );
	}

	/**
	 * Merge two string lists deduplicating empty values.
	 *
	 * @param mixed $a First list (or scalar).
	 * @param mixed $b Second list (or scalar).
	 * @return string[]
	 */
	protected function merge_string_lists( $a, $b ) {
		$out = array();
		foreach ( array( $a, $b ) as $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $value ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$out[] = (string) $value;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}
