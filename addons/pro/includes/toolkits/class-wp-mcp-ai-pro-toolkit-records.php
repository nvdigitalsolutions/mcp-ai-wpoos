<?php
/**
 * Toolkit records repository.
 *
 * One table (`{prefix}nvoos_toolkit_records`) stores every family's records;
 * the manifest decides the shape. This class owns the query, validation,
 * aggregation and import logic so the REST controller stays a thin adapter.
 *
 * The Laravel runtime implements the same contract in
 * `App\Http\Controllers\Pro\ToolkitsController`; behaviour (validation
 * messages, envelope keys, import accounting) is kept identical so the shared
 * console screens cannot tell the two backends apart.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Records' ) ) {
	/**
	 * Records CRUD, validation, aggregates and bulk import.
	 *
	 * @since 2.6.0
	 */
	class WP_MCP_AI_Pro_Toolkit_Records {

		/**
		 * Maximum rows accepted by one import.
		 */
		const IMPORT_LIMIT = 500;

		/**
		 * Maximum duplicate groups returned by one scan.
		 */
		const DUPLICATE_GROUP_LIMIT = 100;

		/**
		 * One page of records.
		 *
		 * @param ToolkitManifest      $manifest Manifest.
		 * @param string               $entity   Entity key.
		 * @param array<string, mixed> $args     page, per_page, search, filter.
		 * @return array{records: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
		 */
		public static function query( ToolkitManifest $manifest, string $entity, array $args = array() ): array {
			global $wpdb;

			$config = $manifest->entity( $entity );

			if ( null === $config ) {
				return array(
					'records'  => array(),
					'total'    => 0,
					'page'     => 1,
					'per_page' => 0,
				);
			}

			$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
			$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );

			$where  = array( 'toolkit = %s', 'entity = %s' );
			$params = array( $manifest->slug(), $entity );

			$search = trim( (string) ( $args['search'] ?? '' ) );

			if ( '' !== $search ) {
				self::apply_search( $where, $params, $config, $search );
			}

			$filters = $args['filter'] ?? array();

			if ( is_array( $filters ) ) {
				foreach ( $filters as $column => $value ) {
					if ( ! is_string( $column ) || ! self::entity_has_column( $config, $column ) || ! is_scalar( $value ) ) {
						continue;
					}

					if ( '' === (string) $value ) {
						continue;
					}

					$where[]  = self::json_path( $column ) . ' = %s';
					$params[] = (string) $value;
				}
			}

			$table = WP_MCP_AI_Pro_Toolkit_Manifests::table();
			$sql   = 'WHERE ' . implode( ' AND ', $where );

			$total = (int) self::value(
				"SELECT COUNT(*) FROM {$table} {$sql}",
				$params
			);

			$offset = ( $page - 1 ) * $per_page;

			$rows = self::results(
				"SELECT * FROM {$table} {$sql} ORDER BY id ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			);

			return array(
				'records'  => array_map( array( __CLASS__, 'shape' ), $rows ),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		/**
		 * One record by business key.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @param string          $entity   Entity key.
		 * @param string          $id       Business key.
		 * @return array<string, mixed>|null
		 */
		public static function find( ToolkitManifest $manifest, string $entity, string $id ): ?array {
			$row = self::row( $manifest, $entity, $id );

			return null === $row ? null : self::shape( $row );
		}

		/**
		 * Create a record.
		 *
		 * @param ToolkitManifest      $manifest Manifest.
		 * @param string               $entity   Entity key.
		 * @param array<string, mixed> $data     Submitted payload.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function create( ToolkitManifest $manifest, string $entity, array $data ) {
			global $wpdb;

			$config = $manifest->entity( $entity );

			if ( null === $config ) {
				return new WP_Error( 'entity_not_found', __( 'Unknown entity.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
			}

			list( $errors, $normalized ) = self::validate( $config, $data, true );

			if ( array() !== $errors ) {
				return new WP_Error(
					'validation_failed',
					__( 'The record is invalid.', 'mcp-ai-wpoos-pro' ),
					array(
						'status' => 422,
						'fields' => $errors,
					)
				);
			}

			$id_field = (string) ( $config['id_field'] ?? '' );
			$external = (string) ( $normalized[ $id_field ] ?? '' );

			if ( null !== self::row( $manifest, $entity, $external ) ) {
				return new WP_Error(
					'duplicate_record',
					/* translators: %s: id field name. */
					sprintf( __( 'A record with that %s already exists.', 'mcp-ai-wpoos-pro' ), $id_field ),
					array( 'status' => 422 )
				);
			}

			$now = current_time( 'mysql', true );

			$inserted = $wpdb->insert(
				WP_MCP_AI_Pro_Toolkit_Manifests::table(),
				array(
					'toolkit'     => $manifest->slug(),
					'entity'      => $entity,
					'external_id' => $external,
					'data'        => (string) wp_json_encode( $normalized ),
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return new WP_Error( 'storage_failed', __( 'The record could not be stored.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
			}

			$record = self::find( $manifest, $entity, $external );

			return null === $record ? new WP_Error( 'storage_failed', __( 'The record could not be read back.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) ) : $record;
		}

		/**
		 * Patch a record. The business key is immutable.
		 *
		 * @param ToolkitManifest      $manifest Manifest.
		 * @param string               $entity   Entity key.
		 * @param string               $id       Business key.
		 * @param array<string, mixed> $data     Submitted payload.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function update( ToolkitManifest $manifest, string $entity, string $id, array $data ) {
			global $wpdb;

			$config = $manifest->entity( $entity );

			if ( null === $config ) {
				return new WP_Error( 'entity_not_found', __( 'Unknown entity.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
			}

			$row = self::row( $manifest, $entity, $id );

			if ( null === $row ) {
				return new WP_Error( 'record_not_found', __( 'Record not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
			}

			$id_field = (string) ( $config['id_field'] ?? '' );

			if ( array_key_exists( $id_field, $data ) && (string) $data[ $id_field ] !== $id ) {
				return new WP_Error(
					'id_immutable',
					/* translators: %s: id field name. */
					sprintf( __( 'The %s cannot be changed.', 'mcp-ai-wpoos-pro' ), $id_field ),
					array(
						'status' => 422,
						'fields' => array( $id_field => __( 'This value cannot be changed.', 'mcp-ai-wpoos-pro' ) ),
					)
				);
			}

			list( $errors, $normalized ) = self::validate( $config, $data, false );

			if ( array() !== $errors ) {
				return new WP_Error(
					'validation_failed',
					__( 'The record is invalid.', 'mcp-ai-wpoos-pro' ),
					array(
						'status' => 422,
						'fields' => $errors,
					)
				);
			}

			$current = json_decode( (string) $row->data, true );
			$merged  = array_merge( is_array( $current ) ? $current : array(), $normalized );

			$updated = $wpdb->update(
				WP_MCP_AI_Pro_Toolkit_Manifests::table(),
				array(
					'data'       => (string) wp_json_encode( $merged ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array(
					'toolkit'     => $manifest->slug(),
					'entity'      => $entity,
					'external_id' => $id,
				),
				array( '%s', '%s' ),
				array( '%s', '%s', '%s' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'storage_failed', __( 'The record could not be stored.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
			}

			$record = self::find( $manifest, $entity, $id );

			return null === $record ? new WP_Error( 'record_not_found', __( 'Record not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) ) : $record;
		}

		/**
		 * Delete a record by business key.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @param string          $entity   Entity key.
		 * @param string          $id       Business key.
		 * @return bool
		 */
		public static function delete( ToolkitManifest $manifest, string $entity, string $id ): bool {
			global $wpdb;

			$deleted = $wpdb->delete(
				WP_MCP_AI_Pro_Toolkit_Manifests::table(),
				array(
					'toolkit'     => $manifest->slug(),
					'entity'      => $entity,
					'external_id' => $id,
				),
				array( '%s', '%s', '%s' )
			);

			return is_int( $deleted ) && $deleted > 0;
		}

		/**
		 * Group the whole entity by one text/enum column.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @param string          $entity   Entity key.
		 * @param string          $column   Column key.
		 * @param int             $limit    Maximum groups.
		 * @return array{column: string, scanned: int, groups: array<int, array<string, mixed>>, truncated: bool}|WP_Error
		 */
		public static function duplicates( ToolkitManifest $manifest, string $entity, string $column, int $limit = 25 ) {
			global $wpdb;

			$config = $manifest->entity( $entity );

			if ( null === $config ) {
				return new WP_Error( 'entity_not_found', __( 'Unknown entity.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
			}

			if ( ! self::entity_has_column( $config, $column ) ) {
				return new WP_Error(
					'validation_failed',
					__( 'The record is invalid.', 'mcp-ai-wpoos-pro' ),
					array(
						'status' => 422,
						'fields' => array( 'column' => __( 'Choose a column of this entity.', 'mcp-ai-wpoos-pro' ) ),
					)
				);
			}

			$type = self::column_type( $config, $column );

			if ( ! in_array( $type, array( 'string', 'enum' ), true ) ) {
				return new WP_Error(
					'validation_failed',
					__( 'The record is invalid.', 'mcp-ai-wpoos-pro' ),
					array(
						'status' => 422,
						'fields' => array( 'column' => __( 'Only text or list columns can be grouped.', 'mcp-ai-wpoos-pro' ) ),
					)
				);
			}

			$limit   = max( 1, min( self::DUPLICATE_GROUP_LIMIT, $limit ) );
			$table   = WP_MCP_AI_Pro_Toolkit_Manifests::table();
			$path    = self::json_path( $column );
			$scanned = (int) self::value(
				"SELECT COUNT(*) FROM {$table} WHERE toolkit = %s AND entity = %s",
				array( $manifest->slug(), $entity )
			);

			$collisions = self::results(
				"SELECT {$path} AS group_value, COUNT(*) AS total
				 FROM {$table}
				 WHERE toolkit = %s AND entity = %s AND {$path} IS NOT NULL
				 GROUP BY {$path}
				 HAVING COUNT(*) > 1
				 ORDER BY COUNT(*) DESC
				 LIMIT %d",
				array( $manifest->slug(), $entity, $limit )
			);

			$groups = array();

			foreach ( $collisions as $collision ) {
				$value = (string) $collision->group_value;

				$members = self::results(
					"SELECT * FROM {$table} WHERE toolkit = %s AND entity = %s AND {$path} = %s ORDER BY id ASC",
					array( $manifest->slug(), $entity, $value )
				);

				$groups[] = array(
					'value'   => $value,
					'count'   => count( $members ),
					'records' => array_map( array( __CLASS__, 'shape' ), $members ),
				);
			}

			return array(
				'column'    => $column,
				'entity'    => $entity,
				'scanned'   => $scanned,
				'groups'    => $groups,
				'truncated' => count( $groups ) >= $limit,
			);
		}

		/**
		 * Bulk create with an optional dry run.
		 *
		 * `duplicates` counts skipped rows only, so
		 * `valid + duplicates + failed` always equals the batch size.
		 *
		 * @param ToolkitManifest                 $manifest      Manifest.
		 * @param string                          $entity        Entity key.
		 * @param array<int, array<string,mixed>> $rows          Submitted rows.
		 * @param bool                            $preview       Validate only.
		 * @param bool                            $skip_existing Skip existing keys instead of failing.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function import( ToolkitManifest $manifest, string $entity, array $rows, bool $preview, bool $skip_existing = true ) {
			global $wpdb;

			$config = $manifest->entity( $entity );

			if ( null === $config ) {
				return new WP_Error( 'entity_not_found', __( 'Unknown entity.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
			}

			if ( count( $rows ) > self::IMPORT_LIMIT ) {
				return new WP_Error(
					'too_many_rows',
					/* translators: %d: maximum rows. */
					sprintf( __( 'Import at most %d rows at a time.', 'mcp-ai-wpoos-pro' ), self::IMPORT_LIMIT ),
					array( 'status' => 422 )
				);
			}

			$id_field  = (string) ( $config['id_field'] ?? '' );
			$seen      = array();
			$valid     = array();
			$failed    = array();
			$duplicate = 0;

			foreach ( array_values( $rows ) as $index => $row ) {
				if ( ! is_array( $row ) ) {
					$failed[] = array(
						'index'  => $index,
						'fields' => array( 'row' => __( 'Each row must be an object.', 'mcp-ai-wpoos-pro' ) ),
					);
					continue;
				}

				list( $errors, $normalized ) = self::validate( $config, $row, true );

				if ( array() !== $errors ) {
					$failed[] = array(
						'index'  => $index,
						'fields' => $errors,
					);
					continue;
				}

				$external = (string) ( $normalized[ $id_field ] ?? '' );

				if ( isset( $seen[ $external ] ) || null !== self::row( $manifest, $entity, $external ) ) {
					if ( $skip_existing ) {
						++$duplicate;
						continue;
					}

					$failed[] = array(
						'index'  => $index,
						'fields' => array( $id_field => __( 'A record with that value already exists.', 'mcp-ai-wpoos-pro' ) ),
					);
					continue;
				}

				$seen[ $external ] = true;
				$valid[]           = $normalized;
			}

			if ( $preview ) {
				return array(
					'preview'    => true,
					'total'      => count( $rows ),
					'valid'      => count( $valid ),
					'duplicates' => $duplicate,
					'failed'     => $failed,
					'rows'       => $valid,
				);
			}

			$created = 0;

			foreach ( $valid as $normalized ) {
				$now = current_time( 'mysql', true );

				$inserted = $wpdb->insert(
					WP_MCP_AI_Pro_Toolkit_Manifests::table(),
					array(
						'toolkit'     => $manifest->slug(),
						'entity'      => $entity,
						'external_id' => (string) $normalized[ $id_field ],
						'data'        => (string) wp_json_encode( $normalized ),
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( false !== $inserted ) {
					++$created;
				}
			}

			return array(
				'preview'    => false,
				'total'      => count( $rows ),
				'created'    => $created,
				'duplicates' => $duplicate,
				'failed'     => $failed,
			);
		}

		/**
		 * Compute every dashboard card declared by the manifest.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @return array{slug: string, cards: array<int, array<string, mixed>>}
		 */
		public static function dashboard( ToolkitManifest $manifest ): array {
			$settings = WP_MCP_AI_Pro_Toolkit_Manifests::settings( $manifest );
			$table    = WP_MCP_AI_Pro_Toolkit_Manifests::table();
			$cards    = array();

			foreach ( $manifest->dashboardCards() as $card ) {
				$source = (string) ( $card['source'] ?? '' );
				$entity = str_starts_with( $source, 'entity:' ) ? substr( $source, 7 ) : '';
				$config = '' === $entity ? null : $manifest->entity( $entity );

				if ( null === $config ) {
					continue;
				}

				$where  = array( 'toolkit = %s', 'entity = %s' );
				$params = array( $manifest->slug(), $entity );

				self::apply_filters( $where, $params, $card['filter'] ?? null, $settings );

				$aggregate = (string) ( $card['aggregate'] ?? 'count' );
				$sql_where = 'WHERE ' . implode( ' AND ', $where );

				if ( 'count' === $aggregate ) {
					$value = (int) self::value( "SELECT COUNT(*) FROM {$table} {$sql_where}", $params );
				} else {
					$column = (string) ( $card['column'] ?? '' );
					$raw    = self::value(
						'SELECT ' . strtoupper( $aggregate ) . '(' . self::json_path( $column ) . ") FROM {$table} {$sql_where}",
						$params
					);
					$value  = is_numeric( $raw ) ? 0 + $raw : 0;
				}

				$cards[] = array(
					'key'       => (string) ( $card['key'] ?? '' ),
					'label'     => (string) ( $card['label'] ?? '' ),
					'aggregate' => $aggregate,
					'value'     => $value,
				);
			}

			return array(
				'slug'  => $manifest->slug(),
				'cards' => $cards,
			);
		}

		// ---------------------------------------------------------- internals

		/**
		 * Portable JSON path. The column comes from a validated manifest.
		 *
		 * WordPress always runs MySQL/MariaDB, where `json_extract` returns a
		 * quoted JSON string and every comparison would miss; `json_unquote`
		 * is what makes grouping and filtering behave like the SQLite tests.
		 *
		 * @param string $column Column key.
		 * @return string
		 */
		private static function json_path( string $column ): string {
			$path = "'$." . str_replace( "'", '', $column ) . "'";

			return 'json_unquote(json_extract(data, ' . $path . '))';
		}

		/**
		 * Apply dashboard filters (one object or a list) to the WHERE parts.
		 *
		 * @param array<int, string>   $where    WHERE fragments (by reference).
		 * @param array<int, mixed>    $params   Bound values (by reference).
		 * @param mixed                $filters  Filter object or list.
		 * @param array<string, mixed> $settings Resolved settings values.
		 * @return void
		 */
		private static function apply_filters( array &$where, array &$params, $filters, array $settings ): void {
			if ( ! is_array( $filters ) || array() === $filters ) {
				return;
			}

			if ( ! array_is_list( $filters ) ) {
				$filters = array( $filters );
			}

			foreach ( $filters as $filter ) {
				if ( ! is_array( $filter ) ) {
					continue;
				}

				$column = (string) ( $filter['column'] ?? '' );
				$path   = self::json_path( $column );

				if ( array_key_exists( 'setting', $filter ) ) {
					$key = (string) $filter['setting'];

					// An unset threshold disables the condition rather than
					// filtering every row out.
					if ( ! array_key_exists( $key, $settings ) || null === $settings[ $key ] ) {
						continue;
					}

					$value = $settings[ $key ];
				} else {
					$value = $filter['value'] ?? null;
				}

				switch ( (string) ( $filter['op'] ?? 'eq' ) ) {
					case 'neq':
						$where[]  = '(' . $path . ' IS NULL OR ' . $path . ' != %s)';
						$params[] = $value;
						break;
					case 'lt':
						$where[]  = $path . ' < %s';
						$params[] = $value;
						break;
					case 'lte':
						$where[]  = $path . ' <= %s';
						$params[] = $value;
						break;
					case 'gt':
						$where[]  = $path . ' > %s';
						$params[] = $value;
						break;
					case 'gte':
						$where[]  = $path . ' >= %s';
						$params[] = $value;
						break;
					case 'like':
						global $wpdb;
						$where[]  = $path . ' LIKE %s';
						$params[] = '%' . $wpdb->esc_like( (string) $value ) . '%';
						break;
					case 'eq':
					default:
						$where[]  = $path . ' = %s';
						$params[] = $value;
						break;
				}
			}
		}

		/**
		 * Search every textual column.
		 *
		 * @param array<int, string>   $where  WHERE fragments (by reference).
		 * @param array<int, mixed>    $params Bound values (by reference).
		 * @param array<string, mixed> $config Entity config.
		 * @param string               $search Search term.
		 * @return void
		 */
		private static function apply_search( array &$where, array &$params, array $config, string $search ): void {
			global $wpdb;

			$clauses = array();

			foreach ( (array) ( $config['columns'] ?? array() ) as $column ) {
				if ( ! in_array( (string) ( $column['type'] ?? '' ), array( 'string', 'enum', 'date' ), true ) ) {
					continue;
				}

				$clauses[] = self::json_path( (string) $column['key'] ) . ' LIKE %s';
				$params[]  = '%' . $wpdb->esc_like( $search ) . '%';
			}

			if ( array() !== $clauses ) {
				$where[] = '(' . implode( ' OR ', $clauses ) . ')';
			}
		}

		/**
		 * Validate a payload against the entity's columns.
		 *
		 * @param array<string, mixed> $config   Entity config.
		 * @param array<string, mixed> $payload  Submitted payload.
		 * @param bool                 $creating True for create.
		 * @return array{0: array<string, string>, 1: array<string, mixed>}
		 */
		private static function validate( array $config, array $payload, bool $creating ): array {
			$errors     = array();
			$normalized = array();
			$columns    = array();

			foreach ( (array) ( $config['columns'] ?? array() ) as $column ) {
				$columns[ (string) $column['key'] ] = $column;
			}

			foreach ( array_keys( $payload ) as $key ) {
				if ( ! isset( $columns[ (string) $key ] ) ) {
					$errors[ (string) $key ] = __( 'Unknown field.', 'mcp-ai-wpoos-pro' );
				}
			}

			foreach ( $columns as $key => $column ) {
				$present  = array_key_exists( $key, $payload );
				$required = (bool) ( $column['required'] ?? false );
				$is_id    = (string) ( $config['id_field'] ?? '' ) === $key;

				if ( ! $present ) {
					// Required means "supplied on create, not empty on update".
					if ( $creating && ( $required || $is_id ) ) {
						$errors[ $key ] = __( 'This field is required.', 'mcp-ai-wpoos-pro' );
					}
					continue;
				}

				$value = $payload[ $key ];

				if ( $required && ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) ) {
					$errors[ $key ] = __( 'This field is required.', 'mcp-ai-wpoos-pro' );
					continue;
				}

				list( $valid, $cast, $message ) = self::cast_column( $column, $value );

				if ( ! $valid ) {
					$errors[ $key ] = (string) $message;
					continue;
				}

				$normalized[ $key ] = $cast;
			}

			if ( $creating ) {
				$id_field = (string) ( $config['id_field'] ?? '' );

				if ( isset( $normalized[ $id_field ] ) && '' === trim( (string) $normalized[ $id_field ] ) ) {
					$errors[ $id_field ] = __( 'This field is required.', 'mcp-ai-wpoos-pro' );
				}
			}

			return array( $errors, $normalized );
		}

		/**
		 * Cast one column value.
		 *
		 * @param array<string, mixed> $column Column config.
		 * @param mixed                $value  Submitted value.
		 * @return array{0: bool, 1: mixed, 2: string|null}
		 */
		private static function cast_column( array $column, $value ): array {
			switch ( (string) ( $column['type'] ?? 'string' ) ) {
				case 'number':
				case 'currency':
					return is_numeric( $value )
						? array( true, 0 + $value, null )
						: array( false, null, __( 'Must be numeric.', 'mcp-ai-wpoos-pro' ) );

				case 'boolean':
					if ( is_bool( $value ) ) {
						return array( true, $value, null );
					}

					if ( in_array( $value, array( 1, '1', 'true' ), true ) ) {
						return array( true, true, null );
					}

					if ( in_array( $value, array( 0, '0', 'false' ), true ) ) {
						return array( true, false, null );
					}

					return array( false, null, __( 'Must be a boolean.', 'mcp-ai-wpoos-pro' ) );

				case 'date':
					return is_string( $value ) && false !== strtotime( $value )
						? array( true, $value, null )
						: array( false, null, __( 'Must be a date.', 'mcp-ai-wpoos-pro' ) );

				case 'enum':
					$allowed = (array) ( $column['enum'] ?? array() );

					/* translators: %s: comma-separated allowed values. */
					$message = sprintf( __( 'Must be one of: %s.', 'mcp-ai-wpoos-pro' ), implode( ', ', $allowed ) );

					return in_array( $value, $allowed, true )
						? array( true, $value, null )
						: array( false, null, $message );

				case 'string':
				default:
					return is_scalar( $value )
						? array( true, (string) $value, null )
						: array( false, null, __( 'Must be a string.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		/**
		 * Whether an entity declares a column.
		 *
		 * @param array<string, mixed> $config Entity config.
		 * @param string               $column Column key.
		 * @return bool
		 */
		private static function entity_has_column( array $config, string $column ): bool {
			foreach ( (array) ( $config['columns'] ?? array() ) as $candidate ) {
				if ( (string) ( $candidate['key'] ?? '' ) === $column ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Declared type of a column.
		 *
		 * @param array<string, mixed> $config Entity config.
		 * @param string               $column Column key.
		 * @return string
		 */
		private static function column_type( array $config, string $column ): string {
			foreach ( (array) ( $config['columns'] ?? array() ) as $candidate ) {
				if ( (string) ( $candidate['key'] ?? '' ) === $column ) {
					return (string) ( $candidate['type'] ?? 'string' );
				}
			}

			return '';
		}

		/**
		 * Raw row by business key.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @param string          $entity   Entity key.
		 * @param string          $id       Business key.
		 * @return object|null
		 */
		private static function row( ToolkitManifest $manifest, string $entity, string $id ): ?object {
			global $wpdb;

			$table = WP_MCP_AI_Pro_Toolkit_Manifests::table();

			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name derives from $wpdb->prefix; values are bound below.
					"SELECT * FROM {$table} WHERE toolkit = %s AND entity = %s AND external_id = %s LIMIT 1",
					$manifest->slug(),
					$entity,
					$id
				)
			);

			return is_object( $row ) ? $row : null;
		}

		/**
		 * Shape a stored row for the wire.
		 *
		 * @param object $row Raw row.
		 * @return array<string, mixed>
		 */
		private static function shape( object $row ): array {
			$data = json_decode( (string) ( $row->data ?? '' ), true );

			return array(
				'id'         => (string) ( $row->external_id ?? $row->id ),
				'entity'     => (string) ( $row->entity ?? '' ),
				'data'       => is_array( $data ) ? $data : array(),
				'created_at' => isset( $row->created_at ) ? (string) $row->created_at : null,
				'updated_at' => isset( $row->updated_at ) ? (string) $row->updated_at : null,
			);
		}

		/**
		 * Run a SELECT that returns rows.
		 *
		 * @param string            $sql    SQL with placeholders.
		 * @param array<int, mixed> $params Bound values.
		 * @return array<int, object>
		 */
		private static function results( string $sql, array $params ): array {
			global $wpdb;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below; the table name is derived from $wpdb->prefix.
			$prepared = array() === $params ? $sql : $wpdb->prepare( $sql, $params );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom Pro table; prepared above.
			$rows = $wpdb->get_results( $prepared );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Run a SELECT that returns one scalar.
		 *
		 * @param string            $sql    SQL with placeholders.
		 * @param array<int, mixed> $params Bound values.
		 * @return mixed
		 */
		private static function value( string $sql, array $params ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below; the table name is derived from $wpdb->prefix.
			$prepared = array() === $params ? $sql : $wpdb->prepare( $sql, $params );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom Pro table; prepared above.
			return $wpdb->get_var( $prepared );
		}
	}
}
