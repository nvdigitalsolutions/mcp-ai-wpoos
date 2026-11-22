<?php
/**
 * Canonical-envelope tests for the document-generation, math and regulatory
 * registration toolkits.
 *
 * These three toolkits share the same defect shape: a failure path that returns
 * `array( 'success' => false, ... )`. That reads to the tool loop as a
 * *successful* call whose payload happens to say false, so the model reports
 * done instead of retrying. This file pins both halves of the repair — the
 * source shape and the WP_Error contract on the deterministic argument paths.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group envelope
 */

class DocumentMathRegulatoryToolkitTest extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	/** @var array */
	protected static $dirs = array( 'document-generation', 'math', 'regulatory-registration' );

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped();
		}
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// The document-generation and quiz tools check their feature toggle
		// before they look at arguments; enable both so the missing-argument
		// assertions measure the argument path and not the toggle.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['enable_document_generation_toolkit'] = true;
		$settings['enable_quiz_system']                 = true;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Every class in the three toolkits, mapped to its file.
	 *
	 * @return array
	 */
	public static function provide_all_tools() {
		$tools = array();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return $tools;
		}

		foreach ( self::$dirs as $dir ) {
			$path  = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/';
			$files = glob( $path . 'class-*.php' );

			if ( false === $files ) {
				continue;
			}

			foreach ( $files as $file ) {
				$source = (string) file_get_contents( $file );

				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $source, $match ) ) {
					$tools[ $match[1] ] = array( $match[1], $file );
				}
			}
		}

		return $tools;
	}

	/**
	 * No file in the three toolkits may return the hollow failure envelope.
	 *
	 * A source scan rather than a behavioural call: several of these tools gate
	 * on npm packages (mathjs, KaTeX, MJML) or on WooCommerce, so an execute()
	 * assertion would measure the test environment. The shape is the defect
	 * class under repair here, and it is visible without running anything.
	 */
	public function test_no_tool_returns_a_success_false_envelope(): void {
		$offenders = array();

		foreach ( self::provide_all_tools() as $row ) {
			$source = (string) file_get_contents( $row[1] );

			if ( preg_match( '/[\'\"]success[\'\"]\s*=>\s*false/', $source ) ) {
				$offenders[] = basename( $row[1] );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Return WP_Error on the failure path; a `success => false` array reads as a successful call.'
		);
	}

	/**
	 * Behavioural proof that the argument paths are WP_Error, per tool.
	 *
	 * Only tools whose first check is argument validation are listed. The math
	 * tools are absent on purpose: calculate_derivative(), calculate_integral(),
	 * matrix_operations() and solve_equation() check for the mathjs package
	 * before they check arguments, so their outcome depends on the host.
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
		$this->assertSame(
			'wp_mcp_ai_missing_param',
			$result->get_error_code(),
			$slug . ' must use the shared missing-param code so a caller can branch on it.'
		);
	}

	/**
	 * Tools that fail on empty arguments before any I/O or host check.
	 *
	 * @return array
	 */
	public static function provide_missing_argument_calls() {
		return array(
			'archive_documents'           => array( 'WP_MCP_AI_Tool_Archive_Documents', 'archive_documents' ),
			'generate_email_template'     => array( 'WP_MCP_AI_Tool_Generate_Email_Template', 'generate_email_template' ),
			'generate_invoice_batch'      => array( 'WP_MCP_AI_Tool_Generate_Invoice_Batch', 'generate_invoice_batch' ),
			'add_regulatory_requirement'  => array( 'WP_MCP_AI_Tool_Add_Regulatory_Requirement', 'add_regulatory_requirement' ),
			'check_hs_code'               => array( 'WP_MCP_AI_Tool_Check_HS_Code', 'check_hs_code' ),
			'generate_submission_pack'    => array( 'WP_MCP_AI_Tool_Generate_Submission_Pack', 'generate_submission_pack' ),
			'get_reg_document'            => array( 'WP_MCP_AI_Tool_Get_Reg_Document', 'get_reg_document' ),
			'get_registration_timeline'   => array( 'WP_MCP_AI_Tool_Get_Registration_Timeline', 'get_registration_timeline' ),
			'get_regulatory_requirements' => array( 'WP_MCP_AI_Tool_Get_Regulatory_Requirements', 'get_regulatory_requirements' ),
		);
	}

	/**
	 * The OCR tool's all-failed path must keep its per-document detail.
	 *
	 * `pro_document_ocr` is a batch tool: when every document fails it returns a
	 * WP_Error now, but the per-document errors and the human-readable report are
	 * still the model's evidence. Asserted at the source level because the branch
	 * needs a live provider to reach.
	 */
	public function test_the_ocr_all_failed_error_keeps_its_per_document_detail(): void {
		$file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Pro Document OCR is not available.' );
		}

		$source = (string) file_get_contents( $file );

		$this->assertStringContainsString(
			"'wp_mcp_ai_ocr_failed'",
			$source,
			'The all-failed path must carry a code a caller can branch on.'
		);
		$this->assertStringContainsString(
			"'details' => \$response['errors']",
			$source,
			'The WP_Error data must keep the per-document errors.'
		);
	}

	/**
	 * Make sure a toolkit class is loaded, using the class→file map above.
	 *
	 * @param string $class Tool class name.
	 * @return bool Whether the class is now loadable.
	 */
	protected function load_tool( $class ) {
		if ( class_exists( $class ) ) {
			return true;
		}

		foreach ( self::provide_all_tools() as $row ) {
			if ( $row[0] === $class && is_readable( $row[1] ) ) {
				require_once $row[1];
				break;
			}
		}

		return class_exists( $class );
	}
}
