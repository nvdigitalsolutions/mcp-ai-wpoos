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
