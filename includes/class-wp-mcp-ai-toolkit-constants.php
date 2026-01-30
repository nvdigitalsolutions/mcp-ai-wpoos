<?php
/**
 * Toolkit Constants.
 *
 * Defines constants for toolkit slugs, multi-agent patterns, and risk levels
 * used throughout the toolkit enhancement system.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit slug constants.
 *
 * Use these constants instead of hardcoded strings for better maintainability
 * and to catch typos at runtime.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Toolkit_Constants {
	/**
	 * Content & Publishing toolkit.
	 *
	 * Tools for creating, editing, and publishing content including text,
	 * images, video, and audio.
	 */
	const TOOLKIT_CONTENT_PUBLISHING = 'content_publishing';

	/**
	 * Media Processing toolkit.
	 *
	 * Tools for transforming, optimizing, and managing media assets.
	 */
	const TOOLKIT_MEDIA_PROCESSING = 'media_processing';

	/**
	 * Data & Analytics toolkit.
	 *
	 * Tools for analyzing data, creating visualizations, and managing datasets.
	 */
	const TOOLKIT_DATA_ANALYTICS = 'data_analytics';

	/**
	 * E-Commerce & Business toolkit.
	 *
	 * Tools for managing products, orders, customers, and business operations.
	 */
	const TOOLKIT_ECOMMERCE_BUSINESS = 'ecommerce_business';

	/**
	 * Developer & Technical toolkit.
	 *
	 * Tools for code analysis, technical documentation, and system management.
	 */
	const TOOLKIT_DEVELOPER_TECHNICAL = 'developer_technical';

	/**
	 * Security & Compliance toolkit.
	 *
	 * Tools for security monitoring, authentication, and content moderation.
	 */
	const TOOLKIT_SECURITY_COMPLIANCE = 'security_compliance';

	/**
	 * Research & Discovery toolkit.
	 *
	 * Tools for information gathering, web research, and content analysis.
	 */
	const TOOLKIT_RESEARCH_DISCOVERY = 'research_discovery';

	/**
	 * Geospatial & Location toolkit.
	 *
	 * Tools for location-based services, mapping, and disaster response.
	 */
	const TOOLKIT_GEOSPATIAL_LOCATION = 'geospatial_location';

	/**
	 * Workflow & Automation toolkit.
	 *
	 * Tools for task orchestration, scheduling, and workflow management.
	 */
	const TOOLKIT_WORKFLOW_AUTOMATION = 'workflow_automation';

	/**
	 * Communication & Outreach toolkit.
	 *
	 * Tools for email, messaging, and community engagement.
	 */
	const TOOLKIT_COMMUNICATION_OUTREACH = 'communication_outreach';

	/**
	 * Integration & External Services toolkit.
	 *
	 * Tools for connecting to third-party APIs and external services.
	 */
	const TOOLKIT_INTEGRATION_EXTERNAL = 'integration_external';

	/**
	 * AI & Model Management toolkit.
	 *
	 * Tools for managing AI models, reasoning, and model operations.
	 */
	const TOOLKIT_AI_MODEL_MANAGEMENT = 'ai_model_management';

	/**
	 * Get all toolkit slugs.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of all toolkit slug constants.
	 */
	public static function get_all_toolkits() {
		return array(
			self::TOOLKIT_CONTENT_PUBLISHING,
			self::TOOLKIT_MEDIA_PROCESSING,
			self::TOOLKIT_DATA_ANALYTICS,
			self::TOOLKIT_ECOMMERCE_BUSINESS,
			self::TOOLKIT_DEVELOPER_TECHNICAL,
			self::TOOLKIT_SECURITY_COMPLIANCE,
			self::TOOLKIT_RESEARCH_DISCOVERY,
			self::TOOLKIT_GEOSPATIAL_LOCATION,
			self::TOOLKIT_WORKFLOW_AUTOMATION,
			self::TOOLKIT_COMMUNICATION_OUTREACH,
			self::TOOLKIT_INTEGRATION_EXTERNAL,
			self::TOOLKIT_AI_MODEL_MANAGEMENT,
		);
	}

	/**
	 * Validate if a toolkit slug is valid.
	 *
	 * @since 1.1.0
	 *
	 * @param string $toolkit_slug Toolkit slug to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_toolkit( $toolkit_slug ) {
		return in_array( $toolkit_slug, self::get_all_toolkits(), true );
	}
}

/**
 * Multi-agent pattern constants.
 *
 * Defines the 8 standard multi-agent coordination patterns.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Pattern_Constants {
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
	 * Nested orchestration with multiple levels of coordination.
	 * Good for complex multi-stage workflows.
	 */
	const PATTERN_HIERARCHICAL = 'hierarchical';

	/**
	 * Experimentation Pipeline pattern.
	 *
	 * A/B testing and model evaluation workflows.
	 * Used for ML/AI model management and testing.
	 */
	const PATTERN_EXPERIMENTATION = 'experimentation';

	/**
	 * Get all pattern slugs.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of all pattern slug constants.
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
	 * Validate if a pattern slug is valid.
	 *
	 * @since 1.1.0
	 *
	 * @param string $pattern_slug Pattern slug to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_pattern( $pattern_slug ) {
		return in_array( $pattern_slug, self::get_all_patterns(), true );
	}

	/**
	 * Get pattern description.
	 *
	 * @since 1.1.0
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @return string Pattern description.
	 */
	public static function get_pattern_description( $pattern_slug ) {
		$descriptions = array(
			self::PATTERN_ORCHESTRATOR     => __( 'Centralized coordinator managing other agents', 'mcp-ai-wpoos' ),
			self::PATTERN_SEQUENTIAL       => __( 'Linear chain where output of one feeds into the next', 'mcp-ai-wpoos' ),
			self::PATTERN_PEER_TO_PEER     => __( 'Collaborative agents working together without central coordinator', 'mcp-ai-wpoos' ),
			self::PATTERN_SKILL_ROUTER     => __( 'Routes requests to appropriate specialized agents', 'mcp-ai-wpoos' ),
			self::PATTERN_LAYERED_DEFENSE  => __( 'Multiple security layers for validation and protection', 'mcp-ai-wpoos' ),
			self::PATTERN_EVENT_DRIVEN     => __( 'Agents respond to events and triggers', 'mcp-ai-wpoos' ),
			self::PATTERN_HIERARCHICAL     => __( 'Nested orchestration with multiple coordination levels', 'mcp-ai-wpoos' ),
			self::PATTERN_EXPERIMENTATION  => __( 'A/B testing and model evaluation workflows', 'mcp-ai-wpoos' ),
		);

		return isset( $descriptions[ $pattern_slug ] ) ? $descriptions[ $pattern_slug ] : '';
	}
}

/**
 * Risk level constants.
 *
 * Defines the three risk levels for tool operations.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Risk_Level_Constants {
	/**
	 * Info risk level.
	 *
	 * Read-only operations with no side effects.
	 * Safe to execute multiple times, no data modification.
	 */
	const RISK_INFO = 'info';

	/**
	 * Standard risk level.
	 *
	 * Modifies data but changes are reversible.
	 * May create, update, or delete content but can be undone.
	 */
	const RISK_STANDARD = 'standard';

	/**
	 * Destructive risk level.
	 *
	 * Irreversible operations that permanently modify or delete data.
	 * Requires extra confirmation and careful consideration.
	 */
	const RISK_DESTRUCTIVE = 'destructive';

	/**
	 * Get all risk level slugs.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of all risk level constants.
	 */
	public static function get_all_risk_levels() {
		return array(
			self::RISK_INFO,
			self::RISK_STANDARD,
			self::RISK_DESTRUCTIVE,
		);
	}

	/**
	 * Validate if a risk level is valid.
	 *
	 * @since 1.1.0
	 *
	 * @param string $risk_level Risk level to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_risk_level( $risk_level ) {
		return in_array( $risk_level, self::get_all_risk_levels(), true );
	}

	/**
	 * Get risk level description.
	 *
	 * @since 1.1.0
	 *
	 * @param string $risk_level Risk level slug.
	 * @return string Risk level description.
	 */
	public static function get_risk_level_description( $risk_level ) {
		$descriptions = array(
			self::RISK_INFO        => __( 'Read-only operations with no side effects', 'mcp-ai-wpoos' ),
			self::RISK_STANDARD    => __( 'Modifies data but changes are reversible', 'mcp-ai-wpoos' ),
			self::RISK_DESTRUCTIVE => __( 'Irreversible operations that permanently modify or delete data', 'mcp-ai-wpoos' ),
		);

		return isset( $descriptions[ $risk_level ] ) ? $descriptions[ $risk_level ] : '';
	}

	/**
	 * Get risk level color for UI display.
	 *
	 * @since 1.1.0
	 *
	 * @param string $risk_level Risk level slug.
	 * @return string CSS color class or hex color.
	 */
	public static function get_risk_level_color( $risk_level ) {
		$colors = array(
			self::RISK_INFO        => '#28a745', // Green.
			self::RISK_STANDARD    => '#ffc107', // Yellow/Amber.
			self::RISK_DESTRUCTIVE => '#dc3545', // Red.
		);

		return isset( $colors[ $risk_level ] ) ? $colors[ $risk_level ] : '#6c757d'; // Gray default.
	}
}
