<?php
/**
 * Encodes/decodes markup elicitation envelopes.
 *
 * Produces two envelope shapes from a single source-of-truth request:
 *
 *  - The MCP `elicitation/create` payload (spec 2025-06-18 / 2025-11-25)
 *    used when an external MCP client (Claude Desktop, Cursor, VS Code,
 *    etc.) is the active host. Includes URL-mode fallback.
 *  - The in-house chat-bubble `display.widget` payload used by our own
 *    chat client to render the inline canvas.
 *
 * The annotation interchange format follows the W3C Web Annotation Data
 * Model (https://www.w3.org/TR/annotation-model/) so external clients
 * already familiar with it can interoperate.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Markup_Elicitation
 *
 * Pure functions over a markup request and its (eventual) annotation
 * payload. No state, no side effects.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_Elicitation {

	/**
	 * The W3C Web Annotation JSON-LD context URL.
	 */
	const ANNOTATION_CONTEXT = 'http://www.w3.org/ns/anno.jsonld';

	/**
	 * Build the chat-bubble widget payload for the in-house client.
	 *
	 * @param WP_MCP_AI_Markup_Request $request Source request.
	 * @return array
	 */
	public static function to_widget_payload( WP_MCP_AI_Markup_Request $request ) {
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
		 * @param array                    $payload Widget payload.
		 * @param WP_MCP_AI_Markup_Request $request Source request.
		 */
		return apply_filters( 'wp_mcp_ai_markup_widget_payload', $payload, $request );
	}

	/**
	 * Build an MCP `elicitation/create` payload for external MCP clients.
	 *
	 * The MCP spec 2025-11-25 added URL-mode elicitation; we always include
	 * a URL fallback alongside the structured schema so hosts that do not
	 * yet understand our extension can still surface the canvas.
	 *
	 * @param WP_MCP_AI_Markup_Request $request Source request.
	 * @return array
	 */
	public static function to_mcp_elicitation( WP_MCP_AI_Markup_Request $request ) {
		$schema_props = self::normalize_schema( $request->get_schema() );
		// Inject the markup field at the top of the schema so MCP clients
		// that introspect the schema know the canonical interchange field.
		$properties = array(
			'markup' => array(
				'type'        => 'object',
				'description' => 'W3C Web Annotation document describing the user markup.',
			),
		);
		if ( ! empty( $schema_props['properties'] ) && is_array( $schema_props['properties'] ) ) {
			$properties = array_merge( $properties, $schema_props['properties'] );
		}

		$elicitation = array(
			'method' => 'elicitation/create',
			'params' => array(
				'message'         => $request->get_instructions() !== ''
					? $request->get_instructions()
					: __( 'Please mark up the asset to continue.', 'mcp-ai-wpoos' ),
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'markup' ),
				),
				'urlMode'         => array(
					'url'         => self::build_fallback_url( $request->get_request_id() ),
					'description' => __( 'Open the markup canvas in a web browser.', 'mcp-ai-wpoos' ),
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
		 * @param array                    $elicitation MCP envelope.
		 * @param WP_MCP_AI_Markup_Request $request     Source request.
		 */
		return apply_filters( 'wp_mcp_ai_markup_mcp_elicitation', $elicitation, $request );
	}

	/**
	 * Build a skeleton W3C Web Annotation envelope.
	 *
	 * Helper for tests and rasterizer callers; the canvas widget itself
	 * produces its own annotation document.
	 *
	 * @param WP_MCP_AI_Markup_Request $request Source request.
	 * @param array                    $body    Optional body items.
	 * @param array                    $target  Optional target overrides.
	 * @return array
	 */
	public static function build_annotation( WP_MCP_AI_Markup_Request $request, array $body = array(), array $target = array() ) {
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
			case WP_MCP_AI_Markup_Request::MODE_REDACT:
				return 'moderating';
			case WP_MCP_AI_Markup_Request::MODE_ANNOTATE:
				return 'commenting';
			case WP_MCP_AI_Markup_Request::MODE_TEXT_RANGE:
				return 'highlighting';
			case WP_MCP_AI_Markup_Request::MODE_POSITION:
				return 'linking';
			case WP_MCP_AI_Markup_Request::MODE_CROP:
				return 'identifying';
			case WP_MCP_AI_Markup_Request::MODE_REGION:
			case WP_MCP_AI_Markup_Request::MODE_MASK:
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
				'properties' => new stdClass(),
				'required'   => array(),
			);
		}
		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}
		if ( ! isset( $schema['required'] ) || ! is_array( $schema['required'] ) ) {
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
		$path = '/mcp-ai/v1/markup/' . rawurlencode( $request_id ) . '/submit';
		return function_exists( 'rest_url' ) ? rest_url( ltrim( $path, '/' ) ) : $path;
	}

	/**
	 * Build the URL-mode fallback URL (admin canvas page).
	 *
	 * @param string $request_id Request ID.
	 * @return string
	 */
	public static function build_fallback_url( $request_id ) {
		if ( function_exists( 'admin_url' ) ) {
			return add_query_arg(
				array(
					'page'    => 'wp-mcp-ai-markup',
					'request' => $request_id,
				),
				admin_url( 'admin.php' )
			);
		}
		return '?page=wp-mcp-ai-markup&request=' . rawurlencode( $request_id );
	}
}
