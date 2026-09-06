<?php
/**
 * Markup elicitation envelopes (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Elicitation`
 * (`includes/markup/`): byte-identical chat-widget payload
 * (`markup_elicitation` discriminator) and MCP `elicitation/create`
 * envelope (with the injected `markup` schema field and the URL-mode
 * fallback), the W3C Web Annotation skeleton builder with the
 * mode→motivation map, the schema normalizer (empty-properties
 * `stdClass` quirk), and the submit/fallback URL builders.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `stdClass` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Pure functions over a markup request and its (eventual) annotation
 * payload. No state, no side effects.
 *
 * @since 1.1.0
 */
class MarkupElicitation {

	/**
	 * The W3C Web Annotation JSON-LD context URL.
	 */
	const ANNOTATION_CONTEXT = 'http://www.w3.org/ns/anno.jsonld';

	/**
	 * Build the chat-bubble widget payload for the in-house client.
	 *
	 * @param MarkupRequest $request Source request.
	 * @return array
	 */
	public static function to_widget_payload( MarkupRequest $request ) {
		$payload = array(
			'type'         => 'markup_elicitation',
			'request_id'   => $request->get_request_id(),
			'tool'         => $request->get_tool_slug(),
			'target_type'  => $request->get_target_type(),
			'mode'         => $request->get_mode(),
			'target'       => $request->get_target(),
			'instructions' => $request->get_instructions(),
			'schema'       => self::normalize_schema( $request->get_schema() ),
			'expires_at'   => $request->get_expires_at(),
			'submit_url'   => self::build_submit_url( $request->get_request_id() ),
			'fallback_url' => self::build_fallback_url( $request->get_request_id() ),
		);

		/**
		 * Filter the chat widget payload before it is streamed to the client.
		 *
		 * @param array         $payload Widget payload.
		 * @param MarkupRequest $request Source request.
		 */
		return \apply_filters( 'wp_mcp_ai_markup_widget_payload', $payload, $request );
	}

	/**
	 * Build an MCP `elicitation/create` payload for external MCP clients.
	 *
	 * The MCP spec 2025-11-25 added URL-mode elicitation; we always include
	 * a URL fallback alongside the structured schema so hosts that do not
	 * yet understand our extension can still surface the canvas.
	 *
	 * @param MarkupRequest $request Source request.
	 * @return array
	 */
	public static function to_mcp_elicitation( MarkupRequest $request ) {
		$schema_props = self::normalize_schema( $request->get_schema() );
		// Inject the markup field at the top of the schema so MCP clients
		// that introspect the schema know the canonical interchange field.
		$properties = array(
			'markup' => array(
				'type'        => 'object',
				'description' => 'W3C Web Annotation document describing the user markup.',
			),
		);
		if ( ! empty( $schema_props['properties'] ) && \is_array( $schema_props['properties'] ) ) {
			$properties = \array_merge( $properties, $schema_props['properties'] );
		}

		$elicitation = array(
			'method' => 'elicitation/create',
			'params' => array(
				'message'         => $request->get_instructions() !== ''
					? $request->get_instructions()
					: __( 'Please mark up the asset to continue.', 'nvoos-content-graph-ai' ),
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'markup' ),
				),
				'urlMode'         => array(
					'url'         => self::build_fallback_url( $request->get_request_id() ),
					'description' => __( 'Open the markup canvas in a web browser.', 'nvoos-content-graph-ai' ),
				),
				'_nvoos'          => array(
					'request_id'  => $request->get_request_id(),
					'tool'        => $request->get_tool_slug(),
					'target_type' => $request->get_target_type(),
					'mode'        => $request->get_mode(),
					'target'      => $request->get_target(),
					'expires_at'  => $request->get_expires_at(),
				),
			),
		);

		/**
		 * Filter the MCP elicitation payload before it is delivered.
		 *
		 * @param array         $elicitation MCP envelope.
		 * @param MarkupRequest $request     Source request.
		 */
		return \apply_filters( 'wp_mcp_ai_markup_mcp_elicitation', $elicitation, $request );
	}

	/**
	 * Build a skeleton W3C Web Annotation envelope.
	 *
	 * Helper for tests and rasterizer callers; the canvas widget itself
	 * produces its own annotation document.
	 *
	 * @param MarkupRequest $request Source request.
	 * @param array         $body    Optional body items.
	 * @param array         $target  Optional target overrides.
	 * @return array
	 */
	public static function build_annotation( MarkupRequest $request, array $body = array(), array $target = array() ) {
		$source = $target;
		if ( empty( $source ) ) {
			$req_target = $request->get_target();
			if ( ! empty( $req_target['url'] ) ) {
				$source = array(
					'source' => $req_target['url'],
				);
			} elseif ( ! empty( $req_target['attachment_id'] ) ) {
				$source = array(
					'source' => 'wp-attachment://' . (int) $req_target['attachment_id'],
				);
			} else {
				$source = array(
					'source' => '',
				);
			}
		}

		return array(
			'@context'   => self::ANNOTATION_CONTEXT,
			'type'       => 'Annotation',
			'id'         => 'urn:nvoos:markup:' . $request->get_request_id(),
			'motivation' => self::motivation_for_mode( $request->get_mode() ),
			'body'       => $body,
			'target'     => $source,
		);
	}

	/**
	 * Map an interaction mode to a W3C motivation term.
	 *
	 * @param string $mode Interaction mode.
	 * @return string Motivation term.
	 */
	public static function motivation_for_mode( $mode ) {
		switch ( $mode ) {
			case MarkupRequest::MODE_REDACT:
				return 'moderating';
			case MarkupRequest::MODE_ANNOTATE:
				return 'commenting';
			case MarkupRequest::MODE_TEXT_RANGE:
				return 'highlighting';
			case MarkupRequest::MODE_POSITION:
				return 'linking';
			case MarkupRequest::MODE_CROP:
				return 'identifying';
			case MarkupRequest::MODE_REGION:
			case MarkupRequest::MODE_MASK:
			default:
				return 'editing';
		}
	}

	/**
	 * Normalize an arbitrary schema fragment to a JSON-Schema-shaped array.
	 *
	 * @param array $schema Raw schema fragment.
	 * @return array
	 */
	private static function normalize_schema( array $schema ) {
		if ( empty( $schema ) ) {
			return array(
				'type'       => 'object',
				// Empty stdClass encodes as `{}`; an empty PHP array would
				// encode as `[]`, which strict clients reject.
				'properties' => new \stdClass(),
				'required'   => array(),
			);
		}
		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( ! isset( $schema['properties'] ) || ! \is_array( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}
		if ( ! isset( $schema['required'] ) || ! \is_array( $schema['required'] ) ) {
			$schema['required'] = array();
		}
		return $schema;
	}

	/**
	 * Build the REST submit URL for a request.
	 *
	 * @param string $request_id Request ID.
	 * @return string
	 */
	public static function build_submit_url( $request_id ) {
		$path = '/mcp-ai/v1/markup/' . \rawurlencode( $request_id ) . '/submit';
		return \function_exists( 'rest_url' ) ? \rest_url( \ltrim( $path, '/' ) ) : $path;
	}

	/**
	 * Build the URL-mode fallback URL (admin canvas page).
	 *
	 * @param string $request_id Request ID.
	 * @return string
	 */
	public static function build_fallback_url( $request_id ) {
		if ( \function_exists( 'admin_url' ) ) {
			return \add_query_arg(
				array(
					'page'    => 'wp-mcp-ai-markup',
					'request' => $request_id,
				),
				\admin_url( 'admin.php' )
			);
		}
		return '?page=wp-mcp-ai-markup&request=' . \rawurlencode( $request_id );
	}
}
