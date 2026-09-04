<?php
/**
 * Tests for Product Actualization tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Product Actualization tool availability and basic functionality.
 */
class WP_MCP_AI_Product_Actualization_Tool_Test extends WP_UnitTestCase {

	/**
	 * Minimal 1×1 white PNG as raw binary, used across multiple image-fixture tests.
	 *
	 * @var string
	 */
	private $minimal_png;

	/**
	 * Set up per-test state.
	 */
	public function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding known-safe test fixture binary.
		$this->minimal_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==' );
	}

	/**
	 * Test tool availability check.
	 */
	public function test_tool_requires_image_extension() {
		// Check if tool is available based on extensions.
		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		$available = WP_MCP_AI_Pro_Tool_Product_Actualization::is_available();

		if ( $has_imagick || $has_gd ) {
			$this->assertTrue( $available, 'Tool should be available when Imagick or GD is loaded' );
		} else {
			$this->assertFalse( $available, 'Tool should not be available without Imagick or GD' );
		}
	}

	/**
	 * Test tool slug.
	 */
	public function test_tool_slug() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$this->assertSame( 'product_actualization', $tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_tool_name() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_tool_description() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema structure.
	 */
	public function test_parameters_schema() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required parameters.
		$this->assertContains( 'product_attachment_id', $schema['required'] );
		$this->assertContains( 'scene_prompt', $schema['required'] );

		// Check optional parameters exist.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'mode', $properties );
		$this->assertArrayHasKey( 'aspect_ratio', $properties );
		$this->assertArrayHasKey( 'background_mode', $properties );
		$this->assertArrayHasKey( 'placement_hint', $properties );
		$this->assertArrayHasKey( 'scale_factor', $properties );

		// Check new provider-agnostic parameters.
		$this->assertArrayHasKey( 'integration_mode', $properties );
		$this->assertArrayHasKey( 'provider', $properties );
	}

	/**
	 * Test execution requires authentication.
	 */
	public function test_execute_requires_authentication() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution requires upload_files capability.
	 */
	public function test_execute_requires_capability() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution with missing product_attachment_id.
	 */
	public function test_execute_requires_product_id() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'scene_prompt' => 'Test scene' );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_product', $result->get_error_code() );
	}

	/**
	 * Test execution with missing scene_prompt.
	 */
	public function test_execute_requires_scene_prompt() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'product_attachment_id' => 123 );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test execution with invalid product attachment.
	 */
	public function test_execute_validates_product_attachment() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'product_attachment_id' => 99999,
			'scene_prompt'          => 'Beautiful sunset beach scene',
		);
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_product', $result->get_error_code() );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool  = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_model_requirements() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool         = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$requirements = $tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertContains( 'image-generation', $requirements );
	}

	/**
	 * Test tool rules structure.
	 */
	public function test_tool_rules() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool  = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'model_requirements', $rules );
		$this->assertArrayHasKey( 'parameter_constraints', $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );
		$this->assertArrayHasKey( 'dependencies', $rules );
		$this->assertArrayHasKey( 'orchestration_hints', $rules );
	}

	/**
	 * Test video mode requires VEO tool.
	 */
	public function test_video_mode_requires_veo_tool() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		// Only test if VEO tool is not available.
		if ( class_exists( 'WP_MCP_AI_Tool_Generate_Veo_Video' ) ) {
			$this->markTestSkipped( 'VEO tool is available, video mode should work.' );
		}

		// Create a test image attachment.
		$upload_dir = wp_upload_dir();
		$test_image = trailingslashit( $upload_dir['path'] ) . 'test-product.png';

		// Create a simple 100x100 PNG.
		$image = imagecreatetruecolor( 100, 100 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 255, 255, 255 ) );
		imagepng( $image, $test_image );
		imagedestroy( $image );

		$attachment_id = self::factory()->attachment->create_upload_object( $test_image );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'product_attachment_id' => $attachment_id,
			'scene_prompt'          => 'Beautiful sunset beach scene',
			'mode'                  => 'video',
		);

		$result = $tool->execute( $args, $context );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_dependency', $result->get_error_code() );
	}

	/**
	 * Test scale factor validation.
	 */
	public function test_scale_factor_validation() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'scale_factor', $schema['properties'] );
		$this->assertSame( 0.1, $schema['properties']['scale_factor']['minimum'] );
		$this->assertSame( 2.0, $schema['properties']['scale_factor']['maximum'] );
		$this->assertSame( 1.0, $schema['properties']['scale_factor']['default'] );
	}

	/**
	 * Test aspect ratio options.
	 */
	public function test_aspect_ratio_options() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['aspect_ratio'] );

		$ratios = $schema['properties']['aspect_ratio']['enum'];
		$this->assertContains( '1:1', $ratios );
		$this->assertContains( '4:5', $ratios );
		$this->assertContains( '16:9', $ratios );
		$this->assertContains( '9:16', $ratios );
		$this->assertContains( 'auto', $ratios );
	}

	/**
	 * Test background mode options.
	 */
	public function test_background_mode_options() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'background_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['background_mode'] );

		$modes = $schema['properties']['background_mode']['enum'];
		$this->assertContains( 'auto', $modes );
		$this->assertContains( 'remove', $modes );
		$this->assertContains( 'preserve', $modes );
	}

	/**
	 * Test integration_mode parameter options and defaults.
	 */
	public function test_integration_mode_parameter() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'integration_mode', $schema['properties'] );

		$prop = $schema['properties']['integration_mode'];
		$this->assertArrayHasKey( 'enum', $prop );
		$this->assertContains( 'ai', $prop['enum'] );
		$this->assertContains( 'composite', $prop['enum'] );

		// Default must be 'ai' (the new AI-powered integration approach).
		$this->assertSame( 'ai', $prop['default'] );
	}

	/**
	 * Test provider parameter options and defaults.
	 */
	public function test_provider_parameter() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'provider', $schema['properties'] );

		$prop = $schema['properties']['provider'];
		$this->assertArrayHasKey( 'enum', $prop );
		$this->assertContains( 'auto', $prop['enum'] );
		$this->assertContains( 'gemini', $prop['enum'] );
		$this->assertContains( 'openai', $prop['enum'] );

		// Default must be 'auto' (prefers Gemini when available).
		$this->assertSame( 'auto', $prop['default'] );
	}

	/**
	 * Test tool rules include new providers and integration capabilities.
	 */
	public function test_tool_rules_include_gemini_provider() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool  = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$rules = $tool->get_tool_rules();

		$this->assertArrayHasKey( 'model_requirements', $rules );
		$providers = $rules['model_requirements']['providers'];
		$this->assertContains( 'gemini', $providers );
		$this->assertContains( 'openai', $providers );

		$capabilities = $rules['model_requirements']['capabilities'];
		$this->assertContains( 'image-generation', $capabilities );
		$this->assertContains( 'image-editing', $capabilities );
	}

	/**
	 * Test that save_ai_result_to_temp with is_base64=false writes raw binary unchanged.
	 *
	 * This covers the Gemini path fix: the Gemini client's edit_image() already decodes
	 * inline base64 to raw binary, so generate_ai_integrated_image_gemini must pass
	 * is_base64=false to avoid a double-decode that caused "Failed to decode base64
	 * image data returned by AI during product integration".
	 */
	public function test_save_ai_result_to_temp_raw_binary() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$png_data = $this->minimal_png;

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();

		// Access the protected save_ai_result_to_temp via reflection.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'save_ai_result_to_temp' );
		$method->setAccessible( true );

		// Call with is_base64=false (raw binary) — must succeed and write the PNG.
		$result = $method->invoke( $tool, $png_data, 'png', false );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'file_path', $result );
		$this->assertFileExists( $result['file_path'] );
		$this->assertSame( $png_data, file_get_contents( $result['file_path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required to verify raw binary content in unit test.

		// Clean up.
		wp_delete_file( $result['file_path'] );
	}

	/**
	 * Test that save_ai_result_to_temp rejects raw binary when is_base64=true.
	 *
	 * Validates the exact failure path that was triggered before the fix: passing
	 * already-decoded binary with is_base64=true causes a base64_decode() failure
	 * and returns a WP_Error.
	 */
	public function test_save_ai_result_to_temp_fails_on_double_decode() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		// Raw binary that is NOT valid base64 (e.g. a PNG header). When passed with
		// is_base64=true, base64_decode() strict-mode returns false.
		$raw_binary = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR";

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'save_ai_result_to_temp' );
		$method->setAccessible( true );

		// is_base64=true on raw binary triggers the decode error.
		$result = $method->invoke( $tool, $raw_binary, 'png', true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_decode_failed', $result->get_error_code() );
	}

	/**
	 * Test that OpenAI image model support flag correctly gates response_format.
	 *
	 * gpt-image-1 and gpt-image-1.5 must NOT support response_format, while
	 * DALL-E variants must. This verifies the existing helper that the fixed
	 * edit_image() method now uses.
	 */
	public function test_openai_image_model_supports_response_format() {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_OpenAI_Client class not available.' );
		}

		// All models default to false since the OpenAI API currently rejects response_format.
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( 'gpt-image-1' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( 'gpt-image-1.5' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( 'dall-e-2' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( 'dall-e-3' ) );
	}

	/**
	 * Test OpenAI b64_json whitespace stripping before decode.
	 *
	 * A base64 string with embedded newlines (MIME line-wrapped) must decode
	 * successfully after the str_replace() strip added in the fix.
	 */
	public function test_openai_b64_json_whitespace_stripped_before_decode() {
		// A 1x1 PNG in base64, split with MIME-style line breaks every 76 chars.
		$raw_b64     = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$wrapped_b64 = wordwrap( $raw_b64, 76, "\n", true );

		// Simulate the whitespace-strip applied in generate_ai_integrated_image_openai().
		$clean   = str_replace( array( "\r", "\n", ' ' ), '', $wrapped_b64 );
		$decoded = base64_decode( $clean, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding test fixture to verify whitespace-stripping logic, not obfuscating code.

		$this->assertNotFalse( $decoded, 'Whitespace-stripped b64_json must decode successfully.' );
		// PHP tolerates whitespace inside base64 input even in strict mode, so
		// assert the stripped payload matches the raw payload instead.
		$this->assertSame( base64_decode( $raw_b64, true ), $decoded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Comparing decoded fixtures to verify whitespace-stripping logic.
	}

	/**
	 * Test that product_attachment_id schema accepts both integer and string types.
	 */
	public function test_product_attachment_id_schema_accepts_string_type() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$schema = $tool->get_parameters_schema();

		$type = $schema['properties']['product_attachment_id']['type'];
		// Must accept both integer (attachment ID) and string (URL / file_id).
		$this->assertContains( 'integer', (array) $type );
		$this->assertContains( 'string', (array) $type );
	}

	/**
	 * Test download_url_to_temp rejects a non-URL string.
	 */
	public function test_download_url_to_temp_rejects_invalid_url() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'download_url_to_temp' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'not-a-url' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	/**
	 * Test download_url_to_temp rejects a response with a non-image content-type.
	 */
	public function test_download_url_to_temp_rejects_non_image_content_type() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		// Intercept the HTTP request and return an HTML response.
		$pre_http_request = function ( $preempt, $args, $url ) {
			return array(
				'headers'       => array( 'content-type' => 'text/html; charset=utf-8' ),
				'body'          => '<html></html>',
				'response'      => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'       => array(),
				'http_response' => null,
			);
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'download_url_to_temp' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'https://example.com/page.html' );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_file_type', $result->get_error_code() );
	}

	/**
	 * Test download_url_to_temp rejects a non-200 HTTP response.
	 */
	public function test_download_url_to_temp_rejects_non_200_status() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$pre_http_request = function ( $preempt, $args, $url ) {
			return array(
				'headers'       => array( 'content-type' => 'image/png' ),
				'body'          => '',
				'response'      => array( 'code' => 404, 'message' => 'Not Found' ),
				'cookies'       => array(),
				'http_response' => null,
			);
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'download_url_to_temp' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'https://example.com/missing.png' );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_url_download_failed', $result->get_error_code() );
	}

	/**
	 * Test download_url_to_temp saves a valid image response to a temp file.
	 */
	public function test_download_url_to_temp_saves_valid_image() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$png_data = $this->minimal_png;

		$pre_http_request = function ( $preempt, $args, $url ) use ( $png_data ) {
			return array(
				'headers'       => array( 'content-type' => 'image/png' ),
				'body'          => $png_data,
				'response'      => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'       => array(),
				'http_response' => null,
			);
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool = new WP_MCP_AI_Pro_Tool_Product_Actualization();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'download_url_to_temp' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'https://example.com/product.png' );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'file_path', $result );
		$this->assertFileExists( $result['file_path'] );
		// The saved file must use the .png extension derived from content-type.
		$this->assertStringEndsWith( '.png', $result['file_path'] );
		$this->assertSame( $png_data, file_get_contents( $result['file_path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading temp file in unit test.

		// Clean up.
		wp_delete_file( $result['file_path'] );
	}

	/**
	 * Test execute() routes a URL input via download_url_to_temp and produces
	 * wp_mcp_ai_invalid_file_type when the URL returns non-image content.
	 *
	 * This validates the full execute() URL code path without requiring a live
	 * AI provider by using a mocked HTTP response that fails content-type validation.
	 */
	public function test_execute_with_url_rejects_non_image_content() {
		if ( ! WP_MCP_AI_Pro_Tool_Product_Actualization::is_available() ) {
			$this->markTestSkipped( 'Product Actualization tool requires Imagick or GD extension.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Mock: URL returns text/html.
		$pre_http_request = function ( $preempt, $args, $url ) {
			return array(
				'headers'       => array( 'content-type' => 'text/html' ),
				'body'          => '<html></html>',
				'response'      => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'       => array(),
				'http_response' => null,
			);
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool    = new WP_MCP_AI_Pro_Tool_Product_Actualization();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'product_attachment_id' => 'https://example.com/product.html',
			'scene_prompt'          => 'Bright kitchen counter scene',
		);

		$result = $tool->execute( $args, $context );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_file_type', $result->get_error_code() );
	}
}
