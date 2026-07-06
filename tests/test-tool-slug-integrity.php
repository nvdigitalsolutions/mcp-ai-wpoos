<?php
/**
 * Tests to verify that all tools are registered with the correct slugs.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for tool slug integrity tests.
 *
 * @group tool-registry
 * @group tool-integrity
 */
class WP_MCP_AI_Tool_Slug_Integrity_Tests extends WP_UnitTestCase {

	/**
	 * Known slug overrides for tools whose slugs cannot be derived
	 * algorithmically from their class names (compound brand names,
	 * dropped prefixes, or fully custom slugs).
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_SLUGS = array(
		'WP_MCP_AI_Tool_EZuite_ERP'                    => 'ezuite_erp',
		'WP_MCP_AI_Tool_EZuite_ERP_Get_Products'       => 'ezuite_erp_get_products',
		'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message'     => 'send_whatsapp_message',
		'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template'    => 'send_whatsapp_template',
		'WP_MCP_AI_Pro_Tool_Get_WhatsApp_Messages'     => 'get_whatsapp_messages',
		'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync'   => 'quickbooks_desktop_sync',
		'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report'     => 'quickbooks_report',
		'WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report' => 'google_analytics_report',
		'WP_MCP_AI_Pro_Tool_CPT'                       => 'toolkit_cpt',
		// OpenAI-containing class names.
		'WP_MCP_AI_Tool_Generate_OpenAI_Image_Validated' => 'generate_openai_image_validated',
		'WP_MCP_AI_Tool_Generate_OpenAI_Speech_Validated' => 'generate_openai_speech_validated',
		'WP_MCP_AI_Tool_Edit_OpenAI_Image'             => 'edit_openai_image',
		'WP_MCP_AI_Tool_Generate_OpenAI_Image'         => 'generate_openai_image',
		'WP_MCP_AI_Tool_Generate_OpenAI_Speech'        => 'generate_openai_speech',
		'WP_MCP_AI_Tool_Edit_OpenAI_Image_Validated'   => 'edit_openai_image_validated',
		'WP_MCP_AI_Tool_Run_OpenAI_External_Action'    => 'run_openai_external_action',
		'WP_MCP_AI_Tool_OpenAI_Usage_Analytics'        => 'openai_usage_analytics',
		'WP_MCP_AI_Tool_List_OpenAI_Files'             => 'list_openai_files',
		'WP_MCP_AI_Tool_Get_OpenAI_File_Details'       => 'get_openai_file_details',
		'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio'       => 'transcribe_openai_audio',
		'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated' => 'transcribe_openai_audio_validated',
		// MCP-containing class names.
		'WP_MCP_AI_Tool_Probe_Remote_MCP'              => 'probe_remote_mcp',
		// NHC / GDACS acronyms.
		'WP_MCP_AI_Tool_Get_NHC_Active_Storms'         => 'get_nhc_active_storms',
		'WP_MCP_AI_Tool_Get_GDACS_Events'              => 'get_gdacs_events',
		// Crawl4AI.
		'WP_MCP_AI_Tool_Crawl4AI_Price_Lookup'         => 'crawl4ai_price_lookup',
		'WP_MCP_AI_Tool_Run_Crawl4AI_Job_Validated'    => 'run_crawl4ai_job_validated',
		'WP_MCP_AI_Tool_Run_Crawl4AI_Job'              => 'run_crawl4ai_job',
		// Common base-tool slugs that drop "get_" prefix.
		'WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report' => 'google_analytics_report',
		'WP_MCP_AI_Tool_Open_OpenAI_Logs'              => 'open_openai_logs',
		'WP_MCP_AI_Tool_Open_OpenAI_Usage'             => 'open_openai_usage',
		'WP_MCP_AI_Tool_ReliefWeb_Reports'             => 'reliefweb_reports',
		'WP_MCP_AI_Tool_PayHere_Get_Payment'           => 'payhere_get_payment',
		'WP_MCP_AI_Tool_Profession_Stats'              => 'get_profession_stats',
		'WP_MCP_AI_Tool_Get_User_Info_Validated'       => 'get_user_info',
		'WP_MCP_AI_Tool_Generate_Auth0Token'           => 'generate_auth0_token',
		'WP_MCP_AI_Tool_Generate_CloudflareAI_Image'   => 'cloudflareai_text_to_image',
		'WP_MCP_AI_Tool_Generate_Auth0_Token'          => 'generate_auth0_token',
	);

	/**
	 * Test that all tools return slugs that match their class names.
	 */
	public function test_all_tools_have_correct_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = $registry->get_tools();

		$this->assertNotEmpty( $tools, 'Registry should have tools registered' );

		$mismatches = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$class_name = get_class( $tool );
			$slug       = $tool->get_slug();

			$expected_slug = $this->class_name_to_slug( $class_name );

			if ( $slug !== $expected_slug ) {
				$mismatches[] = array(
					'class'    => $class_name,
					'expected' => $expected_slug,
					'actual'   => $slug,
				);
			}
		}

		if ( ! empty( $mismatches ) ) {
			$error_message = "Tool slug mismatches found:\n";
			foreach ( $mismatches as $mismatch ) {
				$error_message .= sprintf(
					"  - Class: %s\n    Expected slug: %s\n    Actual slug: %s\n",
					$mismatch['class'],
					$mismatch['expected'],
					$mismatch['actual']
				);
			}
			$this->fail( $error_message );
		}
	}

	/**
	 * Convert a tool class name to its expected slug.
	 *
	 * @param string $class_name Full class name.
	 * @return string Expected slug.
	 */
	protected function class_name_to_slug( $class_name ) {
		if ( isset( self::KNOWN_SLUGS[ $class_name ] ) ) {
			return self::KNOWN_SLUGS[ $class_name ];
		}

		$slug = str_replace( 'WP_MCP_AI_Pro_Tool_', '', $class_name );
		$slug = str_replace( 'WP_MCP_AI_Tool_', '', $slug );

		// Insert underscore between consecutive-uppercase block and next
		// uppercase+lowercase pair: "AIImage" -> "AI_Image".
		$slug = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1_$2', $slug );
		// Insert underscore between lowercase/digit and uppercase.
		$slug = preg_replace( '/([a-z\d])([A-Z])/', '$1_$2', $slug );
		$slug = strtolower( $slug );

		// Fix digit-adjacent underscores: "crawl4_ai" -> "crawl4ai".
		$slug = preg_replace( '/([a-z])(\d)_([a-z])/', '$1$2$3', $slug );

		$slug = str_replace( '__', '_', $slug );

		return $slug;
	}

	public function test_send_group_email_tool_exists() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'send_group_email' );
		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertEquals( 'send_group_email', $tool->get_slug() );
		$this->assertEquals( 'WP_MCP_AI_Tool_Send_Group_Email', get_class( $tool ) );
	}

	public function test_get_open_meteo_forecast_tool_exists() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'get_open_meteo_forecast' );
		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertEquals( 'get_open_meteo_forecast', $tool->get_slug() );
		$this->assertEquals( 'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast', get_class( $tool ) );
	}

	public function test_tool_retrieval_by_slug_is_correct() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$test_cases = array(
			'send_group_email'        => 'WP_MCP_AI_Tool_Send_Group_Email',
			'get_open_meteo_forecast' => 'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast',
			'search_content'          => 'WP_MCP_AI_Tool_Search_Content',
			'save_post'               => 'WP_MCP_AI_Tool_Save_Post',
			'web_search'              => 'WP_MCP_AI_Tool_Web_Search',
		);

		foreach ( $test_cases as $slug => $expected_class ) {
			$tool = $registry->get_tool( $slug );
			if ( $tool ) {
				$this->assertEquals(
					$expected_class,
					get_class( $tool ),
					"Tool slug '$slug' should return class '$expected_class'"
				);
			}
		}
	}

	public function test_no_duplicate_tool_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = $registry->get_tools();
		$slugs = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}
			$slug = $tool->get_slug();
			if ( isset( $slugs[ $slug ] ) ) {
				$this->fail(
					sprintf(
						"Duplicate tool slug found: '%s' is used by both %s and %s",
						$slug,
						get_class( $slugs[ $slug ] ),
						get_class( $tool )
					)
				);
			}
			$slugs[ $slug ] = $tool;
		}

		$this->assertGreaterThan( 0, count( $slugs ) );
	}
}
