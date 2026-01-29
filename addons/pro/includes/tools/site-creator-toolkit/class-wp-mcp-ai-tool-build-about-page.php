<?php
/**
 * Build About Page Tool
 *
 * Creates comprehensive about pages with company story, team sections,
 * values, mission/vision, and timeline/milestones.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build About Page Tool
 *
 * Creates about pages with:
 * - Company story and background
 * - Mission, vision, and values
 * - Team member profiles
 * - Company timeline/milestones
 * - Culture and workplace info
 * - Contact CTA
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Build_About_Page implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'build_about_page';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Build About Page', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates comprehensive about pages with company story, mission/vision/values, team profiles, timeline, and culture sections. Builds trust and connection with visitors through authentic storytelling.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'company_name' => array(
					'type'        => 'string',
					'description' => __( 'Company or organization name', 'mcp-ai-wpoos-pro' ),
				),
				'story'        => array(
					'type'        => 'string',
					'description' => __( 'Company story and background', 'mcp-ai-wpoos-pro' ),
				),
				'mission'      => array(
					'type'        => 'string',
					'description' => __( 'Company mission statement', 'mcp-ai-wpoos-pro' ),
				),
				'values'       => array(
					'type'        => 'array',
					'description' => __( 'Core company values (max 5)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
					'maxItems'    => 5,
				),
				'team_size'    => array(
					'type'        => 'string',
					'description' => __( 'Team size category', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'small', 'medium', 'large' ),
					'default'     => 'small',
				),
				'founded_year' => array(
					'type'        => 'integer',
					'description' => __( 'Year company was founded', 'mcp-ai-wpoos-pro' ),
				),
				'include_team' => array(
					'type'        => 'boolean',
					'description' => __( 'Include team members section', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'style'        => array(
					'type'        => 'string',
					'description' => __( 'Visual style preference', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'professional', 'friendly', 'modern', 'traditional' ),
					'default'     => 'professional',
				),
			),
			'required'             => array( 'company_name', 'story' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error About page data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create about pages.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate and sanitize arguments.
		$company_name = isset( $arguments['company_name'] ) ? sanitize_text_field( $arguments['company_name'] ) : '';
		$story        = isset( $arguments['story'] ) ? sanitize_textarea_field( $arguments['story'] ) : '';
		$mission      = isset( $arguments['mission'] ) ? sanitize_textarea_field( $arguments['mission'] ) : '';
		$values       = isset( $arguments['values'] ) && is_array( $arguments['values'] ) ?
			array_slice( array_map( 'sanitize_text_field', $arguments['values'] ), 0, 5 ) : array();
		$team_size    = isset( $arguments['team_size'] ) ? sanitize_text_field( $arguments['team_size'] ) : 'small';
		$founded_year = isset( $arguments['founded_year'] ) ? absint( $arguments['founded_year'] ) : null;
		$include_team = isset( $arguments['include_team'] ) ? (bool) $arguments['include_team'] : true;
		$style        = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'professional';

		if ( empty( $company_name ) || empty( $story ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_required',
				__( 'Company name and story are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Generate about page structure.
		$about_page = array(
			'title'    => 'About ' . $company_name,
			'story'    => $story,
			'style'    => $style,
			'sections' => array(),
		);

		// Build about page sections.
		$about_page['sections'][] = $this->generate_hero_section( $company_name, $story, $style );
		$about_page['sections'][] = $this->generate_story_section( $company_name, $story, $founded_year, $style );

		if ( ! empty( $mission ) || ! empty( $values ) ) {
			$about_page['sections'][] = $this->generate_mission_values_section( $mission, $values, $style );
		}

		if ( $founded_year ) {
			$about_page['sections'][] = $this->generate_timeline_section( $company_name, $founded_year, $style );
		}

		if ( $include_team ) {
			$about_page['sections'][] = $this->generate_team_section( $team_size, $style );
		}

		$about_page['sections'][] = $this->generate_culture_section( $company_name, $style );
		$about_page['sections'][] = $this->generate_contact_cta_section( $company_name, $style );

		// Log generation activity.
		$this->log_generation_activity( $user_id, $company_name );

		return array(
			'success'    => true,
			'about_page' => $about_page,
			'summary'    => $this->generate_summary( $about_page ),
			'timestamp'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate hero section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $company_name Company name.
	 * @param string $story        Story excerpt.
	 * @param string $style        Visual style.
	 * @return array Hero section.
	 */
	private function generate_hero_section( $company_name, $story, $style ) {
		return array(
			'type'    => 'hero',
			'content' => array(
				'headline' => "About {$company_name}",
				'subtitle' => substr( $story, 0, 200 ) . '...',
			),
			'style'   => $style,
		);
	}

	/**
	 * Generate story section.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $company_name Company name.
	 * @param string   $story        Full story.
	 * @param int|null $founded_year Founded year.
	 * @param string   $style        Visual style.
	 * @return array Story section.
	 */
	private function generate_story_section( $company_name, $story, $founded_year, $style ) {
		return array(
			'type'    => 'story',
			'content' => array(
				'title'   => 'Our Story',
				'content' => $story,
				'stats'   => array(
					array(
						'value' => $founded_year ? ( current_time( 'Y' ) - $founded_year ) . '+' : '10+',
						'label' => 'Years of Excellence',
					),
					array(
						'value' => '500+',
						'label' => 'Happy Clients',
					),
					array(
						'value' => '50+',
						'label' => 'Team Members',
					),
				),
			),
			'style'   => $style,
		);
	}

	/**
	 * Generate mission and values section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $mission Mission statement.
	 * @param array  $values  Company values.
	 * @param string $style   Visual style.
	 * @return array Mission/values section.
	 */
	private function generate_mission_values_section( $mission, $values, $style ) {
		$value_items = ! empty( $values ) ? $values : array( 'Integrity', 'Innovation', 'Excellence', 'Collaboration' );

		return array(
			'type'    => 'mission-values',
			'content' => array(
				'mission' => ! empty( $mission ) ? $mission : 'To deliver exceptional value to our clients',
				'values'  => array_map(
					function ( $value ) {
						return array(
							'title'       => $value,
							'description' => 'We believe in ' . strtolower( $value ) . ' in everything we do',
						);
					},
					$value_items
				),
			),
			'style'   => $style,
		);
	}

	/**
	 * Generate timeline section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $company_name Company name.
	 * @param int    $founded_year Founded year.
	 * @param string $style        Visual style.
	 * @return array Timeline section.
	 */
	private function generate_timeline_section( $company_name, $founded_year, $style ) {
		return array(
			'type'    => 'timeline',
			'content' => array(
				'title'      => 'Our Journey',
				'milestones' => array(
					array(
						'year'        => $founded_year,
						'title'       => "{$company_name} Founded",
						'description' => 'Our journey began with a vision',
					),
					array(
						'year'        => $founded_year + 3,
						'title'       => 'Major Growth',
						'description' => 'Expanded our services and team',
					),
					array(
						'year'        => current_time( 'Y' ),
						'title'       => 'Today',
						'description' => 'Leading the industry with innovation',
					),
				),
			),
			'style'   => $style,
		);
	}

	/**
	 * Generate team section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $team_size Team size.
	 * @param string $style     Visual style.
	 * @return array Team section.
	 */
	private function generate_team_section( $team_size, $style ) {
		$member_count = array(
			'small'  => 3,
			'medium' => 6,
			'large'  => 8,
		);

		$count = isset( $member_count[ $team_size ] ) ? $member_count[ $team_size ] : 3;

		$members = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$members[] = array(
				'name'     => 'Team Member ' . $i,
				'position' => 'Position Title',
				'bio'      => 'Brief bio about this team member',
			);
		}

		return array(
			'type'    => 'team',
			'content' => array(
				'title'   => 'Meet Our Team',
				'members' => $members,
			),
			'layout'  => 'grid',
			'style'   => $style,
		);
	}

	/**
	 * Generate culture section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $company_name Company name.
	 * @param string $style        Visual style.
	 * @return array Culture section.
	 */
	private function generate_culture_section( $company_name, $style ) {
		return array(
			'type'    => 'culture',
			'content' => array(
				'title'    => 'Life at ' . $company_name,
				'features' => array(
					array(
						'title'       => 'Collaborative Environment',
						'description' => 'Work with talented people who share your passion',
					),
					array(
						'title'       => 'Growth Opportunities',
						'description' => 'Continuous learning and career development',
					),
					array(
						'title'       => 'Work-Life Balance',
						'description' => 'Flexible schedules and remote options',
					),
				),
			),
			'style'   => $style,
		);
	}

	/**
	 * Generate contact CTA section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $company_name Company name.
	 * @param string $style        Visual style.
	 * @return array Contact CTA section.
	 */
	private function generate_contact_cta_section( $company_name, $style ) {
		return array(
			'type'    => 'cta',
			'content' => array(
				'title'   => "Want to Learn More About {$company_name}?",
				'text'    => 'Get in touch with us today',
				'buttons' => array(
					array(
						'text' => 'Contact Us',
						'link' => '/contact',
					),
				),
			),
			'style'   => $style,
		);
	}

	/**
	 * Generate summary.
	 *
	 * @since 1.2.0
	 *
	 * @param array $about_page About page data.
	 * @return string Summary.
	 */
	private function generate_summary( $about_page ) {
		return sprintf(
			/* translators: 1: about page title, 2: number of sections */
			__( 'Generated about page for %1$s with %2$d sections including story, mission/values, timeline, team, and culture.', 'mcp-ai-wpoos-pro' ),
			$about_page['title'],
			count( $about_page['sections'] )
		);
	}

	/**
	 * Log generation activity.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id      User ID.
	 * @param string $company_name Company name.
	 */
	private function log_generation_activity( $user_id, $company_name ) {
		if ( ! function_exists( 'wp_mcp_ai_log_activity' ) ) {
			return;
		}

		wp_mcp_ai_log_activity(
			sprintf( 'Site Creator: Generated about page - Company: %s (User: %d)', $company_name, $user_id ),
			'info'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'requires-capability',
			'consumes-tokens',
			'non-deterministic',
		);
	}
}
