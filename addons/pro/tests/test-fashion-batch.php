<?php
/**
 * Fashion Batch Jobs tests (Pro).
 *
 * Covers the job lifecycle: creation with D-3 cost gates, variant processing
 * through the base AI service seam, review decisions (approve/reject/reroll),
 * permission checks, and the WooCommerce/collection export helpers.
 *
 * @package WP_MCP_AI
 */
class Test_Fashion_Batch extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/media-studio/includes/ai/class-nvoos-media-studio-ai-service.php';
		require_once dirname( __DIR__ ) . '/includes/fashion/class-wp-mcp-ai-fashion-batch.php';

		if ( ! class_exists( 'WP_MCP_AI_Media_Collection_CPT' ) && file_exists( dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-collection-cpt.php' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-collection-cpt.php';
		}

		WP_MCP_AI_Fashion_Batch::register_post_type();
		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_execute_tool' );
		remove_all_filters( 'nvoos_media_studio_cost_estimate' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_execute_tool' );
		remove_all_filters( 'nvoos_media_studio_cost_estimate' );
		parent::tearDown();
	}

	/**
	 * Create a GD image attachment fixture.
	 *
	 * @return int Attachment ID.
	 */
	private function create_image_attachment() {
		$img = imagecreatetruecolor( 40, 40 );
		imagefilledrectangle( $img, 0, 0, 40, 40, imagecolorallocate( $img, 240, 240, 240 ) );
		$dir  = wp_upload_dir();
		$name = 'nvoos-fb-test-' . uniqid() . '.png';
		$path = $dir['path'] . '/' . $name;
		imagepng( $img, $path );
		imagedestroy( $img );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Batch Fixture',
				'post_status'    => 'inherit',
			),
			$path
		);
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

		return $attachment_id;
	}

	/**
	 * Force a cheap, known cost estimate.
	 *
	 * @return void
	 */
	private function force_cheap_estimate() {
		add_filter(
			'nvoos_media_studio_cost_estimate',
			static function () {
				return array(
					'usd'   => 0.01,
					'known' => true,
					'tool'  => 'edit_gemini_image',
				);
			}
		);
	}

	/**
	 * Run every pending variant (AS-agnostic — invokes the processor directly).
	 *
	 * @param int $job_id Job post ID.
	 * @return void
	 */
	private function run_all_variants( $job_id ) {
		$variants = get_post_meta( $job_id, WP_MCP_AI_Fashion_Batch::META_VARIANTS, true );
		foreach ( (array) $variants as $variant ) {
			if ( 'pending' === $variant['status'] ) {
				WP_MCP_AI_Fashion_Batch::process_variant( $job_id, $variant['key'], $variant['source_id'] );
			}
		}
	}

	/**
	 * Test unknown transforms are rejected.
	 */
	public function test_create_job_rejects_unknown_transform() {
		$source = $this->create_image_attachment();
		$result = WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'nope', array(), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_invalid_transform', $result->get_error_code() );
	}

	/**
	 * Test empty source lists are rejected.
	 */
	public function test_create_job_rejects_empty_sources() {
		$result = WP_MCP_AI_Fashion_Batch::create_job( array(), 'background', array(), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_job_no_sources', $result->get_error_code() );
	}

	/**
	 * Test the hard-cap tripwire blocks the job outright (D-3).
	 */
	public function test_create_job_hard_cap_blocks() {
		update_option(
			NV_oOS_Media_Studio_AI_Service::OPTION_KEY,
			array( 'hard_cap' => 0.02 )
		);
		add_filter(
			'nvoos_media_studio_cost_estimate',
			static function () {
				return array(
					'usd'   => 0.05,
					'known' => true,
					'tool'  => 'edit_gemini_image',
				);
			}
		);

		$source = $this->create_image_attachment();
		$result = WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'background', array( 'confirmed' => true ), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_hard_cap', $result->get_error_code() );
	}

	/**
	 * Test the review tripwire requires confirmation (D-3).
	 */
	public function test_create_job_requires_confirm() {
		add_filter(
			'nvoos_media_studio_cost_estimate',
			static function () {
				return array(
					'usd'   => 0.0,
					'known' => false,
					'tool'  => 'edit_gemini_image',
				);
			}
		);

		$source = $this->create_image_attachment();
		$result = WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'background', array(), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_review_required', $result->get_error_code() );
	}

	/**
	 * Test the full lifecycle: create → process → review approve.
	 */
	public function test_job_lifecycle_create_process_approve() {
		$this->force_cheap_estimate();

		$source   = $this->create_image_attachment();
		$source_2 = $this->create_image_attachment();
		$output_a = $this->create_image_attachment();
		$output_b = $this->create_image_attachment();

		$outputs = array( $output_a, $output_b );
		add_filter(
			'nvoos_media_studio_execute_tool',
			static function () use ( &$outputs ) {
				$output_id = array_shift( $outputs );
				return array(
					'attachment_id' => $output_id,
					'url'           => 'http://example.org/out.png',
					'provider'      => 'gemini',
					'model'         => 'test-model',
				);
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$job = WP_MCP_AI_Fashion_Batch::create_job(
			array( $source, $source_2 ),
			'background',
			array( 'confirmed' => true ),
			$user_id
		);
		$this->assertIsArray( $job );
		$this->assertSame( 2, $job['total'] );

		$job_id = $job['id'];

		// Drive the processor directly (AS-agnostic).
		$this->run_all_variants( $job_id );

		$full = WP_MCP_AI_Fashion_Batch::get_job( $job_id, $user_id );
		$this->assertIsArray( $full );
		$this->assertSame( 'review', $full['status'] );
		$this->assertSame( 2, $full['done'] );

		// Every variant carries provenance meta from the base service.
		foreach ( $full['variants'] as $variant ) {
			$this->assertSame( 'generated', $variant['status'] );
			$this->assertSame( '1', get_post_meta( $variant['attachment_id'], '_nvoos_ai_generated', true ) );
		}

		// Approve the first variant → status flips to completed once all settled.
		$first_variant = $full['variants'][0];
		WP_MCP_AI_Fashion_Batch::review_variant( $job_id, $first_variant['key'], 'approve', $user_id );
		WP_MCP_AI_Fashion_Batch::review_variant( $job_id, $full['variants'][1]['key'], 'reject', $user_id );

		$final = WP_MCP_AI_Fashion_Batch::get_job( $job_id, $user_id );
		$this->assertSame( 'completed', $final['status'] );
		$this->assertSame( 'approved', $final['variants'][0]['status'] );
		$this->assertSame( 'rejected', $final['variants'][1]['status'] );
	}

	/**
	 * Test reroll appends a pending variant and regenerates.
	 */
	public function test_review_reroll_appends_variant() {
		$this->force_cheap_estimate();

		$source  = $this->create_image_attachment();
		$outputs = array( $this->create_image_attachment(), $this->create_image_attachment() );
		add_filter(
			'nvoos_media_studio_execute_tool',
			static function () use ( &$outputs ) {
				$output_id = array_shift( $outputs );
				return array(
					'attachment_id' => $output_id,
					'url'           => 'http://example.org/out.png',
					'provider'      => 'gemini',
				);
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$job     = WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'background', array( 'confirmed' => true ), $user_id );
		$this->run_all_variants( $job['id'] );

		$full    = WP_MCP_AI_Fashion_Batch::get_job( $job['id'], $user_id );
		$variant = WP_MCP_AI_Fashion_Batch::review_variant( $job['id'], $full['variants'][0]['key'], 'reroll', $user_id );

		$this->assertSame( 'pending', $variant['status'] );
		$this->assertSame( 0, $variant['reroll_of'] );

		$this->run_all_variants( $job['id'] );
		$after = WP_MCP_AI_Fashion_Batch::get_job( $job['id'], $user_id );
		$this->assertSame( 2, $after['total'] );
		$this->assertSame( 'replaced', $after['variants'][0]['status'] );
		$this->assertSame( 'generated', $after['variants'][1]['status'] );
	}

	/**
	 * Test permission model: authors only manage their own jobs.
	 */
	public function test_permission_model() {
		$this->force_cheap_estimate();
		$source = $this->create_image_attachment();

		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$job = WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'background', array( 'confirmed' => true ), $owner_id );

		// The bootstrap grants manage_options globally; strip it so the
		// author-scoped checks exercise the real permission model.
		$strip_cap = static function ( $allcaps ) {
			unset( $allcaps['manage_options'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip_cap, 100 );

		$result = WP_MCP_AI_Fashion_Batch::get_job( $job['id'], $other_id );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_forbidden', $result->get_error_code() );

		$this->run_all_variants( $job['id'] );
		$variants = get_post_meta( $job['id'], WP_MCP_AI_Fashion_Batch::META_VARIANTS, true );
		$review   = WP_MCP_AI_Fashion_Batch::review_variant( $job['id'], $variants[0]['key'], 'approve', $other_id );
		$this->assertWPError( $review );
		$this->assertSame( 'nvoos_ms_forbidden', $review->get_error_code() );

		remove_filter( 'user_has_cap', $strip_cap, 100 );

		// The owner can.
		$this->assertIsArray( WP_MCP_AI_Fashion_Batch::get_job( $job['id'], $owner_id ) );
	}

	/**
	 * Test list jobs filters by owner for non-admin users.
	 */
	public function test_list_jobs_is_owner_scoped() {
		$this->force_cheap_estimate();
		$source = $this->create_image_attachment();

		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

		WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'background', array( 'confirmed' => true ), $author_a );

		$strip_cap = static function ( $allcaps ) {
			unset( $allcaps['manage_options'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip_cap, 100 );

		$jobs_a = WP_MCP_AI_Fashion_Batch::get_jobs( $author_a );
		$jobs_b = WP_MCP_AI_Fashion_Batch::get_jobs( $author_b );

		remove_filter( 'user_has_cap', $strip_cap, 100 );

		$this->assertNotEmpty( $jobs_a );
		foreach ( $jobs_a as $job ) {
			$this->assertNotSame( 0, $job['id'] );
		}
		// Author B has no jobs — they must not see author A's jobs.
		$this->assertEmpty( $jobs_b );
	}

	/**
	 * Test review rejects invalid decisions.
	 */
	public function test_review_rejects_invalid_decision() {
		$this->force_cheap_estimate();
		$source  = $this->create_image_attachment();
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$job    = WP_MCP_AI_Fashion_Batch::create_job( array( $source ), 'background', array( 'confirmed' => true ), $user_id );
		$result = WP_MCP_AI_Fashion_Batch::review_variant( $job['id'], 0, 'explode', $user_id );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_invalid_decision', $result->get_error_code() );
	}

	/**
	 * Test WooCommerce gallery attachment (WC-loaded or graceful error).
	 */
	public function test_attach_to_product_gallery() {
		$attachment = $this->create_image_attachment();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$result = WP_MCP_AI_Fashion_Batch::attach_to_product_gallery( $attachment, 123 );
			$this->assertWPError( $result );
			$this->assertSame( 'nvoos_ms_wc_unavailable', $result->get_error_code() );
			return;
		}

		$product_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Fixture Product',
			)
		);
		$result     = WP_MCP_AI_Fashion_Batch::attach_to_product_gallery( $attachment, $product_id );
		$this->assertTrue( $result );

		$product = wc_get_product( $product_id );
		$this->assertContains( $attachment, $product->get_gallery_image_ids() );
	}

	/**
	 * Test media collection attachment helper.
	 */
	public function test_add_to_collection() {
		if ( ! class_exists( 'WP_MCP_AI_Media_Collection_CPT' ) ) {
			$this->markTestSkipped( 'Media Collection CPT class not loaded.' );
		}

		$attachment    = $this->create_image_attachment();
		$collection_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Media_Collection_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Fixture Collection',
			)
		);

		$this->assertTrue( WP_MCP_AI_Fashion_Batch::add_to_collection( array( $attachment ), $collection_id ) );
		$items = get_post_meta( $collection_id, '_mcp_ai_collection_items', true );
		$this->assertContains( $attachment, $items );
	}
}
