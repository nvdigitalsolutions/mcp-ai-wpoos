<?php

/**
 * PostgreSQL write-path tests — proposal, approval, drift.
 *
 * Proposal 167 phase P6. The properties under test are the ones that make a
 * write safe to review: proposing changes nothing, applying without a human
 * approval refuses, a changed scope refuses, and a changed row count refuses.
 *
 * Runs as the writer role, which holds DML. The reader role could not reach any
 * of this — gate 6 covers that.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Approved-write path.
 */
#[CoversClass( WP_MCP_AI_Db_Writer::class )]
#[CoversClass( WP_MCP_AI_Db_Write_Compiler::class )]
final class PostgresWritePathTest extends TestCase {

	use PostgresFixture;

	/**
	 * Writer principal.
	 *
	 * @var PrincipalRef
	 */
	private PrincipalRef $principal;

	/**
	 * Skip without a DSN and apply fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		self::maybe_skip_postgres();
		self::bootstrap_fixtures();

		$this->principal = new PrincipalRef( 'assistant', '42' );
	}

	/**
	 * Build a writer over the fixture.
	 *
	 * @return array{writer: WP_MCP_AI_Db_Writer, parts: array<string, mixed>}
	 */
	private function writer(): array {
		$parts = self::make_executor( 'writer', 'tenant-a' );

		$writer = new WP_MCP_AI_Db_Writer(
			$parts['connections'],
			$parts['scopes'],
			new WP_MCP_AI_Db_Catalogue( $parts['factory'], new WP_MCP_AI_Db_Array_Cache() ),
			$parts['factory'],
			$parts['audit'],
			new WP_MCP_AI_Db_Proposal_Store(),
			static fn (): ?string => 'tenant-a'
		);

		return array(
			'writer' => $writer,
			'parts'  => $parts,
		);
	}

	/**
	 * A proposal is counted by the database and changes nothing.
	 */
	public function test_propose_counts_and_does_not_write(): void {
		$built = $this->writer();

		$proposal = $built['writer']->propose(
			$this->principal,
			'warehouse',
			'writer',
			array(
				'operation' => 'update',
				'relation'  => 'reporting.orders',
				'set'       => array( 'status' => 'cancelled' ),
				'filters'   => array(
					array(
						'column' => 'order_id',
						'op'     => 'eq',
						'value'  => 1,
					),
				),
			),
			'Unit test proposal.'
		);

		$this->assertSame( 1, (int) $proposal['estimated_rows'] );
		$this->assertSame( 'proposed', $proposal['status'] );
		$this->assertStringStartsWith( 'UPDATE "reporting"."orders" SET', (string) $proposal['statement_template'] );

		$status = self::admin_pdo()->query( 'SELECT status FROM reporting.orders WHERE order_id = 1' )->fetchColumn();

		$this->assertSame( 'paid', $status, 'Proposing must not modify the row.' );
	}

	/**
	 * Applying without a human approval refuses.
	 */
	public function test_apply_without_approval_refuses(): void {
		$built    = $this->writer();
		$proposal = $this->propose_delete_order( $built['writer'], 10 );

		self::assert_refusal(
			fn () => $built['writer']->apply( $this->principal, (string) $proposal['id'] ),
			'db_approval_required'
		);
	}

	/**
	 * After approval the write applies, commits, and is counted.
	 */
	public function test_approved_write_applies_and_commits(): void {
		$built    = $this->writer();
		$proposal = $built['writer']->propose(
			$this->principal,
			'warehouse',
			'writer',
			array(
				'operation' => 'update',
				'relation'  => 'reporting.orders',
				'set'       => array( 'status' => 'cancelled' ),
				'filters'   => array(
					array(
						'column' => 'order_id',
						'op'     => 'eq',
						'value'  => 1,
					),
				),
			),
			'Approved update.'
		);

		$this->assertTrue( $built['writer']->approve( (string) $proposal['id'], 7 ) );

		try {
			$result = $built['writer']->apply( $this->principal, (string) $proposal['id'] );

			$this->assertSame( 1, (int) $result['affected_rows'] );

			$status = self::admin_pdo()->query( 'SELECT status FROM reporting.orders WHERE order_id = 1' )->fetchColumn();

			$this->assertSame( 'cancelled', $status );
		} finally {
			self::admin_pdo()->exec( "UPDATE reporting.orders SET status = 'paid' WHERE order_id = 1" );
		}
	}

	/**
	 * A scope edited after approval invalidates the proposal.
	 */
	public function test_scope_version_drift_refuses(): void {
		$built    = $this->writer();
		$proposal = $this->propose_delete_order( $built['writer'], 9 );

		$this->assertTrue( $built['writer']->approve( (string) $proposal['id'], 7 ) );

		// Re-save the scope with a wider table list: a different grant.
		$scope = $built['parts']['scopes']->get( 'writer' );
		$this->assertNotNull( $scope );

		$record                = $scope->toArray();
		$record['tables']      = array( 'reporting.orders', 'reporting.notes', 'reporting.financial' );
		$record['tables']      = array_map( 'strval', $record['tables'] );
		$built['parts']['scopes']->save( $record );

		self::assert_refusal(
			fn () => $built['writer']->apply( $this->principal, (string) $proposal['id'] ),
			'db_approval_required'
		);
	}

	/**
	 * A row-count drift between proposal and apply refuses.
	 */
	public function test_row_count_drift_refuses(): void {
		$built    = $this->writer();
		$proposal = $built['writer']->propose(
			$this->principal,
			'warehouse',
			'writer',
			array(
				'operation' => 'delete',
				'relation'  => 'reporting.orders',
				'filters'   => array(
					array(
						'column' => 'order_id',
						'op'     => 'eq',
						'value'  => 5,
					),
				),
			),
			'Approved delete.'
		);

		$this->assertTrue( $built['writer']->approve( (string) $proposal['id'], 7 ) );

		// Someone else removes the row after approval.
		self::admin_pdo()->exec( 'DELETE FROM reporting.orders WHERE order_id = 5' );

		try {
			self::assert_refusal(
				fn () => $built['writer']->apply( $this->principal, (string) $proposal['id'] ),
				'db_write_affected_mismatch'
			);
		} finally {
			self::admin_pdo()->exec(
				"INSERT INTO reporting.orders (order_id, tenant_id, status, email, total, placed_at)
				 VALUES (5, 'tenant-a', 'paid', 'a5@example.test', 5, now() - interval '5 minutes')"
			);
		}
	}

	/**
	 * Propose a single-row delete on orders.
	 *
	 * @param WP_MCP_AI_Db_Writer $writer   Writer.
	 * @param int                 $order_id Order id.
	 * @return array<string, mixed>
	 */
	private function propose_delete_order( WP_MCP_AI_Db_Writer $writer, int $order_id ): array {
		return $writer->propose(
			$this->principal,
			'warehouse',
			'writer',
			array(
				'operation' => 'delete',
				'relation'  => 'reporting.orders',
				'filters'   => array(
					array(
						'column' => 'order_id',
						'op'     => 'eq',
						'value'  => $order_id,
					),
				),
			),
			'Delete one order.'
		);
	}
}
