<?php
/**
 * Tests for the third batch of Phase 3 SaaS connectors:
 * Microsoft 365 / SharePoint and ServiceNow.
 *
 * No live HTTP requests are made — these tests exercise the pure-PHP
 * transformation paths (record_to_node, *_node_id helpers, capability
 * flags, registry registration).
 *
 * @package NV_oOS_Graphify
 * @since   0.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Saas_Connectors_Batch3
 */
class Test_Graphify_Saas_Connectors_Batch3 extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Capability flags & metadata
	// -------------------------------------------------------------------------

	/**
	 * M365 driver advertises the full capability set.
	 */
	public function test_m365_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_M365();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'm365', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_pagination'] );
		$this->assertTrue( $flags['supports_relationships'] );
	}

	/**
	 * ServiceNow driver advertises OAuth + incremental + pagination
	 * (no native webhooks — set explicitly to false).
	 */
	public function test_servicenow_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_ServiceNow();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'servicenow', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertFalse( $flags['supports_webhooks'] );
		$this->assertTrue( $flags['supports_pagination'] );
	}

	// -------------------------------------------------------------------------
	// M365 — *_to_node + node_id helpers
	// -------------------------------------------------------------------------

	/**
	 * A Graph site payload maps to a 'site' node and produces a stable,
	 * key-safe node_id (Graph IDs contain commas).
	 */
	public function test_m365_site_to_node_and_node_id() {
		$driver = new NV_oOS_Graphify_Remote_M365();
		$site   = array(
			'id'          => 'contoso.sharepoint.com,abc-1234,def-5678',
			'displayName' => 'Marketing',
			'webUrl'      => 'https://contoso.sharepoint.com/sites/marketing',
		);
		$node   = $driver->site_to_node( $site, 'm365' );
		$this->assertSame( 'site', $node['type'] );
		$this->assertSame( 'Marketing', $node['label'] );
		$this->assertSame( 'https://contoso.sharepoint.com/sites/marketing', $node['url'] );
		$this->assertSame( $driver->site_node_id( $site['id'], 'm365' ), $node['node_id'] );
		// node_id must be sanitize_key-safe (lowercase, alnum, _, -).
		$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]+$/', $node['node_id'] );
		$this->assertStringStartsWith( 'm365:site:', $node['external_id'] );
	}

	/**
	 * A drive payload maps to a 'drive' node carrying the parent site ID
	 * in properties.
	 */
	public function test_m365_drive_to_node_carries_site_id() {
		$driver = new NV_oOS_Graphify_Remote_M365();
		$node   = $driver->drive_to_node(
			array(
				'id'        => 'b!1234',
				'name'      => 'Documents',
				'driveType' => 'documentLibrary',
				'webUrl'    => 'https://contoso.sharepoint.com/sites/marketing/Shared%20Documents',
			),
			'site-xyz',
			'm365'
		);
		$this->assertSame( 'drive', $node['type'] );
		$this->assertSame( 'site-xyz', $node['properties']['m365_site_id'] );
		$this->assertSame( 'documentLibrary', $node['properties']['drive_type'] );
		$this->assertStringStartsWith( 'm365:drive:', $node['external_id'] );
	}

	/**
	 * A drive-item folder payload maps to a 'folder' node, while a file
	 * payload (no `folder` key) maps to 'document'.
	 */
	public function test_m365_item_to_node_folder_vs_file() {
		$driver = new NV_oOS_Graphify_Remote_M365();

		$folder = $driver->item_to_node(
			array(
				'id'                   => 'item-1',
				'name'                 => 'Reports',
				'folder'               => array( 'childCount' => 3 ),
				'lastModifiedDateTime' => '2026-04-15T10:00:00Z',
			),
			'drive-1',
			'm365'
		);
		$this->assertSame( 'folder', $folder['type'] );
		$this->assertTrue( $folder['properties']['is_folder'] );
		$this->assertSame( 'drive-1', $folder['properties']['m365_drive_id'] );

		$file = $driver->item_to_node(
			array(
				'id'   => 'item-2',
				'name' => 'Roadmap.docx',
				'file' => array( 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			),
			'drive-1',
			'm365'
		);
		$this->assertSame( 'document', $file['type'] );
		$this->assertFalse( $file['properties']['is_folder'] );
		$this->assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$file['properties']['mime_type']
		);
	}

	// -------------------------------------------------------------------------
	// ServiceNow — *_to_node + node_id helpers
	// -------------------------------------------------------------------------

	/**
	 * Incident records map to 'incident' nodes with state, priority,
	 * category surfaced and number used as label fallback.
	 */
	public function test_servicenow_incident_to_node() {
		$driver = new NV_oOS_Graphify_Remote_ServiceNow();
		$node   = $driver->incident_to_node(
			array(
				'sys_id'            => '46e5b1f0a9fe198100b9b9c0e7f9c8c9',
				'number'            => 'INC0010001',
				'short_description' => 'Email outage',
				'state'             => '2',
				'priority'          => '1',
				'category'          => 'network',
			),
			'sn'
		);
		$this->assertSame( 'incident', $node['type'] );
		$this->assertSame( 'Email outage', $node['label'] );
		$this->assertSame( 'INC0010001', $node['properties']['sn_number'] );
		$this->assertSame( '2', $node['properties']['state'] );
		$this->assertSame( '1', $node['properties']['priority'] );
		$this->assertSame( 'servicenow:incident:46e5b1f0a9fe198100b9b9c0e7f9c8c9', $node['external_id'] );
	}

	/**
	 * sys_user records map to 'person' nodes with email surfaced at the
	 * top level for entity resolution.
	 */
	public function test_servicenow_user_to_node_with_email() {
		$driver = new NV_oOS_Graphify_Remote_ServiceNow();
		$node   = $driver->user_to_node(
			array(
				'sys_id'    => 'u1',
				'name'      => 'Charlie Admin',
				'user_name' => 'cadmin',
				'email'     => 'charlie@example.com',
			),
			'sn'
		);
		$this->assertSame( 'person', $node['type'] );
		$this->assertSame( 'Charlie Admin', $node['label'] );
		$this->assertSame( 'charlie@example.com', $node['email'] );
		$this->assertSame( 'cadmin', $node['properties']['sn_username'] );
	}

	/**
	 * cmdb_ci records map to 'configuration_item' nodes carrying the
	 * sys_class_name in properties.
	 */
	public function test_servicenow_ci_to_node() {
		$driver = new NV_oOS_Graphify_Remote_ServiceNow();
		$node   = $driver->ci_to_node(
			array(
				'sys_id'             => 'ci42',
				'name'               => 'mail-prod-01',
				'sys_class_name'     => 'cmdb_ci_linux_server',
				'operational_status' => '1',
			),
			'sn'
		);
		$this->assertSame( 'configuration_item', $node['type'] );
		$this->assertSame( 'mail-prod-01', $node['label'] );
		$this->assertSame( 'cmdb_ci_linux_server', $node['properties']['sn_class_name'] );
		$this->assertSame( '1', $node['properties']['operational_status'] );
		$this->assertSame( 'servicenow:ci:ci42', $node['external_id'] );
	}

	// -------------------------------------------------------------------------
	// Registry contract
	// -------------------------------------------------------------------------

	/**
	 * Third-batch SaaS drivers register through the registry contract.
	 */
	public function test_batch3_drivers_register_through_registry() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$registry->register_driver( new NV_oOS_Graphify_Remote_M365() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_ServiceNow() );

		$ids = array();
		foreach ( $registry->get_drivers() as $d ) {
			$ids[] = $d->get_driver_id();
		}
		$this->assertContains( 'm365', $ids );
		$this->assertContains( 'servicenow', $ids );
	}
}
