<?php
/**
 * The host-neutral implementation of the database port.
 *
 * Proposal 167 sections 3.3, 4.7, 6 and 7. It began life as
 * `WP_MCP_AI_Db_Executor` and was lifted into the kernel for phase P4: the same
 * ordering, the same caps, the same audit shape now serves the WordPress plugin
 * and the Laravel runtime, so the safety argument is proved once instead of
 * asserted twice. This is the only class in the toolkit that opens a connection,
 * starts a transaction and reads rows. Everything else decides *what* to ask;
 * this decides *how*, once, for every tool.
 *
 * A host supplies seven collaborators — connections, scopes, catalogue, factory,
 * audit sink, redactor and (for raw SQL) an engine profile — and the host keeps
 * its own storage, credentials and probe. Nothing here references `wpdb`, a
 * WordPress function or an Eloquent class.
 *
 * ## The state machine
 *
 * ```
 * resolve descriptor -> availability -> scope -> build -> admit -> open
 *        -> BEGIN [READ ONLY] -> session statements -> prepare/execute
 *        -> stream rows (deadline, byte cap, row cap) -> ROLLBACK -> closeCursor
 *        -> shape -> mask -> cursor -> audit -> ResultSet
 * ```
 *
 * Failure after the transaction opens still rolls back in a `finally`. There is
 * no path that leaves a transaction open, and no path that commits a read.
 *
 * ## Principal binding
 *
 * {@see \Nvoos\Database\Domain\Contract\DatabaseQueryInterface} deliberately has
 * no principal parameter — the port is about databases, not users. The host binds
 * a principal per request with {@see self::for_principal()}; an executor with no
 * bound principal refuses every read, which is the safe default for a service
 * object that escaped its scope.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Closure;
use PDO;
use PDOException;
use Throwable;

use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;
use Nvoos\Database\Domain\Contract\Database\AuditSinkInterface;
use Nvoos\Database\Domain\Contract\Database\CatalogueInterface;
use Nvoos\Database\Domain\Contract\Database\ConnectionFactoryInterface;
use Nvoos\Database\Domain\Contract\Database\ConnectionRegistryInterface;
use Nvoos\Database\Domain\Contract\Database\DatabaseEngineInterface;
use Nvoos\Database\Domain\Contract\Database\RedactorInterface;
use Nvoos\Database\Domain\Contract\Database\ScopeAwareInterface;
use Nvoos\Database\Domain\Contract\Database\ScopeRegistryInterface;
use Nvoos\Database\Domain\Service\Database\AdmissionClassifier;
use Nvoos\Database\Domain\Service\Database\CursorCodec;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\ErrorMapper;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\Service\Database\ResultShaper;
use Nvoos\Database\Domain\Service\Database\ScopePolicy;
use Nvoos\Database\Domain\Service\Database\StatementGrammar;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\PlanDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PreviewDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\RowFilterRule;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

/**
 * Scoped, audited query execution.
 */
class PdoExecutor implements DatabaseQueryInterface, ScopeAwareInterface {

	/**
	 * Connection registry.
	 *
	 * @var ConnectionRegistryInterface
	 */
	private ConnectionRegistryInterface $connections;

	/**
	 * Scope registry.
	 *
	 * @var ScopeRegistryInterface
	 */
	private ScopeRegistryInterface $scopes;

	/**
	 * Catalogue loader.
	 *
	 * @var CatalogueInterface
	 */
	private CatalogueInterface $catalogue;

	/**
	 * Connection factory.
	 *
	 * @var ConnectionFactoryInterface
	 */
	private ConnectionFactoryInterface $factory;

	/**
	 * Audit log.
	 *
	 * @var AuditSinkInterface
	 */
	private AuditSinkInterface $audit;

	/**
	 * Redactor.
	 *
	 * @var RedactorInterface
	 */
	private RedactorInterface $redactor;

	/**
	 * Tenant resolver, called only when a scope needs a tenant value.
	 *
	 * @var Closure|null
	 */
	private ?Closure $tenant;

	/**
	 * Grammar.
	 *
	 * @var StatementGrammar
	 */
	private StatementGrammar $grammar;

	/**
	 * Admission classifier.
	 *
	 * @var AdmissionClassifier
	 */
	private AdmissionClassifier $admission;

	/**
	 * Scope policy.
	 *
	 * @var ScopePolicy
	 */
	private ScopePolicy $policy;

	/**
	 * Result shaper.
	 *
	 * @var ResultShaper
	 */
	private ResultShaper $shaper;

	/**
	 * Cursor codec.
	 *
	 * @var CursorCodec
	 */
	private CursorCodec $cursors;

	/**
	 * Error mapper.
	 *
	 * @var ErrorMapper
	 */
	private ErrorMapper $errors;

	/**
	 * Cursor signing secret.
	 *
	 * @var string
	 */
	private string $cursor_secret;

	/**
	 * Bound principal, if any.
	 *
	 * @var PrincipalRef|null
	 */
	private ?PrincipalRef $principal = null;

	/**
	 * Extra time the PHP deadline allows beyond the server timeout.
	 *
	 * The server timeout is the primary control; the PHP deadline exists for the
	 * engines that have none (SQLite) or only cover `SELECT` (MySQL). Giving the
	 * server a head start means a slow query is cancelled by the engine and mapped
	 * to `db_timeout`, instead of ending as a silently empty `truncated: deadline`
	 * result because a PHP check won the race by a millisecond.
	 */
	private const DEADLINE_GRACE_SECONDS = 1.0;

	/**
	 * The statement the current call compiled, for refusal auditing.
	 *
	 * Set at the start of {@see self::run()} and by the explain path. A refusal
	 * that happens before compilation records an empty template, which is honest:
	 * nothing was compiled and nothing was sent.
	 *
	 * @var CompiledStatement|null
	 */
	private ?CompiledStatement $last_statement = null;

	/**
	 * Constructor.
	 *
	 * @param ConnectionRegistryInterface $connections   Connections.
	 * @param ScopeRegistryInterface      $scopes        Scopes.
	 * @param CatalogueInterface           $catalogue     Catalogue loader.
	 * @param ConnectionFactoryInterface  $factory       Factory.
	 * @param AuditSinkInterface           $audit         Audit log.
	 * @param RedactorInterface            $redactor      Redactor.
	 * @param string                           $cursor_secret HMAC key for cursors.
	 * @param Closure|null                     $tenant        Returns the current tenant id, or null.
	 * @param StatementGrammar|null            $grammar       Grammar override.
	 * @param AdmissionClassifier|null         $admission     Admission override.
	 * @param ScopePolicy|null                 $policy        Policy override.
	 * @param ResultShaper|null                $shaper        Shaper override.
	 * @param CursorCodec|null                 $cursors       Cursor override.
	 * @param ErrorMapper|null                 $errors        Error mapper override.
	 */
	public function __construct(
		ConnectionRegistryInterface $connections,
		ScopeRegistryInterface $scopes,
		CatalogueInterface $catalogue,
		ConnectionFactoryInterface $factory,
		AuditSinkInterface $audit,
		RedactorInterface $redactor,
		string $cursor_secret,
		?Closure $tenant = null,
		?StatementGrammar $grammar = null,
		?AdmissionClassifier $admission = null,
		?ScopePolicy $policy = null,
		?ResultShaper $shaper = null,
		?CursorCodec $cursors = null,
		?ErrorMapper $errors = null
	) {
		$this->connections   = $connections;
		$this->scopes        = $scopes;
		$this->catalogue     = $catalogue;
		$this->factory       = $factory;
		$this->audit         = $audit;
		$this->redactor      = $redactor;
		$this->cursor_secret = $cursor_secret;
		$this->tenant        = $tenant;
		$this->policy        = $policy ?? new ScopePolicy();
		$this->grammar       = $grammar ?? new StatementGrammar( $this->policy );
		$this->admission     = $admission ?? new AdmissionClassifier();
		$this->shaper        = $shaper ?? new ResultShaper();
		$this->cursors       = $cursors ?? new CursorCodec();
		$this->errors        = $errors ?? new ErrorMapper();
	}

	/**
	 * Remove columns the scope excludes from a table descriptor.
	 *
	 * `db_describe` is the model's map of what it may ask for. Listing an
	 * excluded column would advertise a column that every query then refuses,
	 * and would disclose that the column exists — which the scope did not
	 * grant. The grammar would refuse it anyway; this makes the catalogue and
	 * the grammar tell the same story.
	 *
	 * @param ScopeDescriptor $scope Scope.
	 * @param TableDescriptor $table Relation.
	 */
	private function project_columns( ScopeDescriptor $scope, TableDescriptor $table ): TableDescriptor {
		$excluded = $scope->excludedColumns( $table );

		if ( array() === $excluded ) {
			return $table;
		}

		$columns = array();

		foreach ( $table->columns as $key => $column ) {
			if ( ! in_array( strtolower( (string) $key ), $excluded, true ) ) {
				$columns[ $key ] = $column;
			}
		}

		$primary = array();

		foreach ( $table->primaryKey as $column ) {
			if ( isset( $columns[ strtolower( $column ) ] ) ) {
				$primary[] = $column;
			}
		}

		return new TableDescriptor( $table->schema, $table->name, $columns, $primary, $table->isView );
	}

	/**
	 * Bind the principal for the current request.
	 *
	 * @param PrincipalRef $principal Principal.
	 */
	public function for_principal( PrincipalRef $principal ): static {
		$clone            = clone $this;
		$clone->principal = $principal;

		return $clone;
	}

	/**
	 * {@inheritDoc}
	 */
	public function connections( PrincipalRef $principal ): array {
		return $this->connections->visible_to( $principal );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The descriptor, not a decision: `db_profile_table` needs to know which
	 * columns the scope marks sensitive so it can skip them, and that shaping
	 * rule cannot be applied by the executor on the tool's behalf.
	 */
	public function scopeDescriptor( string $connection, string $scope, PrincipalRef $principal ): ScopeDescriptor {
		$descriptor = $this->connections->get_or_refuse( $connection );

		return $this->scopes->resolve_for( $principal, $descriptor->id, $scope, GrantTier::READ );
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe( string $connection, ?string $relation = null ): CatalogueDescriptor {
		$principal = $this->require_principal();
		$descriptor = $this->connections->get_or_refuse( $connection );
		$scope_for_audit = null;

		try {
			$scopes  = $this->scopes->for_principal( $principal, $descriptor->id );
			$visible = array();
			$schemas = array();

			foreach ( $scopes as $scope ) {
				if ( ! $scope->hasTier( GrantTier::METADATA ) ) {
					continue;
				}

				$visible[] = $scope;
				$schemas   = array_merge( $schemas, $scope->schemas );
			}

			if ( array() === $visible ) {
				throw DatabaseRefusal::of(
					RefusalCode::SCOPE_NOT_ALLOWED,
					'No metadata scope binds this principal to ' . $descriptor->id . '.'
				);
			}

			$scope_for_audit = $visible[0];

			$this->assert_servable( $descriptor, $scope_for_audit );

			$catalogue = $this->catalogue->load( $descriptor, array_values( array_unique( $schemas ) ) );
			$merged    = array();

			foreach ( $visible as $scope ) {
				$this->assert_tenant_supported( $descriptor, $scope );

				foreach ( $catalogue->only( $scope )->tables as $key => $table ) {
					$merged[ $key ] = $this->project_columns( $scope, $table );
				}
			}

			$filtered = new CatalogueDescriptor( $merged );

			if ( null === $relation || '' === trim( $relation ) ) {
				$this->audit_metadata_call( $descriptor, $visible[0], count( $filtered->tables ) );

				return $filtered;
			}

			$relation = strtolower( trim( $relation ) );

			foreach ( $visible as $scope ) {
				try {
					$table = $this->policy->assertRelation( $scope, $filtered, $relation );
				} catch ( DatabaseRefusal $refusal ) {
					continue;
				}

				$this->audit_metadata_call( $descriptor, $scope, 1 );

				return new CatalogueDescriptor( array( strtolower( $table->key() ) => $table ) );
			}

			throw DatabaseRefusal::of( RefusalCode::TABLE_NOT_ALLOWED, 'Relation is not visible to this principal: ' . $relation );
		} catch ( DatabaseRefusal $refusal ) {
			$this->audit_call_refusal( $descriptor, $scope_for_audit, 'describe', 'db_describe', $refusal );

			throw $refusal;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function select( string $connection, string $scope, SelectQuery $query ): ResultSet {
		$descriptor = $this->connections->get_or_refuse( $connection );
		$resolved   = $this->scopes->resolve_for( $this->require_principal(), $descriptor->id, $scope, GrantTier::READ );

		return $this->read(
			$descriptor,
			$resolved,
			$query->relation,
			'select',
			function ( CatalogueDescriptor $catalogue, ?string $tenant, DatabaseLimits $limits, string $engine ) use ( $resolved, $query ): array {
				$plan    = $this->cursor_plan( $query );
				$after   = null;

				if ( null !== $query->cursor ) {
					if ( null === $plan ) {
						throw DatabaseRefusal::of(
							RefusalCode::STATEMENT_NOT_ADMITTED,
							'A cursor requires an explicit, single-direction ordering.'
						);
					}

					$after = $this->cursors->decode(
						$query->cursor,
						$this->cursor_secret,
						$resolved->id,
						strtolower( trim( $query->relation ) ),
						$plan['order'],
						$plan['direction']
					);
				}

				$compiled = $this->grammar->select( $resolved, $catalogue, $query, $tenant, $engine, $limits, $after );

				return array( $compiled, $limits->effectiveRowLimit( $query->limit ), $plan );
			}
		);
	}

	/**
	 * Compile a scope against a query without reading a row.
	 *
	 * Proposal 167 phase P3 task 3.3. The console's editor calls this so an
	 * operator can see what a grant resolves to before saving it — the exact
	 * statement (placeholders only), which columns come back, which are masked,
	 * and the caps that will apply.
	 *
	 * ## Why this is on the port, not in the console
	 *
	 * The compile sequence is the executor's: resolve, tier, servability, tenant,
	 * catalogue, relation, grammar, admission. Reimplementing it in a controller
	 * would be a second place for the guarantee to rot — the same reason the tools
	 * are thin. What differs from {@see self::select()} is only the ending: no
	 * transaction, no `prepare()`, no rows, no audit row (a preview is not a call
	 * the assistant made; the editor holds the grant it is inspecting).
	 *
	 * The catalogue read does open a connection — reading `information_schema` is
	 * how identifiers are resolved — so the connection must hold a successful
	 * probe, exactly as a real read would require.
	 *
	 * @param string       $connection Connection id.
	 * @param ScopeDescriptor $scope    The grant being inspected.
	 * @param SelectQuery  $query      Query to compile.
	 * @throws DatabaseRefusal When the connection, scope or relation cannot serve it.
	 */
	public function preview( string $connection, ScopeDescriptor $scope, SelectQuery $query ): PreviewDescriptor {
		$descriptor = $this->connections->get_or_refuse( $connection );

		if ( strtolower( trim( $scope->connection ) ) !== $descriptor->id ) {
			throw DatabaseRefusal::of(
				RefusalCode::SCOPE_NOT_ALLOWED,
				'Scope ' . $scope->id . ' does not grant connection ' . $descriptor->id . '.'
			);
		}

		$engine = $this->factory->engine( $descriptor );

		$this->policy->assertTier( $scope, GrantTier::READ );
		$this->assert_servable( $descriptor, $scope );
		$this->assert_tenant_supported( $descriptor, $scope );

		$limits    = $scope->limitsOver( new DatabaseLimits() );
		$catalogue = $this->catalogue->load( $descriptor, $scope->schemas )->only( $scope );
		$table     = $this->policy->assertRelation( $scope, $catalogue, $query->relation );
		$tenant    = $this->tenant_for( $scope );

		$compiled = $this->grammar->select( $scope, $catalogue, $query, $tenant, $engine->name(), $limits, null );

		$this->admission->admit( $compiled->sql, $engine->name() );

		$asked     = array() === $query->columns ? array_keys( $table->columns ) : $query->columns;
		$projected = array();

		foreach ( $asked as $column ) {
			if ( $scope->allowsColumn( $table, (string) $column ) ) {
				$projected[] = strtolower( (string) $column );
			}
		}

		$masked = array();

		foreach ( $projected as $column ) {
			if ( $scope->isSensitive( $table, $column ) ) {
				$masked[] = $column;
			}
		}

		return new PreviewDescriptor(
			connection: $descriptor->id,
			scope: $scope->id,
			relation: strtolower( trim( $query->relation ) ),
			engine: $engine->name(),
			statement: $compiled->template(),
			bindings: count( $compiled->bindings ),
			projected: $projected,
			masked: $masked,
			excluded: $scope->excludedColumns( $table ),
			searchable: $scope->searchableColumns( $table ),
			rowLimit: $limits->effectiveRowLimit( $query->limit ),
			limits: array(
				'max_rows'          => $limits->maxRows,
				'max_bytes'         => $limits->maxBytes,
				'timeout_ms'        => $limits->timeoutMs,
				'cell_bytes'        => $limits->cellBytes,
				'offset_max'        => $limits->offsetMax,
				'max_affected_rows' => $limits->maxAffectedRows,
			),
			tenantBound: null !== $tenant,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function aggregate( string $connection, string $scope, AggregateQuery $query ): ResultSet {
		$descriptor = $this->connections->get_or_refuse( $connection );
		$resolved   = $this->scopes->resolve_for( $this->require_principal(), $descriptor->id, $scope, GrantTier::READ );

		return $this->read(
			$descriptor,
			$resolved,
			$query->relation,
			'aggregate',
			function ( CatalogueDescriptor $catalogue, ?string $tenant, DatabaseLimits $limits, string $engine ) use ( $resolved, $query ): array {
				$compiled = $this->grammar->aggregate( $resolved, $catalogue, $query, $tenant, $engine, $limits );

				return array( $compiled, $limits->effectiveRowLimit( $query->limit ), null );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function search( string $connection, string $scope, SearchQuery $query ): ResultSet {
		$descriptor = $this->connections->get_or_refuse( $connection );
		$resolved   = $this->scopes->resolve_for( $this->require_principal(), $descriptor->id, $scope, GrantTier::READ );

		return $this->read(
			$descriptor,
			$resolved,
			$query->relation,
			'search',
			function ( CatalogueDescriptor $catalogue, ?string $tenant, DatabaseLimits $limits, string $engine ) use ( $resolved, $query ): array {
				$compiled = $this->grammar->search( $resolved, $catalogue, $query, $tenant, $engine, $limits );

				return array( $compiled, $limits->effectiveRowLimit( $query->limit ), null );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function explain( string $connection, string $scope, SelectQuery $query, bool $analyze = false ): PlanDescriptor {
		$descriptor = $this->connections->get_or_refuse( $connection );
		$resolved   = $this->scopes->resolve_for( $this->require_principal(), $descriptor->id, $scope, GrantTier::METADATA );
		$action     = $analyze ? 'explain_analyze' : 'explain';

		$this->last_statement = null;

		try {
			return $this->do_explain( $descriptor, $resolved, $query, $analyze );
		} catch ( DatabaseRefusal $refusal ) {
			$this->audit_call_refusal( $descriptor, $resolved, $action, 'db_explain', $refusal );

			throw $refusal;
		}
	}

	/**
	 * Explain implementation, separated so the public method can audit refusals.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $resolved   Scope.
	 * @param SelectQuery          $query      Query.
	 * @param bool                 $analyze    Whether to execute.
	 */
	private function do_explain(
		ConnectionDescriptor $descriptor,
		ScopeDescriptor $resolved,
		SelectQuery $query,
		bool $analyze
	): PlanDescriptor {
		$engine = $this->factory->engine( $descriptor );

		$this->policy->assertTier( $resolved, GrantTier::READ );
		$this->assert_servable( $descriptor, $resolved );
		$this->assert_tenant_supported( $descriptor, $resolved );

		if ( $analyze ) {
			$this->policy->assertTier( $resolved, GrantTier::EXPLAIN_ANALYZE );

			if ( ! $engine->supports_explain_analyze( $descriptor ) ) {
				throw DatabaseRefusal::of(
					RefusalCode::STATEMENT_NOT_ADMITTED,
					'EXPLAIN ANALYZE is not supported on ' . $engine->name() . '.'
				);
			}
		}

		$limits    = $resolved->limitsOver( new DatabaseLimits() );
		$catalogue = $this->catalogue->load( $descriptor, $resolved->schemas )->only( $resolved );
		$tenant    = $this->tenant_for( $resolved );
		$compiled  = $this->grammar->select( $resolved, $catalogue, $query, $tenant, $engine->name(), $limits );
		$compiled  = $engine->explain_statement( $compiled, $analyze );

		$this->last_statement = $compiled;

		$pdo     = $this->factory->open( $descriptor );
		$started = microtime( true );
		$rows    = array();

		try {
			$this->begin( $pdo, $engine, $descriptor, $resolved, $limits, 'explain' );

			$statement = $pdo->prepare( $compiled->sql );
			$statement->execute( $compiled->bindings );
			$rows = $statement->fetchAll( PDO::FETCH_ASSOC );
			$statement->closeCursor();
		} catch ( PDOException $error ) {
			throw $this->map_driver_error( $error, $descriptor );
		} finally {
			$this->rollback( $pdo );
		}

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		$plan    = $this->decode_plan( $rows, $descriptor );

		$this->audit->append(
			array(
				'connection'    => $descriptor->id,
				'scope'         => $resolved->id,
				'scope_version' => $resolved->version,
				'principal'     => (string) $this->principal?->key(),
				'action'        => $analyze ? 'explain_analyze' : 'explain',
				'tool'          => 'db_explain',
				'relation'      => strtolower( trim( $query->relation ) ),
				'row_count'     => count( $rows ),
				'elapsed_ms'    => $elapsed,
			) + self::describe_statement( $compiled )
		);

		return new PlanDescriptor(
			plan: $plan,
			analyzed: $analyze,
			engine: $engine->name(),
			elapsedMs: $elapsed
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function query( string $connection, string $scope, RawQuery $query ): ResultSet {
		$descriptor = $this->connections->get_or_refuse( $connection );
		$resolved   = $this->scopes->resolve_for( $this->require_principal(), $descriptor->id, $scope, GrantTier::RAW_SQL );
		$engine     = $this->factory->engine( $descriptor );

		$this->last_statement = null;

		try {
			$this->assert_servable( $descriptor, $resolved );
			$this->assert_tenant_supported( $descriptor, $resolved );

			// Raw SQL is the one path where the statement is caller-authored, so
			// admission is the primary lexical control rather than a backstop.
			$this->admission->admit( $query->sql, $engine->name() );

			$limits   = $resolved->limitsOver( new DatabaseLimits() );
			$compiled = new CompiledStatement( $query->sql, $query->params );

			return $this->run(
				$descriptor,
				$resolved,
				$compiled,
				$limits,
				'raw_sql',
				'db_query',
				new TableDescriptor( '', '', array() ),
				null,
				$limits->maxRows
			);
		} catch ( DatabaseRefusal $refusal ) {
			$this->audit_call_refusal( $descriptor, $resolved, 'raw_sql', 'db_query', $refusal );

			throw $refusal;
		}
	}

	/**
	 * Shared read path: resolve, compile, execute, audit.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param string               $relation   Relation the request names.
	 * @param string               $action     Audit action.
	 * @param callable             $compile    Returns `array{CompiledStatement, int, ?array}`.
	 */
	private function read(
		ConnectionDescriptor $descriptor,
		ScopeDescriptor $scope,
		string $relation,
		string $action,
		callable $compile
	): ResultSet {
		$engine           = $this->factory->engine( $descriptor );
		$this->last_statement = null;

		try {
			$this->policy->assertTier( $scope, GrantTier::READ );
			$this->assert_servable( $descriptor, $scope );
			$this->assert_tenant_supported( $descriptor, $scope );

			$limits    = $scope->limitsOver( new DatabaseLimits() );
			$catalogue = $this->catalogue->load( $descriptor, $scope->schemas )->only( $scope );
			$table     = $this->policy->assertRelation( $scope, $catalogue, $relation );
			$tenant    = $this->tenant_for( $scope );

			list( $compiled, $row_limit, $plan ) = $compile( $catalogue, $tenant, $limits, $engine->name() );

			$this->admission->admit( $compiled->sql, $engine->name() );

			return $this->run(
				$descriptor,
				$scope,
				$compiled,
				$limits,
				$action,
				'db_' . $action,
				$table,
				$plan,
				$row_limit
			);
		} catch ( DatabaseRefusal $refusal ) {
			$this->audit_call_refusal( $descriptor, $scope, $action, 'db_' . $action, $refusal );

			throw $refusal;
		}
	}

	/**
	 * Execute one compiled statement inside a read-only transaction.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param CompiledStatement    $compiled   Statement.
	 * @param DatabaseLimits       $limits     Caps.
	 * @param string               $action     Audit action.
	 * @param string               $tool       Audit tool slug.
	 * @param TableDescriptor      $table      Relation, for shaping and masking.
	 * @param array{order: list<string>, direction: string}|null $plan Cursor plan.
	 * @param int                  $row_limit  Row cap.
	 */
	private function run(
		ConnectionDescriptor $descriptor,
		ScopeDescriptor $scope,
		CompiledStatement $compiled,
		DatabaseLimits $limits,
		string $action,
		string $tool,
		TableDescriptor $table,
		?array $plan,
		int $row_limit
	): ResultSet {
		$engine   = $this->factory->engine( $descriptor );
		$audit_id = $this->audit->next_id();
		$pdo      = $this->factory->open( $descriptor );
		$started  = microtime( true );
		$deadline = $started + ( $limits->timeoutMs / 1000 ) + self::DEADLINE_GRACE_SECONDS;
		$rows     = array();
		$masked   = array();
		$bytes    = 0;
		$truncate = null;

		$this->last_statement = $compiled;

		try {
			$this->begin( $pdo, $engine, $descriptor, $scope, $limits, $scope->id . ':' . $audit_id );

			$statement = $pdo->prepare( $compiled->sql );

			if ( false === $statement ) {
				throw DatabaseRefusal::of( RefusalCode::STATEMENT_NOT_ADMITTED, 'Statement could not be prepared.' );
			}

			$statement->execute( $compiled->bindings );

			while ( ( $row = $statement->fetch( PDO::FETCH_ASSOC ) ) !== false ) {
				if ( microtime( true ) > $deadline ) {
					$truncate = 'deadline';
					break;
				}

				$cell_truncated = false;
				$shaped         = $this->shaper->shapeRow( $row, $table, $limits, $cell_truncated );
				$bytes         += $this->shaper->rowBytes( $shaped );

				if ( $bytes > $limits->maxBytes ) {
					$truncate = 'max_bytes';
					break;
				}

				$mask     = $this->redactor->mask_row( $shaped, $table, $scope, $table->key() );
				$rows[]   = $mask['row'];

				foreach ( $mask['masked'] as $label ) {
					$masked[ $label ] = true;
				}

				if ( count( $rows ) >= $row_limit ) {
					// Peek once so `truncated` means "there was more", not
					// "the result happened to be exactly the cap".
					if ( false !== $statement->fetch( PDO::FETCH_ASSOC ) ) {
						$truncate = 'max_rows';
					}

					break;
				}

				if ( $cell_truncated && null === $truncate ) {
					$truncate = 'cell_cap';
				}
			}

			$statement->closeCursor();
		} catch ( DatabaseRefusal $refusal ) {
			$this->rollback( $pdo );

			throw $refusal;
		} catch ( PDOException $error ) {
			$this->rollback( $pdo );

			throw $this->map_driver_error( $error, $descriptor );
		} finally {
			$this->rollback( $pdo );
		}

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		$next    = $this->next_cursor( $rows, $plan, $scope, $table );

		$this->audit->append(
			array(
				'id'            => $audit_id,
				'connection'    => $descriptor->id,
				'scope'         => $scope->id,
				'scope_version' => $scope->version,
				'principal'     => (string) $this->principal?->key(),
				'action'        => $action,
				'tool'          => $tool,
				'relation'      => $table->key(),
				'row_count'     => count( $rows ),
				'elapsed_ms'    => $elapsed,
				'truncated'     => (string) $truncate,
			) + self::describe_statement( $compiled )
		);

		return new ResultSet(
			columns: array() === $rows ? array() : array_keys( $rows[0] ),
			rows: $rows,
			rowCount: count( $rows ),
			truncated: null !== $truncate,
			truncatedReason: $truncate,
			nextCursor: $next,
			masked: array_keys( $masked ),
			elapsedMs: $elapsed,
			auditId: $audit_id
		);
	}

	/**
	 * Open the transaction and apply the session preamble.
	 *
	 * @param PDO                  $pdo        Handle.
	 * @param DatabaseEngineInterface  $engine     Engine.
	 * @param ConnectionDescriptor $connection Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param DatabaseLimits       $limits     Caps.
	 * @param string               $label      Application label.
	 */
	private function begin(
		PDO $pdo,
		DatabaseEngineInterface $engine,
		ConnectionDescriptor $connection,
		ScopeDescriptor $scope,
		DatabaseLimits $limits,
		string $label
	): void {
		$pdo->exec( $engine->begin_statement() );

		$tenant = $this->tenant_for( $scope );

		foreach ( $engine->session_statements( $connection, $scope, $limits, 'nvoos:' . $label, $tenant ) as $session ) {
			$statement = $pdo->prepare( $session->sql );

			if ( false === $statement ) {
				throw DatabaseRefusal::of( RefusalCode::STATEMENT_NOT_ADMITTED, 'Session statement could not be prepared.' );
			}

			$statement->execute( $session->bindings );
			$statement->closeCursor();
		}
	}

	/**
	 * Roll back, never committing.
	 *
	 * @param PDO $pdo Handle.
	 */
	private function rollback( PDO $pdo ): void {
		try {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
		} catch ( Throwable $error ) {
			// A rollback failure means the server already ended the transaction
			// (timeout, disconnect). Nothing further can be done, and throwing
			// here would mask the original refusal.
			unset( $error );
		}
	}

	/**
	 * Record a successful metadata call.
	 *
	 * `db_describe` is a read of the catalogue, not of the data, but it is still a
	 * call: an operator reviewing an assistant's behaviour should see that it
	 * enumerated forty tables, even though no row left the database.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param int                  $tables     Relations returned.
	 */
	private function audit_metadata_call( ConnectionDescriptor $descriptor, ScopeDescriptor $scope, int $tables ): void {
		$this->audit->append(
			array(
				'connection'    => $descriptor->id,
				'scope'         => $scope->id,
				'scope_version' => $scope->version,
				'principal'     => (string) $this->principal?->key(),
				'action'        => 'describe',
				'tool'          => 'db_describe',
				'row_count'     => $tables,
			)
		);
	}

	/**
	 * Record one refusal for a call that did not produce a result.
	 *
	 * Exactly one record per call: the read/query wrappers call this, and the
	 * executor's own catch blocks rethrow without auditing. A pre-compilation
	 * refusal (unknown scope, disallowed table) records an empty template, which
	 * is the truth - nothing was compiled and nothing was sent.
	 *
	 * @param ConnectionDescriptor $descriptor Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @param string               $action     Action.
	 * @param string               $tool       Tool slug.
	 * @param DatabaseRefusal      $refusal    Refusal.
	 */
	private function audit_call_refusal(
		ConnectionDescriptor $descriptor,
		?ScopeDescriptor $scope,
		string $action,
		string $tool,
		DatabaseRefusal $refusal
	): void {
		$statement = $this->last_statement ?? new CompiledStatement( '' );

		$this->audit->append(
			array(
				'connection'    => $descriptor->id,
				'scope'         => null === $scope ? '' : $scope->id,
				'scope_version' => null === $scope ? 0 : $scope->version,
				'principal'     => (string) $this->principal?->key(),
				'action'        => $action,
				'tool'          => $tool,
				'refusal_code'  => $refusal->getRefusalCode(),
				'detail'        => $this->redactor->scrub_detail( $refusal->getLogDetail() ),
			) + self::describe_statement( $statement )
		);
	}

	/**
	 * Convert a driver exception into a refusal.
	 *
	 * @param PDOException         $error      Driver error.
	 * @param ConnectionDescriptor $connection Connection.
	 */
	private function map_driver_error( PDOException $error, ConnectionDescriptor $connection ): DatabaseRefusal {
		$code = $this->errors->fromThrowable( $error, $connection->engine );

		return DatabaseRefusal::wrap( $code, $error, $this->errors->auditDetail( $error ) );
	}

	/**
	 * Refuse a connection that has not passed its probe.
	 *
	 * @param ConnectionDescriptor $connection Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 * @throws DatabaseRefusal When the probe is missing, failed, or reports a writable role.
	 */
	private function assert_servable( ConnectionDescriptor $connection, ScopeDescriptor $scope ): void {
		$probe = $connection->probe;

		if ( null === $probe || ! $probe->reachable ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'Connection ' . $connection->id . ' has no successful probe; run the connection test.'
			);
		}

		// A read-only scope on a write-capable role is a configuration error: the
		// database would not be the thing preventing a write. SQLite is exempt
		// because its role-equivalent is `PRAGMA query_only`, set by the engine.
		if (
			ConnectionDescriptor::SQLITE !== $connection->engine
			&& $probe->canWrite
			&& ! $scope->hasTier( GrantTier::PROPOSE_WRITE )
			&& ! $scope->hasTier( GrantTier::APPLY_WRITE )
			&& ! $scope->hasTier( GrantTier::ADMIN )
		) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'Connection ' . $connection->id . ' authenticates as a role that can write; use a read-only role for a read scope.'
			);
		}

		if ( $probe->bypassesRls && $this->scope_uses_tenant( $scope ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'Connection ' . $connection->id . ' authenticates as a role that bypasses row-level security.'
			);
		}
	}

	/**
	 * Refuse a tenant-scoped scope on an engine that has no tenant mechanism.
	 *
	 * @param ConnectionDescriptor $connection Connection.
	 * @param ScopeDescriptor      $scope      Scope.
	 */
	private function assert_tenant_supported( ConnectionDescriptor $connection, ScopeDescriptor $scope ): void {
		if ( ! $this->scope_uses_tenant( $scope ) ) {
			return;
		}

		if ( ConnectionDescriptor::PGSQL === $connection->engine ) {
			return;
		}

		throw DatabaseRefusal::of(
			RefusalCode::FILTER_UNMAPPABLE,
			'The scope filters by tenant, which ' . $connection->engine . ' cannot enforce here.'
		);
	}

	/**
	 * Whether any row-filter rule sources its value from the tenant.
	 *
	 * @param ScopeDescriptor $scope Scope.
	 */
	private function scope_uses_tenant( ScopeDescriptor $scope ): bool {
		foreach ( $scope->rowFilter as $rule ) {
			if ( $rule instanceof RowFilterRule && RowFilterRule::FROM_TENANT === $rule->valueFrom ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The tenant value for a scope, when it needs one.
	 *
	 * @param ScopeDescriptor $scope Scope.
	 */
	private function tenant_for( ScopeDescriptor $scope ): ?string {
		if ( ! $this->scope_uses_tenant( $scope ) || null === $this->tenant ) {
			return null;
		}

		$value = ( $this->tenant )();

		return is_string( $value ) && '' !== trim( $value ) ? $value : null;
	}

	/**
	 * Derive the cursor plan from an ordering.
	 *
	 * A cursor requires one direction across every term; a mixed ordering has no
	 * single key tuple that can resume it, so it is not paginated by cursor.
	 *
	 * @param SelectQuery $query Query.
	 * @return array{order: list<string>, direction: string}|null
	 */
	private function cursor_plan( SelectQuery $query ): ?array {
		if ( array() === $query->order ) {
			return null;
		}

		$names     = array();
		$direction = null;

		foreach ( $query->order as $clause ) {
			$names[] = strtolower( $clause->column );

			if ( null === $direction ) {
				$direction = $clause->direction;
			} elseif ( $direction !== $clause->direction ) {
				return null;
			}
		}

		if ( null === $direction ) {
			return null;
		}

		return array(
			'order'     => $names,
			'direction' => $direction,
		);
	}

	/**
	 * Build the next cursor from the last row.
	 *
	 * @param list<array<string, mixed>>                        $rows  Rows.
	 * @param array{order: list<string>, direction: string}|null $plan  Cursor plan.
	 * @param ScopeDescriptor                                   $scope Scope.
	 * @param TableDescriptor                                   $table Relation.
	 */
	private function next_cursor( array $rows, ?array $plan, ScopeDescriptor $scope, TableDescriptor $table ): ?string {
		if ( null === $plan || array() === $rows || '' === $table->name ) {
			return null;
		}

		$last = $rows[ count( $rows ) - 1 ];
		$key  = array();

		foreach ( $plan['order'] as $column ) {
			if ( ! array_key_exists( $column, $last ) ) {
				return null;
			}

			$key[] = $last[ $column ];
		}

		return $this->cursors->encode( $key, $plan['order'], $plan['direction'], $scope->id, $table->key(), $this->cursor_secret );
	}

	/**
	 * Decode an EXPLAIN payload.
	 *
	 * PostgreSQL and MySQL return one JSON column; SQLite returns plan rows. The
	 * plan is normalised to an array either way so the host does not branch.
	 *
	 * @param list<array<string, mixed>> $rows       Rows.
	 * @param ConnectionDescriptor       $connection Connection.
	 * @return array<int|string, mixed>
	 */
	private function decode_plan( array $rows, ConnectionDescriptor $connection ): array {
		if ( ConnectionDescriptor::SQLITE === $connection->engine ) {
			return $rows;
		}

		if ( array() === $rows ) {
			return array();
		}

		$first = reset( $rows );
		$value = is_array( $first ) ? reset( $first ) : $first;

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return $rows;
	}

	/**
	 * The bound principal, or a refusal.
	 *
	 * @throws DatabaseRefusal When no principal is bound.
	 */
	private function require_principal(): PrincipalRef {
		if ( null === $this->principal ) {
			throw DatabaseRefusal::of(
				RefusalCode::SCOPE_NOT_ALLOWED,
				'No principal is bound to this executor.'
			);
		}

		return $this->principal;
	}

	/**
	 * The statement template and fingerprint for an audit record.
	 *
	 * No bindings, ever: the trail records what was asked, not what was matched.
	 *
	 * @param CompiledStatement $statement Compiled statement.
	 * @return array{sql_template: string, fingerprint: string}
	 */
	private static function describe_statement( CompiledStatement $statement ): array {
		return array(
			'sql_template' => $statement->template(),
			'fingerprint'  => $statement->fingerprint(),
		);
	}
}
