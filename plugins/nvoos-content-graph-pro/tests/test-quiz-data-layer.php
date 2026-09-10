<?php
/**
 * Characterization tests for the Wave F5 quiz-management data layer — the
 * quiz/submission CPT class, the JetEngine quizzes CCT, and the three
 * metabox classes ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` +
 *   `src/metaboxes/` are asserted in full, including the slim init's
 *   file-gate targets and the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Quiz data layer tests.
 */
class Test_Quiz_Data_Layer extends WP_UnitTestCase {

	/**
	 * The five ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Quiz_CPT'               => 'class-wp-mcp-ai-quiz-cpt.php',
			'WP_MCP_AI_JetEngine_Quizzes_CCT'  => 'class-wp-mcp-ai-jetengine-quizzes-cct.php',
			'WP_MCP_AI_Quiz_Metabox_Base'      => 'metaboxes/class-wp-mcp-ai-quiz-metabox-base.php',
			'WP_MCP_AI_Quiz_Metabox_Details'   => 'metaboxes/class-wp-mcp-ai-quiz-metabox-details.php',
			'WP_MCP_AI_Quiz_Metabox_Questions' => 'metaboxes/class-wp-mcp-ai-quiz-metabox-questions.php',
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
	}

	/**
	 * The quiz CPT constants must be byte-identical.
	 */
	public function test_cpt_constants(): void {
		$this->assertSame( 'mcp_ai_quiz', WP_MCP_AI_Quiz_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_submission', WP_MCP_AI_Quiz_CPT::SUBMISSION_POST_TYPE );
		$this->assertSame( 5, WP_MCP_AI_Quiz_CPT::SYNC_LOCK_TIMEOUT );
	}

	/**
	 * The JetEngine quizzes CCT constants must be byte-identical.
	 */
	public function test_cct_constants(): void {
		$this->assertSame( 'quizzes', WP_MCP_AI_JetEngine_Quizzes_CCT::SLUG );
		$this->assertSame( 30000, WP_MCP_AI_JetEngine_Quizzes_CCT::FIELD_ID_BASE );
	}

	/**
	 * The quiz CPT init must wire the registration hooks and register the
	 * quiz and submission post types (settings-gated).
	 */
	public function test_cpt_init_and_registration(): void {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_quiz_system'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		WP_MCP_AI_Quiz_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Quiz_CPT', 'register_post_types' ) ) );

		WP_MCP_AI_Quiz_CPT::register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_quiz' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_submission' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The metabox hierarchy must be byte-identical: an abstract base with
	 * `get_id()` plus the details/questions implementations.
	 */
	public function test_metabox_contracts(): void {
		$base = new ReflectionClass( 'WP_MCP_AI_Quiz_Metabox_Base' );
		$this->assertTrue( $base->isAbstract() );
		$this->assertTrue( $base->hasMethod( 'get_id' ) );

		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_Quiz_Metabox_Details', 'WP_MCP_AI_Quiz_Metabox_Base' ) );
		$this->assertTrue( is_subclass_of( 'WP_MCP_AI_Quiz_Metabox_Questions', 'WP_MCP_AI_Quiz_Metabox_Base' ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry the full twelve-entry quiz map, and the standalone
	 * helper functions must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base quiz init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-quiz-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-jetengine-quizzes-cct.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-quiz-metabox-base.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-quiz-metabox-details.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-quiz-metabox-questions.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/quiz-management/init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/quiz-admin.css',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/quiz-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_quiz_tools', 10 );
		$this->assertCount( 12, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_quiz_ecosystem_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_enqueue_quiz_management_admin_styles' ) );
	}
}
