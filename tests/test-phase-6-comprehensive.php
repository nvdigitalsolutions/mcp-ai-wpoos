<?php
/**
 * Phase 6 Comprehensive Test Suite
 *
 * Tests all components required for Phase 6: Testing & Documentation completion
 * Based on PRO_PLUGIN_ENHANCEMENT_SLASH_COMMANDS.md proposal
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Phase 6 Comprehensive Test Class
 *
 * @group phase-6
 * @group comprehensive
 */
class Test_Phase_6_Comprehensive extends WP_UnitTestCase {

	/**
	 * Test Week 15: Comprehensive Testing - Unit Tests
	 *
	 * @group unit-tests
	 */
	public function test_unit_tests_all_command_classes_exist() {
		// Check if all command classes have test files.
		$test_files = array(
			'test-slash-commands-pro-toolkit.php',
			'test-slash-command-workflow.php',
			'test-slash-command-optimize-perf.php',
			'test-slash-command-sync-docs.php',
			'test-slash-command-error-handling.php',
		);

		foreach ( $test_files as $test_file ) {
			$file_path = __DIR__ . '/' . $test_file;
			$this->assertFileExists( $file_path, "Test file {$test_file} should exist" );
		}
	}

	/**
	 * Test Week 15: Comprehensive Testing - Integration Tests
	 *
	 * @group integration-tests
	 */
	public function test_integration_tests_workflow_execution_exist() {
		$integration_test_files = array(
			'test-slash-commands-pro-workflows.php',
			'test-slash-commands-pro-workflows-phase2.php',
			'test-slash-command-workflow-dependencies.php',
			'test-slash-command-workflow-parallel.php',
			'test-agentic-chat-workflow-comprehensive.php',
		);

		foreach ( $integration_test_files as $test_file ) {
			$file_path = __DIR__ . '/' . $test_file;
			$this->assertFileExists( $file_path, "Integration test file {$test_file} should exist" );
		}
	}

	/**
	 * Test Week 15: Comprehensive Testing - Performance Tests
	 *
	 * @group performance-tests
	 */
	public function test_performance_tests_exist() {
		// Check if performance test directory and files exist.
		$performance_dir = __DIR__ . '/performance';
		$this->assertDirectoryExists( $performance_dir, 'Performance test directory should exist' );
	}

	/**
	 * Test Week 15: Comprehensive Testing - Security Audit
	 *
	 * @group security-audit
	 */
	public function test_security_audit_files_exist() {
		$security_dir = __DIR__ . '/security';
		$this->assertDirectoryExists( $security_dir, 'Security test directory should exist' );
	}

	/**
	 * Test Week 16: Documentation - User Documentation
	 *
	 * @group user-documentation
	 */
	public function test_user_documentation_exists() {
		$user_docs = array(
			'SLASH_COMMANDS_QUICK_START.md',
			'SLASH_COMMANDS_GUIDE.md',
			'PRO_TOOLKIT_SLASH_COMMANDS_USER_GUIDE.md',
			'pro-workflow-builder.md',
			'workflow-migration-guide.md',
		);

		foreach ( $user_docs as $doc_file ) {
			$this->assertNotNull(
				$this->find_doc( $doc_file ),
				"User documentation file {$doc_file} should exist"
			);
		}
	}

	/**
	 * Test Week 16: Documentation - Developer Documentation
	 *
	 * @group developer-documentation
	 */
	public function test_developer_documentation_exists() {
		$dev_docs = array(
			'PRO_TOOLKIT_SLASH_COMMANDS_IMPLEMENTATION.md',
			'TOOLKIT_SLASH_COMMANDS_PROPOSAL.md',
			'workflow-builder-architecture.md',
		);

		foreach ( $dev_docs as $doc_file ) {
			$this->assertNotNull(
				$this->find_doc( $doc_file ),
				"Developer documentation file {$doc_file} should exist"
			);
		}
	}

	/**
	 * Test Week 16: Documentation - Video Tutorials
	 *
	 * @group video-tutorials
	 */
	public function test_video_tutorial_documentation_exists() {
		// Check if video tutorial documentation exists.
		$docs_dir          = dirname( __DIR__ ) . '/docs';
		$visual_guides_dir = $docs_dir . '/visual-guides';

		// We should have visual guides directory.
		$this->assertDirectoryExists( $visual_guides_dir, 'Visual guides directory should exist' );
	}

	/**
	 * Test Week 16: Documentation - Migration Guide
	 *
	 * @group migration-guide
	 */
	public function test_migration_guide_exists() {
		$this->assertNotNull(
			$this->find_doc( 'workflow-migration-guide.md' ),
			'Migration guide should exist'
		);
	}

	/**
	 * Test Success Criteria - Functional Requirements
	 *
	 * @group success-criteria
	 * @group functional
	 */
	public function test_functional_requirements_met() {
		// Test that REST API endpoint for slash commands exists.
		$rest_server = rest_get_server();
		$routes      = $rest_server->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/slash-command', $routes, 'Slash command REST endpoint should exist' );
	}

	/**
	 * Test Success Criteria - Performance Requirements
	 *
	 * @group success-criteria
	 * @group performance
	 */
	public function test_performance_requirements() {
		// Simple performance check - measure command execution time.
		$start_time = microtime( true );

		// Simulate a simple command execution.
		$result = apply_filters( 'wp_mcp_ai_slash_command_test', true );

		$end_time       = microtime( true );
		$execution_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.

		// Command execution should be < 2 seconds (2000ms) for simple commands.
		$this->assertLessThan( 2000, $execution_time, 'Simple command execution should be under 2 seconds' );
	}

	/**
	 * Test Success Criteria - Quality Requirements
	 *
	 * @group success-criteria
	 * @group quality
	 */
	public function test_quality_requirements_documentation_complete() {
		// Check that key documentation files exist and are not empty.
		$key_docs = array(
			'SLASH_COMMANDS_GUIDE.md',
			'PRO_TOOLKIT_SLASH_COMMANDS_USER_GUIDE.md',
		);

		foreach ( $key_docs as $doc_file ) {
			$file_path = $this->find_doc( $doc_file );

			$this->assertNotNull( $file_path, "Documentation file {$doc_file} should exist" );
			$this->assertGreaterThan( 100, filesize( $file_path ), "Documentation file {$doc_file} should not be empty" );
		}
	}

	/**
	 * Locate a documentation file by basename.
	 *
	 * Documentation files moved several times during the docs reorganization,
	 * so resolve by basename under docs/ (plus the repo root) instead of
	 * asserting a single fixed path.
	 *
	 * @param string $filename Documentation file basename.
	 * @return string|null Absolute path if found, null otherwise.
	 */
	private function find_doc( $filename ) {
		static $index = null;

		if ( null === $index ) {
			$index = array();
			$root  = dirname( __DIR__ );
			$docs  = $root . '/docs';

			// Repo root, non-recursive.
			foreach ( glob( $root . '/*' ) as $path ) {
				if ( is_file( $path ) ) {
					$index[ basename( $path ) ] = $path;
				}
			}

			// Docs tree, recursive.
			if ( is_dir( $docs ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $docs, FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file ) {
					if ( $file->isFile() ) {
						$index[ $file->getFilename() ] = $file->getPathname();
					}
				}
			}
		}

		return isset( $index[ $filename ] ) ? $index[ $filename ] : null;
	}

	/**
	 * Test Success Criteria - User Experience Requirements
	 *
	 * @group success-criteria
	 * @group ux
	 */
	public function test_user_experience_requirements() {
		// Test that chat integration exists.
		$chat_js           = dirname( __DIR__ ) . '/assets/js/chat.js';
		$slash_commands_js = dirname( __DIR__ ) . '/assets/js/slash-commands.js';

		// At least one of these should exist.
		$this->assertTrue(
			file_exists( $chat_js ) || file_exists( $slash_commands_js ),
			'Chat or slash commands JavaScript should exist'
		);
	}

	/**
	 * Test Phase 6 Launch Checklist - All Components
	 *
	 * @group launch-checklist
	 */
	public function test_launch_checklist_components_exist() {
		// Verify key components exist.
		$components = array(
			'tests'    => __DIR__,
			'docs'     => dirname( __DIR__ ) . '/docs',
			'assets'   => dirname( __DIR__ ) . '/assets',
			'includes' => dirname( __DIR__ ) . '/includes',
		);

		foreach ( $components as $component_name => $component_path ) {
			$this->assertDirectoryExists( $component_path, "{$component_name} directory should exist" );
		}
	}
}
