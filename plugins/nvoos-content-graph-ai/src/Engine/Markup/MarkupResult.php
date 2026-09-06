<?php
/**
 * Markup result value object (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Result`
 * (`includes/markup/`): byte-identical wrapper over the submitted W3C
 * Web Annotation document, the extra schema fields, and the rasterized
 * artifacts, with the full accessor set including the keyed artifact
 * lookup.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Wraps the W3C Web Annotation document the user submitted along with
 * any rasterized artifacts (e.g. RGBA mask attachment ID, position
 * vector, crop rect). Tools consume this through `consume_markup()`.
 *
 * @since 1.1.0
 */
class MarkupResult {

	/**
	 * The original markup request this result satisfies.
	 *
	 * @var MarkupRequest
	 */
	private $request;

	/**
	 * The W3C Web Annotation document submitted by the client.
	 *
	 * @var array
	 */
	private $annotation;

	/**
	 * Optional extra fields collected via the request schema (e.g. prompt).
	 *
	 * @var array
	 */
	private $extra;

	/**
	 * Rasterized artifacts produced by the rasterizer (mask, rect, vector).
	 *
	 * @var array
	 */
	private $artifacts;

	/**
	 * Constructor.
	 *
	 * @param MarkupRequest $request    Original request.
	 * @param array         $annotation Submitted W3C annotation.
	 * @param array         $extra      Extra fields per schema.
	 * @param array         $artifacts  Rasterized artifacts.
	 */
	public function __construct( MarkupRequest $request, array $annotation, array $extra = array(), array $artifacts = array() ) {
		$this->request    = $request;
		$this->annotation = $annotation;
		$this->extra      = $extra;
		$this->artifacts  = $artifacts;
	}

	/**
	 * Original request accessor.
	 *
	 * @return MarkupRequest
	 */
	public function get_request() {
		return $this->request;
	}

	/**
	 * Submitted annotation accessor.
	 *
	 * @return array
	 */
	public function get_annotation() {
		return $this->annotation;
	}

	/**
	 * Extra fields accessor.
	 *
	 * @return array
	 */
	public function get_extra() {
		return $this->extra;
	}

	/**
	 * Artifacts accessor.
	 *
	 * @return array
	 */
	public function get_artifacts() {
		return $this->artifacts;
	}

	/**
	 * Convenience accessor for a specific artifact key.
	 *
	 * @param string $key     Artifact key (e.g. 'mask_attachment_id', 'crop_rect').
	 * @param mixed  $default Default value when the key is absent.
	 * @return mixed
	 */
	public function get_artifact( $key, $default = null ) {
		return isset( $this->artifacts[ $key ] ) ? $this->artifacts[ $key ] : $default;
	}
}
