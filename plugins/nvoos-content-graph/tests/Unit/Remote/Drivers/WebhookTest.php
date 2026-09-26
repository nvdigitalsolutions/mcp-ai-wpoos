<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Remote\Drivers;

use NvoosContentGraph\Remote\Drivers\Webhook;
use WP_UnitTestCase;

/**
 * Unit tests for the webhook receiver driver.
 *
 * Pure in-process tests — no HTTP involved.
 *
 * @since 1.0.9
 */
class WebhookTest extends WP_UnitTestCase {

	/**
	 * Build a configured driver instance.
	 *
	 * @param array<string,mixed> $config Config overrides.
	 * @return Webhook
	 */
	private function driver( array $config = array() ): Webhook {
		$driver = new Webhook();
		$driver->setConfig(
			array_merge(
				array(
					'_slug'          => 'wh_test',
					'webhook_secret' => 'supersecret',
				),
				$config
			)
		);
		return $driver;
	}

	/**
	 * A correctly computed HMAC signature verifies, with or without the
	 * "sha256=" prefix, and a wrong signature is rejected.
	 *
	 * @return void
	 */
	public function test_verify_signature_accepts_plain_and_prefixed(): void {
		$body   = '{"event":"created"}';
		$secret = 'supersecret';
		$hex    = hash_hmac( 'sha256', $body, $secret );

		$driver = $this->driver();

		$this->assertTrue( $driver->verifySignature( $body, $hex ) );
		$this->assertTrue( $driver->verifySignature( $body, 'sha256=' . $hex ) );
		$this->assertFalse( $driver->verifySignature( $body, str_repeat( '0', 64 ) ) );
		$this->assertFalse( $driver->verifySignature( $body, '' ) );
	}

	/**
	 * A missing secret rejects every signature.
	 *
	 * @return void
	 */
	public function test_verify_signature_requires_secret(): void {
		$driver = $this->driver( array( 'webhook_secret' => '' ) );

		$this->assertFalse( $driver->verifySignature( '{"a":1}', hash_hmac( 'sha256', '{"a":1}', 'x' ) ) );
	}

	/**
	 * A records array at the configured path is ingested with the field map.
	 *
	 * @return void
	 */
	public function test_ingest_payload_maps_records_at_path(): void {
		$body = wp_json_encode(
			array(
				'data' => array(
					array(
						'id'       => 7,
						'name'     => 'Seven',
						'homepage' => 'https://seven.example/',
					),
					array(
						'id'   => 8,
						'name' => 'Eight',
					),
				),
			)
		);

		$nodes = $this->driver(
			array(
				'records_path' => 'data',
				'field_map'    => array(
					'id'    => 'id',
					'label' => 'name',
					'url'   => 'homepage',
					'type'  => 'contact',
				),
			)
		)->ingestPayload( $body );

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'remote_wh_test_7', $nodes[0]['node_id'] );
		$this->assertSame( 'Seven', $nodes[0]['label'] );
		$this->assertSame( 'contact', $nodes[0]['type'] );
		$this->assertSame( 'https://seven.example/', $nodes[0]['url'] );
	}

	/**
	 * A single JSON object is wrapped and ingested as one record.
	 *
	 * @return void
	 */
	public function test_ingest_wraps_single_object(): void {
		$nodes = $this->driver()->ingestPayload( '{"name":"Solo"}' );

		$this->assertCount( 1, $nodes );
		$this->assertSame( 'Solo', $nodes[0]['label'] );
	}

	/**
	 * Invalid JSON yields no nodes.
	 *
	 * @return void
	 */
	public function test_ingest_invalid_json_returns_empty(): void {
		$this->assertSame( array(), $this->driver()->ingestPayload( 'not-json' ) );
	}

	/**
	 * testConnection requires a configured secret.
	 *
	 * @return void
	 */
	public function test_test_connection_requires_secret(): void {
		$missing = $this->driver( array( 'webhook_secret' => '' ) )->testConnection();
		$present = $this->driver()->testConnection();

		$this->assertFalse( $missing['success'] );
		$this->assertTrue( $present['success'] );
	}
}
