<?php
/**
 * Canonical-envelope tests for the AI Tool Builder toolkit.
 *
 * The toolkit's own subject matter is the contract: it scaffolds, generates and
 * checks tools. A failure shape that is wrong here propagates to every tool it
 * produces, which is why this is tested before the rest of the sweep.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 * @group ai-tool-builder
 */

class AiToolBuilderToolkitTest extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	/** @var array */
	protected static $dirs = array( 'ai-tool-builder' );

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
	 * Every class in the toolkit, mapped to its file.
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
	 * No file in the toolkit may teach or use the hollow failure envelope.
	 *
	 * Covers `generate_tool_scaffold` too, deliberately: its execute() writes the
	 * generated file to disk, so a behavioural call has no place in a unit test.
	 * The template lives in this file as string literals, which is exactly what
	 * this scan reads — and a scaffold that emits the wrong shape would seed the
	 * defect into every tool built from it.
	 */
	public function test_no_tool_returns_or_teaches_a_success_false_envelope(): void {
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
	 * The scaffold's generated example must show WP_Error.
	 *
	 * Asserted separately from the scan above so a rename of the template wording
	 * cannot quietly turn this into a test of nothing.
	 */
	public function test_the_scaffold_template_teaches_wp_error(): void {
		if ( ! $this->load_tool( 'WP_MCP_AI_Tool_Generate_Tool_Scaffold' ) ) {
			$this->markTestSkipped( 'Generate Tool Scaffold is not available.' );
		}

		$file   = WP_MCP_AI_PRO_PATH . 'includes/tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-scaffold.php';
		$source = (string) file_get_contents( $file );

		$this->assertStringContainsString(
			'return new WP_Error(',
			$source,
			'The scaffold example must show the canonical failure shape.'
		);
	}

	/**
	 * Behavioural proof for the missing-argument path, per tool.
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
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result, $slug . ' must return WP_Error when required arguments are missing.' );
		$this->assertSame(
			'wp_mcp_ai_missing_param',
			$result->get_error_code(),
			$slug . ' must use the shared missing-param code so a caller can branch on it.'
		);
	}

	/**
	 * Argument validation must not become a way around the shell-tools gate.
	 *
	 * `check_tool_compliance` validates its argument contract before the gate, so
	 * pin that a call that *does* supply code still hits the operator opt-in
	 * first. Without this, a future reorder could let a caller reach the phpcs
	 * shell-out with `WP_MCP_AI_ALLOW_SHELL_TOOLS` disabled.
	 */
	public function test_check_tool_compliance_still_gates_on_the_shell_constant(): void {
		if ( ! $this->load_tool( 'WP_MCP_AI_Tool_Check_Tool_Compliance' ) ) {
			$this->markTestSkipped( 'Check Tool Compliance is not available.' );
		}

		if ( defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) && WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'Shell tools are enabled in this environment.' );
		}

		$tool   = new WP_MCP_AI_Tool_Check_Tool_Compliance();
		$result = $tool->execute( array( 'code' => '<?php // stub.' ), array() );

		$this->assertWPError( $result );
		$this->assertSame(
			'shell_tools_disabled',
			$result->get_error_code(),
			'An argument that satisfies the contract must still hit the shell-tools gate.'
		);
	}

	/**
	 * Tools that fail fast on empty arguments, before any I/O.
	 *
	 * `generate_tool_scaffold`, `generate_tool_tests` and `refactor_tool_code` are
	 * deliberately absent: they accept empty arguments far enough to start
	 * writing files, so they are covered by the source scan instead.
	 *
	 * @return array
	 */
	public static function provide_missing_argument_calls() {
		return array(
			'analyze_tool_security'       => array( 'WP_MCP_AI_Tool_Analyze_Tool_Security', 'analyze_tool_security' ),
			'benchmark_tool_performance'  => array( 'WP_MCP_AI_Tool_Benchmark_Tool_Performance', 'benchmark_tool_performance' ),
			'check_tool_compliance'       => array( 'WP_MCP_AI_Tool_Check_Tool_Compliance', 'check_tool_compliance' ),
			'generate_tool_documentation' => array( 'WP_MCP_AI_Tool_Generate_Tool_Documentation', 'generate_tool_documentation' ),
			'generate_tool_logic'         => array( 'WP_MCP_AI_Tool_Generate_Tool_Logic', 'generate_tool_logic' ),
			'generate_tool_parameters'    => array( 'WP_MCP_AI_Tool_Generate_Tool_Parameters', 'generate_tool_parameters' ),
			'validate_tool_schema'        => array( 'WP_MCP_AI_Tool_Validate_Tool_Schema', 'validate_tool_schema' ),
		);
	}

	/**
	 * Make sure a toolkit class is loaded, using the class→file map above.
	 *
	 * Without this a behavioural assertion could skip itself silently whenever the
	 * Pro autoloader is not eager — passing while proving nothing.
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
