<?php
/**
 * Value object representing a validated markup submission.
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
 * Class WP_MCP_AI_Markup_Result
 *
 * Wraps the W3C Web Annotation document the user submitted along with
 * any rasterized artifacts (e.g. RGBA mask attachment ID, position
 * vector, crop rect). Tools consume this through `consume_markup()`.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_Result {

	/**
	 * The original markup request this result satisfies.
	 *
	 * @var WP_MCP_AI_Markup_Request
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
	 * @param WP_MCP_AI_Markup_Request $request    Original request.
	 * @param array                    $annotation Submitted W3C annotation.
	 * @param array                    $extra      Extra fields per schema.
	 * @param array                    $artifacts  Rasterized artifacts.
	 */
	public function __construct( WP_MCP_AI_Markup_Request $request, array $annotation, array $extra = array(), array $artifacts = array() ) {
		$this->request    = $request;
		$this->annotation = $annotation;
		$this->extra      = $extra;
		$this->artifacts  = $artifacts;
	}

	/**
	 * Original request accessor.
	 *
	 * @return WP_MCP_AI_Markup_Request
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
