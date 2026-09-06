<?php
/**
 * Site Node Interface (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Node_Interface`:
 * byte-identical contract — every site-building node declares a unique
 * slug, human-readable name/description, a palette category, typed
 * INPUT / OUTPUT port definitions, and an execute() method that
 * transforms inputs into outputs.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder;

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
 *
 * @since 2.1.0
 */
interface SiteNodeInterface {

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
	 * @return array|\WP_Error Associative array of output values keyed by port name, or WP_Error on failure.
	 */
	public function execute( array $inputs );
}
