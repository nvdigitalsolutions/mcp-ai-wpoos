<?php
/**
 * Tool Safety Profile — default trait implementation.
 *
 * Provides a safe, conservative default implementation of
 * WP_MCP_AI_Tool_Safety_Profile_Interface that derives irreversibility
 * from the tool's capability flags. Tools can use this trait to satisfy
 * the interface without writing custom safety logic.
 *
 * Usage: add `use WP_MCP_AI_Tool_Safety_Profile;` inside any tool class
 * that implements WP_MCP_AI_Tool_Safety_Profile_Interface.
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

// Ensure the safety-profile domain class is available.
require_once __DIR__ . '/../domain/class-wp-mcp-ai-action-safety-profile.php';

/**
 * Default safety profile for tool classes.
 *
 * @since 1.9.0
 */
trait WP_MCP_AI_Tool_Safety_Profile {

	/**
	 * Get the irreversibility score for this tool.
	 *
	 * Default: derived from capability flags. Override in the tool class
	 * for a more precise score.
	 *
	 * @since 1.9.0
	 *
	 * @return float
	 */
	public function get_irreversibility_score() {
		$flags = array();
		if ( $this instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = (array) $this->get_capability_flags();
		}

		return WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags( $flags );
	}

	/**
	 * Get the minimum necessity threshold for this tool.
	 *
	 * Default: 'essential' for write/state-changing tools, 'helpful' for read-only.
	 * Override in the tool class for a more precise threshold.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public function get_minimum_necessity() {
		$flags = array();
		if ( $this instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = (array) $this->get_capability_flags();
		}

		// Irreversible or destructive tools require essential necessity.
		if (
			in_array( 'irreversible', $flags, true )
			|| in_array( 'financial-impact', $flags, true )
			|| in_array( 'data-destruction', $flags, true )
			|| in_array( 'access-control-change', $flags, true )
		) {
			return WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL;
		}

		// Write tools require essential necessity.
		if ( in_array( 'write', $flags, true ) || in_array( 'state-changing', $flags, true ) ) {
			return WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL;
		}

		// Read-only tools only need to be helpful.
		return WP_MCP_AI_Action_Safety_Profile::NECESSITY_HELPFUL;
	}

	/**
	 * Get the full safety profile as a structured array.
	 *
	 * @since 1.9.0
	 *
	 * @return array
	 */
	public function get_safety_profile() {
		$flags = array();
		if ( $this instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = (array) $this->get_capability_flags();
		}

		$irreversibility = $this->get_irreversibility_score();

		return array(
			'irreversibility_score'           => $irreversibility,
			'minimum_necessity'               => $this->get_minimum_necessity(),
			'requires_approval_by_default'    => $irreversibility >= WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_HIGH,
			'is_irreversible'                 => in_array( 'irreversible', $flags, true ),
			'has_financial_impact'            => in_array( 'financial-impact', $flags, true ),
			'involves_external_communication' => in_array( 'external-communication', $flags, true ),
			'destroys_data'                   => in_array( 'data-destruction', $flags, true ),
			'changes_access_control'          => in_array( 'access-control-change', $flags, true ),
		);
	}
}
