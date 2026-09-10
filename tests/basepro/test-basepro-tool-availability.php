<?php
/**
 * Base+pro regression pins for tool-level availability gates.
 *
 * Regression for the is_available()/get_unavailable_reason() gate batch:
 * with the base version constant true AND the Pro addon active, enabled
 * toolkits must expose their tools as available — the base-version gate must
 * never be the blocker. For credential-gated toolkits (Cloudways, DietPi)
 * the pinned contract is that the unavailable reason is the settings/
 * credentials message, never the "only available in the Pro addon" message.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/trait-basepro-test-case.php';

/**
 * Tool availability base+pro regression tests.
 */
class Test_BasePro_Tool_Availability extends WP_UnitTestCase {
	use WP_MCP_AI_BasePro_Test_Case;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_mcp_ai_basepro_require_matrix();
		$this->wp_mcp_ai_basepro_enable_toolkits(
			array(
				'enable_analytics_toolkit',
				'enable_financial_planner_toolkit',
				'enable_architectural_design_toolkit',
				'enable_social_media_toolkit',
				'enable_video_production_toolkit',
				'enable_dj_management_toolkit',
				'enable_ai_tool_builder_toolkit',
				'enable_architect_agent_toolkit',
				'enable_comic_creation_toolkit',
				'enable_cre_debt_toolkit',
				'enable_law_firm_toolkit',
				'enable_multilingual_toolkit',
				'enable_image_production_toolkit',
				'enable_site_creator_toolkit',
				'enable_document_generation_toolkit',
				'enable_cloudways_toolkit',
				'enable_dietpi_toolkit',
				'enable_webchat_integration',
			)
		);
	}

	/**
	 * Representative tools across toolkits must report available in base+pro.
	 *
	 * @return void
	 */
	public function test_representative_tools_available_with_pro_active(): void {
		$cases = array(
			// Class => absolute file.
			'WP_MCP_AI_Tool_Cohort_Analysis'              => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-cohort-analysis.php',
			'WP_MCP_AI_Tool_Retirement_Calculator'        => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-retirement-calculator.php',
			'WP_MCP_AI_Tool_Generate_Floor_Plan'          => WP_MCP_AI_PRO_PATH . 'includes/tools/architectural-design/floor-planning/class-wp-mcp-ai-tool-generate-floor-plan.php',
			'WP_MCP_AI_Tool_Post_To_Multiple_Platforms'   => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-post-to-multiple-platforms.php',
			'WP_MCP_AI_Tool_Trim_Video'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-trim-video.php',
			'WP_MCP_AI_Tool_Get_Trending_Tracks'          => WP_MCP_AI_PRO_PATH . 'includes/tools/dj-management/class-wp-mcp-ai-tool-get-trending-tracks.php',
			'WP_MCP_AI_Tool_CRE_Leverage_Return_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-leverage-return-analyzer.php',
			'WP_MCP_AI_Tool_Lf_Document_Drafter'          => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-drafter.php',
			'WP_MCP_AI_Tool_Auto_Translate_Content'       => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-auto-translate-content.php',
			'WP_MCP_AI_Tool_Harmonize_Color'              => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonize-color.php',
			'WP_MCP_AI_Tool_Archive_Documents'            => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-archive-documents.php',
		);

		foreach ( $cases as $class => $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			$this->assertTrue( class_exists( $class ), "Tool class {$class} should exist." );

			$method = new ReflectionMethod( $class, 'is_available' );
			$target = $method->isStatic() ? null : new $class();
			$this->assertTrue(
				(bool) $method->invoke( $target ),
				"{$class}::is_available() must be true in base+pro with its toolkit enabled."
			);
		}
	}

	/**
	 * Credential-gated toolkits must not be blocked by the base gate — their
	 * unavailable reason must be the settings/credentials message.
	 *
	 * @return void
	 */
	public function test_credential_gated_tools_reason_not_pro_only(): void {
		$cases = array(
			'WP_MCP_AI_Tool_Cloudways_Base' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php',
			'WP_MCP_AI_Tool_Dietpi_Base'    => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-base.php',
		);

		foreach ( $cases as $class => $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			$this->assertTrue( class_exists( $class ), "Tool class {$class} should exist." );

			$method = new ReflectionMethod( $class, 'get_unavailable_reason' );
			$target = $method->isStatic() ? null : new $class();
			$reason = (string) $method->invoke( $target );

			$this->assertStringNotContainsString(
				'only available in the Pro addon',
				$reason,
				"{$class} must not report the Pro-only reason in base+pro: {$reason}"
			);
		}
	}

	/**
	 * Embedded webchat tools must report available in base+pro.
	 *
	 * @return void
	 */
	public function test_webchat_tool_available_with_pro_active(): void {
		$plugin_root    = dirname( dirname( __DIR__ ) );
		$embedded_entry = $plugin_root . '/addons/embedded/nvoos-embedded.php';
		if ( file_exists( $embedded_entry ) ) {
			require_once $embedded_entry;
		}

		$tool_file = $plugin_root . '/addons/embedded/includes/webchat/tools/class-wp-mcp-ai-tool-create-webchat-room.php';
		$this->assertFileExists( $tool_file );
		require_once $tool_file;

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Create_WebChat_Room' ) );
		$this->assertTrue(
			WP_MCP_AI_Tool_Create_WebChat_Room::is_available(),
			'WebChat room tool must be available in base+pro with webchat enabled.'
		);

		$reason = WP_MCP_AI_Tool_Create_WebChat_Room::get_unavailable_reason();
		$this->assertStringNotContainsString( 'only available in the Pro version', $reason );
	}
}
