<?php
/**
 * Tests for the JSON-driven model catalog loader, filter hook, cache, and
 * the one-time migration that rewrites stored model identifiers.
 *
 * Covers:
 *  - JSON file is valid and round-trips through the loader.
 *  - wp_mcp_ai_model_catalog filter additions appear in callers.
 *  - flush_catalog_cache() invalidates the cache.
 *  - Every option key in every dropdown exists in the catalog with status=active.
 *  - User-pinned defaults remain ACTIVE.
 *  - Removed ids do not appear in the catalog.
 *  - Migration map covers known legacy ids.
 *
 * @package WP_MCP_AI
 */
class Test_Model_Catalog extends WP_UnitTestCase {

	/**
	 * Tear down: ensure cache is reset and any test filters are removed.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		remove_all_filters( 'wp_mcp_ai_model_catalog' );
		remove_all_filters( 'wp_mcp_ai_model_catalog_source_path' );
		parent::tearDown();
	}

	/**
	 * The bundled JSON catalog file is valid JSON with the expected shape.
	 */
	public function test_bundled_catalog_json_is_valid() {
		$path = WP_MCP_AI_PATH . 'includes/data/model-catalog.json';
		$this->assertFileExists( $path );

		$decoded = json_decode( file_get_contents( $path ), true );
		$this->assertIsArray( $decoded, 'Catalog JSON must decode to an array.' );
		$this->assertArrayHasKey( 'models', $decoded );
		$this->assertNotEmpty( $decoded['models'] );

		foreach ( $decoded['models'] as $idx => $entry ) {
			$this->assertIsArray( $entry, "Entry {$idx} must be an object." );
			$this->assertNotEmpty( $entry['model_name'], "Entry {$idx} missing model_name." );
			$this->assertNotEmpty( $entry['provider'], "Entry {$idx} ({$entry['model_name']}) missing provider." );
		}
	}

	/**
	 * The loader returns at least one entry when reading the bundled JSON.
	 */
	public function test_loader_returns_entries() {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		$entries = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$this->assertIsArray( $entries );
		$this->assertGreaterThan( 50, count( $entries ), 'Catalog should contain at least 50 entries.' );
	}

	/**
	 * The wp_mcp_ai_model_catalog filter can append a custom entry.
	 */
	public function test_filter_can_add_custom_entry() {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();

		add_filter(
			'wp_mcp_ai_model_catalog',
			function ( $catalog ) {
				$catalog[] = array(
					'model_name'                => 'test-custom-model',
					'provider'                  => 'openai',
					'name'                      => 'Test Custom Model',
					'tpm'                       => 1000,
					'rpm'                       => 60,
					'tpd'                       => 100000,
					'rpd'                       => 1000,
					'context_window'            => 4096,
					'max_output_tokens'         => 1024,
					'supports_function_calling' => false,
					'supports_vision'           => false,
					'cost_per_1k_input_tokens'  => 0.0001,
					'cost_per_1k_output_tokens' => 0.0002,
					'fallback_model'            => 'gpt-4o-mini',
					'status'                    => 'active',
					'sunset_date'               => '',
					'notes'                     => 'Added by filter for regression test.',
				);
				return $catalog;
			}
		);

		$entries = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$names   = array_column( $entries, 'model_name' );
		$this->assertContains( 'test-custom-model', $names, 'Filter-added entry should be present.' );
	}

	/**
	 * Cache invalidation forces a re-read of the JSON + filters.
	 */
	public function test_flush_catalog_cache_reloads_filtered_data() {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		$initial       = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$initial_count = count( $initial );

		add_filter(
			'wp_mcp_ai_model_catalog',
			function ( $catalog ) {
				$catalog[] = array(
					'model_name' => 'cache-bust-test',
					'provider'   => 'openai',
				);
				return $catalog;
			}
		);

		// Without flushing, the cache returns the pre-filter list.
		$cached = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$this->assertCount( $initial_count, $cached, 'Cached call should not see new filter.' );

		// After flushing, the new filter result is reflected.
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		$reloaded = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$names    = array_column( $reloaded, 'model_name' );
		$this->assertContains( 'cache-bust-test', $names );
	}

	/**
	 * User-pinned defaults remain ACTIVE in the catalog after the April 2026 refresh.
	 */
	public function test_user_pinned_defaults_remain_active() {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		$entries = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();

		$status_by_name = array();
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['model_name'] ) ) {
				$status_by_name[ $entry['model_name'] ] = isset( $entry['status'] ) ? $entry['status'] : 'active';
			}
		}

		$user_pinned_active = array( 'gpt-4.1', 'gpt-4o-mini', 'gpt-4.1-mini' );
		foreach ( $user_pinned_active as $id ) {
			$this->assertArrayHasKey( $id, $status_by_name, "User-pinned id {$id} must exist in catalog." );
			$this->assertSame( 'active', $status_by_name[ $id ], "User-pinned id {$id} must remain ACTIVE." );
		}

		// OpenAI announced retirements for these pinned ids (shutdown 2026-10-23) —
		// they stay servable (deprecated) with a documented successor until sunset.
		$user_pinned_deprecated = array( 'gpt-4o', 'gpt-4.1-nano' );
		foreach ( $user_pinned_deprecated as $id ) {
			$this->assertArrayHasKey( $id, $status_by_name, "User-pinned id {$id} must exist in catalog." );
			$this->assertSame( 'deprecated', $status_by_name[ $id ], "User-pinned id {$id} must stay DEPRECATED (servable) with a successor." );
		}
	}

	/**
	 * Fully retired ids must not appear in the catalog.
	 */
	public function test_removed_ids_absent_from_catalog() {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		$entries = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$names   = array_column( $entries, 'model_name' );

		$removed = array(
			'gpt-3.5-turbo',
			'gpt-4',
			'gpt-4-turbo',
			'o1',
			'o1-mini',
			'o1-preview',
			'gpt-5-codex',
			'gpt-5.1-codex',
			'gpt-5.1-codex-max',
			'gpt-5.1-codex-mini',
			'gpt-5.2-codex',
			'gemini-1.5-pro',
			'gemini-1.5-flash',
			'gemini-pro',
			'gemini-3-pro-preview',
			'gemini-2.5-flash-image',
			'gemini-live-2.5-flash-preview',
			'claude-3-opus-20240229',
			'claude-mythos-preview',
			'claude-3-5-sonnet-20241022',
			'claude-3-5-haiku-20241022',
			'deepseek-v4-flash',
			'deepseek-v4-flash-vision-exp',
			'kimi-k2',
			'kimi-k2.5',
			'kimi-k2-thinking',
			'moonshot-v1-8k',
			'moonshot-v1-32k',
			'moonshot-v1-128k',
		);

		foreach ( $removed as $id ) {
			$this->assertNotContains( $id, $names, "Retired id {$id} must not appear in the April 2026 catalog." );
		}
	}

	/**
	 * Migration map provides successors for every documented removal.
	 */
	public function test_migration_map_covers_known_removals() {
		$map = WP_MCP_AI_Model_Catalog_Migration::get_legacy_id_map();
		$this->assertNotEmpty( $map );

		$documented = array(
			'gpt-3.5-turbo',
			'gpt-4',
			'gpt-4-turbo',
			'o1',
			'gemini-1.5-pro',
			'claude-3-opus-20240229',
		);
		foreach ( $documented as $id ) {
			$this->assertArrayHasKey( $id, $map, "Migration map must rewrite legacy id {$id}." );
			$this->assertNotEmpty( $map[ $id ], "Migration map must map {$id} to a successor." );
		}
	}

	/**
	 * The migration rewrites stored option keys and assistant post meta.
	 */
	public function test_migration_rewrites_option_and_post_meta() {
		// Seed option with legacy id.
		update_option(
			'wp_mcp_ai_model_configs',
			array(
				'gpt-3.5-turbo' => array(
					'enabled'        => true,
					'fallback_model' => 'gpt-4',
				),
			)
		);

		// Seed assistant post meta with legacy id.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wp_mcp_ai_model', 'gpt-3.5-turbo' );

		// Force a re-run by using a fresh catalog version key.
		delete_option( WP_MCP_AI_Model_Catalog_Migration::OPTION_KEY );
		WP_MCP_AI_Model_Catalog_Migration::run_if_needed( 'test-' . time() );

		$configs = get_option( 'wp_mcp_ai_model_configs', array() );
		$this->assertArrayNotHasKey( 'gpt-3.5-turbo', $configs, 'Legacy key should be removed.' );
		$this->assertArrayHasKey( 'gpt-4o-mini', $configs, 'Successor key should be created.' );

		$this->assertSame( 'gpt-4o-mini', get_post_meta( $post_id, '_wp_mcp_ai_model', true ) );
	}

	/**
	 * The migration also rewrites retired ids stored under per-provider
	 * default model settings (e.g. deepseek_model) so stale defaults cannot
	 * leak a retired model into provider-specific resolution.
	 */
	public function test_migration_rewrites_provider_model_settings() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'deepseek_model'       => 'deepseek-v4-flash',
				'default_model'        => 'gpt-3.5-turbo',
				'openai_base_url'      => 'https://api.openai.com/v1',
				'request_timeout'      => 30,
				'default_gemini_model' => 'gemini-2.5-flash',
			)
		);

		// Force a re-run by using a fresh catalog version key.
		delete_option( WP_MCP_AI_Model_Catalog_Migration::OPTION_KEY );
		WP_MCP_AI_Model_Catalog_Migration::run_if_needed( 'test-' . time() );

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$this->assertSame( 'deepseek-flash', $settings['deepseek_model'] );
		$this->assertSame( 'gpt-4o-mini', $settings['default_model'] );
		$this->assertSame( 'gemini-2.5-flash', $settings['default_gemini_model'] );

		// Non-model keys must be left untouched.
		$this->assertSame( 'https://api.openai.com/v1', $settings['openai_base_url'] );
		$this->assertSame( 30, $settings['request_timeout'] );
	}

	/**
	 * Provider dropdown fallback options must all exist as active entries.
	 */
	public function test_provider_dropdown_options_exist_in_catalog() {
		WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache();
		$entries = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$active  = array();
		foreach ( $entries as $entry ) {
			$status = isset( $entry['status'] ) ? $entry['status'] : 'active';
			if ( 'active' === $status ) {
				$active[] = $entry['model_name'];
			}
		}

		// These keys mirror the fallback dropdowns in the admin Providers section.
		$dropdown_keys = array(
			// OpenAI fallback list.
			'gpt-6-astra',
			'gpt-6-sol',
			'gpt-6-luna',
			'gpt-5.6-sol',
			'gpt-5.6-terra',
			'gpt-5.6-luna',
			'gpt-5.4',
			'gpt-5.4-mini',
			'gpt-5.5',
			'gpt-4.1',
			'gpt-4.1-mini',
			'gpt-4o-mini',
			// Anthropic fallback list.
			'claude-opus-5-5',
			'claude-opus-5',
			'claude-fable-5.1',
			'claude-sonnet-5-5',
			'claude-sonnet-5',
			'claude-opus-4-7',
			'claude-opus-4-6',
			'claude-sonnet-4-6',
			'claude-haiku-4-5',
			// Gemini fallback list.
			'gemini-3.8-flash',
			'gemini-3.7-flash',
			'gemini-3.6-flash',
			'gemini-3.5-flash',
			'gemini-3.5-flash-lite',
			'gemini-3.1-pro',
			'gemini-2.5-pro',
			'gemini-2.5-flash',
			// DeepSeek fallback list.
			'deepseek-flash',
			// Kimi fallback list.
			'kimi-k3',
			'kimi-k2.7-code',
			'kimi-k2.6',
			// Z.AI (GLM) catalog entries.
			'glm-5.3',
			'glm-5.3-flash',
			'glm-5.3-flashx',
			'glm-5.2',
		);

		foreach ( $dropdown_keys as $id ) {
			$this->assertContains( $id, $active, "Dropdown id {$id} must be present and active in the catalog." );
		}
	}
}
