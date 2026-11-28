<?php
/**
 * Shared behaviour for the thirteen database tools.
 *
 * Proposal 167 section 8 and the two-gate sanitisation rule. Every tool in this
 * toolkit does the same six things before it can do anything interesting:
 *
 *  1. check availability (kernel + toggle + at least one connection);
 *  2. resolve the principal from the execution context — never from an argument;
 *  3. resolve the connection id, defaulting only when exactly one is visible;
 *  4. sanitise every argument at entry;
 *  5. call the kernel/executor, which may refuse;
 *  6. return a canonical envelope — a success array or a `WP_Error`.
 *
 * Doing that once here is what keeps thirteen tool classes small enough to review
 * and makes it impossible for one of them to forget a gate.
 *
 * ## Principal, not a parameter
 *
 * There is deliberately no `principal` or `assistant_id` tool argument. A model
 * that could name its own principal could name someone else's scope. The
 * principal comes from `$context`, which the orchestrator populates.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;

/**
 * Base class for `db_*` tools.
 */
abstract class WP_MCP_AI_Database_Tool_Base implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Per-request executor.
	 *
	 * @var WP_MCP_AI_Db_Executor|null
	 */
	private static ?WP_MCP_AI_Db_Executor $executor = null;

	/**
	 * Settings option name.
	 */
	protected const SETTINGS_OPTION = 'wp_mcp_ai_settings';

	/**
	 * Toolkit toggle key.
	 */
	protected const TOGGLE = 'enable_database_toolkit';

	/**
	 * Whether the toolkit can run here.
	 *
	 * Three conditions, all required: the kernel loads, the toolkit toggle is on,
	 * and at least one connection exists. The last one matters for the agent: with
	 * no connection there is nothing to list, describe or query, and registering
	 * ten tools that can only refuse is worse than registering none.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! class_exists( 'WP_MCP_AI_Db_Runtime' ) || ! WP_MCP_AI_Db_Runtime::load() ) {
			return false;
		}

		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( empty( $settings[ self::TOGGLE ] ) ) {
			return false;
		}

		if ( ( new WP_MCP_AI_Db_Connection_Registry() )->has_any() ) {
			return true;
		}

		return self::has_remote_site_database();
	}

	/**
	 * Why the toolkit is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WP_MCP_AI_Db_Runtime' ) || ! WP_MCP_AI_Db_Runtime::load() ) {
			return __( 'The database toolkit requires the Pro add-on and PHP 8.2 or newer.', 'mcp-ai-wpoos-pro' );
		}

		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( empty( $settings[ self::TOGGLE ] ) ) {
			return __( 'The Database toolkit is not enabled. Enable it in the plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'No database connection has been configured yet.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Whether a Remote Sites database record exists.
	 */
	protected static function has_remote_site_database(): bool {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		foreach ( (array) WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $record ) {
			if ( is_array( $record ) && isset( $record['connection_type'] ) && 'database' === $record['connection_type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'external-api', 'network-dependent', 'paginated' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reading from a database an administrator has scoped to this assistant.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Writing data, changing schema, or reading anything outside the configured scope.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'db_list_connections', 'db_describe', 'db_select' ),
			'notes'           => __( 'Only the tables and columns in the assistant scope are visible. A refusal names a stable code; it is not a suggestion to retry with different arguments.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Run a callback and convert kernel refusals into `WP_Error`.
	 *
	 * @param callable $callback Work to run.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function attempt( callable $callback ) {
		try {
			return $callback();
		} catch ( DatabaseRefusal $refusal ) {
			return $this->to_error( $refusal );
		} catch ( Throwable $error ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI database tool error: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only failure detail; never returned to the model.
			}

			return new WP_Error(
				'wp_mcp_ai_db_internal',
				__( 'The database call could not be completed.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Map a refusal to a `WP_Error` with a stable code.
	 *
	 * @param DatabaseRefusal $refusal Refusal.
	 */
	protected function to_error( DatabaseRefusal $refusal ): WP_Error {
		return new WP_Error(
			'wp_mcp_ai_' . $refusal->getRefusalCode(),
			$refusal->getMessage(),
			array(
				'status'       => $this->status_for( $refusal->getRefusal() ),
				'refusal_code' => $refusal->getRefusalCode(),
			)
		);
	}

	/**
	 * HTTP status for a refusal.
	 *
	 * @param RefusalCode $code Refusal.
	 */
	protected function status_for( RefusalCode $code ): int {
		return match ( $code ) {
			RefusalCode::NOT_CONFIGURED,
			RefusalCode::UNKNOWN_CONNECTION,
			RefusalCode::NOT_A_DATABASE,
			RefusalCode::RELATION_NOT_FOUND,
			RefusalCode::COLUMN_NOT_FOUND => 404,
			RefusalCode::SCOPE_NOT_ALLOWED,
			RefusalCode::TABLE_NOT_ALLOWED,
			RefusalCode::COLUMN_NOT_ALLOWED,
			RefusalCode::FILTER_UNMAPPABLE,
			RefusalCode::TIER_REQUIRED,
			RefusalCode::STATEMENT_NOT_ADMITTED,
			RefusalCode::SITE_DATABASE_REFUSED,
			RefusalCode::PRIVILEGE_DENIED,
			RefusalCode::APPROVAL_REQUIRED => 403,
			RefusalCode::TIMEOUT,
			RefusalCode::LOCK_TIMEOUT     => 504,
			RefusalCode::CONNECTION_LIMIT => 503,
			RefusalCode::EXPORT_LIMIT     => 429,
			default                       => 400,
		};
	}

	/**
	 * The executor bound to the context's principal.
	 *
	 * @param array<string, mixed> $context Execution context.
	 * @throws DatabaseRefusal When no principal can be resolved.
	 */
	protected function executor( array $context ): WP_MCP_AI_Db_Executor {
		if ( null === self::$executor ) {
			// One assembly, shared with the console's preview route
			// ({@see WP_MCP_AI_Db_Executor_Builder}).
			self::$executor = WP_MCP_AI_Db_Executor_Builder::build();
		}

		return self::$executor->for_principal( $this->principal( $context ) );
	}

	/**
	 * Resolve the principal from the execution context.
	 *
	 * @param array<string, mixed> $context Execution context.
	 * @throws DatabaseRefusal When the context carries neither an assistant nor a user.
	 */
	protected function principal( array $context ): PrincipalRef {
		$assistant = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( $assistant > 0 ) {
			return new PrincipalRef( PrincipalRef::ASSISTANT, (string) $assistant );
		}

		$user = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( $user > 0 ) {
			return new PrincipalRef( PrincipalRef::USER, (string) $user );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An enum case plus a literal; nothing user-derived reaches the message.
		throw DatabaseRefusal::of( RefusalCode::SCOPE_NOT_ALLOWED, 'No assistant or user principal in the execution context.' );
	}

	/**
	 * Resolve the connection id for a call.
	 *
	 * An explicit argument wins. Otherwise, exactly one visible connection is
	 * used; zero or several is a refusal, because guessing which warehouse a
	 * question meant is not a decision this layer should make.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param array<string, mixed> $context   Execution context.
	 * @throws DatabaseRefusal When no unambiguous connection exists.
	 */
	protected function connection( array $arguments, array $context ): string {
		$explicit = isset( $arguments['connection'] ) ? sanitize_text_field( wp_unslash( (string) $arguments['connection'] ) ) : '';

		if ( '' !== $explicit ) {
			return strtolower( $explicit );
		}

		$visible = $this->executor( $context )->connections( $this->principal( $context ) );

		if ( 0 === count( $visible ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An enum case plus a literal; nothing user-derived reaches the message.
			throw DatabaseRefusal::of( RefusalCode::NOT_CONFIGURED, 'No connection is visible to this assistant.' );
		}

		if ( 1 === count( $visible ) ) {
			return $visible[0]->id;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An enum case plus a literal; nothing user-derived reaches the message.
		throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Several connections are available; name one.' );
	}

	/**
	 * Resolve the scope id for a call.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	protected function scope( array $arguments ): string {
		return isset( $arguments['scope'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $arguments['scope'] ) ) ) : '';
	}

	/**
	 * Sanitise a relation argument.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	protected function relation( array $arguments ): string {
		return strtolower( sanitize_text_field( wp_unslash( (string) ( $arguments['relation'] ?? $arguments['table'] ?? '' ) ) ) );
	}

	/**
	 * Build a select query from sanitised arguments.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	protected function select_query( array $arguments ): SelectQuery {
		return new SelectQuery(
			relation: $this->relation( $arguments ),
			columns: $this->string_list( $arguments['columns'] ?? array() ),
			filters: $this->filter_clauses( $arguments['filters'] ?? array() ),
			order: $this->order_clauses( $arguments['order'] ?? array() ),
			limit: isset( $arguments['limit'] ) ? max( 1, absint( $arguments['limit'] ) ) : null,
			offset: isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : null,
			cursor: isset( $arguments['cursor'] ) && '' !== (string) $arguments['cursor']
				? sanitize_text_field( wp_unslash( (string) $arguments['cursor'] ) )
				: null
		);
	}

	/**
	 * Build an aggregate query from sanitised arguments.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	protected function aggregate_query( array $arguments ): AggregateQuery {
		$metrics = array();

		foreach ( (array) ( $arguments['metrics'] ?? array() ) as $metric ) {
			if ( ! is_array( $metric ) ) {
				continue;
			}

			$metrics[] = array(
				'metric' => sanitize_key( (string) ( $metric['metric'] ?? 'count' ) ),
				'alias'  => sanitize_key( (string) ( $metric['alias'] ?? '' ) ),
				'column' => isset( $metric['column'] ) ? sanitize_key( (string) $metric['column'] ) : null,
			);
		}

		return new AggregateQuery(
			relation: $this->relation( $arguments ),
			groupBy: $this->string_list( $arguments['group_by'] ?? array() ),
			metrics: $this->metric_clauses( $metrics ),
			filters: $this->filter_clauses( $arguments['filters'] ?? array() ),
			order: $this->order_clauses( $arguments['order'] ?? array() ),
			limit: isset( $arguments['limit'] ) ? max( 1, absint( $arguments['limit'] ) ) : null
		);
	}

	/**
	 * Build a search query from sanitised arguments.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	protected function search_query( array $arguments ): SearchQuery {
		return new SearchQuery(
			relation: $this->relation( $arguments ),
			term: sanitize_text_field( wp_unslash( (string) ( $arguments['term'] ?? $arguments['query'] ?? '' ) ) ),
			columns: $this->string_list( $arguments['columns'] ?? array() ),
			limit: isset( $arguments['limit'] ) ? max( 1, absint( $arguments['limit'] ) ) : null
		);
	}

	/**
	 * Sanitise a list of identifiers.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	protected function string_list( mixed $value ): array {
		$list = array();

		foreach ( (array) $value as $entry ) {
			if ( is_string( $entry ) && '' !== trim( $entry ) ) {
				$list[] = strtolower( sanitize_text_field( wp_unslash( $entry ) ) );
			}
		}

		return $list;
	}

	/**
	 * Sanitise filters.
	 *
	 * Scalar values keep their type so a numeric comparison stays numeric; only
	 * strings are passed through `sanitize_text_field`, and arrays are flattened
	 * to a list of scalars (for `in`/`between`).
	 *
	 * @param mixed $value Raw filters.
	 * @return list<array{column: string, op: string, value: mixed}>
	 */
	protected function filters( mixed $value ): array {
		$filters = array();

		foreach ( (array) $value as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$filters[] = array(
				'column' => strtolower( sanitize_key( (string) ( $filter['column'] ?? '' ) ) ),
				'op'     => sanitize_key( (string) ( $filter['op'] ?? $filter['operator'] ?? '' ) ),
				'value'  => $this->filter_value( $filter['value'] ?? null ),
			);
		}

		return $filters;
	}

	/**
	 * Sanitise an order list.
	 *
	 * @param mixed $value Raw order.
	 * @return list<array{column: string, direction: string}>
	 */
	protected function order( mixed $value ): array {
		$order = array();

		foreach ( (array) $value as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}

			$order[] = array(
				'column'    => strtolower( sanitize_key( (string) ( $term['column'] ?? '' ) ) ),
				'direction' => 'desc' === strtolower( (string) ( $term['direction'] ?? 'asc' ) ) ? 'desc' : 'asc',
			);
		}

		return $order;
	}

	/**
	 * Sanitise a filter value.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	protected function filter_value( mixed $value ): mixed {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return array_values( array_map( array( $this, 'filter_value' ), $value ) );
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Build a metric clause list from sanitised arguments.
	 *
	 * @param array<int, array{metric: string, alias: string, column: ?string}> $metrics Raw metrics.
	 * @return list<MetricClause>
	 * @throws DatabaseRefusal When a metric name is unknown.
	 */
	protected function metric_clauses( array $metrics ): array {
		$clauses = array();

		foreach ( $metrics as $metric ) {
			$value = Metric::tryFrom( (string) $metric['metric'] );

			if ( null === $value ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The metric name was sanitised at entry and is bound to a fixed label, not echoed.
				throw DatabaseRefusal::of( RefusalCode::UNSUPPORTED_METRIC, 'Unknown metric: ' . $metric['metric'] );
			}

			$clauses[] = new MetricClause(
				$value,
				'' !== $metric['alias'] ? $metric['alias'] : $value->value,
				$metric['column']
			);
		}

		return $clauses;
	}

	/**
	 * Canonical success envelope for a result set.
	 *
	 * @param ResultSet            $result Result.
	 * @param array<string, mixed> $extra  Additional keys.
	 * @return array<string, mixed>
	 */
	protected function envelope( ResultSet $result, array $extra = array() ): array {
		return array_merge( array( 'success' => true ), $result->toArray(), $extra );
	}

	/**
	 * A redacted scope summary.
	 *
	 * @param ScopeDescriptor $scope Scope.
	 * @return array<string, mixed>
	 */
	protected function scope_summary( ScopeDescriptor $scope ): array {
		return array(
			'id'      => $scope->id,
			'label'   => $scope->label,
			'version' => $scope->version,
		);
	}

	/**
	 * Require a tier on the scope of this call.
	 *
	 * The executor only enforces READ on a read; a tool whose contract needs more
	 * (export, raw SQL, explain-analyze) asserts it here, through the same scope
	 * registry the executor uses.
	 *
	 * The registry answers `SCOPE_NOT_ALLOWED` for two different situations: no
	 * grant binds this principal to the connection, and a grant binds it but does
	 * not hold the tier. Only the second is a tier problem, and the core tool
	 * surface (and so the Laravel host) reports it as `TIER_REQUIRED`. Collapsing
	 * them here made the same condition carry two codes depending on the host, and
	 * told an operator "no scope grants access" about a scope they can see. The
	 * distinction is made after the refusal, so the resolution rule itself is
	 * still the registry's.
	 *
	 * @param string               $connection Connection id.
	 * @param string               $scope      Scope id.
	 * @param array<string, mixed> $context Execution context.
	 * @param GrantTier            $tier       Required tier.
	 * @throws DatabaseRefusal When the scope does not hold the tier.
	 */
	protected function assert_tier( string $connection, string $scope, array $context, GrantTier $tier ): void {
		$principal = $this->principal( $context );
		$registry  = new WP_MCP_AI_Db_Scope_Registry();

		try {
			$registry->resolve_for( $principal, $connection, '' !== $scope ? $scope : null, $tier );

			return;
		} catch ( DatabaseRefusal $refusal ) {
			if ( RefusalCode::SCOPE_NOT_ALLOWED !== $refusal->getRefusal() ) {
				throw $refusal;
			}

			foreach ( $registry->for_principal( $principal, $connection ) as $grant ) {
				if ( '' === $scope || $grant->id === $scope ) {
					throw DatabaseRefusal::of(
						RefusalCode::TIER_REQUIRED,
						'Scope ' . $grant->id . ' does not hold tier ' . $tier->value . '.'
					);
				}
			}

			throw $refusal;
		}
	}

	/**
	 * SQLite allowlist roots.
	 *
	 * @return list<string>
	 */
	protected function sqlite_roots(): array {
		return WP_MCP_AI_Db_Executor_Builder::sqlite_roots();
	}

	/**
	 * Cursor signing secret.
	 *
	 * Derived from the master key where available, so a cursor signed on one node
	 * verifies on another behind a shared database, and rotating the key
	 * invalidates outstanding cursors (which is the desired behaviour).
	 */
	protected function cursor_secret(): string {
		return WP_MCP_AI_Db_Executor_Builder::cursor_secret();
	}

	/**
	 * Whether the write path is enabled on this site.
	 *
	 * Default off. Both write tools check this in `is_available()`, and the central
	 * registry also omits them from its map, so a site that never opted in has no
	 * write tool registered at all — not a tool that refuses.
	 */
	protected static function writes_enabled(): bool {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return ! empty( $settings['enable_database_writes'] );
	}

	/**
	 * The tenant resolver.
	 *
	 * Decision D4: reuse the estate vocabulary and never accept a tenant id as a
	 * tool argument. A host integration supplies the value through a filter; with
	 * no resolver the tenant-sourced row filter refuses, which is safe.
	 */
	protected function tenant_resolver(): Closure {
		return WP_MCP_AI_Db_Executor_Builder::tenant_resolver();
	}

	/**
	 * Shared schema properties for every data tool.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function connection_properties(): array {
		return array(
			'connection' => array(
				'type'        => 'string',
				'description' => 'Connection id from db_list_connections. Optional when only one connection is available.',
			),
			'scope'      => array(
				'type'        => 'string',
				'description' => 'Scope id, when the assistant holds more than one for the connection.',
			),
		);
	}

	/**
	 * Filter schema fragment.
	 *
	 * @return array<string, mixed>
	 */
	protected function filter_schema(): array {
		return array(
			'type'        => 'array',
			'description' => 'Predicates, all ANDed. A value is always bound, never interpolated.',
			'maxItems'    => 20,
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'column' => array(
						'type'        => 'string',
						'description' => 'Column name.',
					),
					'op'     => array(
						'type'        => 'string',
						'enum'        => array( 'eq', 'ne', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'like', 'ilike', 'is_null', 'is_not_null' ),
						'description' => 'Comparison operator.',
					),
					'value'  => array(
						'description' => 'Value; an array for in/not_in/between. Omit for is_null/is_not_null.',
					),
				),
				'required'             => array( 'column', 'op' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Order schema fragment.
	 *
	 * @return array<string, mixed>
	 */
	protected function order_schema(): array {
		return array(
			'type'        => 'array',
			'description' => 'Ordering, applied in the given sequence. A single direction enables cursor paging.',
			'maxItems'    => 4,
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'column'    => array(
						'type'        => 'string',
						'description' => 'Column name.',
					),
					'direction' => array(
						'type' => 'string',
						'enum' => array( 'asc', 'desc' ),
					),
				),
				'required'             => array( 'column' ),
				'additionalProperties' => false,
			),
		);
	}


	/**
	 * Filters as clause objects.
	 *
	 * The grammar compiles `FilterClause` / `OrderClause`; passing the sanitised
	 * arrays straight through made every filtered read a null-column TypeError.
	 * Converting here keeps one representation on the way in.
	 *
	 * @param mixed $value Raw filters.
	 * @return list<FilterClause>
	 * @throws DatabaseRefusal When an operator is outside the closed enum.
	 */
	protected function filter_clauses( mixed $value ): array {
		$clauses = array();

		foreach ( $this->filters( $value ) as $filter ) {
			$clauses[] = FilterClause::fromArray( $filter );
		}

		return $clauses;
	}

	/**
	 * Ordering as clause objects.
	 *
	 * @param mixed $value Raw order.
	 * @return list<OrderClause>
	 */
	protected function order_clauses( mixed $value ): array {
		$clauses = array();

		foreach ( $this->order( $value ) as $term ) {
			$clauses[] = OrderClause::fromArray( $term );
		}

		return $clauses;
	}

	/**
	 * Relation schema fragment.
	 *
	 * @return array<string, mixed>
	 */
	protected function relation_schema(): array {
		return array(
			'type'        => 'string',
			'description' => 'Schema-qualified relation, e.g. "reporting.orders".',
		);
	}
}
