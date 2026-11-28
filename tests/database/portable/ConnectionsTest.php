<?php

/**
 * Connection resolver tests.
 *
 * Proposal 167 decision D11 and gate 11 (provider independence). The resolver is
 * the seam that lets one tool argument address two stores; the properties under
 * test are that an unknown namespace is refused, that a bare id never silently
 * selects a Remote Sites record, and that a provider's visibility rules are the
 * only source of a connection.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A provider that owns `fx:` ids.
 */
final class WP_MCP_AI_Test_Connection_Provider implements WP_MCP_AI_Connection_Provider_Interface {

	/**
	 * Connections this provider knows.
	 *
	 * @var array<string, ConnectionDescriptor>
	 */
	private array $connections;

	/**
	 * Constructor.
	 *
	 * @param array<string, ConnectionDescriptor> $connections Connections.
	 */
	public function __construct( array $connections ) {
		$this->connections = $connections;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'fixture';
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'fx';
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_connections( PrincipalRef $principal ): array {
		unset( $principal );

		return array_values( $this->connections );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $id ): bool {
		return str_starts_with( strtolower( trim( $id ) ), 'fx:' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function resolve( string $id, PrincipalRef $principal ): ConnectionDescriptor {
		unset( $principal );

		$bare = substr( strtolower( trim( $id ) ), 3 );

		if ( ! isset( $this->connections[ $bare ] ) ) {
			throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Fixture not found.' );
		}

		return $this->connections[ $bare ];
	}
}

/**
 * Connection resolver tests.
 */
#[CoversClass( WP_MCP_AI_Connection_Resolver::class )]
#[CoversClass( WP_MCP_AI_Native_Connection_Provider::class )]
final class ConnectionsTest extends TestCase {

	/**
	 * A descriptor.
	 *
	 * @param string $id Id.
	 */
	private function descriptor( string $id ): ConnectionDescriptor {
		return new ConnectionDescriptor(
			id: $id,
			label: $id,
			engine: ConnectionDescriptor::PGSQL,
			host: 'db.example.test',
			port: 5432,
			database: 'analytics',
			probe: new ProbeResult( reachable: true, checkedAt: time() )
		);
	}

	/**
	 * Two providers merge into one sorted list.
	 */
	public function test_providers_merge(): void {
		$resolver = new WP_MCP_AI_Connection_Resolver();

		$scope_registry = new WP_MCP_AI_Db_Scope_Registry(
			array(
				'analyst' => array(
					'id'         => 'analyst',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( '*' ),
					'tiers'      => array( 'read' ),
					'principals' => array( 'assistant:42' ),
				),
			)
		);

		$connections = new WP_MCP_AI_Db_Connection_Registry(
			null,
			$scope_registry,
			array( 'warehouse' => array( 'id' => 'warehouse', 'label' => 'Warehouse', 'engine' => 'pgsql', 'host' => 'db.example.test', 'port' => 5432, 'database' => 'analytics' ) )
		);

		$resolver->register( new WP_MCP_AI_Native_Connection_Provider( $connections ) );
		$resolver->register( new WP_MCP_AI_Test_Connection_Provider( array( 'other' => $this->descriptor( 'fx:other' ) ) ) );

		$all = $resolver->list_all( new PrincipalRef( 'assistant', '42' ) );

		$this->assertCount( 2, $all );
		$this->assertSame( 'fx:other', $all[0]->id );
		$this->assertSame( 'warehouse', $all[1]->id );
	}

	/**
	 * An unknown namespace is refused rather than treated as native.
	 */
	public function test_unknown_namespace_is_refused(): void {
		$resolver = new WP_MCP_AI_Connection_Resolver();

		$this->expectException( DatabaseRefusal::class );

		$resolver->resolve( 'xx:warehouse', new PrincipalRef( 'assistant', '42' ) );
	}

	/**
	 * A namespaced id reaches the right provider.
	 */
	public function test_namespaced_id_resolves(): void {
		$resolver = new WP_MCP_AI_Connection_Resolver();
		$resolver->register( new WP_MCP_AI_Test_Connection_Provider( array( 'other' => $this->descriptor( 'fx:other' ) ) ) );

		$resolved = $resolver->resolve( 'fx:other', new PrincipalRef( 'assistant', '42' ) );

		$this->assertSame( 'fx:other', $resolved->id );
	}

	/**
	 * A native connection without a scope is invisible and unresolvable.
	 */
	public function test_native_visibility_is_scope_derived(): void {
		$scope_registry = new WP_MCP_AI_Db_Scope_Registry( array() );
		$connections    = new WP_MCP_AI_Db_Connection_Registry(
			null,
			$scope_registry,
			array( 'warehouse' => array( 'id' => 'warehouse', 'label' => 'Warehouse', 'engine' => 'pgsql', 'host' => 'db.example.test', 'port' => 5432, 'database' => 'analytics' ) )
		);

		$provider = new WP_MCP_AI_Native_Connection_Provider( $connections );

		$this->assertSame( array(), $provider->list_connections( new PrincipalRef( 'assistant', '42' ) ) );

		$this->expectException( DatabaseRefusal::class );

		$provider->resolve( 'warehouse', new PrincipalRef( 'assistant', '42' ) );
	}

	/**
	 * A `db:` prefix resolves a native connection that a scope grants.
	 */
	public function test_db_prefix_resolves(): void {
		$scope_registry = new WP_MCP_AI_Db_Scope_Registry(
			array(
				'analyst' => array(
					'id'         => 'analyst',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( '*' ),
					'tiers'      => array( 'read' ),
					'principals' => array( 'assistant:42' ),
				),
			)
		);

		$connections = new WP_MCP_AI_Db_Connection_Registry(
			null,
			$scope_registry,
			array( 'warehouse' => array( 'id' => 'warehouse', 'label' => 'Warehouse', 'engine' => 'pgsql', 'host' => 'db.example.test', 'port' => 5432, 'database' => 'analytics' ) )
		);

		$resolver = new WP_MCP_AI_Connection_Resolver();
		$resolver->register( new WP_MCP_AI_Native_Connection_Provider( $connections ) );

		$resolved = $resolver->resolve( 'db:warehouse', new PrincipalRef( 'assistant', '42' ) );

		$this->assertSame( 'warehouse', $resolved->id );
	}
}
