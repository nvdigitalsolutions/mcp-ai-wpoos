<?php
/**
 * Test Phase 3 Unexposed Settings Implementation
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Phase 3 unexposed settings.
 */
class Test_Phase3_Unexposed_Settings extends WP_UnitTestCase {

	/**
	 * Test that chat_colors field is defined in Chat Client section.
	 */
	public function test_chat_colors_field_defined() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Chat_Client' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-chat-client.php';
		}

		$section = new WP_MCP_AI_Section_Chat_Client();
		$fields  = $section->get_fields();

		$this->assertArrayHasKey( 'chat_colors', $fields, 'chat_colors field should be defined' );
		$this->assertEquals( 'html', $fields['chat_colors']['type'], 'chat_colors should be an HTML field' );
	}

	/**
	 * Test that chat_colors is in appearance subtab.
	 */
	public function test_chat_colors_in_appearance_subtab() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Chat_Client' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-chat-client.php';
		}

		$section        = new WP_MCP_AI_Section_Chat_Client();
		$reflection     = new ReflectionClass( $section );
		$method         = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups  = $method->invoke( $section );

		$this->assertArrayHasKey( 'appearance', $subtab_groups, 'appearance subtab should exist' );
		$this->assertContains( 'chat_colors', $subtab_groups['appearance']['fields'], 'chat_colors should be in appearance subtab' );
	}

	/**
	 * Test that orchestration_preset field is defined in Orchestration section.
	 */
	public function test_orchestration_preset_field_defined() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Orchestration' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-orchestration.php';
		}

		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		$this->assertArrayHasKey( 'orchestration_preset', $fields, 'orchestration_preset field should be defined' );
		$this->assertEquals( 'hidden', $fields['orchestration_preset']['type'], 'orchestration_preset should be a hidden field' );
		$this->assertEquals( 'auto', $fields['orchestration_preset']['default'], 'orchestration_preset default should be auto' );
	}

	/**
	 * Test that google_analytics_credentials_json is in google_analytics subtab.
	 */
	public function test_google_analytics_credentials_json_in_subtab() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Integrations' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-integrations.php';
		}

		$section        = new WP_MCP_AI_Section_Integrations();
		$fields         = $section->get_fields();
		$reflection     = new ReflectionClass( $section );
		$method         = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups  = $method->invoke( $section );

		$this->assertArrayHasKey( 'google_analytics_credentials_json', $fields, 'google_analytics_credentials_json field should be defined' );
		$this->assertArrayHasKey( 'google_analytics', $subtab_groups, 'google_analytics subtab should exist' );
		$this->assertContains( 'google_analytics_credentials_json', $subtab_groups['google_analytics']['fields'], 'google_analytics_credentials_json should be in google_analytics subtab' );
	}

	/**
	 * Test that ita_tariff_api_key is in google_analytics subtab.
	 */
	public function test_ita_tariff_api_key_in_subtab() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Integrations' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-integrations.php';
		}

		$section        = new WP_MCP_AI_Section_Integrations();
		$fields         = $section->get_fields();
		$reflection     = new ReflectionClass( $section );
		$method         = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups  = $method->invoke( $section );

		$this->assertArrayHasKey( 'ita_tariff_api_key', $fields, 'ita_tariff_api_key field should be defined' );
		$this->assertArrayHasKey( 'google_analytics', $subtab_groups, 'google_analytics subtab should exist' );
		$this->assertContains( 'ita_tariff_api_key', $subtab_groups['google_analytics']['fields'], 'ita_tariff_api_key should be in google_analytics subtab' );
	}

	/**
	 * Test that all 28 Phase 3 settings are accessible.
	 */
	public function test_all_28_settings_accessible() {
		// Load all section classes.
		$section_files = array(
			'class-wp-mcp-ai-section-chat-client.php',
			'class-wp-mcp-ai-section-media.php',
			'class-wp-mcp-ai-section-integrations.php',
			'class-wp-mcp-ai-section-advanced.php',
			'class-wp-mcp-ai-section-authentication.php',
			'class-wp-mcp-ai-section-providers.php',
			'class-wp-mcp-ai-section-orchestration.php',
			'class-wp-mcp-ai-section-tools.php',
		);

		foreach ( $section_files as $file ) {
			$filepath = dirname( __DIR__ ) . '/includes/admin/sections/' . $file;
			if ( file_exists( $filepath ) ) {
				require_once $filepath;
			}
		}

		$required_settings = array(
			'allowed_file_mimes',
			'allowed_image_mimes',
			'chat_colors',
			'cloudways_app_id',
			'cloudways_server_id',
			'enable_federation_directory',
			'federation_regions',
			'federation_data_tags',
			'federation_qps',
			'federation_burst',
			'federation_jwks_keys',
			'federation_price_hints',
			'google_analytics_credentials_json',
			'ita_tariff_api_key',
			'wordpress_gravatar_userinfo_endpoint',
			'mesh_inbound_api_key',
			'mesh_peer_sites',
			'enable_high_token_model_switch',
			'high_token_fallback_model',
			'openai_speech_model',
			'openai_speech_voice',
			'openai_speech_format',
			'orchestration_preset',
			'web_search_provider',
			'group_email_capability',
			'group_email_max_recipients',
			'enable_varnish_purge',
			'enable_wordpress_gravatar_bridge',
		);

		$missing = array();

		foreach ( $required_settings as $setting ) {
			$found = false;

			// Check each section class.
			$section_classes = array(
				'WP_MCP_AI_Section_Chat_Client',
				'WP_MCP_AI_Section_Media',
				'WP_MCP_AI_Section_Integrations',
				'WP_MCP_AI_Section_Advanced',
				'WP_MCP_AI_Section_Authentication',
				'WP_MCP_AI_Section_Providers',
				'WP_MCP_AI_Section_Orchestration',
				'WP_MCP_AI_Section_Tools',
			);

			foreach ( $section_classes as $class ) {
				if ( class_exists( $class ) ) {
					$section = new $class();
					$fields  = $section->get_fields();

					if ( isset( $fields[ $setting ] ) ) {
						$found = true;
						break;
					}
				}
			}

			if ( ! $found ) {
				$missing[] = $setting;
			}
		}

		$this->assertEmpty( $missing, 'All 28 Phase 3 settings should be defined. Missing: ' . implode( ', ', $missing ) );
	}
}
