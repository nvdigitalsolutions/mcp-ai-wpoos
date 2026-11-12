<?php
/**
 * Test suite for the generative-media model capability registry.
 *
 * Covers registry integrity, drift against the declaring tool constants,
 * argument validation (drop/clamp semantics), payload building, and model
 * recommendation.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Media_Model_Catalog.
 */
class Test_Media_Model_Catalog extends WP_UnitTestCase {

	/**
	 * Load the registry and reset its in-request cache between tests.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-model-catalog.php';
		WP_MCP_AI_Media_Model_Catalog::reset_cache();
	}

	/**
	 * Restore filters and cache.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_media_model_catalog_path' );
		WP_MCP_AI_Media_Model_Catalog::reset_cache();
		parent::tear_down();
	}

	/**
	 * The shipped registry loads and reports a version.
	 */
	public function test_registry_loads() {
		$this->assertTrue( WP_MCP_AI_Media_Model_Catalog::is_available() );

		$catalog = WP_MCP_AI_Media_Model_Catalog::get_catalog();

		$this->assertNotEmpty( $catalog['version'] );
		$this->assertNotEmpty( $catalog['updated_at'] );
		$this->assertGreaterThanOrEqual( 10, count( $catalog['models'] ) );
	}

	/**
	 * Every entry carries the keys consumers rely on.
	 */
	public function test_every_entry_has_required_keys() {
		$required = array(
			'id',
			'provider',
			'tool',
			'kind',
			'label',
			'modes',
			'aspect_ratios',
			'resolutions',
			'max_reference_images',
			'audio',
			'tier',
			'styles',
		);

		foreach ( WP_MCP_AI_Media_Model_Catalog::get_catalog()['models'] as $id => $model ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $model, "Model {$id} is missing {$key}" );
			}

			$this->assertContains( $model['kind'], WP_MCP_AI_Media_Model_Catalog::KINDS, "Model {$id} has an unknown kind" );
			$this->assertContains( $model['tier'], WP_MCP_AI_Media_Model_Catalog::TIERS, "Model {$id} has an unknown tier" );
			$this->assertContains( $model['audio'], WP_MCP_AI_Media_Model_Catalog::AUDIO_POLICIES, "Model {$id} has an unknown audio policy" );
			$this->assertNotEmpty( $model['modes'], "Model {$id} declares no modes" );
			$this->assertNotEmpty( $model['styles'], "Model {$id} declares no styles" );
		}
	}

	/**
	 * The registry must not silently disagree with the Higgsfield video tool.
	 *
	 * This is the guard that keeps the catalog from becoming a second stale
	 * copy of constraints that already live in code.
	 */
	public function test_higgsfield_video_entries_match_tool_constant() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Video' );
		$declared   = $reflection->getConstant( 'VIDEO_MODELS' );

		$this->assertNotEmpty( $declared, 'VIDEO_MODELS constant not found' );

		foreach ( $declared as $id => $spec ) {
			$model = WP_MCP_AI_Media_Model_Catalog::get_model( $id );
			$this->assertNotNull( $model, "Registry is missing Higgsfield video model {$id}" );
			$this->assertSame( 'higgsfield', $model['provider'], "Provider mismatch for {$id}" );
			$this->assertSame( 'generate_higgsfield_video', $model['tool'], "Tool mismatch for {$id}" );

			$range = WP_MCP_AI_Media_Model_Catalog::get_duration_range( $id );
			$this->assertNotNull( $range, "Duration range missing for {$id}" );
			$this->assertSame( (int) $spec['duration'][0], $range['min'], "duration.min drift for {$id}" );
			$this->assertSame( (int) $spec['duration'][1], $range['max'], "duration.max drift for {$id}" );

			$this->assertSame(
				array_values( $spec['resolutions'] ),
				WP_MCP_AI_Media_Model_Catalog::get_enum( $id, 'resolutions' ),
				"resolutions drift for {$id}"
			);

			$this->assertSame(
				array_values( $spec['aspects'] ),
				WP_MCP_AI_Media_Model_Catalog::get_enum( $id, 'aspect_ratios' ),
				"aspect_ratios drift for {$id}"
			);

			$expected_refs = isset( $spec['refs']['image'] ) ? (int) $spec['refs']['image'] : 0;
			$this->assertSame(
				$expected_refs,
				WP_MCP_AI_Media_Model_Catalog::max_reference_images( $id ),
				"max_reference_images drift for {$id}"
			);
		}
	}

	/**
	 * Every declared tool slug is a real string and kind is inferable.
	 */
	public function test_tool_slugs_are_sane() {
		foreach ( WP_MCP_AI_Media_Model_Catalog::get_catalog()['models'] as $id => $model ) {
			$this->assertSame( $model['tool'], sanitize_key( $model['tool'] ), "Tool slug for {$id} is not key-safe" );

			// Model ids may contain dots (cinema-studio-4.0), so the registry's
			// own normaliser — not sanitize_key() — defines "key-safe".
			$this->assertSame( $id, WP_MCP_AI_Media_Model_Catalog::normalize_id( $id ), "Model id {$id} is not lookup-safe" );
		}
	}

	/**
	 * Query filters compose.
	 */
	public function test_get_models_filters() {
		$videos = WP_MCP_AI_Media_Model_Catalog::get_models( array( 'kind' => 'video' ) );
		foreach ( $videos as $model ) {
			$this->assertSame( 'video', $model['kind'] );
		}

		$higgsfield = WP_MCP_AI_Media_Model_Catalog::get_models(
			array(
				'kind'     => 'video',
				'provider' => 'higgsfield',
			)
		);
		$this->assertCount( 5, $higgsfield );

		$cinematic = WP_MCP_AI_Media_Model_Catalog::get_models( array( 'style' => 'cinematic' ) );
		$this->assertNotEmpty( $cinematic );

		$by_tool = WP_MCP_AI_Media_Model_Catalog::get_models( array( 'tool' => 'generate_sora_video' ) );
		$this->assertCount( 2, $by_tool );
	}

	/**
	 * A model with no resolution parameter drops it rather than forwarding it.
	 */
	public function test_validate_drops_unsupported_resolution() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate(
			'kling-3.0',
			array(
				'resolution' => '1080p',
				'prompt'     => 'A cat',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'resolution', $result['params'] );
		$this->assertNotEmpty( $result['dropped'] );
		$this->assertSame( 'resolution', $result['dropped'][0]['field'] );
	}

	/**
	 * Non-capability keys are forwarded untouched for the owning tool.
	 */
	public function test_validate_forwards_unknown_keys() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate(
			'soul-2',
			array(
				'prompt'         => 'Editorial portrait',
				'negative_prompt' => 'blurry',
				'style_id'       => 'abc-123',
			)
		);

		$this->assertSame( 'blurry', $result['params']['negative_prompt'] );
		$this->assertSame( 'abc-123', $result['params']['style_id'] );
		$this->assertEmpty( $result['dropped'] );
	}

	/**
	 * Duration is clamped into the declared range, with a warning.
	 */
	public function test_validate_clamps_duration() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate(
			'seedance-2.0',
			array( 'duration' => 99 )
		);

		$this->assertSame( 15, $result['params']['duration'] );
		$this->assertNotEmpty( $result['warnings'] );

		$result = WP_MCP_AI_Media_Model_Catalog::validate(
			'seedance-2.0',
			array( 'duration' => 1 )
		);
		$this->assertSame( 4, $result['params']['duration'] );
	}

	/**
	 * Excess reference images are truncated, not fatal.
	 */
	public function test_validate_truncates_reference_images() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate(
			'seedance-2.0',
			array( 'reference_images' => range( 1, 12 ) )
		);

		$this->assertCount( 9, $result['params']['reference_images'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	/**
	 * Reference media is dropped entirely for a model that accepts none.
	 */
	public function test_validate_drops_reference_media_when_unsupported() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate(
			'soul-2',
			array( 'reference_images' => array( 5, 6 ) )
		);

		$this->assertArrayNotHasKey( 'reference_images', $result['params'] );
		$this->assertNotEmpty( $result['dropped'] );
	}

	/**
	 * An invalid batch size is dropped.
	 */
	public function test_validate_drops_invalid_batch_size() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate( 'soul-2', array( 'batch_size' => 3 ) );

		$this->assertArrayNotHasKey( 'batch_size', $result['params'] );
		$this->assertSame( 'batch_size', $result['dropped'][0]['field'] );

		// The drop record must report the value the caller actually sent.
		$this->assertSame( 3, $result['dropped'][0]['value'] );

		$valid = WP_MCP_AI_Media_Model_Catalog::validate( 'soul-2', array( 'batch_size' => 4 ) );
		$this->assertSame( 4, $valid['params']['batch_size'] );
	}

	/**
	 * Seed is dropped for a model that does not accept one, and the drop record
	 * still reports the submitted value.
	 */
	public function test_validate_drops_seed_when_unsupported() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate( 'omni', array( 'seed' => 12345 ) );

		$this->assertArrayNotHasKey( 'seed', $result['params'] );
		$this->assertSame( 'seed', $result['dropped'][0]['field'] );
		$this->assertSame( 12345, $result['dropped'][0]['value'] );

		$supported = WP_MCP_AI_Media_Model_Catalog::validate( 'soul-2', array( 'seed' => 42 ) );
		$this->assertSame( 42, $supported['params']['seed'] );
	}

	/**
	 * Unknown model ids are a hard error.
	 */
	public function test_validate_unknown_model_is_error() {
		$result = WP_MCP_AI_Media_Model_Catalog::validate( 'does-not-exist', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_media_model_unknown', $result->get_error_code() );
	}

	/**
	 * Payload building casts duration to the declared wire type and injects
	 * fixed parameters, using a fixture so both branches are exercised.
	 */
	public function test_build_payload_coerces_duration_and_applies_fixed() {
		$fixture = __DIR__ . '/fixtures/media-model-catalog-fixture.json';
		$this->assertFileExists( $fixture );

		add_filter(
			'wp_mcp_ai_media_model_catalog_path',
			static function () use ( $fixture ) {
				return $fixture;
			}
		);

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$numeric = WP_MCP_AI_Media_Model_Catalog::build_payload( 'fixture-number', array( 'duration' => 7 ) );
		$this->assertSame( 7, $numeric['payload']['duration'] );
		$this->assertSame( 'veo3', $numeric['payload']['model'] );

		$string = WP_MCP_AI_Media_Model_Catalog::build_payload( 'fixture-string', array( 'duration' => 7 ) );
		$this->assertSame( '7', $string['payload']['duration'] );
	}

	/**
	 * Style-filtered recommendations exclude models that cannot hold the
	 * reference count, and exclude budget models entirely.
	 */
	public function test_recommend_filters_by_style_and_reference_capacity() {
		$results = WP_MCP_AI_Media_Model_Catalog::recommend(
			array(
				'kind'             => 'video',
				'style'            => 'realistic',
				'reference_images' => 12,
			)
		);

		$ids = wp_list_pluck( $results, 'id' );

		$this->assertContains( 'cinema-studio-4.0', $ids );
		$this->assertContains( 'seedance-2.5', $ids );

		// 9-reference cap cannot hold 12.
		$this->assertNotContains( 'seedance-2.0', $ids );
		// Single-reference caps cannot hold 12.
		$this->assertNotContains( 'veo-3.1', $ids );
		// Budget tier is excluded whenever a specific style is requested.
		$this->assertNotContains( 'veo-2.0', $ids );
	}

	/**
	 * A duration requirement excludes models with a shorter ceiling.
	 */
	public function test_recommend_filters_by_duration() {
		$results = WP_MCP_AI_Media_Model_Catalog::recommend(
			array(
				'kind'     => 'video',
				'duration' => 45,
			)
		);

		$ids = wp_list_pluck( $results, 'id' );

		$this->assertContains( 'sora-2', $ids );
		$this->assertContains( 'sora-2-pro', $ids );
		$this->assertNotContains( 'seedance-2.0', $ids );
		$this->assertNotContains( 'veo-3.1', $ids );
	}

	/**
	 * An audio requirement excludes models with no audio control.
	 */
	public function test_recommend_filters_by_audio_requirement() {
		$results = WP_MCP_AI_Media_Model_Catalog::recommend(
			array(
				'kind'  => 'video',
				'audio' => 'required',
			)
		);

		$ids = wp_list_pluck( $results, 'id' );

		$this->assertContains( 'seedance-2.0', $ids );
		$this->assertNotContains( 'sora-2', $ids );
		$this->assertNotContains( 'veo-3.1', $ids );
	}

	/**
	 * A provider filter is honoured, and implausible intents return nothing
	 * rather than an arbitrary fallback.
	 */
	public function test_recommend_provider_filter_and_empty_result() {
		$results = WP_MCP_AI_Media_Model_Catalog::recommend(
			array(
				'kind'     => 'video',
				'provider' => 'openai',
			)
		);

		$this->assertNotEmpty( $results );
		foreach ( $results as $result ) {
			$this->assertSame( 'openai', $result['model']['provider'] );
		}

		$impossible = WP_MCP_AI_Media_Model_Catalog::recommend(
			array(
				'kind'     => 'video',
				'audio'    => 'required',
				'provider' => 'openai',
			)
		);
		$this->assertSame( array(), $impossible );
	}

	/**
	 * Recommendations are ordered best-first and carry reasons.
	 */
	public function test_recommend_ordering_and_reasons() {
		$results = WP_MCP_AI_Media_Model_Catalog::recommend( array( 'kind' => 'video' ) );

		$this->assertNotEmpty( $results );

		$scores = wp_list_pluck( $results, 'score' );
		$sorted = $scores;
		rsort( $sorted );
		$this->assertSame( $sorted, $scores, 'Recommendations are not sorted by score descending' );

		foreach ( $results as $result ) {
			$this->assertNotEmpty( $result['reasons'], "Candidate {$result['id']} has no reasons" );
		}
	}

	/**
	 * A missing registry file degrades to an empty catalog, not a fatal.
	 */
	public function test_missing_registry_degrades_gracefully() {
		add_filter(
			'wp_mcp_ai_media_model_catalog_path',
			static function () {
				return __DIR__ . '/fixtures/does-not-exist.json';
			}
		);

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$this->assertFalse( WP_MCP_AI_Media_Model_Catalog::is_available() );
		$this->assertNull( WP_MCP_AI_Media_Model_Catalog::get_model( 'soul-2' ) );
		$this->assertSame( array(), WP_MCP_AI_Media_Model_Catalog::recommend( array() ) );
	}
}
