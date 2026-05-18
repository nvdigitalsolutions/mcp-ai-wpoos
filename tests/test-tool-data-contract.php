<?php
/**
 * Tests for the data-contract (`produces` / `consumes`) tool composability metadata.
 *
 * Validates Phase P3 of the Unix Theory Compliance Enhancement Proposal.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Fixture tools are grouped for readability.
 * phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Fixture tools are intentionally terse; behaviour is documented in the test methods.
 * phpcs:disable Squiz.Commenting.ClassComment.Missing -- See above.
 * phpcs:disable Generic.Commenting.DocComment.Missing -- See above.
 */

/**
 * A minimal tool that declares only a `produces` contract.
 */
class WP_MCP_AI_Test_Tool_Data_Contract_Producer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	public function get_slug() {
		return 'test_dc_producer';
	}
	public function get_name() {
		return 'Test Producer';
	}
	public function get_description() {
		return 'Returns a post.';
	}
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'ok',
		);
	}
	public function get_data_contract() {
		return array(
			'produces' => 'post_object',
			'consumes' => null,
		);
	}
}

/**
 * A tool that declares a `consumes` array (multiple accepted contracts).
 */
class WP_MCP_AI_Test_Tool_Data_Contract_Consumer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	public function get_slug() {
		return 'test_dc_consumer';
	}
	public function get_name() {
		return 'Test Consumer';
	}
	public function get_description() {
		return 'Updates SEO for a post.';
	}
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'ok',
		);
	}
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'post_object', 'attachment_id' ),
		);
	}
}

/**
 * A tool that returns malformed contract data — registry should normalise it away.
 */
class WP_MCP_AI_Test_Tool_Data_Contract_Malformed implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	public function get_slug() {
		return 'test_dc_malformed';
	}
	public function get_name() {
		return 'Test Malformed';
	}
	public function get_description() {
		return 'Has a junk contract.';
	}
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'ok',
		);
	}
	public function get_data_contract() {
		// Empty strings, non-strings in list, and missing keys must all be ignored.
		return array(
			'produces' => '',
			'consumes' => array( '', 'post_object', 42, null, 'post_object' ),
		);
	}
}

/**
 * A tool that does NOT implement the data-contract interface.
 */
class WP_MCP_AI_Test_Tool_Data_Contract_NoContract implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	public function get_slug() {
		return 'test_dc_none';
	}
	public function get_name() {
		return 'Test None';
	}
	public function get_description() {
		return 'No contract.';
	}
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'message' => 'ok',
		);
	}
}

/**
 * Test-case for the data-contract registry helpers and the tool-service suffix.
 *
 * @group unix-theory
 * @group tools
 */
class Test_Tool_Data_Contract extends WP_UnitTestCase {

	/**
	 * Tool registry singleton under test.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();

		$this->registry->register_tool( new WP_MCP_AI_Test_Tool_Data_Contract_Producer() );
		$this->registry->register_tool( new WP_MCP_AI_Test_Tool_Data_Contract_Consumer() );
		$this->registry->register_tool( new WP_MCP_AI_Test_Tool_Data_Contract_Malformed() );
		$this->registry->register_tool( new WP_MCP_AI_Test_Tool_Data_Contract_NoContract() );
	}

	public function tearDown(): void {
		$this->registry->unregister_tool( 'test_dc_producer' );
		$this->registry->unregister_tool( 'test_dc_consumer' );
		$this->registry->unregister_tool( 'test_dc_malformed' );
		$this->registry->unregister_tool( 'test_dc_none' );
		parent::tearDown();
	}

	public function test_interface_exists() {
		$this->assertTrue( interface_exists( 'WP_MCP_AI_Tool_Data_Contract_Interface' ) );
	}

	public function test_get_tool_data_contract_returns_normalised_produces() {
		$this->assertSame(
			array( 'produces' => 'post_object' ),
			$this->registry->get_tool_data_contract( 'test_dc_producer' )
		);
	}

	public function test_get_tool_data_contract_returns_normalised_consumes_array() {
		$this->assertSame(
			array( 'consumes' => array( 'post_object', 'attachment_id' ) ),
			$this->registry->get_tool_data_contract( 'test_dc_consumer' )
		);
	}

	public function test_get_tool_data_contract_drops_malformed_values() {
		$contract = $this->registry->get_tool_data_contract( 'test_dc_malformed' );
		$this->assertArrayNotHasKey( 'produces', $contract );
		$this->assertArrayHasKey( 'consumes', $contract );
		// Duplicates and non-strings stripped.
		$this->assertSame( array( 'post_object' ), $contract['consumes'] );
	}

	public function test_get_tool_data_contract_empty_for_tool_without_interface() {
		$this->assertSame( array(), $this->registry->get_tool_data_contract( 'test_dc_none' ) );
	}

	public function test_get_tool_data_contract_empty_for_unknown_slug() {
		$this->assertSame( array(), $this->registry->get_tool_data_contract( 'totally_made_up_slug' ) );
	}

	public function test_get_tool_definition_includes_data_contract_when_present() {
		$definition = $this->registry->get_tool_definition( 'test_dc_producer' );
		$this->assertArrayHasKey( 'data_contract', $definition );
		$this->assertSame( array( 'produces' => 'post_object' ), $definition['data_contract'] );
	}

	public function test_get_tool_definition_omits_data_contract_when_absent() {
		$definition = $this->registry->get_tool_definition( 'test_dc_none' );
		$this->assertArrayNotHasKey( 'data_contract', $definition );
	}

	public function test_tool_service_appends_contract_suffix_to_description() {
		$service = new WP_MCP_AI_Tool_Service( $this->registry );

		$payload = $service->build_tools_payload(
			array(
				'tools' => array( 'test_dc_producer', 'test_dc_consumer', 'test_dc_none' ),
			)
		);

		$by_name = array();
		foreach ( $payload as $entry ) {
			$by_name[ $entry['function']['name'] ] = $entry['function']['description'];
		}

		$this->assertStringContainsString( '[Data contract: produces=post_object]', $by_name['test_dc_producer'] );
		$this->assertStringContainsString( 'Returns a post.', $by_name['test_dc_producer'] );

		$this->assertStringContainsString( '[Data contract: consumes=post_object|attachment_id]', $by_name['test_dc_consumer'] );

		// Tool without a contract gets a clean description.
		$this->assertSame( 'No contract.', $by_name['test_dc_none'] );
	}

	public function test_suffix_filter_can_suppress_output() {
		add_filter( 'wp_mcp_ai_tool_data_contract_description_suffix', '__return_empty_string', 10, 3 );

		$service = new WP_MCP_AI_Tool_Service( $this->registry );

		$payload = $service->build_tools_payload(
			array( 'tools' => array( 'test_dc_producer' ) )
		);

		remove_filter( 'wp_mcp_ai_tool_data_contract_description_suffix', '__return_empty_string', 10 );

		$this->assertSame( 'Returns a post.', $payload[0]['function']['description'] );
	}
}
