<?php
/**
 * Canonical-envelope tests for the CRM and developer toolkits.
 *
 * These are the last two toolkits in the sweep. Most sites were the CRM
 * toolkit toggle repeated per tool; the developer toolkit added a protected
 * helper whose array was returned verbatim by execute(), and a response DTO
 * that carried the whole HTTP context alongside its false flag.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group envelope
 */

class CrmDeveloperToolkitTest extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	/** @var array */
	protected static $dirs = array( 'crm', 'developer' );

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
	 * Every class in the two toolkits, mapped to its file.
	 *
	 * @return array
	 */
	public static function provide_all_files() {
		$files = array();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return $files;
		}

		foreach ( self::$dirs as $dir ) {
			$found = glob( WP_MCP_AI_PRO_PATH . 'includes/tools/' . $dir . '/class-*.php' );
			if ( false !== $found ) {
				$files = array_merge( $files, $found );
			}
		}

		return $files;
	}

	/**
	 * Any surviving `success => false` must carry its annotation.
	 *
	 * The CRM unsubscribe responder is the one allowed case: RFC 8058 expects
	 * that JSON body from the POST, and it is a WP_REST_Response, not a tool
	 * result.
	 */
	public function test_no_unannotated_success_false_envelope(): void {
		$offenders = array();

		foreach ( self::provide_all_files() as $file ) {
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

		$this->assertSame( array(), $offenders, 'Return WP_Error on a tool failure path.' );
	}

	/**
	 * The CRM toolkit gate is one WP_Error shape across all four tools.
	 */
	public function test_the_crm_toolkit_gate_is_a_wp_error(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_crm_toolkit' => false ) );

		$classes = array(
			'WP_MCP_AI_Tool_Archive_Stale_Contacts',
			'WP_MCP_AI_Tool_Get_Contact_Interactions',
			'WP_MCP_AI_Tool_Recalculate_Engagement_Scores',
			'WP_MCP_AI_Tool_Scan_Duplicate_Contacts',
		);

		foreach ( $classes as $class ) {
			if ( ! $this->load_tool( $class ) ) {
				$this->markTestSkipped( $class . ' is not available.' );
			}

			$tool   = new $class();
			$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

			$this->assertWPError( $result, $class . ' must return WP_Error when the toolkit is disabled.' );
			$this->assertSame( 'toolkit_not_available', $result->get_error_code() );
		}
	}

	/**
	 * Past the gate, a missing contact id is the shared missing-param code.
	 */
	public function test_get_contact_interactions_requires_a_contact(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_crm_toolkit' => true ) );

		$class = 'WP_MCP_AI_Tool_Get_Contact_Interactions';

		if ( ! $this->load_tool( $class ) ) {
			$this->markTestSkipped( $class . ' is not available.' );
		}

		$tool   = new $class();
		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * The developer helpers return straight from execute(), so the underlying
	 * WP_Error must propagate rather than being flattened into a string.
	 */
	public function test_the_connection_test_propagates_its_error(): void {
		$file = WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-tool-generic-rest-api.php';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Generic REST API tool is not available.' );
		}

		$source = (string) file_get_contents( $file );

		$this->assertStringContainsString(
			"if ( is_wp_error( \$result ) ) {\n\t\t\t// execute() returns this verbatim",
			$source,
			'test_connection() must propagate the WP_Error it already has.'
		);
	}

	/**
	 * The oversize guard keeps the HTTP context in the error data.
	 */
	public function test_the_oversize_guard_keeps_its_response_context(): void {
		$file = WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-pro-tool-generic-rest.php';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Pro Generic REST tool is not available.' );
		}

		$source = (string) file_get_contents( $file );

		$this->assertStringContainsString( "'wp_mcp_ai_response_too_large'", $source );
		$this->assertStringContainsString( "'status_code' => \$status_code", $source );
		$this->assertStringContainsString( "'truncated'   => true", $source );
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

		foreach ( self::provide_all_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/m', $source, $match ) && $match[1] === $class ) {
				require_once $file;
				break;
			}
		}

		return class_exists( $class );
	}
}
