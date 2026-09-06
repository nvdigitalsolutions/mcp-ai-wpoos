<?php
/**
 * Multi-Agent Pattern Constants (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Pattern_Constants`:
 * byte-identical eight pattern slugs, the `get_all_patterns()`
 * catalog, `is_valid_pattern()`, and `get_pattern_description()` —
 * pure domain constants, no WordPress or infrastructure dependencies
 * beyond translation.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Pattern Constants class.
 *
 * Defines the 8 standard multi-agent coordination patterns.
 *
 * @since 2.1.0
 */
class PatternConstants {

	/**
	 * Orchestrator (Supervisor) pattern.
	 *
	 * Centralized coordinator that manages other agents.
	 * Most common pattern for complex workflows.
	 */
	const PATTERN_ORCHESTRATOR = 'orchestrator';

	/**
	 * Sequential Pipeline pattern.
	 *
	 * Linear chain of agents where output of one feeds into the next.
	 * Good for transformation workflows.
	 */
	const PATTERN_SEQUENTIAL = 'sequential';

	/**
	 * Peer-to-Peer Collaboration pattern.
	 *
	 * Agents work together collaboratively without a central coordinator.
	 * Good for analysis and consensus tasks.
	 */
	const PATTERN_PEER_TO_PEER = 'peer_to_peer';

	/**
	 * Skill Router pattern.
	 *
	 * Routes requests to appropriate specialized agents.
	 * Good for triage and request routing.
	 */
	const PATTERN_SKILL_ROUTER = 'skill_router';

	/**
	 * Layered Defense pattern.
	 *
	 * Multiple security layers for validation and protection.
	 * Used primarily for security and compliance tools.
	 */
	const PATTERN_LAYERED_DEFENSE = 'layered_defense';

	/**
	 * Event-Driven Response pattern.
	 *
	 * Agents respond to events and triggers.
	 * Good for monitoring and alert systems.
	 */
	const PATTERN_EVENT_DRIVEN = 'event_driven';

	/**
	 * Hierarchical Orchestrator pattern.
	 *
	 * Multi-level management hierarchy with supervisors and workers.
	 * Good for large-scale orchestration.
	 */
	const PATTERN_HIERARCHICAL = 'hierarchical';

	/**
	 * Experimentation Pipeline pattern.
	 *
	 * Multiple agents try different approaches and compare results.
	 * Good for optimization and A/B testing.
	 */
	const PATTERN_EXPERIMENTATION = 'experimentation';

	/**
	 * Get all pattern constants.
	 *
	 * @return array Array of pattern slugs.
	 */
	public static function get_all_patterns() {
		return array(
			self::PATTERN_ORCHESTRATOR,
			self::PATTERN_SEQUENTIAL,
			self::PATTERN_PEER_TO_PEER,
			self::PATTERN_SKILL_ROUTER,
			self::PATTERN_LAYERED_DEFENSE,
			self::PATTERN_EVENT_DRIVEN,
			self::PATTERN_HIERARCHICAL,
			self::PATTERN_EXPERIMENTATION,
		);
	}

	/**
	 * Check if a pattern slug is valid.
	 *
	 * @param string $pattern Pattern slug to check.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_pattern( $pattern ) {
		return in_array( $pattern, self::get_all_patterns(), true );
	}

	/**
	 * Get pattern description.
	 *
	 * @param string $pattern Pattern slug.
	 * @return string|null Pattern description or null if not found.
	 */
	public static function get_pattern_description( $pattern ) {
		$descriptions = array(
			self::PATTERN_ORCHESTRATOR    => __( 'Centralized coordinator managing other agents', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_SEQUENTIAL      => __( 'Linear agent chain processing', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_PEER_TO_PEER    => __( 'Agents work together as equals', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_SKILL_ROUTER    => __( 'Route tasks to specialized agents', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_LAYERED_DEFENSE => __( 'Multi-layer security validation', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_EVENT_DRIVEN    => __( 'React to events and triggers', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_HIERARCHICAL    => __( 'Multi-level management hierarchy', 'nvoos-content-graph-ai-platform' ),
			self::PATTERN_EXPERIMENTATION => __( 'Try multiple approaches, select best', 'nvoos-content-graph-ai-platform' ),
		);

		return isset( $descriptions[ $pattern ] ) ? $descriptions[ $pattern ] : null;
	}
}
