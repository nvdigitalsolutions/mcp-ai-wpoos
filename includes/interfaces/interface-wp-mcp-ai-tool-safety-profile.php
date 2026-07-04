<?php
/**
 * Tool Safety Profile — optional interface for tools that declare irreversibility
 * and minimum necessity thresholds.
 *
 * Complements (does not replace) the capability flags and risk-level metadata
 * already present in the tool system. A tool that implements this interface
 * gives the Necessity Gate precise data; a tool that does not gets conservative
 * defaults derived from its capability flags.
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional interface for tools that expose a safety profile.
 *
 * @since 1.9.0
 */
interface WP_MCP_AI_Tool_Safety_Profile_Interface {

	/**
	 * Return the irreversibility score for this tool.
	 *
	 * Scale: 0.0 (fully reversible, read-only) to 1.0 (permanently irreversible).
	 *
	 * If the tool's irreversibility varies by arguments, return the MAXIMUM
	 * possible score (conservative estimate). Argument-level precision is a
	 * future enhancement.
	 *
	 * @since 1.9.0
	 *
	 * @return float Irreversibility score (0.0–1.0). Use the constants defined
	 *               in WP_MCP_AI_Action_Safety_Profile for consistency.
	 */
	public function get_irreversibility_score();

	/**
	 * Return the minimum necessity threshold required for this tool to execute
	 * without triggering a gate.
	 *
	 * Tools with high irreversibility should require 'essential' necessity.
	 * Read-only tools can safely default to 'helpful'.
	 *
	 * @since 1.9.0
	 *
	 * @return string One of the WP_MCP_AI_Action_Safety_Profile::NECESSITY_* constants.
	 */
	public function get_minimum_necessity();

	/**
	 * Return the tool's safety profile as a structured array.
	 *
	 * Convenience method that bundles irreversibility + necessity + derived metadata
	 * for consumers that want a single call.
	 *
	 * @since 1.9.0
	 *
	 * @return array{
	 *     irreversibility_score: float,
	 *     minimum_necessity: string,
	 *     requires_approval_by_default: bool,
	 *     is_irreversible: bool,
	 *     has_financial_impact: bool,
	 *     involves_external_communication: bool,
	 *     destroys_data: bool,
	 *     changes_access_control: bool,
	 * }
	 */
	public function get_safety_profile();
}

// Load the default safety profile trait so it is available wherever this
// interface file is included — matching the pattern established by
// interface-wp-mcp-ai-tool.php which loads trait-wp-mcp-ai-tool-default-capability.php.
require_once dirname( __DIR__ ) . '/tools/trait-wp-mcp-ai-tool-safety-profile.php';
