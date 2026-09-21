<?php
/**
 * L1 static honesty suite for the ID-handoff data contracts.
 *
 * Cross-validates the committed manifest fixture
 * (tests/fixtures/tool-contract-manifest.php) against the live tool
 * registry in both directions, so a tool annotation can never drift from
 * the manifest or lie about the key names that flow between tools.
 *
 * Part of the P3 data-contract rollout
 * (docs/project/proposals/P3-data-contract-rollout-plan-2026-09.md).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @group tools
 * @group unix-theory
 */
class Test_Tool_Id_Handoff_Contract extends WP_UnitTestCase {

	/**
	 * Shared tool registry.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Manifest fixture, loaded once per test.
	 *
	 * @var array
	 */
	protected $manifest;

	/**
	 * Set up registry access and the manifest fixture.
	 *
	 * Never clears or replaces the shared registry instance — other suites
	 * depend on its bootstrap state.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();

		$manifest = require __DIR__ . '/fixtures/tool-contract-manifest.php';
		$this->assertIsArray( $manifest, 'Manifest fixture must return an array.' );
		$this->assertArrayHasKey( 'families', $manifest, 'Manifest must declare "families".' );
		$this->manifest = $manifest;
	}

	/**
	 * Every family key in the manifest must have produces + consumes lists.
	 */
	public function test_manifest_shape_is_valid() {
		foreach ( $this->manifest['families'] as $key => $family ) {
			$this->assertIsString( $key, 'Family keys must be strings.' );
			$this->assertArrayHasKey( 'produces', $family, "Family {$key} must list produces." );
			$this->assertArrayHasKey( 'consumes', $family, "Family {$key} must list consumes." );
			$this->assertIsArray( $family['produces'], "Family {$key} produces must be an array." );
			$this->assertIsArray( $family['consumes'], "Family {$key} consumes must be an array." );
			$this->assertNotEmpty( $family['produces'], "Family {$key} must have at least one producer." );
			$this->assertNotEmpty( $family['consumes'], "Family {$key} must have at least one consumer." );
		}
	}

	/**
	 * Every manifest producer must be registered and declare produces = key.
	 */
	public function test_manifest_producers_declare_contract() {
		foreach ( $this->manifest['families'] as $key => $family ) {
			foreach ( $family['produces'] as $slug ) {
				$tool = $this->registry->get_tool( $slug );
				$this->assertNotNull( $tool, "Manifest producer {$slug} (family {$key}) is not registered." );
				$this->assertInstanceOf(
					'WP_MCP_AI_Tool_Data_Contract_Interface',
					$tool,
					"Producer {$slug} must implement WP_MCP_AI_Tool_Data_Contract_Interface."
				);
				$contract = $tool->get_data_contract();
				$this->assertSame(
					$key,
					isset( $contract['produces'] ) ? $contract['produces'] : null,
					"Producer {$slug} must declare produces = {$key}."
				);
			}
		}
	}

	/**
	 * Every manifest consumer must be registered, declare consumes including
	 * the key, and actually accept the key in its parameters schema.
	 */
	public function test_manifest_consumers_accept_key() {
		foreach ( $this->manifest['families'] as $key => $family ) {
			foreach ( $family['consumes'] as $slug ) {
				$tool = $this->registry->get_tool( $slug );
				$this->assertNotNull( $tool, "Manifest consumer {$slug} (family {$key}) is not registered." );
				$this->assertInstanceOf(
					'WP_MCP_AI_Tool_Data_Contract_Interface',
					$tool,
					"Consumer {$slug} must implement WP_MCP_AI_Tool_Data_Contract_Interface."
				);

				$contract = $tool->get_data_contract();
				$consumes = isset( $contract['consumes'] ) ? (array) $contract['consumes'] : array();
				$this->assertContains(
					$key,
					$consumes,
					"Consumer {$slug} must declare consumes including {$key}."
				);

				$schema = $tool->get_parameters_schema();
				$this->assertIsArray( $schema, "Consumer {$slug} must return a parameters schema." );
				$this->assertArrayHasKey(
					'properties',
					$schema,
					"Consumer {$slug} schema must declare properties."
				);
				$this->assertArrayHasKey(
					$key,
					$schema['properties'],
					"Consumer {$slug} declares consumes={$key} but its parameters schema has no {$key} property."
				);
			}
		}
	}

	/**
	 * Reverse drift check: every registered tool that implements the
	 * data-contract interface must have all of its declared keys vetted in
	 * the manifest. Unvetted annotations fail CI.
	 */
	public function test_no_unvetted_contract_keys() {
		$manifest_keys = array_keys( $this->manifest['families'] );

		foreach ( $this->registry->get_tools() as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Data_Contract_Interface ) {
				continue;
			}

			$slug     = $tool->get_slug();
			$contract = $tool->get_data_contract();
			if ( ! is_array( $contract ) ) {
				continue;
			}

			if ( ! empty( $contract['produces'] ) ) {
				$this->assertContains(
					$contract['produces'],
					$manifest_keys,
					"Tool {$slug} produces '{$contract['produces']}' which is not vetted in the manifest."
				);
			}

			foreach ( (array) ( isset( $contract['consumes'] ) ? $contract['consumes'] : array() ) as $consumed_key ) {
				if ( '' === $consumed_key || null === $consumed_key ) {
					continue;
				}
				$this->assertContains(
					$consumed_key,
					$manifest_keys,
					"Tool {$slug} consumes '{$consumed_key}' which is not vetted in the manifest."
				);
			}
		}
	}

	/**
	 * The registry helper must normalise a real annotated tool's contract.
	 */
	public function test_registry_normalises_real_tool_contract() {
		$contract = $this->registry->get_tool_data_contract( 'create_cron_job' );
		$this->assertSame( array( 'produces' => 'job_id' ), $contract );
	}

	/**
	 * The tool service must append the data-contract suffix to real tools.
	 */
	public function test_tool_service_appends_suffix_to_real_tools() {
		$service = new WP_MCP_AI_Tool_Service( $this->registry );

		$payload = $service->build_tools_payload(
			array( 'tools' => array( 'create_cron_job', 'get_cron_job' ) )
		);

		$by_name = array();
		foreach ( $payload as $entry ) {
			$by_name[ $entry['function']['name'] ] = $entry['function']['description'];
		}

		$this->assertStringContainsString( '[Data contract: produces=job_id]', $by_name['create_cron_job'] );
		$this->assertStringContainsString( '[Data contract: consumes=job_id]', $by_name['get_cron_job'] );
	}
}
