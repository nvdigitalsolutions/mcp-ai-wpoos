<?php
/**
 * Tests for the second batch of Phase 3 SaaS connectors:
 * Google Drive, Jira (Atlassian), and Zendesk.
 *
 * No live HTTP requests are made — these tests exercise pure-PHP
 * transformation paths (record_to_node, *_node_id helpers, capability
 * flags, registry registration).
 *
 * @package NV_oOS_Graphify
 * @since   0.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Saas_Connectors_Batch2
 */
class Test_Graphify_Saas_Connectors_Batch2 extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Capability flags & metadata
	// -------------------------------------------------------------------------

	/**
	 * Google Drive driver advertises the full capability set.
	 */
	public function test_google_drive_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Google_Drive();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'google_drive', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_pagination'] );
		$this->assertTrue( $flags['supports_relationships'] );
	}

	/**
	 * Jira driver advertises OAuth + webhooks + incremental + pagination.
	 */
	public function test_jira_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Jira();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'jira', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_webhooks'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertContains( 'webhooks', $driver->get_capabilities() );
	}

	/**
	 * Zendesk driver advertises OAuth + webhooks + incremental + pagination.
	 */
	public function test_zendesk_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Zendesk();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'zendesk', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_webhooks'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertContains( 'webhooks', $driver->get_capabilities() );
	}

	// -------------------------------------------------------------------------
	// Google Drive — file_to_node
	// -------------------------------------------------------------------------

	/**
	 * A Drive folder mime type maps to a 'folder' node and a folder external_id.
	 */
	public function test_google_drive_folder_to_node() {
		$driver = new NV_oOS_Graphify_Remote_Google_Drive();
		$node   = $driver->file_to_node(
			array(
				'id'           => '0AbcFolder',
				'name'         => 'Reports',
				'mimeType'     => 'application/vnd.google-apps.folder',
				'modifiedTime' => '2026-04-01T10:00:00Z',
				'webViewLink'  => 'https://drive.google.com/folder/0AbcFolder',
			),
			'gd'
		);
		$this->assertSame( 'folder', $node['type'] );
		$this->assertSame( 'Reports', $node['label'] );
		$this->assertTrue( $node['properties']['is_folder'] );
		$this->assertSame( 'gdrive:folder:0abcfolder', $node['external_id'] );
		$this->assertSame( $driver->file_node_id( '0AbcFolder', 'gd' ), $node['node_id'] );
	}

	/**
	 * A Drive file maps to a 'document' node with owner email surfaced
	 * inside properties.
	 */
	public function test_google_drive_file_to_node_with_owner() {
		$driver = new NV_oOS_Graphify_Remote_Google_Drive();
		$node   = $driver->file_to_node(
			array(
				'id'           => '1FileX',
				'name'         => 'Q1 Roadmap.gdoc',
				'mimeType'     => 'application/vnd.google-apps.document',
				'modifiedTime' => '2026-04-15T10:00:00Z',
				'webViewLink'  => 'https://docs.google.com/document/d/1FileX/edit',
				'owners'       => array(
					array( 'emailAddress' => 'owner@example.com' ),
				),
			),
			'gd'
		);
		$this->assertSame( 'document', $node['type'] );
		$this->assertFalse( $node['properties']['is_folder'] );
		$this->assertSame( array( 'owner@example.com' ), $node['properties']['owners'] );
		$this->assertStringStartsWith( 'gdrive:file:', $node['external_id'] );
	}

	// -------------------------------------------------------------------------
	// Jira — *_to_node + node_id helpers
	// -------------------------------------------------------------------------

	/**
	 * Jira project payloads map to 'project' nodes keyed by numeric id.
	 */
	public function test_jira_project_to_node() {
		$driver = new NV_oOS_Graphify_Remote_Jira();
		$node   = $driver->project_to_node(
			array(
				'id'   => '10001',
				'key'  => 'ENG',
				'name' => 'Engineering',
				'self' => 'https://example.atlassian.net/rest/api/3/project/10001',
			),
			'jira'
		);
		$this->assertSame( 'project', $node['type'] );
		$this->assertSame( 'Engineering', $node['label'] );
		$this->assertSame( 'ENG', $node['properties']['jira_project_key'] );
		$this->assertSame( 'jira:project:10001', $node['external_id'] );
		$this->assertSame( $driver->project_node_id( '10001', 'jira' ), $node['node_id'] );
	}

	/**
	 * Jira issue payloads map to 'issue' nodes with status and type
	 * extracted from the nested fields object.
	 */
	public function test_jira_issue_to_node() {
		$driver = new NV_oOS_Graphify_Remote_Jira();
		$node   = $driver->issue_to_node(
			array(
				'id'     => '20001',
				'key'    => 'ENG-42',
				'self'   => 'https://example.atlassian.net/rest/api/3/issue/20001',
				'fields' => array(
					'summary'   => 'Investigate flaky tests',
					'status'    => array( 'name' => 'In Progress' ),
					'issuetype' => array( 'name' => 'Bug' ),
				),
			),
			'jira'
		);
		$this->assertSame( 'issue', $node['type'] );
		$this->assertSame( 'Investigate flaky tests', $node['label'] );
		$this->assertSame( 'In Progress', $node['properties']['status'] );
		$this->assertSame( 'Bug', $node['properties']['issuetype'] );
		$this->assertSame( 'ENG-42', $node['properties']['jira_issue_key'] );
		$this->assertSame( 'jira:issue:20001', $node['external_id'] );
	}

	/**
	 * Jira user payloads surface email at the top level for entity
	 * resolution.
	 */
	public function test_jira_user_to_node_with_email() {
		$driver = new NV_oOS_Graphify_Remote_Jira();
		$node   = $driver->user_to_node(
			array(
				'accountId'    => '5b10ac8d82e05b22cc7d4ef5',
				'displayName'  => 'Alice Engineer',
				'emailAddress' => 'alice@example.com',
			),
			'jira'
		);
		$this->assertSame( 'person', $node['type'] );
		$this->assertSame( 'Alice Engineer', $node['label'] );
		$this->assertSame( 'alice@example.com', $node['email'] );
		$this->assertStringStartsWith( 'jira:user:', $node['external_id'] );
	}

	// -------------------------------------------------------------------------
	// Zendesk — *_to_node + node_id helpers
	// -------------------------------------------------------------------------

	/**
	 * Zendesk ticket payloads map to 'ticket' nodes with status / priority.
	 */
	public function test_zendesk_ticket_to_node() {
		$driver = new NV_oOS_Graphify_Remote_Zendesk();
		$node   = $driver->ticket_to_node(
			array(
				'id'       => 7,
				'subject'  => 'Cannot log in',
				'status'   => 'open',
				'priority' => 'high',
				'type'     => 'incident',
				'url'      => 'https://acme.zendesk.com/api/v2/tickets/7.json',
			),
			'zd'
		);
		$this->assertSame( 'ticket', $node['type'] );
		$this->assertSame( 'Cannot log in', $node['label'] );
		$this->assertSame( 'open', $node['properties']['status'] );
		$this->assertSame( 'high', $node['properties']['priority'] );
		$this->assertSame( 'zendesk:ticket:7', $node['external_id'] );
		$this->assertSame( $driver->ticket_node_id( '7', 'zd' ), $node['node_id'] );
	}

	/**
	 * Zendesk user payloads surface email at the top level.
	 */
	public function test_zendesk_user_to_node_with_email() {
		$driver = new NV_oOS_Graphify_Remote_Zendesk();
		$node   = $driver->user_to_node(
			array(
				'id'    => 42,
				'name'  => 'Bob Customer',
				'email' => 'bob@example.com',
				'role'  => 'end-user',
			),
			'zd'
		);
		$this->assertSame( 'person', $node['type'] );
		$this->assertSame( 'Bob Customer', $node['label'] );
		$this->assertSame( 'bob@example.com', $node['email'] );
		$this->assertSame( 'end-user', $node['properties']['role'] );
		$this->assertSame( 'zendesk:user:42', $node['external_id'] );
	}

	/**
	 * Zendesk organization payloads with domain_names populate the URL
	 * field with a usable https://… link.
	 */
	public function test_zendesk_org_to_node_with_domain() {
		$driver = new NV_oOS_Graphify_Remote_Zendesk();
		$node   = $driver->org_to_node(
			array(
				'id'           => 99,
				'name'         => 'Acme Corp',
				'domain_names' => array( 'acme.example' ),
				'url'          => 'https://acme.zendesk.com/api/v2/organizations/99.json',
			),
			'zd'
		);
		$this->assertSame( 'organization', $node['type'] );
		$this->assertSame( 'Acme Corp', $node['label'] );
		$this->assertSame( 'https://acme.example', $node['url'] );
		$this->assertSame( 'zendesk:org:99', $node['external_id'] );
		$this->assertSame( array( 'acme.example' ), $node['properties']['domain_names'] );
	}

	// -------------------------------------------------------------------------
	// Registry contract
	// -------------------------------------------------------------------------

	/**
	 * Second-batch SaaS drivers register through the registry contract.
	 */
	public function test_batch2_drivers_register_through_registry() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$registry->register_driver( new NV_oOS_Graphify_Remote_Google_Drive() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_Jira() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_Zendesk() );

		$ids = array();
		foreach ( $registry->get_drivers() as $d ) {
			$ids[] = $d->get_driver_id();
		}
		$this->assertContains( 'google_drive', $ids );
		$this->assertContains( 'jira', $ids );
		$this->assertContains( 'zendesk', $ids );
	}
}
