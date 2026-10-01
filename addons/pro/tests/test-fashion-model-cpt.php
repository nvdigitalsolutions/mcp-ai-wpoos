<?php
/**
 * Fashion Model Identity CPT tests (Pro).
 *
 * Covers CPT + meta registration, versioned seeding idempotency, the SPA
 * payload shape, consent sanitization, and the consent filter seam.
 *
 * @package WP_MCP_AI
 */
class Test_Fashion_Model_CPT extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/includes/fashion/class-wp-mcp-ai-fashion-model-cpt.php';

		delete_option( WP_MCP_AI_Fashion_Model_CPT::SEEDED_KEY );
		WP_MCP_AI_Fashion_Model_CPT::register_post_type();
		WP_MCP_AI_Fashion_Model_CPT::register_meta();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Fashion_Model_CPT::SEEDED_KEY );
		parent::tearDown();
	}

	/**
	 * Test the post type and meta are registered.
	 */
	public function test_post_type_and_meta_registered() {
		$this->assertTrue( post_type_exists( WP_MCP_AI_Fashion_Model_CPT::POST_TYPE ) );

		$meta = get_registered_meta_keys( 'post', WP_MCP_AI_Fashion_Model_CPT::POST_TYPE );
		foreach ( array( WP_MCP_AI_Fashion_Model_CPT::META_GENDER, WP_MCP_AI_Fashion_Model_CPT::META_CONSENT, WP_MCP_AI_Fashion_Model_CPT::META_PROMPT_EMBED ) as $key ) {
			$this->assertArrayHasKey( $key, $meta );
		}
	}

	/**
	 * Test consent sanitization rejects unknown values.
	 */
	public function test_sanitize_consent_enum() {
		$this->assertSame( 'granted', WP_MCP_AI_Fashion_Model_CPT::sanitize_consent( 'granted' ) );
		$this->assertSame( 'revoked', WP_MCP_AI_Fashion_Model_CPT::sanitize_consent( 'revoked' ) );
		$this->assertSame( 'none', WP_MCP_AI_Fashion_Model_CPT::sanitize_consent( 'anything-else' ) );
	}

	/**
	 * Test seeding is versioned and idempotent.
	 */
	public function test_seed_is_idempotent() {
		WP_MCP_AI_Fashion_Model_CPT::seed();
		$first = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Fashion_Model_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$this->assertNotEmpty( $first );
		$this->assertSame( WP_MCP_AI_Fashion_Model_CPT::SEED_VERSION, get_option( WP_MCP_AI_Fashion_Model_CPT::SEEDED_KEY ) );

		WP_MCP_AI_Fashion_Model_CPT::seed();
		$second = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Fashion_Model_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$this->assertSame( count( $first ), count( $second ) );
	}

	/**
	 * Test seeded identities ship with consent "none" (D-1: grant required).
	 */
	public function test_seeded_identities_require_consent() {
		WP_MCP_AI_Fashion_Model_CPT::seed();
		$models = WP_MCP_AI_Fashion_Model_CPT::get_models();
		$this->assertNotEmpty( $models );
		foreach ( $models as $model ) {
			$this->assertSame( 'none', $model['consent_status'] );
		}
	}

	/**
	 * Test the SPA payload shape.
	 */
	public function test_get_models_payload_shape() {
		$model_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Fashion_Model_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Model',
				'meta_input'  => array(
					WP_MCP_AI_Fashion_Model_CPT::META_GENDER      => 'female',
					WP_MCP_AI_Fashion_Model_CPT::META_CONSENT     => 'granted',
					WP_MCP_AI_Fashion_Model_CPT::META_PROMPT_EMBED => 'Descriptor.',
				),
			)
		);

		$models = WP_MCP_AI_Fashion_Model_CPT::get_models();
		$this->assertNotEmpty( $models );
		$found = false;
		foreach ( $models as $model ) {
			if ( $model_id === $model['id'] ) {
				$found = true;
				$this->assertSame( 'Test Model', $model['name'] );
				$this->assertSame( 'female', $model['gender'] );
				$this->assertSame( 'granted', $model['consent_status'] );
				break;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * Test the consent filter grants / blocks based on meta.
	 */
	public function test_consent_filter_gates_identity() {
		$model_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Fashion_Model_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Consent Model',
				'meta_input'  => array(
					WP_MCP_AI_Fashion_Model_CPT::META_CONSENT => 'none',
				),
			)
		);

		$this->assertFalse( WP_MCP_AI_Fashion_Model_CPT::consent_filter( null, $model_id ) );

		update_post_meta( $model_id, WP_MCP_AI_Fashion_Model_CPT::META_CONSENT, 'granted' );
		$this->assertTrue( WP_MCP_AI_Fashion_Model_CPT::consent_filter( null, $model_id ) );

		// Non-identity posts fall through to the caller's value.
		$post_id = self::factory()->post->create();
		$this->assertNull( WP_MCP_AI_Fashion_Model_CPT::consent_filter( null, $post_id ) );
	}
}
