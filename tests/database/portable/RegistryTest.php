<?php

/**
 * Registry, audit and proposal-store tests.
 *
 * Proposal 167 sections 167.6.3, 167.6.8 and 167.6.9. The properties under test
 * are the fail-closed ones: a version that reflects grant changes, a malformed
 * principal that cannot widen access, a credential that cannot be stored in the
 * clear, and an audit record that cannot carry a bound value.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Registry, audit and proposal-store tests.
 */
#[CoversClass( WP_MCP_AI_Db_Scope_Registry::class )]
#[CoversClass( WP_MCP_AI_Db_Connection_Registry::class )]
#[CoversClass( WP_MCP_AI_Db_Audit_Log::class )]
#[CoversClass( WP_MCP_AI_Db_Proposal_Store::class )]
final class RegistryTest extends TestCase {

	/**
	 * A minimal valid scope record.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function scope_record( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => 'analyst',
				'label'      => 'Analyst',
				'connection' => 'warehouse',
				'schemas'    => array( 'reporting' ),
				'tables'     => array( 'reporting.orders' ),
				'tiers'      => array( 'metadata', 'read' ),
				'principals' => array( 'assistant:42' ),
			),
			$overrides
		);
	}

	/**
	 * Saving a changed grant bumps the version.
	 */
	public function test_scope_version_bumps_on_grant_change(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );

		$first = $registry->save( $this->scope_record() );
		$this->assertSame( 1, $first->version );

		$second = $registry->save( $this->scope_record( array( 'tables' => array( 'reporting.orders', 'reporting.customers' ) ) ) );
		$this->assertSame( 2, $second->version );
	}

	/**
	 * Re-saving identical content does not inflate the version.
	 */
	public function test_scope_version_is_stable_for_identical_save(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );

		$registry->save( $this->scope_record() );
		$again = $registry->save( $this->scope_record() );

		$this->assertSame( 1, $again->version );
	}

	/**
	 * A malformed principal is dropped, and an empty list denies everyone.
	 */
	public function test_malformed_principals_cannot_widen_access(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );
		$scope    = $registry->save( $this->scope_record( array( 'principals' => array( 'assistant', '', 'nonsense', 'user:7' ) ) ) );

		$this->assertCount( 1, $scope->principals );
		$this->assertTrue( $scope->allowsPrincipal( new PrincipalRef( 'user', '7' ) ) );
		$this->assertFalse( $scope->allowsPrincipal( new PrincipalRef( 'assistant', '42' ) ) );
	}

	/**
	 * An unbound principal cannot resolve a scope.
	 */
	public function test_unbound_principal_is_refused(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );
		$registry->save( $this->scope_record() );

		$this->expectException( DatabaseRefusal::class );

		$registry->resolve_for( new PrincipalRef( 'assistant', '99' ), 'warehouse', null, GrantTier::READ );
	}

	/**
	 * A named scope that grants a different connection is refused, not remapped.
	 */
	public function test_named_scope_must_match_connection(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );
		$registry->save( $this->scope_record() );

		$this->expectException( DatabaseRefusal::class );

		$registry->resolve_for( new PrincipalRef( 'assistant', '42' ), 'other', 'analyst', GrantTier::READ );
	}

	/**
	 * A tier the scope does not hold is refused.
	 */
	public function test_missing_tier_is_refused(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );
		$registry->save( $this->scope_record() );

		$this->expectException( DatabaseRefusal::class );

		$registry->resolve_for( new PrincipalRef( 'assistant', '42' ), 'warehouse', 'analyst', GrantTier::RAW_SQL );
	}

	/**
	 * An omitted scope resolves to the first matching grant.
	 */
	public function test_scope_resolves_by_connection_when_omitted(): void {
		$registry = new WP_MCP_AI_Db_Scope_Registry( array() );
		$registry->save( $this->scope_record() );

		$resolved = $registry->resolve_for( new PrincipalRef( 'assistant', '42' ), 'warehouse', null, GrantTier::READ );

		$this->assertSame( 'analyst', $resolved->id );
	}

	/**
	 * A plaintext credential cannot be stored.
	 */
	public function test_plaintext_credential_is_refused(): void {
		$registry = new WP_MCP_AI_Db_Connection_Registry();

		$this->expectException( DatabaseRefusal::class );

		$registry->save(
			array(
				'id'         => 'warehouse',
				'label'      => 'Warehouse',
				'engine'     => 'pgsql',
				'host'       => 'db.example.test',
				'port'       => 5432,
				'database'   => 'analytics',
				'credential' => 'hunter2',
			)
		);
	}

	/**
	 * The site database is refused at save time.
	 */
	public function test_connection_save_refuses_site_database(): void {
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_NAME' ) ) {
			$this->markTestSkipped( 'DB_HOST/DB_NAME are not defined.' );
		}

		$registry = new WP_MCP_AI_Db_Connection_Registry();

		$host = (string) DB_HOST;
		$host = preg_replace( '/:\d+$/', '', $host ) ?? $host;
		$port = 5432;

		if ( preg_match( '/:(\d+)$/', (string) DB_HOST, $matches ) ) {
			$port = (int) $matches[1];
		}

		$this->expectException( DatabaseRefusal::class );

		$registry->save(
			array(
				'id'         => 'site',
				'label'      => 'Site',
				'engine'     => 'pgsql',
				'host'       => $host,
				'port'       => $port,
				'database'   => (string) DB_NAME,
				'credential' => 'vault:site',
			)
		);
	}

	/**
	 * A saved connection fills its engine default port.
	 */
	public function test_connection_save_fills_default_port(): void {
		$registry = new WP_MCP_AI_Db_Connection_Registry();

		$saved = $registry->save(
			array(
				'id'         => 'warehouse',
				'label'      => 'Warehouse',
				'engine'     => 'pgsql',
				'host'       => 'db.example.test',
				'database'   => 'analytics',
				'credential' => 'vault:warehouse',
			)
		);

		$this->assertSame( 5432, $saved->port );
		$this->assertNull( $saved->probe );
	}

	/**
	 * An audit record never carries a bound value and the ring is bounded.
	 */
	public function test_audit_log_is_literal_free_and_bounded(): void {
		$log = new WP_MCP_AI_Db_Audit_Log( array() );

		$compiled = new CompiledStatement( 'SELECT id FROM "reporting"."orders" WHERE status = :p0', array( ':p0' => 'paid' ) );
		$id       = $log->append( array( 'action' => 'select' ) + WP_MCP_AI_Db_Audit_Log::describe_statement( $compiled ) );

		$tail = $log->tail( 10 );

		$this->assertCount( 1, $tail );
		$this->assertSame( $id, $tail[0]['id'] );
		$this->assertStringContainsString( ':p0', $tail[0]['sql_template'] );
		$this->assertStringNotContainsString( 'paid', $tail[0]['sql_template'] );
		$this->assertStringNotContainsString( 'paid', (string) wp_json_encode( $tail ) );
	}

	/**
	 * Audit filters narrow the tail.
	 */
	public function test_audit_tail_filters(): void {
		$log = new WP_MCP_AI_Db_Audit_Log( array() );

		$log->append( array( 'connection' => 'a', 'action' => 'select' ) );
		$log->append( array( 'connection' => 'b', 'action' => 'select' ) );
		$log->append( array( 'connection' => 'a', 'action' => 'raw_sql', 'refusal_code' => 'db_statement_not_admitted' ) );

		$this->assertCount( 2, $log->tail( 10, array( 'connection' => 'a' ) ) );
		$this->assertCount( 1, $log->tail( 10, array( 'refusal_code' => 'db_statement_not_admitted' ) ) );
		$this->assertSame( 1, $log->summary()['db_statement_not_admitted'] );
		$this->assertSame( 3, $log->summary()['total'] );
	}

	/**
	 * A proposal cannot be read without its sealed payload.
	 */
	public function test_proposal_without_payload_is_refused(): void {
		$store = new WP_MCP_AI_Db_Proposal_Store(
			null,
			array(
				'wpr_missing' => array(
					'id'         => 'wpr_missing',
					'expires_at' => time() + 60,
					'payload'    => '',
				),
			)
		);

		$this->expectException( DatabaseRefusal::class );

		$store->request( 'wpr_missing' );
	}

	/**
	 * An expired proposal is refused even when approved.
	 */
	public function test_expired_proposal_is_refused(): void {
		$store = new WP_MCP_AI_Db_Proposal_Store(
			null,
			array(
				'wpr_old' => array(
					'id'         => 'wpr_old',
					'expires_at' => time() - 1,
					'payload'    => 'x',
				),
			)
		);

		$this->assertFalse( $store->approve( 'wpr_old', 1 ) );

		$this->expectException( DatabaseRefusal::class );

		$store->request( 'wpr_old' );
	}

	/**
	 * A proposal never exposes its sealed payload in the public view.
	 */
	public function test_proposal_public_view_hides_payload(): void {
		if ( ! class_exists( 'WP_MCP_AI_Encryption' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Encryption is unavailable in the portable harness.' );
		}

		$store    = new WP_MCP_AI_Db_Proposal_Store( null, array() );
		$proposal = $store->create(
			array(
				'connection' => 'warehouse',
				'scope'      => 'analyst',
				'request'    => array( 'set' => array( 'status' => 'refunded' ) ),
			)
		);

		$this->assertArrayNotHasKey( 'payload', $proposal );
	}
}
