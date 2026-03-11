<?php
/**
 * Toolkit Registry.
 *
 * Organizes tools into functional toolkits and provides toolkit-based
 * tool discovery and filtering capabilities.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages toolkit organization and tool categorization.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Toolkit_Registry {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Registry
	 */
	protected static $instance = null;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Toolkit definitions.
	 *
	 * @var array
	 */
	protected $toolkits = array();

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return WP_MCP_AI_Toolkit_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	protected function __construct() {
		$this->tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->define_toolkits();
	}

	/**
	 * Define the 12 functional toolkits.
	 *
	 * @since 1.1.0
	 */
	protected function define_toolkits() {
		$this->toolkits = array(
			'content_publishing'     => array(
				'name'            => __( 'Content & Publishing', 'mcp-ai-wpoos' ),
				'description'     => __( 'Create, edit, and publish content including text, images, video, and audio.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-edit',
				'primary_pattern' => 'orchestrator',
				'professions'     => array( 'writer', 'content_creator', 'journalist', 'marketing_consultant', 'social_media_manager', 'graphic_designer' ),
			),
			'media_processing'       => array(
				'name'            => __( 'Media Processing', 'mcp-ai-wpoos' ),
				'description'     => __( 'Transform, optimize, and manage media assets including images, videos, and audio files.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-format-image',
				'primary_pattern' => 'sequential',
				'professions'     => array( 'photographer', 'graphic_designer', 'video_producer', 'cinematographer' ),
			),
			'data_analytics'         => array(
				'name'            => __( 'Data & Analytics', 'mcp-ai-wpoos' ),
				'description'     => __( 'Analyze data, create visualizations, generate insights, and manage datasets.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-chart-bar',
				'primary_pattern' => 'peer_to_peer',
				'professions'     => array( 'data_scientist', 'applied_statistician', 'research_scientist', 'business_consultant' ),
			),
			'ecommerce_business'     => array(
				'name'            => __( 'E-Commerce & Business', 'mcp-ai-wpoos' ),
				'description'     => __( 'Manage products, orders, customers, inventory, and business operations.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-cart',
				'primary_pattern' => 'orchestrator',
				'professions'     => array( 'ecommerce_manager', 'retail_manager', 'product_manager', 'sales_manager' ),
			),
			'developer_technical'    => array(
				'name'            => __( 'Developer & Technical', 'mcp-ai-wpoos' ),
				'description'     => __( 'Code analysis, technical documentation, system management, and development tools.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-editor-code',
				'primary_pattern' => 'skill_router',
				'professions'     => array( 'software_developer', 'software_engineer', 'devops_engineer', 'systems_administrator' ),
			),
			'security_compliance'    => array(
				'name'            => __( 'Security & Compliance', 'mcp-ai-wpoos' ),
				'description'     => __( 'Security monitoring, authentication, compliance, and content moderation.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-shield',
				'primary_pattern' => 'layered_defense',
				'professions'     => array( 'cybersecurity_specialist', 'security_analyst', 'legal_advisor', 'compliance_officer' ),
			),
			'research_discovery'     => array(
				'name'            => __( 'Research & Discovery', 'mcp-ai-wpoos' ),
				'description'     => __( 'Information gathering, web research, content analysis, and knowledge synthesis.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-search',
				'primary_pattern' => 'orchestrator',
				'professions'     => array( 'research_scientist', 'journalist', 'librarian', 'historian' ),
			),
			'geospatial_location'    => array(
				'name'            => __( 'Geospatial & Location', 'mcp-ai-wpoos' ),
				'description'     => __( 'Location-based services, mapping, geocoding, weather, and disaster response.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-location',
				'primary_pattern' => 'event_driven',
				'professions'     => array( 'urban_planner', 'landscape_architect', 'emergency_management_director', 'meteorologist' ),
			),
			'workflow_automation'    => array(
				'name'            => __( 'Workflow & Automation', 'mcp-ai-wpoos' ),
				'description'     => __( 'Task orchestration, scheduling, automation, and workflow management.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-networking',
				'primary_pattern' => 'hierarchical',
				'professions'     => array( 'project_manager', 'operations_manager', 'business_consultant', 'automation_engineer' ),
			),
			'communication_outreach' => array(
				'name'            => __( 'Communication & Outreach', 'mcp-ai-wpoos' ),
				'description'     => __( 'Email, messaging, social media, notifications, and community engagement.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-email',
				'primary_pattern' => 'orchestrator',
				'professions'     => array( 'marketing_manager', 'pr_specialist', 'social_media_manager', 'community_manager' ),
			),
			'integration_external'   => array(
				'name'            => __( 'Integration & External Services', 'mcp-ai-wpoos' ),
				'description'     => __( 'Connect to third-party APIs, external services, and integrate data sources.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-admin-plugins',
				'primary_pattern' => 'skill_router',
				'professions'     => array( 'integration_specialist', 'api_developer', 'systems_administrator', 'it_consultant' ),
			),
			'ai_model_management'    => array(
				'name'            => __( 'AI & Model Management', 'mcp-ai-wpoos' ),
				'description'     => __( 'Manage AI models, reasoning, inference, embeddings, and model operations.', 'mcp-ai-wpoos' ),
				'icon'            => 'dashicons-admin-generic',
				'primary_pattern' => 'experimentation',
				'professions'     => array( 'ai_researcher', 'machine_learning_engineer', 'data_scientist', 'mlops_specialist' ),
			),
		);

		/**
		 * Filter toolkit definitions.
		 *
		 * @since 1.1.0
		 *
		 * @param array $toolkits Toolkit definitions.
		 */
		$this->toolkits = apply_filters( 'wp_mcp_ai_toolkits', $this->toolkits );
	}

	/**
	 * Get all toolkit definitions.
	 *
	 * @since 1.1.0
	 *
	 * @return array Associative array of toolkits keyed by slug.
	 */
	public function get_toolkits() {
		return $this->toolkits;
	}

	/**
	 * Get a specific toolkit by slug.
	 *
	 * @since 1.1.0
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array|null Toolkit definition or null if not found.
	 */
	public function get_toolkit( $toolkit_slug ) {
		return isset( $this->toolkits[ $toolkit_slug ] ) ? $this->toolkits[ $toolkit_slug ] : null;
	}

	/**
	 * Get tools for a specific toolkit.
	 *
	 * @since 1.1.0
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array Array of tool slugs belonging to the toolkit.
	 */
	public function get_toolkit_tools( $toolkit_slug ) {
		$tools = array();

		// Get all registered tools.
		$all_tools = $this->tool_registry->list_tools();

		foreach ( $all_tools as $tool ) {
			// Get tool metadata.
			$metadata = $this->get_tool_metadata( $tool->get_slug() );

			if ( isset( $metadata['toolkit'] ) && $metadata['toolkit'] === $toolkit_slug ) {
				$tools[] = $tool->get_slug();
			}
		}

		return $tools;
	}

	/**
	 * Get toolkit statistics.
	 *
	 * @since 1.1.0
	 *
	 * @param string $toolkit_slug Optional. Specific toolkit slug.
	 * @return array Statistics array.
	 */
	public function get_toolkit_stats( $toolkit_slug = null ) {
		if ( $toolkit_slug ) {
			$tools = $this->get_toolkit_tools( $toolkit_slug );
			return array(
				'toolkit'    => $toolkit_slug,
				'tool_count' => count( $tools ),
				'tools'      => $tools,
			);
		}

		// Get stats for all toolkits.
		$stats = array();
		foreach ( array_keys( $this->toolkits ) as $slug ) {
			$tools          = $this->get_toolkit_tools( $slug );
			$stats[ $slug ] = array(
				'name'       => $this->toolkits[ $slug ]['name'],
				'tool_count' => count( $tools ),
			);
		}

		return $stats;
	}

	/**
	 * Get tool metadata including toolkit assignment.
	 *
	 * @since 1.1.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @return array Tool metadata.
	 */
	public function get_tool_metadata( $tool_slug ) {
		$tool = $this->tool_registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			return array();
		}

		$metadata = array(
			'slug'        => $tool_slug,
			'name'        => $tool->get_name(),
			'description' => $tool->get_description(),
		);

		// Check if tool has get_definition method for extended metadata.
		if ( method_exists( $tool, 'get_definition' ) ) {
			$definition = $tool->get_definition();

			if ( isset( $definition['toolkit'] ) ) {
				$metadata['toolkit'] = $definition['toolkit'];
			}

			if ( isset( $definition['pattern_compatibility'] ) ) {
				$metadata['pattern_compatibility'] = $definition['pattern_compatibility'];
			}

			if ( isset( $definition['profession_tags'] ) ) {
				$metadata['profession_tags'] = $definition['profession_tags'];
			}

			if ( isset( $definition['risk_level'] ) ) {
				$metadata['risk_level'] = $definition['risk_level'];
			}
		}

		return $metadata;
	}

	/**
	 * Get tools by pattern compatibility.
	 *
	 * @since 1.1.0
	 *
	 * @param string $pattern_slug Multi-agent pattern slug.
	 * @return array Array of tool slugs compatible with the pattern.
	 */
	public function get_tools_by_pattern( $pattern_slug ) {
		$tools     = array();
		$all_tools = $this->tool_registry->list_tools();

		foreach ( $all_tools as $tool ) {
			$metadata = $this->get_tool_metadata( $tool->get_slug() );

			if ( isset( $metadata['pattern_compatibility'] ) &&
				in_array( $pattern_slug, $metadata['pattern_compatibility'], true ) ) {
				$tools[] = $tool->get_slug();
			}
		}

		return $tools;
	}

	/**
	 * Get tools by profession tag.
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Array of tool slugs tagged for the profession.
	 */
	public function get_tools_by_profession( $profession_slug ) {
		$tools     = array();
		$all_tools = $this->tool_registry->list_tools();

		foreach ( $all_tools as $tool ) {
			$metadata = $this->get_tool_metadata( $tool->get_slug() );

			if ( isset( $metadata['profession_tags'] ) &&
				in_array( $profession_slug, $metadata['profession_tags'], true ) ) {
				$tools[] = $tool->get_slug();
			}
		}

		return $tools;
	}

	/**
	 * Get tools by risk level.
	 *
	 * @since 1.1.0
	 *
	 * @param string $risk_level Risk level (info, standard, destructive).
	 * @return array Array of tool slugs at the specified risk level.
	 */
	public function get_tools_by_risk_level( $risk_level ) {
		$tools     = array();
		$all_tools = $this->tool_registry->list_tools();

		foreach ( $all_tools as $tool ) {
			$metadata = $this->get_tool_metadata( $tool->get_slug() );

			if ( isset( $metadata['risk_level'] ) && $metadata['risk_level'] === $risk_level ) {
				$tools[] = $tool->get_slug();
			}
		}

		return $tools;
	}

	/**
	 * Search tools across toolkits.
	 *
	 * @since 1.1.0
	 *
	 * @param string $search_term Search term.
	 * @return array Array of tool metadata matching the search.
	 */
	public function search_tools( $search_term ) {
		$results   = array();
		$all_tools = $this->tool_registry->list_tools();
		$search    = strtolower( $search_term );

		foreach ( $all_tools as $tool ) {
			$metadata = $this->get_tool_metadata( $tool->get_slug() );

			// Search in name, description, and toolkit.
			$searchable = strtolower(
				$metadata['name'] . ' ' .
				$metadata['description'] . ' ' .
				( isset( $metadata['toolkit'] ) ? $metadata['toolkit'] : '' )
			);

			if ( false !== strpos( $searchable, $search ) ) {
				$results[] = $metadata;
			}
		}

		return $results;
	}

	/**
	 * Get unmapped tools (tools without toolkit assignment).
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of tool slugs without toolkit assignment.
	 */
	public function get_unmapped_tools() {
		$unmapped  = array();
		$all_tools = $this->tool_registry->list_tools();

		foreach ( $all_tools as $tool ) {
			$metadata = $this->get_tool_metadata( $tool->get_slug() );

			if ( ! isset( $metadata['toolkit'] ) || empty( $metadata['toolkit'] ) ) {
				$unmapped[] = $tool->get_slug();
			}
		}

		return $unmapped;
	}

	/**
	 * Get toolkit coverage report.
	 *
	 * @since 1.1.0
	 *
	 * @return array Coverage statistics.
	 */
	public function get_coverage_report() {
		$all_tools        = $this->tool_registry->list_tools();
		$total_tools      = count( $all_tools );
		$mapped_tools     = 0;
		$toolkit_coverage = array();

		foreach ( array_keys( $this->toolkits ) as $toolkit_slug ) {
			$tools                             = $this->get_toolkit_tools( $toolkit_slug );
			$toolkit_coverage[ $toolkit_slug ] = count( $tools );
			$mapped_tools                     += count( $tools );
		}

		$unmapped_count = count( $this->get_unmapped_tools() );

		return array(
			'total_tools'      => $total_tools,
			'mapped_tools'     => $mapped_tools,
			'unmapped_tools'   => $unmapped_count,
			'coverage_percent' => $total_tools > 0 ? round( ( $mapped_tools / $total_tools ) * 100, 2 ) : 0,
			'toolkit_counts'   => $toolkit_coverage,
		);
	}

	/**
	 * Prevent cloning.
	 */
	protected function __clone() {}

	/**
	 * Prevent unserialisation.
	 */
	public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Double-underscore magic method (__wakeup/__clone) required by PHP serialization interface; PSR-2 exception for magic methods.
}
