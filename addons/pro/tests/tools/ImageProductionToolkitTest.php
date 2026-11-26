<?php
/**
 * Canonical-envelope tests for the image-production toolkit.
 *
 * This toolkit is where the sweep met its first large block of non-envelope
 * hits: seventeen of the twenty-two `success => false` arrays live inside
 * sanitize_for_llm(), whose job is to serialise a WP_Error into a payload the
 * prompt can carry. Those are annotated rather than converted, and this file
 * pins both the rule and the reason so a later sweep cannot "fix" them into
 * something that breaks the prompt contract.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group envelope
 */

class ImageProductionToolkitTest extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped();
		}
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Every PHP file in the toolkit, including the nested harmonization folder.
	 *
	 * @return array
	 */
	protected static function toolkit_files() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return array();
		}

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/';

		$files = array_merge(
			(array) glob( $base . 'class-*.php' ),
			(array) glob( $base . 'harmonization/class-*.php' )
		);

		return $files;
	}

	/**
	 * Any surviving `success => false` must be an annotated serialisation DTO.
	 *
	 * Line-based on purpose: the annotation has to sit on the array line itself,
	 * so a reader of that line learns why the shape is allowed.
	 */
	public function test_no_unannotated_success_false_envelope(): void {
		$offenders = array();

		foreach ( self::toolkit_files() as $file ) {
			$lines = file( $file );

			foreach ( $lines as $number => $line ) {
				if ( ! preg_match( '/[\'\"]success[\'\"]\s*=>\s*false/', $line ) ) {
					continue;
				}

				if ( false === strpos( $line, 'phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray' ) ) {
					$offenders[] = basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Return WP_Error on a tool failure path; only a serialisation DTO may carry success => false, with its reason annotated.'
		);
	}

	/**
	 * The annotation is not a licence to drift: sanitize_for_llm() must keep
	 * emitting the `{ success, error: { code, message } }` shape the chat client
	 * and cron-status surfaces already read.
	 */
	public function test_sanitize_for_llm_still_serialises_a_wp_error(): void {
		$class = 'WP_MCP_AI_Tool_Colorize_Image';

		if ( ! $this->load_tool( $class ) ) {
			$this->markTestSkipped( $class . ' is not available.' );
		}

		$tool   = new $class();
		$result = $tool->sanitize_for_llm( new WP_Error( 'wp_mcp_ai_test_code', 'Boom.' ) );

		$this->assertIsArray( $result, 'sanitize_for_llm() must return the prompt payload, not the WP_Error.' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'wp_mcp_ai_test_code', $result['error']['code'] );
		$this->assertSame( 'Boom.', $result['error']['message'] );
	}

	/**
	 * The batch tools fail fast on empty arguments, before any GD or sidecar work.
	 *
	 * @dataProvider provide_missing_argument_calls
	 *
	 * @param string $class Tool class name.
	 * @param string $slug  Tool slug, for the failure message.
	 */
	public function test_a_missing_argument_is_a_wp_error( $class, $slug ) {
		if ( ! $this->load_tool( $class ) ) {
			$this->markTestSkipped( $class . ' is not available.' );
		}

		$tool   = new $class();
		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertWPError( $result, $slug . ' must return WP_Error when required arguments are missing.' );
		$this->assertSame( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * @return array
	 */
	public static function provide_missing_argument_calls() {
		return array(
			'apply_watermark_batch'  => array( 'WP_MCP_AI_Tool_Apply_Watermark_Batch', 'apply_watermark_batch' ),
			'optimise_images_batch'  => array( 'WP_MCP_AI_Tool_Optimise_Images_Batch', 'optimise_images_batch' ),
		);
	}

	/**
	 * The GD gate must name the missing engine rather than a generic message.
	 */
	public function test_the_gd_gate_names_its_engine(): void {
		$file = WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-apply-watermark-batch.php';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Apply Watermark Batch is not available.' );
		}

		$source = (string) file_get_contents( $file );

		$this->assertStringContainsString( "'wp_mcp_ai_engine_missing'", $source );
		$this->assertStringContainsString( "'engine' => 'gd'", $source );
	}

	/**
	 * Make sure a toolkit class is loaded.
	 *
	 * @param string $class Tool class name.
	 * @return bool Whether the class is now loadable.
	 */
	protected function load_tool( $class ) {
		if ( class_exists( $class ) ) {
			return true;
		}

		foreach ( self::toolkit_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $source, $match ) && $match[1] === $class ) {
				require_once $file;
				break;
			}
		}

		return class_exists( $class );
	}
}
