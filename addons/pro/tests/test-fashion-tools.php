<?php
/**
 * Fashion Studio Pro tools tests (Phase 5).
 *
 * Covers the eight fashion_* tools: registration shape (slugs, capabilities,
 * schema strictness), transform execution through the base service seam,
 * batch permission escalation, and identity consent escalation.
 *
 * @package WP_MCP_AI
 */
class Test_Fashion_Tools extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/media-studio/includes/ai/class-nvoos-media-studio-ai-service.php';
		require_once dirname( __DIR__ ) . '/includes/fashion/class-wp-mcp-ai-fashion-model-cpt.php';
		require_once dirname( __DIR__ ) . '/includes/fashion/class-wp-mcp-ai-fashion-batch.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-fashion-transform-tool.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-onmodel-generate.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-model-swap.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-background-generate.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-recolor.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-packshot.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-virtual-tryon.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-batch-job.php';
		require_once dirname( __DIR__ ) . '/includes/tools/fashion/class-wp-mcp-ai-tool-fashion-identity-manage.php';

		if ( ! post_type_exists( WP_MCP_AI_Fashion_Batch::POST_TYPE ) ) {
			WP_MCP_AI_Fashion_Batch::register_post_type();
		}
		if ( ! post_type_exists( WP_MCP_AI_Fashion_Model_CPT::POST_TYPE ) ) {
			WP_MCP_AI_Fashion_Model_CPT::register_post_type();
		}

		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_execute_tool' );
		remove_all_filters( 'nvoos_media_studio_cost_estimate' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
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
		$name = 'nvoos-ft-test-' . uniqid() . '.png';
		$path = $dir['path'] . '/' . $name;
		imagepng( $img, $path );
		imagedestroy( $img );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Tool Fixture',
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
	 * Force a cheap, known cost estimate so the review gate passes.
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
	 * Test all eight tools expose the expected slugs, capabilities, and strict schemas.
	 */
	public function test_tool_registration_shape() {
		$map = array(
			'WP_MCP_AI_Tool_Fashion_Onmodel_Generate'    => array( 'fashion_onmodel_generate', 'upload_files' ),
			'WP_MCP_AI_Tool_Fashion_Model_Swap'          => array( 'fashion_model_swap', 'upload_files' ),
			'WP_MCP_AI_Tool_Fashion_Background_Generate' => array( 'fashion_background_generate', 'upload_files' ),
			'WP_MCP_AI_Tool_Fashion_Recolor'             => array( 'fashion_recolor', 'upload_files' ),
			'WP_MCP_AI_Tool_Fashion_Packshot'            => array( 'fashion_packshot', 'upload_files' ),
			'WP_MCP_AI_Tool_Fashion_Virtual_Tryon'       => array( 'fashion_virtual_tryon', 'upload_files' ),
			'WP_MCP_AI_Tool_Fashion_Batch_Job'           => array( 'fashion_batch_job', 'edit_posts' ),
			'WP_MCP_AI_Tool_Fashion_Identity_Manage'     => array( 'fashion_identity_manage', 'edit_posts' ),
		);

		foreach ( $map as $class => $expected ) {
			$tool = new $class();
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool, $class );
			$this->assertSame( $expected[0], $tool->get_slug() );
			$this->assertSame( $expected[1], $tool->get_required_capability() );
			$this->assertNotEmpty( $tool->get_description() );

			$schema = $tool->get_parameters_schema();
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
			$this->assertArrayHasKey( 'additionalProperties', $schema );
			$this->assertFalse( $schema['additionalProperties'] );

			$guidance = $tool->get_usage_guidance();
			$this->assertNotEmpty( $guidance['when_to_use'] );
			$this->assertNotEmpty( $guidance['when_not_to_use'] );

			$this->assertTrue( $class::is_available() );
		}
	}

	/**
	 * Test a transform tool runs through the base service seam and passes the envelope.
	 */
	public function test_transform_tool_executes_through_seam() {
		$this->force_cheap_estimate();

		$source  = $this->create_image_attachment();
		$output  = $this->create_image_attachment();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		add_filter(
			'nvoos_media_studio_execute_tool',
			static function () use ( $output ) {
				return array(
					'attachment_id' => $output,
					'url'           => 'http://example.org/out.png',
					'provider'      => 'gemini',
					'model'         => 'test-model',
				);
			}
		);

		$tool   = new WP_MCP_AI_Tool_Fashion_Packshot();
		$result = $tool->execute(
			array(
				'attachment_id' => $source,
				'description'   => 'Silk blouse',
				'confirmed'     => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $output, $result['attachment_id'] );
		$this->assertSame( 'packshot', $result['transform'] );
	}

	/**
	 * Test transform tools sanitize the color argument at entry (recolor).
	 */
	public function test_recolor_tool_sanitizes_color() {
		$this->force_cheap_estimate();

		$source  = $this->create_image_attachment();
		$output  = $this->create_image_attachment();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$prompts = array();
		add_filter(
			'nvoos_media_studio_execute_tool',
			static function ( $result, $transform, $tool, $tool_args ) use ( $output, &$prompts ) {
				$prompts[] = $tool_args['prompt'];
				return array(
					'attachment_id' => $output,
					'url'           => 'http://example.org/out.png',
					'provider'      => 'gemini',
					'model'         => 'test-model',
				);
			},
			10,
			4
		);

		$tool = new WP_MCP_AI_Tool_Fashion_Recolor();

		// Valid hex passes through to the prompt.
		$result = $tool->execute(
			array(
				'attachment_id' => $source,
				'color'         => '#c0392b',
				'confirmed'     => true,
			),
			array( 'user_id' => $user_id )
		);
		$this->assertIsArray( $result );
		$this->assertStringContainsString( '#c0392b', $prompts[0] );

		// Invalid hex is neutralized at entry — no markup reaches the prompt.
		$result = $tool->execute(
			array(
				'attachment_id' => $source,
				'color'         => '<script>alert(1)</script>',
				'confirmed'     => true,
			),
			array( 'user_id' => $user_id )
		);
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( 'script', strtolower( $prompts[1] ) );
	}

	/**
	 * Test batch create escalates to upload_files.
	 */
	public function test_batch_create_requires_upload_files() {
		// Contributors can edit posts but cannot upload files.
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$tool   = new WP_MCP_AI_Tool_Fashion_Batch_Job();
		$result = $tool->execute(
			array(
				'action'         => 'create',
				'attachment_ids' => array( 1 ),
				'transform'      => 'background',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_forbidden', $result->get_error_code() );
	}

	/**
	 * Test identity list returns the library and consent changes are admin-only.
	 */
	public function test_identity_consent_escalation() {
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Create via the tool (admin).
		$tool   = new WP_MCP_AI_Tool_Fashion_Identity_Manage();
		$result = $tool->execute(
			array(
				'action' => 'create',
				'name'   => 'Test Identity',
				'gender' => 'female',
			),
			array( 'user_id' => $admin_id )
		);
		$this->assertIsArray( $result );
		$identity_id = $result['id'];
		$this->assertGreaterThan( 0, $identity_id );

		// List exposes the new identity.
		$list = $tool->execute( array( 'action' => 'list' ), array( 'user_id' => $editor_id ) );
		$this->assertIsArray( $list );
		$ids = wp_list_pluck( $list['models'], 'id' );
		$this->assertContains( $identity_id, $ids );

		// Editor cannot change consent.
		$blocked = $tool->execute(
			array(
				'action'         => 'set_consent',
				'identity_id'    => $identity_id,
				'consent_status' => 'granted',
			),
			array( 'user_id' => $editor_id )
		);
		$this->assertWPError( $blocked );
		$this->assertSame( 'nvoos_ms_forbidden', $blocked->get_error_code() );

		// Admin can.
		$granted = $tool->execute(
			array(
				'action'         => 'set_consent',
				'identity_id'    => $identity_id,
				'consent_status' => 'granted',
			),
			array( 'user_id' => $admin_id )
		);
		$this->assertIsArray( $granted );
		$this->assertSame( 'granted', $granted['consent_status'] );
	}

	/**
	 * Test the workflow preset registry exposes the fashion category + preset.
	 */
	public function test_workflow_presets_expose_fashion() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pro-workflow-presets.php';
		}

		$categories = WP_MCP_AI_Pro_Workflow_Presets::get_categories();
		$this->assertArrayHasKey( 'fashion', $categories );

		$preset = WP_MCP_AI_Pro_Workflow_Presets::get_preset( 'fashion_product_creative' );
		$this->assertNotNull( $preset );
		$this->assertSame( 'fashion', $preset['category'] );
		$this->assertNotEmpty( $preset['nodes'] );
		$this->assertNotEmpty( $preset['edges'] );

		// The packshot node chains from the on-model node's output.
		$slugs = array();
		foreach ( $preset['nodes'] as $node ) {
			if ( isset( $node['data']['toolSlug'] ) ) {
				$slugs[] = $node['data']['toolSlug'];
			}
		}
		$this->assertContains( 'fashion_onmodel_generate', $slugs );
		$this->assertContains( 'fashion_packshot', $slugs );
	}
}
