<?php
/**
 * Toolkit Enhancement Integration
 *
 * Integrates toolkit registry, pattern registry, and workflow templates
 * with the existing agent team orchestrator.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit Enhancement Integration class
 *
 * Provides integration layer between enhanced toolkit system
 * and existing orchestration infrastructure.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Toolkit_Enhancement_Integration {

	/**
	 * Toolkit registry instance
	 *
	 * @var WP_MCP_AI_Toolkit_Registry
	 */
	protected $toolkit_registry;

	/**
	 * Pattern registry instance
	 *
	 * @var WP_MCP_AI_Pattern_Registry
	 */
	protected $pattern_registry;

	/**
	 * Workflow templates instance
	 *
	 * @var WP_MCP_AI_Pattern_Workflow_Templates
	 */
	protected $workflow_templates;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_registry   = new WP_MCP_AI_Toolkit_Registry();
		$this->pattern_registry   = new WP_MCP_AI_Pattern_Registry( $this->toolkit_registry );
		$this->workflow_templates = new WP_MCP_AI_Pattern_Workflow_Templates( $this->pattern_registry );
	}

	/**
	 * Enhance team composition with pattern-based selection
	 *
	 * @param array $task_requirements Task requirements.
	 * @return array Enhanced task requirements with pattern information.
	 */
	public function enhance_task_requirements( $task_requirements ) {
		// Detect toolkit from task type or explicit specification.
		$toolkit_slug = $this->detect_toolkit( $task_requirements );

		if ( $toolkit_slug ) {
			$task_requirements['toolkit'] = $toolkit_slug;

			// Select appropriate pattern.
			$pattern_slug = $this->select_pattern_for_task( $task_requirements );
			if ( $pattern_slug ) {
				$task_requirements['pattern'] = $pattern_slug;

				// Get pattern-specific workflow template.
				$template = $this->workflow_templates->get_workflow_template( $pattern_slug );
				if ( $template ) {
					$task_requirements['workflow_template'] = $template;
				}
			}
		}

		return $task_requirements;
	}

	/**
	 * Detect toolkit from task requirements
	 *
	 * @param array $task_requirements Task requirements.
	 * @return string|null Toolkit slug or null if not detected.
	 */
	protected function detect_toolkit( $task_requirements ) {
		// Check if explicitly specified.
		if ( isset( $task_requirements['toolkit'] ) ) {
			return $task_requirements['toolkit'];
		}

		// Try to detect from task_type.
		if ( isset( $task_requirements['task_type'] ) ) {
			$task_type = $task_requirements['task_type'];

			// Map common task types to toolkits.
			$toolkit_map = array(
				'content'     => WP_MCP_AI_Toolkit_Constants::TOOLKIT_CONTENT_PUBLISHING,
				'research'    => WP_MCP_AI_Toolkit_Constants::TOOLKIT_RESEARCH_DISCOVERY,
				'ecommerce'   => WP_MCP_AI_Toolkit_Constants::TOOLKIT_ECOMMERCE_BUSINESS,
				'development' => WP_MCP_AI_Toolkit_Constants::TOOLKIT_DEVELOPER_TECHNICAL,
				'media'       => WP_MCP_AI_Toolkit_Constants::TOOLKIT_MEDIA_PROCESSING,
				'analytics'   => WP_MCP_AI_Toolkit_Constants::TOOLKIT_DATA_ANALYTICS,
				'security'    => WP_MCP_AI_Toolkit_Constants::TOOLKIT_SECURITY_COMPLIANCE,
				'workflow'    => WP_MCP_AI_Toolkit_Constants::TOOLKIT_WORKFLOW_AUTOMATION,
			);

			foreach ( $toolkit_map as $keyword => $toolkit ) {
				if ( false !== strpos( $task_type, $keyword ) ) {
					return $toolkit;
				}
			}
		}

		return null;
	}

	/**
	 * Select pattern for task
	 *
	 * @param array $task_requirements Task requirements.
	 * @return string|null Selected pattern slug or null.
	 */
	protected function select_pattern_for_task( $task_requirements ) {
		return $this->pattern_registry->select_pattern( $task_requirements );
	}

	/**
	 * Get toolkit information
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array|null Toolkit information or null.
	 */
	public function get_toolkit_info( $toolkit_slug ) {
		return $this->toolkit_registry->get_toolkit( $toolkit_slug );
	}

	/**
	 * Get pattern information
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @return array|null Pattern information or null.
	 */
	public function get_pattern_info( $pattern_slug ) {
		return $this->pattern_registry->get_pattern( $pattern_slug );
	}

	/**
	 * Get workflow template for pattern
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @return array|null Workflow template or null.
	 */
	public function get_workflow_template( $pattern_slug ) {
		return $this->workflow_templates->get_workflow_template( $pattern_slug );
	}

	/**
	 * Get tools for toolkit
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array Array of tool slugs.
	 */
	public function get_toolkit_tools( $toolkit_slug ) {
		return $this->toolkit_registry->get_toolkit_tools( $toolkit_slug );
	}

	/**
	 * Get recommended pattern for toolkit
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return string|null Primary pattern slug or null.
	 */
	public function get_recommended_pattern( $toolkit_slug ) {
		$toolkit_info = $this->get_toolkit_info( $toolkit_slug );
		return isset( $toolkit_info['primary_pattern'] ) ? $toolkit_info['primary_pattern'] : null;
	}

	/**
	 * Validate team composition against pattern
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @param array  $team_members Team members array.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_team_for_pattern( $pattern_slug, $team_members ) {
		return $this->pattern_registry->validate_pattern_compatibility( $pattern_slug, $team_members );
	}

	/**
	 * Get enhancement statistics
	 *
	 * @return array Statistics about toolkit enhancement system.
	 */
	public function get_statistics() {
		$toolkit_stats = $this->toolkit_registry->get_coverage_report();
		$pattern_stats = $this->pattern_registry->get_pattern_statistics();

		return array(
			'toolkits'    => array(
				'total'            => count( $this->toolkit_registry->get_all_toolkits() ),
				'tools_mapped'     => $toolkit_stats['mapped_tools'],
				'tools_unmapped'   => $toolkit_stats['unmapped_tools'],
				'coverage_percent' => $toolkit_stats['coverage_percent'],
			),
			'patterns'    => array(
				'total'              => $pattern_stats['total_patterns'],
				'by_complexity'      => $pattern_stats['by_complexity'],
				'by_scalability'     => $pattern_stats['by_scalability'],
				'by_fault_tolerance' => $pattern_stats['by_fault_tolerance'],
			),
			'integration' => array(
				'toolkit_pattern_mappings' => count( $pattern_stats['toolkit_coverage'] ),
				'workflow_templates'       => count( $this->workflow_templates->get_all_templates() ),
			),
		);
	}

	/**
	 * Get comprehensive recommendation for task
	 *
	 * @param array $task_requirements Task requirements.
	 * @return array Comprehensive recommendation including toolkit, pattern, template, and tools.
	 */
	public function get_task_recommendation( $task_requirements ) {
		$recommendation = array(
			'toolkit'  => null,
			'pattern'  => null,
			'template' => null,
			'tools'    => array(),
		);

		// Detect toolkit.
		$toolkit_slug = $this->detect_toolkit( $task_requirements );
		if ( $toolkit_slug ) {
			$recommendation['toolkit'] = array(
				'slug' => $toolkit_slug,
				'info' => $this->get_toolkit_info( $toolkit_slug ),
			);

			// Select pattern.
			$pattern_slug = $this->select_pattern_for_task(
				array_merge( $task_requirements, array( 'toolkit' => $toolkit_slug ) )
			);

			if ( $pattern_slug ) {
				$recommendation['pattern'] = array(
					'slug' => $pattern_slug,
					'info' => $this->get_pattern_info( $pattern_slug ),
				);

				// Get workflow template.
				$template = $this->get_workflow_template( $pattern_slug );
				if ( $template ) {
					$recommendation['template'] = $template;
				}
			}

			// Get recommended tools.
			$recommendation['tools'] = $this->get_toolkit_tools( $toolkit_slug );
		}

		return $recommendation;
	}

	/**
	 * Initialize integration hooks
	 *
	 * Connects the enhancement system with WordPress and existing orchestrator.
	 */
	public function init_hooks() {
		// Filter for enhancing team composition.
		add_filter( 'wp_mcp_ai_team_task_requirements', array( $this, 'enhance_task_requirements' ), 10, 1 );

		// Action for toolkit system initialization.
		do_action( 'wp_mcp_ai_toolkit_enhancement_initialized', $this );
	}

	/**
	 * Get instance (singleton pattern for global access)
	 *
	 * @return WP_MCP_AI_Toolkit_Enhancement_Integration Instance.
	 */
	public static function get_instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
			$instance->init_hooks();
		}

		return $instance;
	}
}
