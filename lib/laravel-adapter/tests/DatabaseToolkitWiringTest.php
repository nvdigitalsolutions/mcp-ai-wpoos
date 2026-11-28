<?php
/**
 * The container wiring that makes the eleven `db_*` readiness rows true.
 *
 * Proposal 167. The manifest is shared across hosts and says these tools are
 * `ready`, so every host reading it must be able to build them. That is why the
 * port, the probe and the audit reader are bound whenever the kernel is
 * installed, and why `oos.database.enabled` decides only what the port can see —
 * a disabled toolkit is a port with no connections, not a port that does not
 * exist. The tests below pin both halves of that contract.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\Database\SelectTool;
use Nvoos\Database\Domain\Contract\Database\AuditReaderInterface;
use Nvoos\Database\Domain\Contract\Database\ConnectionProbeInterface;
use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\PdoExecutor;
use Nvoos\Database\Domain\Service\Database\PdoProbe;
use Nvoos\Laravel\Database\TableAuditLog;

final class DatabaseToolkitWiringTest extends TestCase {

	use RefreshDatabase;

	/**
	 * Construction does not depend on the toggle.
	 *
	 * A host that sets `NVOOS_DATABASE_ENABLED=false` still gets a constructible
	 * port, because the readiness manifest promises one. The toggle is applied
	 * when the port is built — the connection set is empty — which is the state
	 * the next test observes.
	 */
	public function test_the_port_is_constructible_while_the_toolkit_is_switched_off(): void {
		$this->disable_toolkit();

		$port = $this->app->make( DatabaseQueryInterface::class );

		$this->assertInstanceOf( PdoExecutor::class, $port );
	}

	/**
	 * With no authenticated user the port refuses metadata, not with an exception
	 * at boot and not by leaking a catalogue.
	 */
	public function test_an_unauthenticated_request_is_refused_with_db_scope_not_allowed(): void {
		$this->assertFalse( $this->app['auth']->check() );

		$port = $this->app->make( DatabaseQueryInterface::class );

		$this->expectException( DatabaseRefusal::class );

		try {
			$port->describe( 'warehouse' );
		} catch ( DatabaseRefusal $refusal ) {
			$this->assertSame( 'db_scope_not_allowed', $refusal->getRefusalCode() );

			throw $refusal;
		}
	}

	/**
	 * The probe is bound too, or `db_test_connection` is a hollow row.
	 */
	public function test_the_privilege_probe_is_bound(): void {
		$this->assertInstanceOf(
			PdoProbe::class,
			$this->app->make( ConnectionProbeInterface::class ),
		);
	}

	/**
	 * The reader the provider binds is the ledger, and it can read the migrated
	 * table through the application connection.
	 *
	 * `TableAuditLog::tail()` swallows failures and returns `[]`, so an empty
	 * result would prove nothing. The write below is what makes the read
	 * meaningful: it only comes back if the migration, the writer and the
	 * reader's `SELECT` agree.
	 */
	public function test_the_audit_reader_is_the_ledger_and_reads_the_migrated_table(): void {
		$ledger = $this->app->make( AuditReaderInterface::class );

		$this->assertInstanceOf( TableAuditLog::class, $ledger );

		$id = $ledger->append(
			array(
				'connection' => 'warehouse',
				'action'     => 'select',
				'tool'       => 'db_select',
			)
		);

		$rows = $ledger->tail( 5 );

		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
	}

	/**
	 * A disabled toolkit answers honestly, through the tool surface.
	 *
	 * The refusal is `db_not_configured` → 404, the same code and status the
	 * WordPress host returns; the adapter's error object carries the status as
	 * its code and the domain code in `normalize()`. Nothing opens a socket, and
	 * no driver text reaches the caller.
	 */
	public function test_a_disabled_toolkit_refuses_a_read_as_not_configured(): void {
		$this->disable_toolkit();

		$tool = new SelectTool(
			$this->app->make( ErrorFactoryInterface::class ),
			$this->app->make( DatabaseQueryInterface::class ),
		);

		$result = $tool->execute( array( 'relation' => 'public.orders' ), array( 'user_id' => 7 ) );

		$this->assertInstanceOf( \Throwable::class, $result );
		$this->assertSame( 404, $result->getCode() );
		$this->assertSame( 'No database connection is configured on this site.', $result->getMessage() );

		$normalised = $this->app->make( ErrorFactoryInterface::class )->normalize( $result );

		$this->assertSame( 'wp_mcp_ai_db_not_configured', $normalised['code'] );
		$this->assertSame( 'db_not_configured', $normalised['data']['refusal_code'] ?? null );
	}

	/**
	 * An enabled toolkit with nothing declared agrees: `db_not_configured`.
	 *
	 * This is the state every fresh install is in, and it is the same refusal —
	 * "enabled" is not a promise that a connection exists.
	 */
	public function test_an_enabled_toolkit_without_connections_refuses_the_same_way(): void {
		$tool = new SelectTool(
			$this->app->make( ErrorFactoryInterface::class ),
			$this->app->make( DatabaseQueryInterface::class ),
		);

		$result = $tool->execute( array( 'relation' => 'public.orders' ), array( 'user_id' => 7 ) );

		$this->assertInstanceOf( \Throwable::class, $result );
		$this->assertSame( 404, $result->getCode() );
	}

	/**
	 * Switch the toolkit off and drop the scoped instance the provider built.
	 *
	 * The binding is `scoped`, so the instance resolved earlier in a test would
	 * otherwise outlive the config change.
	 */
	private function disable_toolkit(): void {
		$this->app['config']->set( 'oos.database.enabled', false );
		$this->app->forgetScopedInstances();
	}
}
