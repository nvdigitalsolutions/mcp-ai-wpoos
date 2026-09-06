<?php
/**
 * Tests for tool-declared sensitive result fields (log masking layer 3).
 *
 * A tool can opt into non-loggable result fields via
 * {@see WP_MCP_AI_Tool_Sensitive_Result_Interface}. These tests assert that
 * `WP_MCP_AI_Logger::log_tool_execution()` masks every declared path before
 * `result_preview` (and `arguments`) reach `wp_mcp_ai_recent_activity`, while
 * tools that declare nothing are byte-for-byte unaffected.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The fake tool fixtures share this file with their consumer, mirroring the grouped-interface convention used elsewhere in the tool system.
 */

/**
 * Fake tool that declares a sensitive result field.
 */
class WP_MCP_AI_Test_Sensitive_Field_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Sensitive_Result_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'test_sensitive_field_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Test Sensitive Field Tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return 'Fake tool for sensitive-result-field tests.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Execute the fake tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Canonical success envelope.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_sensitive_result_fields() {
		// `link_id` is deliberately NOT on the logger's key deny-list, so any
		// redaction of it proves the dot-path mechanism rather than the generic
		// sensitive-key heuristics.
		return array( 'url', 'items.*.link_id' );
	}
}

/**
 * Legacy-format fake tool (no interface) exposing the declaration method.
 */
class WP_MCP_AI_Test_Sensitive_Legacy_Tool {
	/**
	 * Get the fake tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'test_sensitive_legacy_tool';
	}

	/**
	 * Get the legacy tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'Test Sensitive Legacy Tool',
			'description'         => 'Fake legacy tool for wrapper delegation tests.',
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute the fake legacy tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Canonical success envelope.
	 */
	public function execute( array $arguments = array(), array $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fake's interface signature must match real tools; parameters are intentionally unused.
		return array( 'success' => true );
	}

	/**
	 * Declare the sensitive result field for this fake.
	 *
	 * @return string[] Dot-notation paths that must never be logged.
	 */
	public function get_sensitive_result_fields() {
		return array( 'secret_link' );
	}
}

/**
 * Logger masking tests for tool-declared sensitive result fields.
 *
 * @group logger
 * @group security
 */
class WP_MCP_AI_Logger_Sensitive_Result_Fields_Test extends WP_UnitTestCase {

	/**
	 * Sentinel secret that must never be persisted.
	 */
	const SECRET = 'lk_9XgCEUuh9JIN';

	/**
	 * Original registry instance preserved across the test.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $original_instance;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Logger::reset_sensitive_result_fields_cache();

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		// Swap in a fresh registry, register the fakes, and mark it bootstrapped
		// so the logger's registry lookup is exercised.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Test_Sensitive_Field_Tool() );
		$registry->register_tool( new WP_MCP_AI_Legacy_Tool_Wrapper( new WP_MCP_AI_Test_Sensitive_Legacy_Tool() ) );

		// Bootstrapping loads the default tool suite, which is heavier than this
		// test needs but guarantees the same lookup path production uses.
		$registry->init();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Logger::reset_sensitive_result_fields_cache();

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	/**
	 * Declared paths are masked in result_preview while sibling fields survive.
	 *
	 * @group security
	 */
	public function test_declared_field_is_masked_and_siblings_survive() {
		WP_MCP_AI_Logger::log_tool_execution(
			'test_sensitive_field_tool',
			array( 'toolkit' => 'gmail' ),
			array(
				'success' => true,
				'message' => 'Connect Link created.',
				'toolkit' => 'gmail',
				'url'     => 'https://connect.composio.dev/link/' . self::SECRET . '?state=x',
			),
			array( 'assistant_id' => 7 )
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringContainsString( 'test_sensitive_field_tool', $stored, 'Guard: entry should have been stored.' );
		$this->assertStringNotContainsString( self::SECRET, $stored, 'The declared capability URL must never be persisted.' );
		$this->assertStringContainsString( 'url', $stored, 'The key itself stays visible so the log remains diagnostic.' );
		$this->assertStringContainsString( '[redacted]', $stored );
		$this->assertStringContainsString( 'Connect Link created.', $stored, 'Non-declared fields must survive.' );
	}

	/**
	 * Wildcard paths reach values inside numerically-indexed lists.
	 *
	 * @group security
	 */
	public function test_wildcard_path_masks_list_items() {
		WP_MCP_AI_Logger::log_tool_execution(
			'test_sensitive_field_tool',
			array(),
			array(
				'success' => true,
				'items'   => array(
					array(
						'name'    => 'first',
						'link_id' => self::SECRET,
					),
					array(
						'name'    => 'second',
						'link_id' => 't2',
					),
				),
			),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
		$this->assertStringNotContainsString( 't2', $stored );
		$this->assertStringContainsString( 'first', $stored );
		$this->assertStringContainsString( 'second', $stored );
		$this->assertStringContainsString( '[redacted]', $stored );
	}

	/**
	 * Declared paths are masked inside tool arguments too.
	 *
	 * @group security
	 */
	public function test_declared_field_is_masked_in_arguments() {
		WP_MCP_AI_Logger::log_tool_execution(
			'test_sensitive_field_tool',
			array( 'url' => 'https://connect.composio.dev/link/' . self::SECRET ),
			array( 'success' => true ),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
	}

	/**
	 * Declarations are honoured on the tool_error path as well.
	 *
	 * @group security
	 */
	public function test_declared_field_is_masked_on_the_error_path() {
		WP_MCP_AI_Logger::log_tool_execution(
			'test_sensitive_field_tool',
			array( 'url' => 'https://connect.composio.dev/link/' . self::SECRET ),
			new WP_Error( 'wp_mcp_ai_link_failed', 'Callback rejected.' ),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
	}

	/**
	 * The legacy wrapper forwards the declaration from its inner tool.
	 *
	 * @group security
	 */
	public function test_legacy_wrapper_forwards_declaration() {
		WP_MCP_AI_Logger::log_tool_execution(
			'test_sensitive_legacy_tool',
			array(),
			array(
				'success'     => true,
				'secret_link' => 'https://example.test/share/' . self::SECRET,
			),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
	}

	/**
	 * Tools that declare nothing are byte-for-byte unaffected.
	 *
	 * @group security
	 */
	public function test_non_declaring_tool_is_unaffected() {
		WP_MCP_AI_Logger::log_tool_execution(
			'get_media',
			array( 'per_page' => 1 ),
			array( 'url' => 'https://example.test/wp-content/uploads/2026/08/image.png' ),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringContainsString(
			'https:\/\/example.test\/wp-content\/uploads\/2026\/08\/image.png',
			$stored,
			'A diagnostic URL from a non-declaring tool must pass through untouched.'
		);
	}

	/**
	 * The filter may widen the declaration but never weaken it.
	 *
	 * @group security
	 */
	public function test_filter_can_only_add_fields() {
		$filter = function ( $fields ) {
			$fields[] = 'extra_secret';
			return $fields;
		};

		add_filter( 'wp_mcp_ai_tool_sensitive_result_fields', $filter, 10, 2 );
		WP_MCP_AI_Logger::reset_sensitive_result_fields_cache();

		try {
			WP_MCP_AI_Logger::log_tool_execution(
				'test_sensitive_field_tool',
				array(),
				array(
					'success'      => true,
					'url'          => 'https://connect.composio.dev/link/' . self::SECRET,
					'extra_secret' => 'filtered-secret',
				),
				array()
			);
		} finally {
			remove_filter( 'wp_mcp_ai_tool_sensitive_result_fields', $filter, 10 );
			WP_MCP_AI_Logger::reset_sensitive_result_fields_cache();
		}

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
		$this->assertStringNotContainsString( 'filtered-secret', $stored );
	}
}
