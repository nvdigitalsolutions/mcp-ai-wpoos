<?php
/**
 * Team Seeder.
 *
 * Seeds default teams into the database on plugin activation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds default teams.
 */
class WP_MCP_AI_Team_Seeder {
	/**
	 * Option key to track if teams have been seeded.
	 */
	const SEEDED_OPTION = 'wp_mcp_ai_teams_seeded';

	/**
	 * Initialize the seeder.
	 * Runs once on plugin activation or update.
	 */
	public static function init() {
		// Check if already seeded.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Seed teams.
		add_action( 'admin_init', array( __CLASS__, 'seed_teams' ), 20 );
	}

	/**
	 * Seed default teams.
	 */
	public static function seed_teams() {
		// Double-check to prevent duplicate seeding.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Team_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-team-repository.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Team_Knowledge_Base_Loader' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-team-knowledge-base-loader.php';
		}

		$repository = new WP_MCP_AI_Team_Repository();

		// Try to load from JSON files first.
		$loader = new WP_MCP_AI_Team_Knowledge_Base_Loader();
		$teams  = $loader->load_all();

		// Fallback to hard-coded teams if JSON loading fails.
		if ( is_wp_error( $teams ) || empty( $teams ) ) {
			error_log( 'WP_MCP_AI: JSON loading failed for teams, using hard-coded teams. Error: ' . ( is_wp_error( $teams ) ? $teams->get_error_message() : 'Empty result' ) );
			$teams = self::get_default_teams();
		}

		foreach ( $teams as $team_data ) {
			$repository->save( $team_data );
		}

		// Mark as seeded.
		update_option( self::SEEDED_OPTION, true, false );
	}

	/**
	 * Get default teams data.
	 *
	 * @return array Array of team data.
	 */
	protected static function get_default_teams() {
		return array(
			// BUSINESS OPERATIONS TEAM.
			array(
				'title'               => __( 'Business Operations Team', 'wp-mcp-ai' ),
				'slug'                => 'business_operations_team',
				'description'         => __( 'A comprehensive team for managing business operations including accounting, tax, legal, and consulting services.', 'wp-mcp-ai' ),
				'members'             => array( 'accountant', 'tax_advisor', 'legal_advisor', 'business_consultant' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.3,
			),
			// CREATIVE PRODUCTION TEAM.
			array(
				'title'               => __( 'Creative Production Team', 'wp-mcp-ai' ),
				'slug'                => 'creative_production_team',
				'description'         => __( 'A team of creative professionals for design, content creation, and multimedia production.', 'wp-mcp-ai' ),
				'members'             => array( 'graphic_designer', 'content_creator', 'video_producer', 'web_designer' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.7,
			),
			// FILM PRODUCTION TEAM.
			array(
				'title'               => __( 'Film Production Team', 'wp-mcp-ai' ),
				'slug'                => 'film_production_team',
				'description'         => __( 'A complete film production team including director, producer, screenwriter, cinematographer, and editor.', 'wp-mcp-ai' ),
				'members'             => array( 'film_director', 'film_producer', 'screenwriter', 'cinematographer', 'film_editor' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.8,
			),
			// HEALTHCARE ADVISORY TEAM.
			array(
				'title'               => __( 'Healthcare Advisory Team', 'wp-mcp-ai' ),
				'slug'                => 'healthcare_advisory_team',
				'description'         => __( 'Healthcare professionals providing guidance on public health, epidemiology, and medical research.', 'wp-mcp-ai' ),
				'members'             => array( 'healthcare_advisor', 'epidemiologist', 'public_health_advisor', 'medical_researcher' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.2,
			),
			// EMERGENCY MANAGEMENT TEAM.
			array(
				'title'               => __( 'Emergency Management Team', 'wp-mcp-ai' ),
				'slug'                => 'emergency_management_team',
				'description'         => __( 'Emergency response and disaster management professionals for crisis situations.', 'wp-mcp-ai' ),
				'members'             => array( 'emergency_management_director', 'disaster_response_coordinator', 'crisis_communications_manager', 'hazard_mitigation_specialist' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.3,
			),
			// ENVIRONMENTAL SCIENCE TEAM.
			array(
				'title'               => __( 'Environmental Science Team', 'wp-mcp-ai' ),
				'slug'                => 'environmental_science_team',
				'description'         => __( 'Environmental and marine science professionals for conservation and research.', 'wp-mcp-ai' ),
				'members'             => array( 'marine_biologist', 'oceanographer', 'wildlife_conservationist', 'environmental_scientist' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.4,
			),
			// ENGINEERING TEAM.
			array(
				'title'               => __( 'Engineering Team', 'wp-mcp-ai' ),
				'slug'                => 'engineering_team',
				'description'         => __( 'Multi-disciplinary engineering team covering software, mechanical, electrical, and civil engineering.', 'wp-mcp-ai' ),
				'members'             => array( 'software_engineer', 'mechanical_engineer', 'electrical_engineer', 'civil_engineer' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.3,
			),
			// PHARMACEUTICAL DEVELOPMENT TEAM.
			array(
				'title'               => __( 'Pharmaceutical Development Team', 'wp-mcp-ai' ),
				'slug'                => 'pharmaceutical_development_team',
				'description'         => __( 'Pharmaceutical professionals for drug development, research, and regulatory compliance.', 'wp-mcp-ai' ),
				'members'             => array( 'pharmacist', 'pharmaceutical_researcher', 'clinical_pharmacologist', 'regulatory_affairs_specialist' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.2,
			),
			// RESEARCH & DATA SCIENCE TEAM.
			array(
				'title'               => __( 'Research & Data Science Team', 'wp-mcp-ai' ),
				'slug'                => 'research_data_science_team',
				'description'         => __( 'Research and data analysis team for scientific investigation and statistical analysis.', 'wp-mcp-ai' ),
				'members'             => array( 'data_scientist', 'research_scientist', 'statistician', 'computer_scientist' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.3,
			),
			// MARKETING & GROWTH TEAM.
			array(
				'title'               => __( 'Marketing & Growth Team', 'wp-mcp-ai' ),
				'slug'                => 'marketing_growth_team',
				'description'         => __( 'Marketing professionals for strategy, content, design, and business growth.', 'wp-mcp-ai' ),
				'members'             => array( 'marketing_consultant', 'content_creator', 'graphic_designer', 'business_consultant' ),
				'default_provider'    => 'openai',
				'default_model'       => 'gpt-4',
				'default_temperature' => 0.6,
			),
		);
	}
}
