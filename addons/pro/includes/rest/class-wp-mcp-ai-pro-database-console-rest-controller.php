<?php
/**
 * Database toolkit console REST controller.
 *
 * Proposal 167 phase P3. The console surface for the scoped database toolkit:
 * the connections an operator declared, the scopes that grant them, the audit
 * trail and the health snapshot. Read-only by design — this controller never
 * writes a connection, a scope or a database row. The wp-admin page
 * ({@see WP_MCP_AI_Pro_Database_Settings_Page}) remains the write surface; the
 * console observes, and links there when an operator needs to change something.
 *
 * ## Why a separate namespace
 *
 * The generic toolkit controller (`/toolkit-manifests`) renders whatever a JSON
 * manifest declares. This surface reaches into the database kernel — registries,
 * probe, ledger — which no other toolkit has, so it lives at its own base rather
 * than teaching the generic controller one family's internals.
 *
 * Namespace: `mcp-ai-pro/v1`.
 * Base: `/database`.
 * Auth: cookie + nonce; every route requires `manage_options`.
 * Envelope: `{ success: true, data: … }` / `{ success: false, error:
 * { code, message } }`, identical to the toolkit contract.
 *
 * ## What a GET may do
 *
 * Nothing on a GET opens a socket. Probe results are read from the record's
 * stored `probe` field, written by the explicit test route (or by wp-admin).
 * Only `POST /connections/{id}/test` connects, because only that call was
 * asked to.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
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
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;

if ( ! class_exists( 'WP_MCP_AI_Pro_Database_Console_REST_Controller' ) ) {
	/**
	 * REST adapter for the database toolkit console.
	 */
	class WP_MCP_AI_Pro_Database_Console_REST_Controller {

		/**
		 * REST namespace.
		 */
		const NAMESPACE_V1 = 'mcp-ai-pro/v1';

		/**
		 * Route base.
		 *
		 * Distinct from `/database-proposals` and from the toolkit controller's
		 * `/toolkit-manifests`; duplicate route + method registrations replace
		 * each other in `WP_REST_Server`.
		 */
		const BASE = '/database';

		/**
		 * Audit buckets returned by the overview and health routes.
		 */
		const BUCKET_HOURS = 24;

		/**
		 * Hook the route registration.
		 *
		 * The module registry boots on every request (front-end included), so this
		 * must not call `register_rest_route()` directly — routes registered before
		 * `rest_api_init` trigger `_doing_it_wrong()` and are dropped.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Register the console routes.
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/overview',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'overview' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/audit',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'audit' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'connection'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'scope'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'tool'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'action'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'refusal_code' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'limit'        => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/health',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'health' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/scopes',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_scope' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'scope' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/scopes/(?P<id>[a-z0-9_-]+)',
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_scope' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/preview',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'preview' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'connection' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'scope'      => array(
							'type'     => 'object',
							'required' => true,
						),
						'query'      => array(
							'type' => 'object',
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/connections/(?P<id>[a-z0-9_:-]+)/test',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'test_connection' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);
		}

		/**
		 * Every console route requires the admin capability.
		 *
		 * @return bool
		 */
		public static function check_admin_permission(): bool {
			return current_user_can( 'manage_options' );
		}

		// ------------------------------------------------------------ handlers

		/**
		 * Everything the console needs to render its first paint.
		 *
		 * @return WP_REST_Response
		 */
		public static function overview(): WP_REST_Response {
			$settings = self::settings();
			$runtime  = self::runtime();

			// Called once, before any gatherer: `load()` is what makes the
			// adapter classes (and {@see WP_MCP_AI_Db_Limit_Defaults})
			// exist, so every later `new` depends on this having run.
			$available = self::available();

			return self::ok(
				array(
					'runtime'     => $runtime,
					'enabled'     => ! empty( $settings['enable_database_toolkit'] ),
					'write_path'  => ! empty( $settings['enable_database_writes'] ),
					'connections' => $available ? self::connections() : array(),
					'scopes'      => $available ? self::scopes() : array(),
					'pdo'         => self::pdo(),
					'limits'      => $available ? WP_MCP_AI_Db_Limit_Defaults::get() : array(),
					'audit'       => $available ? self::audit_snapshot() : self::empty_audit(),
					'admin_url'   => self::admin_link(),
				)
			);
		}

		/**
		 * The audit trail, newest first, with the filters the tool serves.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function audit( WP_REST_Request $request ): WP_REST_Response {
			if ( ! self::available() ) {
				return self::error( 'database_unavailable', WP_MCP_AI_Db_Runtime::reason_label(), 501 );
			}

			$filters = array_filter(
				array(
					'connection'   => (string) $request->get_param( 'connection' ),
					'scope'        => (string) $request->get_param( 'scope' ),
					'tool'         => (string) $request->get_param( 'tool' ),
					'action'       => (string) $request->get_param( 'action' ),
					'refusal_code' => (string) $request->get_param( 'refusal_code' ),
				),
				static fn ( string $value ): bool => '' !== $value
			);

			$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ) );
			$log   = new WP_MCP_AI_Db_Audit_Log();

			return self::ok(
				array(
					'records'  => $log->tail( $limit, $filters ),
					'filters'  => $filters,
					'retained' => $log->retained(),
					'summary'  => $log->summary( self::BUCKET_HOURS * HOUR_IN_SECONDS ),
				)
			);
		}

		/**
		 * The health snapshot: runtime, extensions, stored probes, refusal rate.
		 *
		 * @return WP_REST_Response
		 */
		public static function health(): WP_REST_Response {
			return self::ok(
				array(
					'runtime'     => self::runtime(),
					'pdo'         => self::pdo(),
					'connections' => self::available() ? self::connections() : array(),
					'audit'       => self::available() ? self::audit_snapshot() : self::empty_audit(),
				)
			);
		}

		/**
		 * Probe one connection and record the result.
		 *
		 * The only route here that opens a socket, and only because it was asked
		 * to. Mirrors the wp-admin handler exactly: probe, persist, invalidate the
		 * catalogue cache.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function test_connection( WP_REST_Request $request ): WP_REST_Response {
			if ( ! self::available() ) {
				return self::error( 'database_unavailable', WP_MCP_AI_Db_Runtime::reason_label(), 501 );
			}

			$id       = (string) $request->get_param( 'id' );
			$registry = new WP_MCP_AI_Db_Connection_Registry();
			$record   = $registry->get( $id );

			if ( null === $record ) {
				return self::error( 'connection_not_found', __( 'That connection does not exist.', 'mcp-ai-wpoos-pro' ), 404 );
			}

			$factory = new WP_MCP_AI_Db_Connection_Factory( new WP_MCP_AI_Db_Encrypted_Credential_Store() );
			$probe   = ( new WP_MCP_AI_Db_Probe( $factory ) )->run( $record );

			$registry->record_probe( $id, $probe );
			( new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Transient_Cache() ) )->invalidate( $record );

			return self::ok( array( 'probe' => $probe->toArray() ) );
		}

		/**
		 * Create or replace a scope.
		 *
		 * The registry is the validator: it builds a `ScopeDescriptor` (refusing a
		 * wildcard schema, an empty principal list, an unknown tier) and bumps the
		 * version only when the grant actually changed. This handler sanitises the
		 * wire shape and translates a refusal — it never re-implements a rule.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function save_scope( WP_REST_Request $request ): WP_REST_Response {
			if ( ! self::available() ) {
				return self::error( 'database_unavailable', WP_MCP_AI_Db_Runtime::reason_label(), 501 );
			}

			$raw    = $request->get_param( 'scope' );
			$record = self::scope_record( is_array( $raw ) ? $raw : array() );

			if ( '' === $record['id'] ) {
				return self::error( 'invalid_scope', __( 'A scope needs an id.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			if ( '' === $record['connection'] ) {
				return self::error( 'invalid_scope', __( 'A scope needs a connection.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			$connections = new WP_MCP_AI_Db_Connection_Registry();

			if ( null === $connections->get( $record['connection'] ) && ! str_starts_with( $record['connection'], 'rs' ) ) {
				return self::error( 'unknown_connection', __( 'Choose a connection that exists.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			try {
				$saved = ( new WP_MCP_AI_Db_Scope_Registry() )->save( $record );
			} catch ( DatabaseRefusal $refusal ) {
				return self::error( $refusal->getRefusalCode(), $refusal->getMessage(), 422 );
			}

			return self::ok( array( 'scope' => $saved->toArray() ) );
		}

		/**
		 * Delete a scope.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function delete_scope( WP_REST_Request $request ): WP_REST_Response {
			if ( ! self::available() ) {
				return self::error( 'database_unavailable', WP_MCP_AI_Db_Runtime::reason_label(), 501 );
			}

			$id = (string) $request->get_param( 'id' );

			if ( ! ( new WP_MCP_AI_Db_Scope_Registry() )->delete( $id ) ) {
				return self::error( 'unknown_scope', __( 'That scope does not exist.', 'mcp-ai-wpoos-pro' ), 404 );
			}

			return self::ok( array( 'deleted' => true ) );
		}

		/**
		 * Compile a draft (or saved) scope against a relation, without reading a row.
		 *
		 * The editor sends the form's current values, so an operator can see the
		 * statement, the masks and the caps *before* saving. The draft is validated
		 * by `ScopeDescriptor::fromArray()`; the compile itself is the executor's
		 * ({@see \Nvoos\Database\Domain\Service\Database\PdoExecutor::preview()}),
		 * so this route cannot disagree with what a real read would do.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function preview( WP_REST_Request $request ): WP_REST_Response {
			if ( ! self::available() ) {
				return self::error( 'database_unavailable', WP_MCP_AI_Db_Runtime::reason_label(), 501 );
			}

			$raw = $request->get_param( 'scope' );

			try {
				$scope = ScopeDescriptor::fromArray( self::scope_record( is_array( $raw ) ? $raw : array() ) );
			} catch ( DatabaseRefusal $refusal ) {
				return self::error( $refusal->getRefusalCode(), $refusal->getMessage(), 422 );
			}

			$draft = $request->get_param( 'query' );
			$draft = is_array( $draft ) ? $draft : array();

			// Check the wire shape before constructing: the value object refuses an
			// empty relation from its constructor, and we want a field-level 422.
			if ( '' === trim( (string) ( $draft['relation'] ?? '' ) ) ) {
				return self::error( 'relation_required', __( 'Choose a relation to preview.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			try {
				$query = SelectQuery::fromArray( $draft );
			} catch ( DatabaseRefusal $refusal ) {
				return self::error( $refusal->getRefusalCode(), $refusal->getMessage(), 422 );
			}

			try {
				$preview = WP_MCP_AI_Db_Executor_Builder::build()->preview(
					(string) $request->get_param( 'connection' ),
					$scope,
					$query
				);
			} catch ( DatabaseRefusal $refusal ) {
				return self::error(
					$refusal->getRefusalCode(),
					$refusal->getMessage(),
					self::status_for( $refusal->getRefusal() )
				);
			}

			return self::ok( array( 'preview' => $preview->toArray() ) );
		}

		// ------------------------------------------------------------- gathers

		/**
		 * Whether the kernel and adapters can run on this site.
		 */
		private static function available(): bool {
			return WP_MCP_AI_Db_Runtime::load();
		}

		/**
		 * Runtime availability, in the shape the console renders.
		 *
		 * @return array<string, mixed>
		 */
		private static function runtime(): array {
			return array(
				'available'    => WP_MCP_AI_Db_Runtime::is_available(),
				'reason'       => WP_MCP_AI_Db_Runtime::reason(),
				'reason_label' => WP_MCP_AI_Db_Runtime::reason_label(),
				'php'          => PHP_VERSION,
			);
		}

		/**
		 * Stored settings.
		 *
		 * @return array<string, mixed>
		 */
		private static function settings(): array {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			return is_array( $settings ) ? $settings : array();
		}

		/**
		 * Connection records without the sealed credential.
		 *
		 * `summaries()` alone omits host/database, which an operator needs to
		 * recognise a row; `find_public()` carries them and drops the secret. The
		 * merge keeps `queryable`/`probe` from the descriptor and the identifying
		 * fields from the record.
		 *
		 * @return list<array<string, mixed>>
		 */
		private static function connections(): array {
			$registry = new WP_MCP_AI_Db_Connection_Registry();
			$rows     = array();

			foreach ( $registry->summaries() as $summary ) {
				$id     = (string) ( $summary['id'] ?? '' );
				$public = '' === $id ? null : $registry->find_public( $id );

				$rows[] = array_merge(
					$summary,
					array(
						'host'           => (string) ( $public['host'] ?? '' ),
						'port'           => (int) ( $public['port'] ?? 0 ),
						'database'       => (string) ( $public['database'] ?? '' ),
						'sslmode'        => (string) ( $public['sslmode'] ?? '' ),
						'role'           => (string) ( $public['role'] ?? '' ),
						'allow_site_db'  => ! empty( $public['allow_site_db'] ),
						'has_credential' => ! empty( $public['has_credential'] ),
					)
				);
			}

			return $rows;
		}

		/**
		 * Scope records, normalised; an invalid record stays visible.
		 *
		 * A governance console that hides a scope it cannot parse is worse than
		 * useless: the grant still exists in storage and may still be served. The
		 * `error` row is what makes a corrupted record actionable.
		 *
		 * @return list<array<string, mixed>>
		 */
		private static function scopes(): array {
			$registry = new WP_MCP_AI_Db_Scope_Registry();
			$rows     = array();

			foreach ( $registry->all() as $id => $record ) {
				try {
					$rows[] = \Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor::fromArray(
						array( 'id' => (string) $id ) + $record
					)->toArray();
				} catch ( \Throwable $error ) {
					$rows[] = array(
						'id'    => (string) $id,
						'error' => $error instanceof \Nvoos\Database\Domain\Service\Database\DatabaseRefusal
							? $error->getRefusalCode()
							: 'invalid_scope',
					);
				}
			}

			return $rows;
		}

		/**
		 * Which PDO drivers are loaded.
		 *
		 * Reported per engine because a missing driver is a per-connection
		 * condition (`db_extension_missing`), not an availability failure.
		 *
		 * @return array<string, bool>
		 */
		private static function pdo(): array {
			return array(
				'pgsql'  => extension_loaded( 'pdo_pgsql' ),
				'mysql'  => extension_loaded( 'pdo_mysql' ),
				'sqlite' => extension_loaded( 'pdo_sqlite' ),
			);
		}

		/**
		 * Audit totals, refusal counts and the 24-hour sparkline.
		 *
		 * @return array<string, mixed>
		 */
		private static function audit_snapshot(): array {
			$log     = new WP_MCP_AI_Db_Audit_Log();
			$window  = self::BUCKET_HOURS * HOUR_IN_SECONDS;
			$summary = $log->summary( $window );

			return array(
				'retained'  => $log->retained(),
				'total_24h' => (int) ( $summary['total'] ?? 0 ),
				'refusals'  => array_filter(
					$summary,
					static fn ( $value, $key ): bool => 'total' !== $key,
					ARRAY_FILTER_USE_BOTH
				),
				'buckets'   => self::buckets( $log ),
			);
		}

		/**
		 * The empty snapshot, for a host where the kernel cannot load.
		 *
		 * @return array<string, mixed>
		 */
		private static function empty_audit(): array {
			return array(
				'retained'  => 0,
				'total_24h' => 0,
				'refusals'  => array(),
				'buckets'   => array(),
			);
		}

		/**
		 * Hourly call counts for the sparkline.
		 *
		 * Built from the ring itself rather than a second store: the log is the
		 * only trail there is, so a chart that disagreed with the list would be
		 * worse than no chart. Fixed-length so the client never has to pad.
		 *
		 * @param WP_MCP_AI_Db_Audit_Log $log Audit log.
		 * @return list<array{ts: int, calls: int, refusals: int}>
		 */
		private static function buckets( WP_MCP_AI_Db_Audit_Log $log ): array {
			$now   = time();
			$stop  = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
			$start = $stop - ( ( self::BUCKET_HOURS - 1 ) * HOUR_IN_SECONDS );

			$series = array();

			for ( $ts = $start; $ts <= $stop; $ts += HOUR_IN_SECONDS ) {
				$series[ $ts ] = array(
					'ts'       => $ts,
					'calls'    => 0,
					'refusals' => 0,
				);
			}

			foreach ( $log->tail( $log->retained(), array() ) as $record ) {
				$ts = (int) ( $record['ts'] ?? 0 );

				if ( $ts < $start ) {
					continue;
				}

				$bucket = (int) ( floor( $ts / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );

				if ( ! isset( $series[ $bucket ] ) ) {
					continue;
				}

				++$series[ $bucket ]['calls'];

				if ( ! empty( $record['refusal_code'] ) ) {
					++$series[ $bucket ]['refusals'];
				}
			}

			return array_values( $series );
		}

		/**
		 * Deep link to the wp-admin page that owns the write surface.
		 *
		 * The page class is loaded by the toolkit initialiser, which does not run
		 * on a front-end REST request — referencing `::SLUG` unguarded is the
		 * provider-class bug class this codebase keeps re-learning (the probe
		 * test caught it: "Class WP_MCP_AI_Pro_Database_Settings_Page not
		 * found"). Use the class when it is there, the literal otherwise; the
		 * two are pinned together by the settings-page test.
		 */
		private static function admin_link(): string {
			if ( ! function_exists( 'admin_url' ) ) {
				return '';
			}

			$page = class_exists( 'WP_MCP_AI_Pro_Database_Settings_Page' )
				? WP_MCP_AI_Pro_Database_Settings_Page::SLUG
				: 'wp-mcp-ai-database';

			return add_query_arg(
				array( 'page' => $page ),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Normalise the wire shape of a scope into the stored record shape.
		 *
		 * Sanitised at entry, validated by the registry: the console sends arrays,
		 * but a hand-written request may send anything, and a comma-joined string
		 * in an array field must not survive into storage.
		 *
		 * @param array<string, mixed> $raw Raw scope payload.
		 * @return array<string, mixed>
		 */
		private static function scope_record( array $raw ): array {
			$tiers = array();

			foreach ( (array) ( $raw['tiers'] ?? array() ) as $tier ) {
				if ( ! is_string( $tier ) ) {
					continue;
				}

				$tier = strtolower( trim( $tier ) );

				if ( null !== GrantTier::tryFrom( $tier ) ) {
					$tiers[] = $tier;
				}
			}

			return array(
				'id'         => sanitize_key( (string) ( $raw['id'] ?? '' ) ),
				'label'      => sanitize_text_field( (string) ( $raw['label'] ?? '' ) ),
				'connection' => sanitize_key( (string) ( $raw['connection'] ?? '' ) ),
				'schemas'    => self::string_list( $raw['schemas'] ?? array() ),
				'tables'     => self::string_list( $raw['tables'] ?? array() ),
				'columns'    => self::columns_map( $raw['columns'] ?? array() ),
				'row_filter' => self::filter_list( $raw['row_filter'] ?? array() ),
				'tiers'      => $tiers,
				'limits'     => self::int_map( $raw['limits'] ?? array() ),
				'principals' => self::string_list( $raw['principals'] ?? array() ),
			);
		}

		/**
		 * A list of trimmed strings from an array or a comma-separated string.
		 *
		 * @param mixed $value Raw value.
		 * @return list<string>
		 */
		private static function string_list( $value ): array {
			if ( is_string( $value ) ) {
				$value = preg_split( '/[\r\n,]+/', $value );
			}

			$list = array();

			foreach ( (array) $value as $item ) {
				if ( ! is_string( $item ) ) {
					continue;
				}

				$item = strtolower( sanitize_text_field( trim( $item ) ) );

				if ( '' !== $item ) {
					$list[] = $item;
				}
			}

			return array_values( array_unique( $list ) );
		}

		/**
		 * Per-relation column rules, normalised.
		 *
		 * @param mixed $value Raw map.
		 * @return array<string, array<string, list<string>>>
		 */
		private static function columns_map( $value ): array {
			$map = array();

			foreach ( is_array( $value ) ? $value : array() as $relation => $rules ) {
				if ( ! is_string( $relation ) || ! is_array( $rules ) ) {
					continue;
				}

				$relation = strtolower( sanitize_text_field( trim( $relation ) ) );
				$entry    = array();

				foreach ( array( 'exclude', 'sensitive', 'searchable' ) as $rule ) {
					if ( ! isset( $rules[ $rule ] ) ) {
						continue;
					}

					$columns = self::string_list( $rules[ $rule ] );

					if ( array() !== $columns ) {
						$entry[ $rule ] = $columns;
					}
				}

				if ( '' !== $relation && array() !== $entry ) {
					$map[ $relation ] = $entry;
				}
			}

			return $map;
		}

		/**
		 * Mandatory row filters, normalised.
		 *
		 * @param mixed $value Raw list.
		 * @return list<array<string, mixed>>
		 */
		private static function filter_list( $value ): array {
			$list = array();

			foreach ( is_array( $value ) ? $value : array() as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$column = strtolower( sanitize_text_field( (string) ( $rule['column'] ?? '' ) ) );
				$op     = strtolower( sanitize_key( (string) ( $rule['op'] ?? $rule['operator'] ?? '' ) ) );

				if ( '' === $column || '' === $op ) {
					continue;
				}

				$filter = array(
					'column' => $column,
					'op'     => $op,
				);

				if ( isset( $rule['relation'] ) && '' !== trim( (string) $rule['relation'] ) ) {
					$filter['relation'] = strtolower( sanitize_text_field( (string) $rule['relation'] ) );
				}

				if ( isset( $rule['value_from'] ) && '' !== trim( (string) $rule['value_from'] ) ) {
					$filter['value_from'] = sanitize_key( (string) $rule['value_from'] );
				}

				if ( array_key_exists( 'value', $rule ) ) {
					// Bound at execution; never interpolated, so the raw scalar is safe
					// to store and the grammar refuses a non-scalar.
					$filter['value'] = is_scalar( $rule['value'] ) ? $rule['value'] : null;
				}

				$list[] = $filter;
			}

			return $list;
		}

		/**
		 * Numeric limit overrides.
		 *
		 * @param mixed $value Raw map.
		 * @return array<string, int>
		 */
		private static function int_map( $value ): array {
			$allowed = array( 'max_rows', 'max_bytes', 'timeout_ms', 'cell_bytes', 'offset_max', 'max_affected_rows' );
			$map     = array();

			foreach ( is_array( $value ) ? $value : array() as $key => $number ) {
				if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) || ! is_numeric( $number ) ) {
					continue;
				}

				$map[ $key ] = max( 0, (int) $number );
			}

			return $map;
		}

		/**
		 * HTTP status for a refusal, matching the tool surface.
		 *
		 * @param RefusalCode $code Refusal.
		 */
		private static function status_for( RefusalCode $code ): int {
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
				RefusalCode::LOCK_TIMEOUT => 504,
				RefusalCode::CONNECTION_LIMIT => 503,
				RefusalCode::EXPORT_LIMIT => 429,
				default => 422,
			};
		}

		// ------------------------------------------------------------ envelopes

		/**
		 * Success envelope.
		 *
		 * @param array<string, mixed> $data   Payload.
		 * @param int                  $status HTTP status.
		 * @return WP_REST_Response
		 */
		private static function ok( array $data, int $status = 200 ): WP_REST_Response {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				$status
			);
		}

		/**
		 * Error envelope.
		 *
		 * @param string $code    Machine code.
		 * @param string $message Human message.
		 * @param int    $status  HTTP status.
		 * @return WP_REST_Response
		 */
		private static function error( string $code, string $message, int $status = 400 ): WP_REST_Response {
			return new WP_REST_Response(
				array(
					// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- A REST envelope, not a tool return: the console contract is { success, error } and the client reads error.code / error.message. A WP_Error here would arrive as WordPress's own error body and lose the contract.
					'success' => false,
					'error'   => array(
						'code'    => $code,
						'message' => $message,
					),
				),
				$status
			);
		}
	}
}
