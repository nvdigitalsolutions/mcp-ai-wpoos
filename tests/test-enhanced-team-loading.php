<?php
/**
 * Tests for team knowledge base loader with enhanced teams.
 */
class WP_MCP_AI_Enhanced_Team_Loading_Test extends WP_UnitTestCase {

	/**
	 * Team knowledge base loader instance.
	 *
	 * @var WP_MCP_AI_Team_Knowledge_Base_Loader
	 */
	protected $loader;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->loader = new WP_MCP_AI_Team_Knowledge_Base_Loader();
	}

	/**
	 * Test that all team JSON files can be loaded successfully.
	 */
	public function test_all_team_json_files_load() {
		$teams = $this->loader->load_all();

		$this->assertNotWPError( $teams, 'Team loading should not return WP_Error' );
		$this->assertIsArray( $teams, 'Teams should be an array' );
		$this->assertGreaterThanOrEqual( 51, count( $teams ), 'Should have at least 51 teams (10 original + 41 new)' );
	}

	/**
	 * Test that the architectural design team loads correctly.
	 */
	public function test_architectural_design_team_loads() {
		$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/teams/architecture-construction-teams.json';
		$teams     = $this->loader->load_from_file( $file_path );

		$this->assertNotWPError( $teams, 'Architecture teams should load without errors' );
		$this->assertIsArray( $teams, 'Teams should be an array' );
		$this->assertCount( 4, $teams, 'Should have 4 architecture/construction teams' );

		// Find architectural design team.
		$arch_team = null;
		foreach ( $teams as $team ) {
			if ( 'architectural_design_team' === $team['slug'] ) {
				$arch_team = $team;
				break;
			}
		}

		$this->assertNotNull( $arch_team, 'Architectural Design Team should exist' );
		$this->assertSame( 'Architectural Design Team', $arch_team['title'] );
		$this->assertCount( 5, $arch_team['members'], 'Should have 5 members' );
		$this->assertContains( 'architect', $arch_team['members'] );
		$this->assertContains( 'interior_designer', $arch_team['members'] );
		$this->assertContains( 'landscape_architect', $arch_team['members'] );
		$this->assertContains( 'architectural_drafter', $arch_team['members'] );
		$this->assertContains( 'bim_coordinator', $arch_team['members'] );
	}

	/**
	 * Test that software development teams load correctly.
	 */
	public function test_software_development_teams_load() {
		$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/teams/software-development-teams.json';
		$teams     = $this->loader->load_from_file( $file_path );

		$this->assertNotWPError( $teams, 'Software development teams should load without errors' );
		$this->assertIsArray( $teams, 'Teams should be an array' );
		$this->assertCount( 4, $teams, 'Should have 4 software development teams' );
	}

	/**
	 * Test that education teams load correctly.
	 */
	public function test_education_teams_load() {
		$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/teams/education-training-teams.json';
		$teams     = $this->loader->load_from_file( $file_path );

		$this->assertNotWPError( $teams, 'Education teams should load without errors' );
		$this->assertIsArray( $teams, 'Teams should be an array' );
		$this->assertCount( 3, $teams, 'Should have 3 education teams' );

		// Verify IGCSE team.
		$igcse_team = null;
		foreach ( $teams as $team ) {
			if ( 'igcse_academic_support_team' === $team['slug'] ) {
				$igcse_team = $team;
				break;
			}
		}

		$this->assertNotNull( $igcse_team, 'IGCSE Academic Support Team should exist' );
		$this->assertCount( 5, $igcse_team['members'], 'Should have 5 IGCSE tutors' );
	}

	/**
	 * Test that healthcare teams load correctly.
	 */
	public function test_healthcare_teams_load() {
		$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/teams/healthcare-medical-teams.json';
		$teams     = $this->loader->load_from_file( $file_path );

		$this->assertNotWPError( $teams, 'Healthcare teams should load without errors' );
		$this->assertIsArray( $teams, 'Teams should be an array' );
		$this->assertCount( 4, $teams, 'Should have 4 healthcare teams' );
	}

	/**
	 * Test that business consulting teams load correctly.
	 */
	public function test_business_consulting_teams_load() {
		$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/teams/business-consulting-teams.json';
		$teams     = $this->loader->load_from_file( $file_path );

		$this->assertNotWPError( $teams, 'Business consulting teams should load without errors' );
		$this->assertIsArray( $teams, 'Teams should be an array' );
		$this->assertCount( 5, $teams, 'Should have 5 business teams' );
	}

	/**
	 * Test that all teams have valid structure.
	 */
	public function test_all_teams_have_valid_structure() {
		$teams = $this->loader->load_all();

		$this->assertNotWPError( $teams, 'Teams should load without errors' );

		foreach ( $teams as $team ) {
			// Required fields.
			$this->assertArrayHasKey( 'title', $team, 'Team should have title' );
			$this->assertArrayHasKey( 'slug', $team, 'Team should have slug' );
			$this->assertArrayHasKey( 'members', $team, 'Team should have members' );

			// Members validation.
			$this->assertIsArray( $team['members'], 'Members should be an array' );
			$this->assertGreaterThanOrEqual( 2, count( $team['members'] ), "Team {$team['slug']} should have at least 2 members" );

			// Optional fields.
			$this->assertArrayHasKey( 'description', $team, 'Team should have description' );
			$this->assertArrayHasKey( 'default_provider', $team, 'Team should have default_provider' );
			$this->assertArrayHasKey( 'default_model', $team, 'Team should have default_model' );
		}
	}

	/**
	 * Test that team members reference valid profession slugs.
	 */
	public function test_team_members_are_valid_profession_slugs() {
		// Load manifest to get all valid profession slugs.
		$manifest_path = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-playbooks/manifest.json';
		$manifest_data = file_get_contents( $manifest_path );
		$manifest      = json_decode( $manifest_data, true );

		$valid_profession_slugs = array();
		foreach ( $manifest['professions'] as $profession ) {
			$valid_profession_slugs[] = $profession['slug'];
		}

		// Load all teams.
		$teams = $this->loader->load_all();
		$this->assertNotWPError( $teams, 'Teams should load without errors' );

		// Check each team's members.
		$invalid_references = array();
		foreach ( $teams as $team ) {
			foreach ( $team['members'] as $member_slug ) {
				if ( ! in_array( $member_slug, $valid_profession_slugs, true ) ) {
					$invalid_references[] = "Team '{$team['slug']}' references invalid profession '{$member_slug}'";
				}
			}
		}

		$this->assertEmpty(
			$invalid_references,
			"Some teams reference invalid professions:\n" . implode( "\n", $invalid_references )
		);
	}

	/**
	 * Test that we have diverse coverage of professions across teams.
	 */
	public function test_teams_utilize_diverse_professions() {
		$teams = $this->loader->load_all();
		$this->assertNotWPError( $teams, 'Teams should load without errors' );

		// Collect all unique profession slugs used in teams.
		$used_professions = array();
		foreach ( $teams as $team ) {
			foreach ( $team['members'] as $member_slug ) {
				$used_professions[ $member_slug ] = true;
			}
		}

		// We should be using a good portion of available professions.
		$unique_profession_count = count( $used_professions );
		$this->assertGreaterThanOrEqual( 50, $unique_profession_count, 'Teams should utilize at least 50 different professions' );
	}

	/**
	 * Test that creative teams exist.
	 */
	public function test_creative_teams_exist() {
		$teams = $this->loader->load_all();
		$this->assertNotWPError( $teams, 'Teams should load without errors' );

		$creative_team_slugs = array(
			'creative_production_team',
			'film_production_team',
			'digital_content_creation_team',
			'graphic_design_visual_arts_team',
		);

		$found_teams = array();
		foreach ( $teams as $team ) {
			if ( in_array( $team['slug'], $creative_team_slugs, true ) ) {
				$found_teams[] = $team['slug'];
			}
		}

		$this->assertGreaterThanOrEqual( 3, count( $found_teams ), 'Should have at least 3 creative teams' );
	}

	/**
	 * Test that technical teams exist.
	 */
	public function test_technical_teams_exist() {
		$teams = $this->loader->load_all();
		$this->assertNotWPError( $teams, 'Teams should load without errors' );

		$technical_team_slugs = array(
			'engineering_team',
			'full_stack_development_team',
			'cloud_infrastructure_team',
			'research_data_science_team',
		);

		$found_teams = array();
		foreach ( $teams as $team ) {
			if ( in_array( $team['slug'], $technical_team_slugs, true ) ) {
				$found_teams[] = $team['slug'];
			}
		}

		$this->assertGreaterThanOrEqual( 3, count( $found_teams ), 'Should have at least 3 technical teams' );
	}
}
