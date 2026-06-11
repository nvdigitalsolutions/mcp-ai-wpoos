<?php
/**
 * Site Node Interface — defines the contract every site-building node must fulfill.
 *
 * A Site Node is the WordPress analogue of a ComfyUI custom node:
 * it declares typed INPUT / OUTPUT ports, a category, and an execute()
 * method that transforms inputs → outputs.
 *
 * @package    WP_MCP_AI
 * @subpackage Site_Builder
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for a site-building pipeline node.
 *
 * Every site node must declare:
 * - A unique slug
 * - A human-readable name and description
 * - A category (source, layout, style, transform, output, integration)
 * - Typed input and output port definitions
 * - An execute() implementation
 *
 * The interface is intentionally minimal — nodes may additionally
 * implement optional interfaces for caching hints, progress reporting,
 * or async execution.
 */
interface WP_MCP_AI_Site_Node_Interface {

	/**
	 * Unique machine-readable slug for this node type.
	 *
	 * Example: 'wp_query_source', 'text_block', 'flex_container'.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Human-readable label for the node palette.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Short description shown in the node palette tooltip.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Category for grouping in the node palette.
	 *
	 * Must be one of: 'source', 'layout', 'style', 'transform', 'output', 'integration'.
	 *
	 * @return string
	 */
	public function get_category(): string;

	/**
	 * Input port definitions.
	 *
	 * Each port is an associative array with keys:
	 *   - name     (string)  — port identifier
	 *   - type     (string)  — data type (html, css, json, markdown, image, url, post_list, page, string, number, boolean)
	 *   - label    (string)  — human-readable label (optional)
	 *   - required (bool)    — whether the port must be connected (default true)
	 *   - default  (mixed)   — default value when not connected (optional)
	 *
	 * @return array[]
	 */
	public function get_inputs(): array;

	/**
	 * Output port definitions (same shape as inputs, minus 'required' and 'default').
	 *
	 * @return array[]
	 */
	public function get_outputs(): array;

	/**
	 * Execute the node — transform inputs into outputs.
	 *
	 * @param array $inputs Associative array of input values keyed by port name.
	 * @return array|WP_Error Associative array of output values keyed by port name, or WP_Error on failure.
	 */
	public function execute( array $inputs );
}
