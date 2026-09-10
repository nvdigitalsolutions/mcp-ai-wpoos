<?php
/**
 * Characterization tests for the Wave F5 quiz-management tool batch — the
 * eleven quiz tools, the render-math tool, and the base-owned math-response
 * D8-compat trait copy ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/quiz-management/` + `src/tools/math/` are asserted in full,
 *   including the standalone tool filter and ecosystem registration.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Quiz tools tests.
 */
class Test_Quiz_Tools extends WP_UnitTestCase {

	/**
	 * The ported tool symbols must follow the ownership boundary, and the
	 * D8-compat math-response trait must serve from the right tree.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Quiz'          => 'tools/quiz-management/class-wp-mcp-ai-tool-create-quiz.php',
			'WP_MCP_AI_Tool_Research_Quiz_Topic'  => 'tools/quiz-management/class-wp-mcp-ai-tool-research-quiz-topic.php',
			'WP_MCP_AI_Tool_Render_Math_Equation' => 'tools/math/class-wp-mcp-ai-tool-render-math-equation.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}

		// The math-response trait is base-owned; the addon's D8-compat copy
		// serves standalone (the root classmap excludes `includes/tools/`).
		$trait_path = str_replace( '\\', '/', (string) ( new ReflectionClass( 'WP_MCP_AI_Tool_Math_Response' ) )->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'includes/tools/trait-wp-mcp-ai-tool-math-response.php', $trait_path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/trait-wp-mcp-ai-tool-math-response.php', $trait_path );
		}
	}

	/**
	 * The twelve tool surfaces must be byte-identical — uniform `edit_posts`
	 * capability, the monolith slug map, and the render-math base-pro flag.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Quiz'          => 'create_quiz',
			'WP_MCP_AI_Tool_Get_Quiz'             => 'get_quiz',
			'WP_MCP_AI_Tool_List_Quizzes'         => 'list_quizzes',
			'WP_MCP_AI_Tool_Update_Quiz'          => 'update_quiz',
			'WP_MCP_AI_Tool_Delete_Quiz'          => 'delete_quiz',
			'WP_MCP_AI_Tool_Submit_Quiz_Answer'   => 'submit_quiz_answer',
			'WP_MCP_AI_Tool_Grade_Quiz'           => 'grade_quiz',
			'WP_MCP_AI_Tool_Get_Quiz_Submissions' => 'get_quiz_submissions',
			'WP_MCP_AI_Tool_Get_Quiz_Results'     => 'get_quiz_results',
			'WP_MCP_AI_Tool_Get_Quiz_Analytics'   => 'get_quiz_analytics',
			'WP_MCP_AI_Tool_Research_Quiz_Topic'  => 'research_quiz_topic',
			'WP_MCP_AI_Tool_Render_Math_Equation' => 'render_math_equation',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$math = new WP_MCP_AI_Tool_Render_Math_Equation();
		$this->assertTrue( $math->requires_base_pro() );

		$create = new WP_MCP_AI_Tool_Create_Quiz();
		$def    = $create->get_definition();
		$this->assertSame( 'Create Quiz', $def['name'] );
		$this->assertSame( 'education', $def['toolkit'] );
		$this->assertSame( 'mcp_ai_quiz', $def['post_type'] );
	}

	/**
	 * The first argument gates must be byte-identical: the capability check
	 * passes for an author, then the missing-field WP_Error fires.
	 */
	public function test_gate_contracts(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$get_quiz = new WP_MCP_AI_Tool_Get_Quiz();
		$result   = $get_quiz->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_quiz_id', $result->get_error_code() );

		$create_quiz = new WP_MCP_AI_Tool_Create_Quiz();
		$result      = $create_quiz->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );

		$grade_quiz = new WP_MCP_AI_Tool_Grade_Quiz();
		$result     = $grade_quiz->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_submission_id', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the full
	 * twelve-entry quiz map and the ecosystem registration helper must load.
	 */
	public function test_standalone_filter_and_registration(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base quiz init registers the tools at boot.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/quiz-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_quiz_tools', 10 );
		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 12, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Render_Math_Equation', $tools );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_quiz_ecosystem_tools' ) );
	}
}
