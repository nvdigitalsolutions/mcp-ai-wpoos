<?php
/**
 * Tests for the Phase 3 SaaS connectors (HubSpot, GitHub, Slack).
 *
 * No live HTTP requests are made — these tests exercise pure-PHP
 * transformation paths (record_to_node, *_node_id helpers, signature
 * verification, capability flags, registry gating).
 *
 * @package NV_oOS_Graphify
 * @since   0.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Saas_Connectors
 */
class Test_Graphify_Saas_Connectors extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Capability flags & metadata
	// -------------------------------------------------------------------------

	/**
	 * HubSpot driver advertises itself as OAuth + incremental + webhooks +
	 * pagination + relationships.
	 */
	public function test_hubspot_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_HubSpot();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'hubspot', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_webhooks'] );
		$this->assertTrue( $flags['supports_pagination'] );
		$this->assertTrue( $flags['supports_relationships'] );
	}

	/**
	 * GitHub driver advertises the same five capabilities.
	 */
	public function test_github_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_GitHub();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'github', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_oauth'] );
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_pagination'] );
	}

	/**
	 * Slack driver advertises webhook capability and exposes 'webhooks'
	 * in its legacy capabilities array (so the enricher's existing webhook
	 * dispatch path picks it up).
	 */
	public function test_slack_capability_flags_and_capabilities() {
		$driver = new NV_oOS_Graphify_Remote_Slack();
		$flags  = $driver->get_capability_flags();
		$this->assertSame( 'slack', $driver->get_driver_id() );
		$this->assertTrue( $flags['supports_webhooks'] );
		$this->assertContains( 'webhooks', $driver->get_capabilities() );
	}

	// -------------------------------------------------------------------------
	// HubSpot — record_to_node
	// -------------------------------------------------------------------------

	/**
	 * HubSpot Contact records map to person nodes with email surfaced at
	 * the top level so the entity resolver can match them.
	 */
	public function test_hubspot_contact_record_to_node() {
		$driver = new NV_oOS_Graphify_Remote_HubSpot();
		$node   = $driver->record_to_node(
			'contacts',
			array(
				'id'         => '101',
				'properties' => array(
					'firstname' => 'Ada',
					'lastname'  => 'Lovelace',
					'email'     => 'ada@example.com',
				),
			),
			'hubspot'
		);
		$this->assertSame( 'person', $node['type'] );
		$this->assertSame( 'Ada Lovelace', $node['label'] );
		$this->assertSame( 'ada@example.com', $node['email'] );
		$this->assertSame( 'hubspot:contacts:101', $node['external_id'] );
		$this->assertSame( 'remote_hubspot_contacts_101', $node['node_id'] );
	}

	/**
	 * HubSpot Company records map to organization nodes with the company
	 * domain surfaced as a URL.
	 */
	public function test_hubspot_company_record_to_node() {
		$driver = new NV_oOS_Graphify_Remote_HubSpot();
		$node   = $driver->record_to_node(
			'companies',
			array(
				'id'         => '500',
				'properties' => array(
					'name'   => 'Acme Inc.',
					'domain' => 'acme.example',
				),
			),
			'hubspot'
		);
		$this->assertSame( 'organization', $node['type'] );
		$this->assertSame( 'Acme Inc.', $node['label'] );
		$this->assertSame( 'https://acme.example', $node['url'] );
		$this->assertSame( 'hubspot:companies:500', $node['external_id'] );
	}

	/**
	 * HubSpot deals fall back to dealname or 'deal:<id>'.
	 */
	public function test_hubspot_deal_label_fallback() {
		$driver = new NV_oOS_Graphify_Remote_HubSpot();
		$node   = $driver->record_to_node(
			'deals',
			array(
				'id'         => '7',
				'properties' => array(),
			),
			'hub'
		);
		$this->assertSame( 'deal', $node['type'] );
		$this->assertSame( 'deal:7', $node['label'] );
	}

	// -------------------------------------------------------------------------
	// GitHub — *_to_node + node_id helpers
	// -------------------------------------------------------------------------

	/**
	 * GitHub repo payloads map to a repository node with stable IDs.
	 */
	public function test_github_repo_to_node() {
		$driver = new NV_oOS_Graphify_Remote_GitHub();
		$node   = $driver->repo_to_node(
			array(
				'id'               => 1296269,
				'full_name'        => 'octocat/Hello-World',
				'html_url'         => 'https://github.com/octocat/Hello-World',
				'description'      => 'My first repository',
				'language'         => 'PHP',
				'stargazers_count' => 42,
				'forks_count'      => 5,
			),
			'gh'
		);
		$this->assertSame( 'repository', $node['type'] );
		$this->assertSame( 'octocat/Hello-World', $node['label'] );
		$this->assertSame( 42, $node['properties']['stars'] );
		$this->assertSame( 'github:repo:1296269', $node['external_id'] );
		$this->assertSame( $driver->repo_node_id( 'octocat/Hello-World', 'gh' ), $node['node_id'] );
	}

	/**
	 * GitHub user payloads map to person nodes keyed by login.
	 */
	public function test_github_user_to_node_and_id_helpers() {
		$driver = new NV_oOS_Graphify_Remote_GitHub();
		$node   = $driver->user_to_node(
			array(
				'login'    => 'octocat',
				'html_url' => 'https://github.com/octocat',
			),
			'gh'
		);
		$this->assertSame( 'person', $node['type'] );
		$this->assertSame( 'octocat', $node['label'] );
		$this->assertSame( $driver->user_node_id( 'octocat', 'gh' ), $node['node_id'] );
		$this->assertSame( 'github:user:octocat', $node['external_id'] );
	}

	/**
	 * Issues and PRs use distinct external_id prefixes and node_id
	 * suffixes so they don't collide.
	 */
	public function test_github_issue_vs_pr_node_separation() {
		$driver  = new NV_oOS_Graphify_Remote_GitHub();
		$payload = array(
			'id'       => 999,
			'title'    => 'Bug',
			'number'   => 12,
			'state'    => 'open',
			'html_url' => 'https://github.com/octocat/Hello-World/issues/12',
		);

		$issue_node = $driver->issue_to_node( $payload, 'octocat/Hello-World', 'gh', false );
		$pr_node    = $driver->issue_to_node( $payload, 'octocat/Hello-World', 'gh', true );

		$this->assertSame( 'issue', $issue_node['type'] );
		$this->assertSame( 'pull_request', $pr_node['type'] );
		$this->assertNotSame( $issue_node['node_id'], $pr_node['node_id'] );
		$this->assertStringStartsWith( 'github:issue:', $issue_node['external_id'] );
		$this->assertStringStartsWith( 'github:pr:', $pr_node['external_id'] );
	}

	// -------------------------------------------------------------------------
	// Slack — channel/user mapping + signature verification
	// -------------------------------------------------------------------------

	/**
	 * Slack channel payloads become channel nodes with a #-prefixed label.
	 */
	public function test_slack_channel_to_node() {
		$driver = new NV_oOS_Graphify_Remote_Slack();
		$node   = $driver->channel_to_node(
			array(
				'id'          => 'C123',
				'name'        => 'general',
				'is_private'  => false,
				'is_archived' => false,
				'topic'       => array( 'value' => 'company-wide announcements' ),
			),
			'slack'
		);
		$this->assertSame( 'channel', $node['type'] );
		$this->assertSame( '#general', $node['label'] );
		$this->assertSame( 'remote_slack_channel_c123', $node['node_id'] );
		$this->assertSame( 'slack:channel:c123', $node['external_id'] );
		$this->assertSame( 'company-wide announcements', $node['properties']['topic'] );
	}

	/**
	 * Slack user payloads with a profile email surface email at the top
	 * level for entity resolution.
	 */
	public function test_slack_user_to_node_with_email() {
		$driver = new NV_oOS_Graphify_Remote_Slack();
		$node   = $driver->user_to_node(
			array(
				'id'        => 'U999',
				'real_name' => 'Grace Hopper',
				'name'      => 'grace',
				'profile'   => array( 'email' => 'grace@example.com' ),
			),
			'slack'
		);
		$this->assertSame( 'person', $node['type'] );
		$this->assertSame( 'Grace Hopper', $node['label'] );
		$this->assertSame( 'grace@example.com', $node['email'] );
		$this->assertSame( 'slack:user:u999', $node['external_id'] );
	}

	/**
	 * Slack signature verification accepts a correct HMAC, rejects an
	 * altered body, and rejects a stale timestamp.
	 */
	public function test_slack_signature_verification() {
		$driver = new NV_oOS_Graphify_Remote_Slack();
		$driver->set_config( array( 'webhook_secret' => 'secret-test-value' ) );

		$body      = '{"event":"hello"}';
		$timestamp = (string) time();
		$expected  = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, 'secret-test-value' );

		$this->assertTrue( $driver->verify_slack_signature( $body, $timestamp, $expected ) );
		$this->assertFalse( $driver->verify_slack_signature( '{"event":"tampered"}', $timestamp, $expected ) );

		// Stale timestamp (>5 min in the past) is rejected.
		$old_timestamp = (string) ( time() - 3600 );
		$old_signature = 'v0=' . hash_hmac( 'sha256', 'v0:' . $old_timestamp . ':' . $body, 'secret-test-value' );
		$this->assertFalse( $driver->verify_slack_signature( $body, $old_timestamp, $old_signature ) );
	}

	/**
	 * Empty signing secret always returns false (fail-closed).
	 */
	public function test_slack_signature_fails_closed_without_secret() {
		$driver = new NV_oOS_Graphify_Remote_Slack();
		$driver->set_config( array() );
		$this->assertFalse( $driver->verify_slack_signature( 'body', (string) time(), 'v0=anything' ) );
	}

	// -------------------------------------------------------------------------
	// Registry gating — Phase 3 drivers only register when Pro is available
	// -------------------------------------------------------------------------

	/**
	 * SaaS drivers can be programmatically registered through the registry
	 * regardless of the Pro gate (verifies the registry contract — the gate
	 * itself is a separate hook in the bootstrap path).
	 */
	public function test_saas_drivers_register_through_registry() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$registry->register_driver( new NV_oOS_Graphify_Remote_HubSpot() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_GitHub() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_Slack() );

		$drivers = $registry->get_drivers();
		$ids     = array();
		foreach ( $drivers as $d ) {
			$ids[] = $d->get_driver_id();
		}
		$this->assertContains( 'hubspot', $ids );
		$this->assertContains( 'github', $ids );
		$this->assertContains( 'slack', $ids );
	}
}
