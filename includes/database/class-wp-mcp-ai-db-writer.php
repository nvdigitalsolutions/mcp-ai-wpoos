<?php
/**
 * The two-phase write path.
 *
 * Proposal 167 section 167.6.9 and phase P6. A write is proposed, approved by a
 * human, then applied — and each phase has a different job:
 *
 *  - **propose** compiles the structured request and asks the database how many
 *    rows it would touch. Nothing is modified. The estimate is stored so an
 *    approver sees the database's answer, not the model's guess.
 *  - **apply** refuses without an approval, re-resolves the scope (a scope edited
 *    between propose and apply is a different grant and must not silently apply),
 *    re-counts inside the write transaction, and refuses if either the scope
 *    version or the row count moved.
 *
 * ## What makes this safe
 *
 * The write statement is compiled from the catalogue: identifiers are resolved,
 * values are bound, and the scope's row filter is always ANDed in. There is no
 * path that takes caller-authored SQL. The database role remains the final
 * authority: a role without `UPDATE` is refused by the server, and that refusal
 * is mapped, not retried.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\ErrorMapper;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

/**
 * Proposes and applies writes.
 */
final class WP_MCP_AI_Db_Writer {

	/**
	 * Connections.
	 *
	 * @var WP_MCP_AI_Db_Connection_Registry
	 */
	private WP_MCP_AI_Db_Connection_Registry $connections;

	/**
	 * Scopes.
	 *
	 * @var WP_MCP_AI_Db_Scope_Registry
	 */
	private WP_MCP_AI_Db_Scope_Registry $scopes;

	/**
	 * Catalogue.
	 *
	 * @var WP_MCP_AI_Db_Catalogue
	 */
	private WP_MCP_AI_Db_Catalogue $catalogue;

	/**
	 * Factory.
	 *
	 * @var WP_MCP_AI_Db_Connection_Factory
	 */
	private WP_MCP_AI_Db_Connection_Factory $factory;

	/**
	 * Audit log.
	 *
	 * @var WP_MCP_AI_Db_Audit_Log
	 */
	private WP_MCP_AI_Db_Audit_Log $audit;

	/**
	 * Proposals.
	 *
	 * @var WP_MCP_AI_Db_Proposal_Store
	 */
	private WP_MCP_AI_Db_Proposal_Store $proposals;

	/**
	 * Tenant resolver.
	 *
	 * @var Closure|null
	 */
	private ?Closure $tenant;

	/**
	 * Compiler.
	 *
	 * @var WP_MCP_AI_Db_Write_Compiler
	 */
	private WP_MCP_AI_Db_Write_Compiler $compiler;

	/**
	 * Error mapper.
	 *
	 * @var ErrorMapper
	 */
	private ErrorMapper $errors;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Db_Connection_Registry $connections Connections.
	 * @param WP_MCP_AI_Db_Scope_Registry      $scopes      Scopes.
	 * @param WP_MCP_AI_Db_Catalogue           $catalogue   Catalogue.
	 * @param WP_MCP_AI_Db_Connection_Factory  $factory     Factory.
	 * @param WP_MCP_AI_Db_Audit_Log           $audit       Audit log.
	 * @param WP_MCP_AI_Db_Proposal_Store      $proposals   Proposals.
	 * @param Closure|null                     $tenant      Tenant resolver.
	 */
	public function __construct(
		WP_MCP_AI_Db_Connection_Registry $connections,
		WP_MCP_AI_Db_Scope_Registry $scopes,
		WP_MCP_AI_Db_Catalogue $catalogue,
		WP_MCP_AI_Db_Connection_Factory $factory,
		WP_MCP_AI_Db_Audit_Log $audit,
		WP_MCP_AI_Db_Proposal_Store $proposals,
		?Closure $tenant = null
	) {
		$this->connections = $connections;
		$this->scopes      = $scopes;
		$this->catalogue   = $catalogue;
		$this->factory     = $factory;
		$this->audit       = $audit;
		$this->proposals   = $proposals;
		$this->tenant      = $tenant;
		$this->compiler    = new WP_MCP_AI_Db_Write_Compiler();
		$this->errors      = new ErrorMapper();
	}

	/**
	 * Propose a write.
	 *
	 * @param PrincipalRef         $principal   Principal.
	 * @param string               $connection  Connection id.
	 * @param string               $scope_id    Scope id, or '' for the only grant.
	 * @param array<string, mixed> $request     `{operation, relation, set, filters}`.
	 * @param string               $rationale   Why the change is wanted.
	 * @return array<string, mixed> Proposal with the database's row estimate.
	 * @throws DatabaseRefusal When the request cannot be proposed.
	 */
	public function propose(
		PrincipalRef $principal,
		string $connection,
		string $scope_id,
		array $request,
		string $rationale
	): array {
		$descriptor = $this->connections->get_or_refuse( $connection );
		$scope      = $this->scopes->resolve_for(
			$principal,
			$descriptor->id,
			'' !== $scope_id ? $scope_id : null,
			GrantTier::PROPOSE_WRITE
		);
		$limits     = $scope->limitsOver( new DatabaseLimits() );

		if ( ! $limits->writesPossible() ) {
			throw DatabaseRefusal::of( RefusalCode::TIER_REQUIRED, 'The scope does not permit any write.' );
		}

		$operation = strtolower( (string) ( $request['operation'] ?? '' ) );
		$relation  = (string) ( $request['relation'] ?? '' );
		$set       = is_array( $request['set'] ?? null ) ? $request['set'] : array();
		$filters   = $this->filters( $request['filters'] ?? array() );

		$catalogue = $this->catalogue->load( $descriptor, $scope->schemas )->only( $scope );
		$compiled  = $this->compiler->compile(
			$scope,
			$catalogue,
			$relation,
			$operation,
			$set,
			$filters,
			$this->tenant_for( $scope ),
			$this->factory->engine( $descriptor )->name()
		);

		$estimate = $this->count( $descriptor, $scope, $limits, $compiled['count'] );

		if ( $estimate < 1 ) {
			throw DatabaseRefusal::of( RefusalCode::STATEMENT_NOT_ADMITTED, 'No row matches the proposed change.' );
		}

		if ( $estimate > $limits->maxAffectedRows ) {
			throw DatabaseRefusal::of(
				RefusalCode::LIMIT_EXCEEDED,
				sprintf( 'The change would affect %d rows; the cap is %d.', $estimate, $limits->maxAffectedRows )
			);
		}

		$proposal = $this->proposals->create(
			array(
				'connection'         => $descriptor->id,
				'scope'              => $scope->id,
				'scope_version'      => $scope->version,
				'principal'          => $principal->key(),
				'operation'          => $operation,
				'relation'           => $compiled['table']->key(),
				'rationale'          => $rationale,
				'statement_hash'     => $compiled['write']->fingerprint(),
				'statement_template' => $compiled['write']->template(),
				'estimated_rows'     => $estimate,
				'request'            => array(
					'operation' => $operation,
					'relation'  => $compiled['table']->key(),
					'set'       => $set,
					'filters'   => $request['filters'] ?? array(),
				),
			)
		);

		$this->audit->append(
			array(
				'connection'    => $descriptor->id,
				'scope'         => $scope->id,
				'scope_version' => $scope->version,
				'principal'     => $principal->key(),
				'action'        => 'propose_write',
				'tool'          => 'db_propose_write',
				'relation'      => $compiled['table']->key(),
				'row_count'     => $estimate,
				'detail'        => 'proposal=' . ( $proposal['id'] ?? '' ),
			) + WP_MCP_AI_Db_Audit_Log::describe_statement( $compiled['write'] )
		);

		return array_merge(
			$proposal,
			array(
				'estimated_rows'     => $estimate,
				'statement_template' => $compiled['write']->template(),
			)
		);
	}

	/**
	 * Approve a proposal as a human.
	 *
	 * @param string $proposal_id Proposal id.
	 * @param int    $user_id     Approving user.
	 */
	public function approve( string $proposal_id, int $user_id ): bool {
		return $this->proposals->approve( $proposal_id, $user_id );
	}

	/**
	 * Apply an approved proposal.
	 *
	 * @param PrincipalRef $principal   Principal.
	 * @param string       $proposal_id Proposal id.
	 * @return array<string, mixed> Apply summary.
	 * @throws DatabaseRefusal When the proposal is unapproved, expired, or drifted.
	 */
	public function apply( PrincipalRef $principal, string $proposal_id ): array {
		$proposal = $this->proposals->get( $proposal_id );

		if ( null === $proposal ) {
			throw DatabaseRefusal::of( RefusalCode::APPROVAL_REQUIRED, 'Proposal not found: ' . $proposal_id );
		}

		if ( (string) ( $proposal['principal'] ?? '' ) !== $principal->key() ) {
			throw DatabaseRefusal::of( RefusalCode::APPROVAL_REQUIRED, 'Proposal belongs to another principal.' );
		}

		if ( 'approved' !== (string) ( $proposal['status'] ?? '' ) || (int) ( $proposal['approved_by'] ?? 0 ) < 1 ) {
			throw DatabaseRefusal::of( RefusalCode::APPROVAL_REQUIRED, 'Proposal has not been approved by a human.' );
		}

		$request    = $this->proposals->request( $proposal_id );
		$descriptor = $this->connections->get_or_refuse( (string) $proposal['connection'] );
		$scope      = $this->scopes->get_or_refuse( (string) $proposal['scope'] );

		( new \Nvoos\Database\Domain\Service\Database\ScopePolicy() )->assertTier( $scope, GrantTier::APPLY_WRITE );

		if ( (int) ( $proposal['scope_version'] ?? 0 ) !== $scope->version ) {
			throw DatabaseRefusal::of(
				RefusalCode::APPROVAL_REQUIRED,
				'The scope changed after the proposal was approved; propose again.'
			);
		}

		$this->assert_servable( $descriptor, $scope );

		$limits    = $scope->limitsOver( new DatabaseLimits() );
		$catalogue = $this->catalogue->load( $descriptor, $scope->schemas )->only( $scope );
		$compiled  = $this->compiler->compile(
			$scope,
			$catalogue,
			(string) ( $request['relation'] ?? '' ),
			(string) ( $request['operation'] ?? '' ),
			is_array( $request['set'] ?? null ) ? $request['set'] : array(),
			$this->filters( $request['filters'] ?? array() ),
			$this->tenant_for( $scope ),
			$this->factory->engine( $descriptor )->name()
		);

		if ( $compiled['write']->fingerprint() !== (string) ( $proposal['statement_hash'] ?? '' ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::WRITE_AFFECTED_MISMATCH,
				'The compiled statement no longer matches the approved proposal.'
			);
		}

		$engine  = $this->factory->engine( $descriptor );
		$pdo     = $this->factory->open( $descriptor );
		$started = microtime( true );

		try {
			$pdo->exec( $engine->begin_write_statement() );

			foreach ( $engine->write_session_statements( $descriptor, $scope, $limits, 'nvoos:write:' . $proposal_id, $this->tenant_for( $scope ) ) as $session ) {
				$statement = $pdo->prepare( $session->sql );
				$statement->execute( $session->bindings );
				$statement->closeCursor();
			}

			$current = $this->execute_count( $pdo, $compiled['count'] );

			if ( (int) ( $proposal['estimated_rows'] ?? 0 ) !== $current ) {
				throw DatabaseRefusal::of(
					RefusalCode::WRITE_AFFECTED_MISMATCH,
					sprintf(
						'The matching row count changed from %d to %d since the proposal.',
						(int) ( $proposal['estimated_rows'] ?? 0 ),
						$current
					)
				);
			}

			$statement = $pdo->prepare( $compiled['write']->sql );
			$statement->execute( $compiled['write']->bindings );
			$affected = $statement->rowCount();
			$statement->closeCursor();

			if ( $affected !== $current ) {
				throw DatabaseRefusal::of(
					RefusalCode::WRITE_AFFECTED_MISMATCH,
					sprintf( 'The write affected %d rows but %d were expected.', $affected, $current )
				);
			}

			$pdo->commit();
		} catch ( DatabaseRefusal $refusal ) {
			$this->rollback( $pdo );
			$this->audit_refusal( $descriptor, $scope, $principal, $compiled['write'], $refusal, $proposal_id );

			throw $refusal;
		} catch ( PDOException $error ) {
			$this->rollback( $pdo );

			$refusal = DatabaseRefusal::wrap(
				$this->errors->fromThrowable( $error, $descriptor->engine ),
				$error,
				$this->errors->auditDetail( $error )
			);
			$this->audit_refusal( $descriptor, $scope, $principal, $compiled['write'], $refusal, $proposal_id );

			throw $refusal;
		}

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		$this->proposals->consume( $proposal_id );

		$this->audit->append(
			array(
				'connection'    => $descriptor->id,
				'scope'         => $scope->id,
				'scope_version' => $scope->version,
				'principal'     => $principal->key(),
				'action'        => 'apply_write',
				'tool'          => 'db_apply_write',
				'relation'      => $compiled['table']->key(),
				'row_count'     => $affected,
				'elapsed_ms'    => $elapsed,
				'detail'        => 'proposal=' . $proposal_id,
			) + WP_MCP_AI_Db_Audit_Log::describe_statement( $compiled['write'] )
		);

		return array(
			'success'       => true,
			'proposal_id'   => $proposal_id,
			'connection'    => $descriptor->id,
			'relation'      => $compiled['table']->key(),
			'operation'     => (string) ( $request['operation'] ?? '' ),
			'affected_rows' => $affected,
			'elapsed_ms'    => $elapsed,
		);
	}

	/**
	 * Count matching rows in a read-only transaction.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param DatabaseLimits       $limits     Caps.
	 * @param CompiledStatement    $count      Count statement.
	 * @throws DatabaseRefusal When the count query fails.
	 */
	private function count(
		ConnectionDescriptor $descriptor,
		ScopeDescriptor $scope,
		DatabaseLimits $limits,
		CompiledStatement $count
	): int {
		$engine = $this->factory->engine( $descriptor );
		$pdo    = $this->factory->open( $descriptor );

		try {
			$pdo->exec( $engine->begin_statement() );

			foreach ( $engine->session_statements( $descriptor, $scope, $limits, 'nvoos:propose', $this->tenant_for( $scope ) ) as $session ) {
				$statement = $pdo->prepare( $session->sql );
				$statement->execute( $session->bindings );
				$statement->closeCursor();
			}

			return $this->execute_count( $pdo, $count );
		} catch ( PDOException $error ) {
			throw DatabaseRefusal::wrap(
				$this->errors->fromThrowable( $error, $descriptor->engine ),
				$error,
				$this->errors->auditDetail( $error )
			);
		} finally {
			$this->rollback( $pdo );
		}
	}

	/**
	 * Execute a count statement.
	 *
	 * @param PDO               $pdo   Handle.
	 * @param CompiledStatement $count Count statement.
	 */
	private function execute_count( PDO $pdo, CompiledStatement $count ): int {
		$statement = $pdo->prepare( $count->sql );
		$statement->execute( $count->bindings );
		$value = $statement->fetchColumn();
		$statement->closeCursor();

		return (int) $value;
	}

	/**
	 * Refuse a connection that has not passed its probe.
	 *
	 * @param ConnectionDescriptor $connection Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @throws DatabaseRefusal When the connection has no successful probe.
	 */
	private function assert_servable( ConnectionDescriptor $connection, ScopeDescriptor $scope ): void {
		unset( $scope );

		if ( null === $connection->probe || ! $connection->probe->reachable ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'Connection ' . $connection->id . ' has no successful probe.'
			);
		}
	}

	/**
	 * Roll back if a transaction is open.
	 *
	 * @param PDO $pdo Handle.
	 */
	private function rollback( PDO $pdo ): void {
		try {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Audit a failed apply.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param PrincipalRef         $principal  Principal.
	 * @param CompiledStatement    $statement  Statement.
	 * @param DatabaseRefusal      $refusal    Refusal.
	 * @param string               $proposal   Proposal id.
	 */
	private function audit_refusal(
		ConnectionDescriptor $descriptor,
		ScopeDescriptor $scope,
		PrincipalRef $principal,
		CompiledStatement $statement,
		DatabaseRefusal $refusal,
		string $proposal
	): void {
		$this->audit->append(
			array(
				'connection'    => $descriptor->id,
				'scope'         => $scope->id,
				'scope_version' => $scope->version,
				'principal'     => $principal->key(),
				'action'        => 'apply_write',
				'tool'          => 'db_apply_write',
				'refusal_code'  => $refusal->getRefusalCode(),
				'detail'        => 'proposal=' . $proposal,
			) + WP_MCP_AI_Db_Audit_Log::describe_statement( $statement )
		);
	}

	/**
	 * Normalise filter arrays into clauses.
	 *
	 * @param mixed $filters Raw filters.
	 * @return list<FilterClause>
	 */
	private function filters( mixed $filters ): array {
		$clauses = array();

		foreach ( (array) $filters as $filter ) {
			if ( is_array( $filter ) ) {
				$clauses[] = FilterClause::fromArray( $filter );
			}
		}

		return $clauses;
	}

	/**
	 * The tenant value for a scope, when it needs one.
	 *
	 * @param ScopeDescriptor $scope Scope.
	 */
	private function tenant_for( ScopeDescriptor $scope ): ?string {
		if ( null === $this->tenant ) {
			return null;
		}

		foreach ( $scope->rowFilter as $rule ) {
			if ( \Nvoos\Database\Domain\ValueObject\Database\RowFilterRule::FROM_TENANT === $rule->valueFrom ) {
				$value = ( $this->tenant )();

				return is_string( $value ) && '' !== trim( $value ) ? $value : null;
			}
		}

		return null;
	}
}
