<?php
/**
 * Schema introspection, filtered and cached.
 *
 * Proposal 167 decisions D7 and the `metadata` tier. The catalogue is the only
 * source of relation and column names the grammar will emit: a name that is not
 * here cannot reach a statement. That is what makes identifier injection
 * structurally impossible rather than a matter of escaping carefully.
 *
 * ## What is never emitted
 *
 * System schemas (`pg_catalog`, `information_schema`, `pg_toast*`, MySQL's
 * `mysql`/`performance_schema`/`sys`, SQLite's `sqlite_*`) are excluded in SQL,
 * not filtered afterwards, so a scope cannot widen the result by naming one.
 *
 * ## Caching
 *
 * Introspection on a large schema is expensive and changes rarely. Results are
 * cached per connection for a short TTL. Invalidation bumps a per-connection
 * flush token rather than trying to enumerate keys, because the cache interface
 * deliberately has no scan operation.
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
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use Nvoos\Database\Domain\Contract\Database\CatalogueInterface;

/**
 * Builds a {@see CatalogueDescriptor} from a live connection.
 */
final class WP_MCP_AI_Db_Catalogue implements CatalogueInterface {

	/**
	 * Default cache lifetime, in seconds.
	 */
	public const DEFAULT_TTL = 300;

	/**
	 * Maximum relations loaded when no schema filter is supplied.
	 */
	private const MAX_RELATIONS = 500;

	/**
	 * Connection factory.
	 *
	 * @var WP_MCP_AI_Db_Connection_Factory
	 */
	private WP_MCP_AI_Db_Connection_Factory $factory;

	/**
	 * Cache.
	 *
	 * @var WP_MCP_AI_Db_Cache_Interface
	 */
	private WP_MCP_AI_Db_Cache_Interface $cache;

	/**
	 * Cache lifetime.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Db_Connection_Factory   $factory Factory.
	 * @param WP_MCP_AI_Db_Cache_Interface|null $cache Cache; an array cache is used when omitted.
	 * @param int                               $ttl   Cache lifetime in seconds.
	 */
	public function __construct(
		WP_MCP_AI_Db_Connection_Factory $factory,
		?WP_MCP_AI_Db_Cache_Interface $cache = null,
		int $ttl = self::DEFAULT_TTL
	) {
		$this->factory = $factory;
		$this->cache   = $cache ?? new WP_MCP_AI_Db_Array_Cache();
		$this->ttl     = max( 1, $ttl );
	}

	/**
	 * Load the catalogue for a connection.
	 *
	 * @param ConnectionDescriptor $connection Connection.
	 * @param string[]             $schemas    Schemas to load; system schemas are removed regardless.
	 * @throws DatabaseRefusal When introspection fails.
	 */
	public function load( ConnectionDescriptor $connection, array $schemas = array() ): CatalogueDescriptor {
		$schemas = $this->normalise_schemas( $schemas, $connection );

		$key    = $this->cache_key( $connection, $schemas );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $this->hydrate( $cached );
		}

		$pdo    = $this->factory->open( $connection );
		$tables = match ( $connection->engine ) {
			ConnectionDescriptor::PGSQL   => $this->introspect_pgsql( $pdo, $schemas ),
			ConnectionDescriptor::MYSQL,
			ConnectionDescriptor::MARIADB => $this->introspect_mysql( $pdo, $schemas ),
			ConnectionDescriptor::SQLITE  => $this->introspect_sqlite( $pdo ),
			default                       => array(),
		};

		$catalogue = new CatalogueDescriptor( $tables );
		$this->cache->set( $key, $this->serialise( $catalogue ), $this->ttl );

		return $catalogue;
	}

	/**
	 * Invalidate the cache for a connection.
	 *
	 * @param ConnectionDescriptor $connection Connection.
	 */
	public function invalidate( ConnectionDescriptor $connection ): void {
		$this->cache->set( 'flush:' . $connection->id, array( 'at' => time() ), $this->ttl * 12 );
	}

	/**
	 * Normalise and bound the schema allowlist.
	 *
	 * @param string[]             $schemas    Requested schemas.
	 * @param ConnectionDescriptor $connection Connection, for the engine default.
	 * @return list<string>
	 */
	private function normalise_schemas( array $schemas, ConnectionDescriptor $connection ): array {
		$system  = $this->system_schemas( $connection->engine );
		$cleaned = array();

		foreach ( $schemas as $schema ) {
			if ( ! is_string( $schema ) ) {
				continue;
			}

			$schema = strtolower( trim( $schema ) );

			if ( '' === $schema || in_array( $schema, $system, true ) ) {
				continue;
			}

			$cleaned[ $schema ] = true;
		}

		if ( array() === $cleaned && ConnectionDescriptor::SQLITE === $connection->engine ) {
			return array( 'main' );
		}

		return array_keys( $cleaned );
	}

	/**
	 * System schemas per engine.
	 *
	 * @param string $engine Engine.
	 * @return list<string>
	 */
	private function system_schemas( string $engine ): array {
		if ( ConnectionDescriptor::PGSQL === $engine ) {
			return array( 'pg_catalog', 'information_schema' );
		}

		if ( ConnectionDescriptor::MYSQL === $engine || ConnectionDescriptor::MARIADB === $engine ) {
			return array( 'mysql', 'information_schema', 'performance_schema', 'sys' );
		}

		return array();
	}

	/**
	 * PostgreSQL introspection.
	 *
	 * @param PDO      $pdo     Handle.
	 * @param string[] $schemas Schema allowlist; empty means every non-system schema up to the bound.
	 * @return array<string, TableDescriptor>
	 */
	private function introspect_pgsql( PDO $pdo, array $schemas ): array {
		$filter = '';
		$params = array();

		if ( array() !== $schemas ) {
			$placeholders = array();

			foreach ( $schemas as $index => $schema ) {
				$key            = ':s' . $index;
				$placeholders[] = $key;
				$params[ $key ] = $schema;
			}

			$filter = ' AND c.table_schema IN (' . implode( ', ', $placeholders ) . ')';
		}

		$columns = $this->fetch_all(
			$pdo,
			"SELECT c.table_schema, c.table_name, c.column_name, c.data_type, c.is_nullable
			   FROM information_schema.columns c
			   JOIN information_schema.tables t
			     ON t.table_schema = c.table_schema AND t.table_name = c.table_name
			  WHERE c.table_schema NOT IN ('pg_catalog', 'information_schema')
			    AND c.table_schema NOT LIKE 'pg_toast%'
			    AND t.table_type IN ('BASE TABLE', 'VIEW')
			    {$filter}
			  ORDER BY c.table_schema, c.table_name, c.ordinal_position
			  LIMIT 20000",
			$params
		);

		$views = array();

		if ( array() !== $schemas ) {
			$view_filter = str_replace( 'c.table_schema', 'v.table_schema', $filter );
			$view_rows   = $this->fetch_all(
				$pdo,
				"SELECT v.table_schema, v.table_name
				   FROM information_schema.views v
				  WHERE v.table_schema NOT IN ('pg_catalog', 'information_schema')
				    {$view_filter}
				  LIMIT 2000",
				$params
			);

			foreach ( $view_rows as $row ) {
				$views[ strtolower( (string) $row['table_schema'] . '.' . (string) $row['table_name'] ) ] = true;
			}
		}

		$primary = $this->fetch_pgsql_primary_keys( $pdo, $schemas );

		return $this->assemble( $columns, $views, $primary );
	}

	/**
	 * Primary keys for PostgreSQL.
	 *
	 * @param PDO      $pdo     Handle.
	 * @param string[] $schemas Schema allowlist.
	 * @return array<string, list<string>>
	 */
	private function fetch_pgsql_primary_keys( PDO $pdo, array $schemas ): array {
		$filter = '';
		$params = array();

		if ( array() !== $schemas ) {
			$placeholders = array();

			foreach ( $schemas as $index => $schema ) {
				$key            = ':s' . $index;
				$placeholders[] = $key;
				$params[ $key ] = $schema;
			}

			$filter = ' AND tc.table_schema IN (' . implode( ', ', $placeholders ) . ')';
		}

		$rows = $this->fetch_all(
			$pdo,
			"SELECT tc.table_schema, tc.table_name, kcu.column_name
			   FROM information_schema.table_constraints tc
			   JOIN information_schema.key_column_usage kcu
			     ON kcu.constraint_name = tc.constraint_name
			    AND kcu.table_schema = tc.table_schema
			    AND kcu.table_name = tc.table_name
			  WHERE tc.constraint_type = 'PRIMARY KEY'
			    {$filter}
			  ORDER BY tc.table_schema, tc.table_name, kcu.ordinal_position",
			$params
		);

		$keys = array();

		foreach ( $rows as $row ) {
			$key            = strtolower( (string) $row['table_schema'] . '.' . (string) $row['table_name'] );
			$keys[ $key ][] = (string) $row['column_name'];
		}

		return $keys;
	}

	/**
	 * MySQL/MariaDB introspection.
	 *
	 * @param PDO      $pdo     Handle.
	 * @param string[] $schemas Schema allowlist.
	 * @return array<string, TableDescriptor>
	 */
	private function introspect_mysql( PDO $pdo, array $schemas ): array {
		$filter = '';
		$params = array();

		if ( array() !== $schemas ) {
			$placeholders = array();

			foreach ( $schemas as $index => $schema ) {
				$key            = ':s' . $index;
				$placeholders[] = $key;
				$params[ $key ] = $schema;
			}

			$filter = ' AND c.table_schema IN (' . implode( ', ', $placeholders ) . ')';
		}

		$columns = $this->fetch_all(
			$pdo,
			"SELECT c.table_schema, c.table_name, c.column_name, c.data_type, c.is_nullable
			   FROM information_schema.columns c
			   JOIN information_schema.tables t
			     ON t.table_schema = c.table_schema AND t.table_name = c.table_name
			  WHERE c.table_schema NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
			    AND t.table_type IN ('BASE TABLE', 'VIEW')
			    {$filter}
			  ORDER BY c.table_schema, c.table_name, c.ordinal_position
			  LIMIT 20000",
			$params
		);

		$view_rows = $this->fetch_all(
			$pdo,
			"SELECT t.table_schema, t.table_name
			   FROM information_schema.tables t
			  WHERE t.table_type = 'VIEW'
			    AND t.table_schema NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
			    {$filter}",
			$params
		);

		$views = array();

		foreach ( $view_rows as $row ) {
			$views[ strtolower( (string) $row['table_schema'] . '.' . (string) $row['table_name'] ) ] = true;
		}

		$keys = array();

		foreach ( $this->fetch_all(
			$pdo,
			"SELECT k.table_schema, k.table_name, k.column_name
			   FROM information_schema.key_column_usage k
			  WHERE k.constraint_name = 'PRIMARY'
			    {$filter}
			  ORDER BY k.table_schema, k.table_name, k.ordinal_position",
			$params
		) as $row ) {
			$key            = strtolower( (string) $row['table_schema'] . '.' . (string) $row['table_name'] );
			$keys[ $key ][] = (string) $row['column_name'];
		}

		return $this->assemble( $columns, $views, $keys );
	}

	/**
	 * SQLite introspection.
	 *
	 * @param PDO $pdo Handle.
	 * @return array<string, TableDescriptor>
	 */
	private function introspect_sqlite( PDO $pdo ): array {
		$relations = $this->fetch_all(
			$pdo,
			"SELECT name, type FROM sqlite_master
			  WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'
			  ORDER BY name
			  LIMIT " . self::MAX_RELATIONS
		);

		$tables = array();
		$views  = array();

		foreach ( $relations as $relation ) {
			$name = (string) $relation['name'];

			if ( '' === $name ) {
				continue;
			}

			$info    = $this->fetch_all( $pdo, 'PRAGMA table_info(' . $this->quote_sqlite( $name ) . ')' );
			$columns = array();
			$primary = array();

			foreach ( $info as $column ) {
				$column_name = (string) ( $column['name'] ?? '' );

				if ( '' === $column_name ) {
					continue;
				}

				$columns[ strtolower( $column_name ) ] = new ColumnDescriptor(
					$column_name,
					(string) ( $column['type'] ?? '' ),
					0 === (int) ( $column['notnull'] ?? 0 )
				);

				if ( (int) ( $column['pk'] ?? 0 ) > 0 ) {
					$primary[ (int) $column['pk'] ] = $column_name;
				}
			}

			ksort( $primary );

			$descriptor = new TableDescriptor(
				'main',
				$name,
				$columns,
				array_values( $primary ),
				'view' === (string) $relation['type']
			);

			$tables[ strtolower( 'main.' . $name ) ] = $descriptor;
		}

		return $tables;
	}

	/**
	 * Quote a SQLite identifier for a PRAGMA.
	 *
	 * PRAGMA arguments cannot be bound, so the name is wrapped and any embedded
	 * quote doubled. The value originates from `sqlite_master`, not from a caller.
	 *
	 * @param string $name Relation name.
	 */
	private function quote_sqlite( string $name ): string {
		return '"' . str_replace( '"', '""', $name ) . '"';
	}

	/**
	 * Turn column rows into descriptors.
	 *
	 * @param list<array<string, mixed>>  $columns Column rows.
	 * @param array<string, bool>         $views   View keys.
	 * @param array<string, list<string>> $primary Primary keys.
	 * @return array<string, TableDescriptor>
	 */
	private function assemble( array $columns, array $views, array $primary ): array {
		$grouped = array();

		foreach ( $columns as $row ) {
			$schema = (string) ( $row['table_schema'] ?? '' );
			$table  = (string) ( $row['table_name'] ?? '' );
			$name   = (string) ( $row['column_name'] ?? '' );

			if ( '' === $schema || '' === $table || '' === $name ) {
				continue;
			}

			$key = strtolower( $schema . '.' . $table );

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'schema'  => $schema,
					'table'   => $table,
					'columns' => array(),
				);
			}

			$grouped[ $key ]['columns'][ strtolower( $name ) ] = new ColumnDescriptor(
				$name,
				(string) ( $row['data_type'] ?? '' ),
				'YES' === strtoupper( (string) ( $row['is_nullable'] ?? 'YES' ) )
			);
		}

		$tables = array();

		foreach ( array_slice( $grouped, 0, self::MAX_RELATIONS, true ) as $key => $entry ) {
			$tables[ $key ] = new TableDescriptor(
				$entry['schema'],
				$entry['table'],
				$entry['columns'],
				$primary[ $key ] ?? array(),
				isset( $views[ $key ] )
			);
		}

		ksort( $tables );

		return $tables;
	}

	/**
	 * Fetch all rows, binding parameters when supplied.
	 *
	 * @param PDO                  $pdo    Handle.
	 * @param string               $sql    Statement.
	 * @param array<string, mixed> $params Bindings.
	 * @return list<array<string, mixed>>
	 */
	private function fetch_all( PDO $pdo, string $sql, array $params = array() ): array {
		$statement = $pdo->prepare( $sql );
		$statement->execute( $params );
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC );
		$statement->closeCursor();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Cache key for a connection and schema set.
	 *
	 * @param ConnectionDescriptor $connection Connection.
	 * @param string[]             $schemas    Schemas.
	 */
	private function cache_key( ConnectionDescriptor $connection, array $schemas ): string {
		$flush = $this->cache->get( 'flush:' . $connection->id );
		$token = is_array( $flush ) ? (int) ( $flush['at'] ?? 0 ) : 0;

		return 'catalogue:' . $connection->id . ':' . $token . ':' . md5( (string) wp_json_encode( $schemas ) );
	}

	/**
	 * Serialise a catalogue for the cache.
	 *
	 * @param CatalogueDescriptor $catalogue Catalogue.
	 * @return array<string, mixed>
	 */
	private function serialise( CatalogueDescriptor $catalogue ): array {
		$tables = array();

		foreach ( $catalogue->tables as $key => $table ) {
			$columns = array();

			foreach ( $table->columns as $name => $column ) {
				$columns[ $name ] = array(
					'name'     => $column->name,
					'type'     => $column->type,
					'nullable' => $column->nullable,
				);
			}

			$tables[ $key ] = array(
				'schema'      => $table->schema,
				'name'        => $table->name,
				'columns'     => $columns,
				'primary_key' => $table->primaryKey,
				'is_view'     => $table->isView,
			);
		}

		return array( 'tables' => $tables );
	}

	/**
	 * Rebuild a catalogue from cached data.
	 *
	 * @param array<string, mixed> $cached Cached payload.
	 */
	private function hydrate( array $cached ): CatalogueDescriptor {
		$tables = array();

		foreach ( (array) ( $cached['tables'] ?? array() ) as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$columns = array();

			foreach ( (array) ( $entry['columns'] ?? array() ) as $name => $column ) {
				if ( ! is_array( $column ) ) {
					continue;
				}

				$columns[ (string) $name ] = new ColumnDescriptor(
					(string) ( $column['name'] ?? $name ),
					(string) ( $column['type'] ?? '' ),
					(bool) ( $column['nullable'] ?? true )
				);
			}

			$tables[ (string) $key ] = new TableDescriptor(
				(string) ( $entry['schema'] ?? '' ),
				(string) ( $entry['name'] ?? '' ),
				$columns,
				array_values( array_map( 'strval', (array) ( $entry['primary_key'] ?? array() ) ) ),
				(bool) ( $entry['is_view'] ?? false )
			);
		}

		return new CatalogueDescriptor( $tables );
	}
}
