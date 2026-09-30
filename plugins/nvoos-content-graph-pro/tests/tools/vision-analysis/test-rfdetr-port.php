<?php
/**
 * Characterization tests for the Wave F3 vision-analysis RF-DETR cluster —
 * the Roboflow inference service, the rfdetr_detect tool, the count
 * normalizer, the rfdetr_catalog_search e-commerce tool, the dHash D8-compat
 * helper copy, and the slim standalone init.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/services/`, `src/tools/vision-analysis/`, `src/tools/ecommerce/`,
 *   and `src/helpers/` are asserted in full, including the standalone-only
 *   init filter.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * RF-DETR cluster characterization tests.
 */
class Test_Rfdetr_Port extends WP_UnitTestCase {

	/**
	 * Load the ported files (idempotent requires — standalone matrix only;
	 * the monolith matrix serves the classes from the base Pro addon).
	 */
	public function setUp(): void {
		parent::setUp();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-roboflow-inference-service.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vision-analysis/class-wp-mcp-ai-vision-count-normalizer.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php';
		// No-autoload guard: the monorepo root classmap may serve the base
		// dHash copy in the test matrix even when the base plugin is skipped
		// (real standalone installs have no root vendor).
		if ( ! class_exists( 'WP_MCP_AI_Image_DHash', false ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/helpers/class-wp-mcp-ai-image-dhash.php';
		}
	}

	/**
	 * The service constants must be byte-identical (derived from the ported
	 * source — no hand-guessed values).
	 */
	public function test_service_surface(): void {
		$service = new WP_MCP_AI_Roboflow_Inference_Service();

		$this->assertSame( 'https://serverless.roboflow.com', WP_MCP_AI_Roboflow_Inference_Service::DEFAULT_API_URL );
		$this->assertSame( 'rfdetr-small', WP_MCP_AI_Roboflow_Inference_Service::DEFAULT_MODEL );
		$this->assertSame( 5242880, WP_MCP_AI_Roboflow_Inference_Service::MAX_PAYLOAD_BYTES );
		$this->assertSame( 11, count( WP_MCP_AI_Roboflow_Inference_Service::APACHE_MODEL_ALIASES ) );
		$this->assertSame( 2, count( WP_MCP_AI_Roboflow_Inference_Service::PML_MODEL_ALIASES ) );
		$this->assertSame( 'rfdetr-small', $service->get_default_model() );
		$this->assertSame( '', $service->get_catalog_model() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Roboflow_Inference_Service'     => 'services/class-wp-mcp-ai-roboflow-inference-service.php',
			'WP_MCP_AI_Tool_Rfdetr_Detect'             => 'tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php',
			'WP_MCP_AI_Pro_Tool_Rfdetr_Catalog_Search' => 'tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php',
			'WP_MCP_AI_Image_DHash'                    => 'helpers/class-wp-mcp-ai-image-dhash.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				// Base-owned dHash: the monolith classmap serves the base copy.
				if ( 'WP_MCP_AI_Image_DHash' === $class ) {
					$this->assertStringContainsString( 'includes/' . $file, $path, $class );
					continue;
				}
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} elseif ( 'WP_MCP_AI_Image_DHash' === $class ) {
				// The root classmap may serve the base D8 copy in the test
				// matrix — real standalone installs resolve the addon copy.
				$this->assertTrue(
					false !== strpos( $path, 'nvoos-content-graph-pro/src/' . $file )
					|| false !== strpos( $path, 'includes/' . $file ),
					$class
				);
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The rfdetr_detect tool surface must be byte-identical.
	 */
	public function test_rfdetr_detect_surface(): void {
		$tool = new WP_MCP_AI_Tool_Rfdetr_Detect();

		$this->assertSame( 'rfdetr_detect', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
		$this->assertSame( 'rfdetr-seg-small', WP_MCP_AI_Tool_Rfdetr_Detect::DEFAULT_SEGMENTATION_MODEL );
		$this->assertSame( 'rfdetr-keypoint-preview', WP_MCP_AI_Tool_Rfdetr_Detect::DEFAULT_KEYPOINT_MODEL );
		$this->assertSame( 1600, WP_MCP_AI_Tool_Rfdetr_Detect::MAX_DOWNSCALE_DIMENSION );

		$schema = $tool->get_parameters_schema();
		$this->assertSame(
			array( 'detect', 'segment', 'keypoints' ),
			$schema['properties']['task']['enum']
		);
	}

	/**
	 * The catalog-search tool surface must be byte-identical.
	 */
	public function test_catalog_search_surface(): void {
		$tool = new WP_MCP_AI_Pro_Tool_Rfdetr_Catalog_Search();

		$this->assertSame( 'rfdetr_catalog_search', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
		$this->assertSame( 300, WP_MCP_AI_Pro_Tool_Rfdetr_Catalog_Search::CACHE_TTL_SECONDS );
	}

	/**
	 * The service's model-alias gate must be byte-identical: Apache aliases
	 * pass, PML aliases need the consent toggle, junk rejects.
	 */
	public function test_alias_gate(): void {
		$service = new WP_MCP_AI_Roboflow_Inference_Service();

		$this->assertSame( 'rfdetr-medium', $service->validate_model_alias( 'rfdetr-medium' ) );
		$this->assertSame( 'myworkspace/myproject/3', $service->validate_model_alias( 'myworkspace/myproject/3' ) );

		$pml = $service->validate_model_alias( 'rfdetr-xlarge' );
		$this->assertInstanceOf( WP_Error::class, $pml );
		$this->assertSame( 'wp_mcp_ai_roboflow_pml_not_enabled', $pml->get_error_code() );

		$junk = $service->validate_model_alias( 'yolo11-x' );
		$this->assertInstanceOf( WP_Error::class, $junk );
		$this->assertSame( 'wp_mcp_ai_roboflow_unknown_model', $junk->get_error_code() );
	}

	/**
	 * The count normalizer must be byte-identical: grouping math on the
	 * canonical detection shape.
	 */
	public function test_count_normalizer(): void {
		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::group_detections(
			array(
				array(
					'label'      => 'person',
					'confidence' => 0.9,
					'box'        => array(
						'x'      => 0.1,
						'y'      => 0.1,
						'width'  => 0.2,
						'height' => 0.3,
					),
				),
				array(
					'label'      => 'person',
					'confidence' => 0.7,
					'box'        => array(
						'x'      => 0.3,
						'y'      => 0.1,
						'width'  => 0.2,
						'height' => 0.3,
					),
				),
				array(
					'label'      => 'cup',
					'confidence' => 0.8,
					'box'        => array(
						'x'      => 0.5,
						'y'      => 0.5,
						'width'  => 0.1,
						'height' => 0.1,
					),
				),
			),
			true
		);

		$this->assertSame( 'person', $breakdown[0]['label'] );
		$this->assertSame( 2, $breakdown[0]['count'] );
		$this->assertSame( 3, WP_MCP_AI_Vision_Count_Normalizer::total_from_breakdown( $breakdown ) );
	}

	/**
	 * The dHash helper copy must be byte-identical: deterministic hash on a
	 * generated fixture.
	 */
	public function test_dhash_copy(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD image support is required.' );
		}

		$path = wp_tempnam( 'rfdetr-port-dhash-' ) . '.png';
		$img  = imagecreatetruecolor( 60, 40 );
		$bg   = imagecolorallocate( $img, 180, 120, 60 );
		imagefilledrectangle( $img, 0, 0, 60, 40, $bg );
		imagepng( $img, $path );
		imagedestroy( $img );

		$hash = WP_MCP_AI_Image_DHash::compute( $path );
		wp_delete_file( $path );

		$this->assertIsString( $hash );
		$this->assertSame( 16, strlen( $hash ) );
		$this->assertSame( '_wp_mcp_ai_image_dhash', WP_MCP_AI_Image_DHash::HASH_META_KEY );
	}

	/**
	 * Standalone only: the init's tool filter must carry the ported
	 * RF-DETR tool with the addon's file path.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool maps inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vision-analysis/init.php';
		// wp-phpunit restores $wp_filter between tests — re-add the init's
		// filter explicitly (same pattern as the ai-tool-builder suite).
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_vision_analysis_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Rfdetr_Detect', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php',
			$tools['WP_MCP_AI_Tool_Rfdetr_Detect']
		);
	}
}
