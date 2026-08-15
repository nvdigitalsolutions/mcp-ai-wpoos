<?php
/**
 * NV oOS Graphify — Generic SQL (read-only) Remote Driver (Pro)
 *
 * Reads nodes (and optionally edges) from any PDO-reachable database
 * (MySQL / MariaDB / PostgreSQL / SQLite) by running a configurable
 * SELECT statement and mapping result columns onto graph fields.
 *
 * Safety contract:
 *   - SELECT (or WITH ... SELECT) statements only — anything else is rejected.
 *   - No multi-statement execution: input must contain a single statement.
 *   - Parameter binding only via named placeholders (`:since`, `:limit`).
 *   - PDO is opened with ATTR_EMULATE_PREPARES=false and a hard timeout.
 *   - DSN scheme is restricted to the PDO drivers actually loaded on the host.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This driver is *designed* to talk to external databases via PDO; the
// WP_DB sniff that requires $wpdb does not apply to Pro remote sources.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO

/**
 * Generic SQL (read-only) remote source driver.
 *
 * @since 0.7.5
 */
class NV_oOS_Graphify_Remote_Generic_SQL implements NV_oOS_Graphify_Remote_Source_Interface {

	/**
	 * Driver configuration.
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * Cached PDO instances keyed by DSN hash.
	 *
	 * @since 1.3.0
	 * @var array<string, PDO>
	 */
	private static $pdo_instances = array();

	/**
	 * DSN schemes we will accept regardless of which PDO drivers are
	 * available — narrowed at connect-time against PDO::getAvailableDrivers().
	 *
	 * @var string[]
	 */
	private static $allowed_schemes = array( 'mysql', 'pgsql', 'sqlite' );

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'generic_sql';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Generic SQL (read-only)', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
	}

	/** {@inheritdoc} */
	public function get_config() {
		return $this->config;
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	/**
	 * Capability flags for the registry / admin UI.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => false,
			'supports_oauth'         => false,
			'supports_pagination'    => false,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'dsn'                  => array(
				'type'        => 'text',
				'label'       => __( 'PDO DSN', 'nvoos-graphify' ),
				'description' => __( 'e.g. mysql:host=db.example.com;dbname=app;charset=utf8mb4 — only mysql, pgsql, sqlite are accepted.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'username'             => array(
				'type'  => 'text',
				'label' => __( 'Username', 'nvoos-graphify' ),
			),
			'password'             => array(
				'type'  => 'password',
				'label' => __( 'Password', 'nvoos-graphify' ),
			),
			'node_query'           => array(
				'type'        => 'textarea',
				'label'       => __( 'Node SELECT', 'nvoos-graphify' ),
				'description' => __( 'A single SELECT (or WITH ... SELECT) returning the columns mapped below. Use :since to receive the incremental cursor and :limit for batch size.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'edge_query'           => array(
				'type'        => 'textarea',
				'label'       => __( 'Edge SELECT (optional)', 'nvoos-graphify' ),
				'description' => __( 'A single SELECT returning source / target / relation columns.', 'nvoos-graphify' ),
				'default'     => '',
			),
			'node_id_column'       => array(
				'type'    => 'text',
				'label'   => __( 'ID Column', 'nvoos-graphify' ),
				'default' => 'id',
			),
			'node_label_column'    => array(
				'type'    => 'text',
				'label'   => __( 'Label Column', 'nvoos-graphify' ),
				'default' => 'name',
			),
			'node_type_column'     => array(
				'type'    => 'text',
				'label'   => __( 'Type Column', 'nvoos-graphify' ),
				'default' => 'type',
			),
			'node_url_column'      => array(
				'type'    => 'text',
				'label'   => __( 'URL Column', 'nvoos-graphify' ),
				'default' => 'url',
			),
			'edge_source_column'   => array(
				'type'    => 'text',
				'label'   => __( 'Edge Source Column', 'nvoos-graphify' ),
				'default' => 'source',
			),
			'edge_target_column'   => array(
				'type'    => 'text',
				'label'   => __( 'Edge Target Column', 'nvoos-graphify' ),
				'default' => 'target',
			),
			'edge_relation_column' => array(
				'type'    => 'text',
				'label'   => __( 'Edge Relation Column', 'nvoos-graphify' ),
				'default' => 'relation',
			),
			'batch_limit'          => array(
				'type'    => 'number',
				'label'   => __( 'Batch Limit', 'nvoos-graphify' ),
				'default' => 500,
			),
			'connection_timeout'   => array(
				'type'    => 'number',
				'label'   => __( 'Connection Timeout (sec)', 'nvoos-graphify' ),
				'default' => 5,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		try {
			$pdo = $this->open_pdo();
		} catch ( Exception $e ) {
			return array(
				// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- Not a tool; internal admin connection test.
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
		if ( null === $pdo ) {
			return array(
				// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- Not a tool; internal admin connection test.
				'success' => false,
				'message' => __( 'PDO is not available on this host.', 'nvoos-graphify' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected.', 'nvoos-graphify' ),
		);
	}

	/** {@inheritdoc} */
	public function discover() {
		return array(
			'driver'       => $this->get_driver_id(),
			'label'        => $this->get_driver_label(),
			'capabilities' => $this->get_capabilities(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		unset( $args );
		$source_slug = isset( $this->config['_slug'] ) ? (string) $this->config['_slug'] : 'generic_sql';
		$query       = isset( $this->config['node_query'] ) ? trim( (string) $this->config['node_query'] ) : '';
		if ( '' === $query || ! self::is_select_only( $query ) ) {
			return array();
		}

		try {
			$rows = $this->run_query( $query, $source_slug );
		} catch ( Exception $e ) {
			return array();
		}

		$id_col    = isset( $this->config['node_id_column'] ) ? (string) $this->config['node_id_column'] : 'id';
		$label_col = isset( $this->config['node_label_column'] ) ? (string) $this->config['node_label_column'] : 'name';
		$type_col  = isset( $this->config['node_type_column'] ) ? (string) $this->config['node_type_column'] : 'type';
		$url_col   = isset( $this->config['node_url_column'] ) ? (string) $this->config['node_url_column'] : 'url';

		$nodes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label     = isset( $row[ $label_col ] ) ? sanitize_text_field( (string) $row[ $label_col ] ) : '';
			$remote_id = isset( $row[ $id_col ] ) ? sanitize_text_field( (string) $row[ $id_col ] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$type    = isset( $row[ $type_col ] ) ? sanitize_text_field( (string) $row[ $type_col ] ) : 'entity';
			$url     = isset( $row[ $url_col ] ) ? esc_url_raw( (string) $row[ $url_col ] ) : '';
			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . ( '' !== $remote_id ? sanitize_key( $remote_id ) : md5( $label ) );

			$nodes[] = array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => $row,
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
				'external_id' => $remote_id,
			);
		}

		NV_oOS_Graphify_Remote_State_Store::set( $source_slug, 'last_run_iso', gmdate( 'c' ) );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $source_slug );
		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		unset( $args );
		$source_slug = isset( $this->config['_slug'] ) ? (string) $this->config['_slug'] : 'generic_sql';
		$query       = isset( $this->config['edge_query'] ) ? trim( (string) $this->config['edge_query'] ) : '';
		if ( '' === $query || ! self::is_select_only( $query ) ) {
			return array();
		}

		try {
			$rows = $this->run_query( $query, $source_slug );
		} catch ( Exception $e ) {
			return array();
		}

		$src_col = isset( $this->config['edge_source_column'] ) ? (string) $this->config['edge_source_column'] : 'source';
		$tgt_col = isset( $this->config['edge_target_column'] ) ? (string) $this->config['edge_target_column'] : 'target';
		$rel_col = isset( $this->config['edge_relation_column'] ) ? (string) $this->config['edge_relation_column'] : 'relation';

		$edges = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$src = isset( $row[ $src_col ] ) ? sanitize_text_field( (string) $row[ $src_col ] ) : '';
			$tgt = isset( $row[ $tgt_col ] ) ? sanitize_text_field( (string) $row[ $tgt_col ] ) : '';
			$rel = isset( $row[ $rel_col ] ) ? sanitize_text_field( (string) $row[ $rel_col ] ) : 'RELATED_TO';
			if ( '' === $src || '' === $tgt ) {
				continue;
			}
			$edges[] = array(
				'source_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_' . sanitize_key( $src ),
				'target_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_' . sanitize_key( $tgt ),
				'relation'       => strtoupper( $rel ),
				'confidence'     => 1.0,
				'provenance'     => 'REMOTE',
				'source_slug'    => $source_slug,
			);
		}
		return $edges;
	}

	/**
	 * Reconciliation not supported.
	 *
	 * @param object $local_node Unused.
	 * @return array
	 */
	public function reconcile( $local_node ) {
		unset( $local_node );
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Open the PDO connection. Returns null when PDO is unavailable.
	 *
	 * @return PDO|null
	 * @throws RuntimeException When the DSN is invalid or the scheme is rejected.
	 */
	private function open_pdo() {
		if ( ! class_exists( 'PDO' ) ) {
			return null;
		}
		$dsn = isset( $this->config['dsn'] ) ? (string) $this->config['dsn'] : '';
		if ( '' === $dsn ) {
			throw new RuntimeException( esc_html__( 'No DSN configured.', 'nvoos-graphify' ) );
		}
		if ( ! self::is_dsn_allowed( $dsn ) ) {
			throw new RuntimeException( esc_html__( 'DSN scheme not permitted.', 'nvoos-graphify' ) );
		}
		$user    = isset( $this->config['username'] ) ? (string) $this->config['username'] : '';
		$pass    = isset( $this->config['password'] ) ? (string) $this->config['password'] : '';
		$timeout = isset( $this->config['connection_timeout'] ) ? max( 1, (int) $this->config['connection_timeout'] ) : 5;

		// Build a cache key from the DSN + credentials so identical
		// configurations reuse the same connection.
		$cache_key = md5( $dsn . '|' . $user . '|' . $pass );

		// Reuse existing connection if still alive.
		if ( isset( self::$pdo_instances[ $cache_key ] ) ) {
			try {
				self::$pdo_instances[ $cache_key ]->query( 'SELECT 1' );
				return self::$pdo_instances[ $cache_key ];
			} catch ( \PDOException $e ) {
				// Connection lost — remove from cache and recreate.
				unset( self::$pdo_instances[ $cache_key ] );
			}
		}

		$options = array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_TIMEOUT            => $timeout,
			// Persistent connections work well with PHP-FPM (Cloudways).
			// Disabled in CLI mode to avoid connection leakage across
			// long-running queue worker daemons.
			PDO::ATTR_PERSISTENT         => ( \PHP_SAPI !== 'cli' ),
		);

		self::$pdo_instances[ $cache_key ] = new PDO( $dsn, $user, $pass, $options );
		return self::$pdo_instances[ $cache_key ];
	}

	/**
	 * Force-close all cached PDO connections.
	 *
	 * Useful in queue worker daemon mode to release connections
	 * after a batch completes. Connections are re-established
	 * on the next open_pdo() call.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function close_all_connections() {
		self::$pdo_instances = array();
	}

	/**
	 * Run a parameterised SELECT and return rows.
	 *
	 * @param string $query       SQL query (already validated as SELECT-only).
	 * @param string $source_slug Source slug for cursor lookup.
	 * @return array
	 * @throws RuntimeException When PDO is unavailable.
	 */
	private function run_query( $query, $source_slug ) {
		$pdo = $this->open_pdo();
		if ( null === $pdo ) {
			throw new RuntimeException( 'PDO unavailable' );
		}
		$stmt = $pdo->prepare( $query );

		$bindings = array();
		if ( false !== strpos( $query, ':since' ) ) {
			$bindings[':since'] = (string) NV_oOS_Graphify_Remote_State_Store::get( $source_slug, 'last_run_iso', '1970-01-01T00:00:00+00:00' );
		}
		if ( false !== strpos( $query, ':limit' ) ) {
			$limit = isset( $this->config['batch_limit'] ) ? max( 1, (int) $this->config['batch_limit'] ) : 500;
			$stmt->bindValue( ':limit', $limit, PDO::PARAM_INT );
		}
		foreach ( $bindings as $name => $value ) {
			$stmt->bindValue( $name, $value, PDO::PARAM_STR );
		}

		$stmt->execute();
		$rows = $stmt->fetchAll();
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * True when the query is a single SELECT (or WITH ... SELECT) with no
	 * trailing statements. Comments and leading whitespace are stripped
	 * before the check.
	 *
	 * @param string $query Raw SQL.
	 * @return bool
	 */
	public static function is_select_only( $query ) {
		// Strip line / block comments.
		$cleaned = preg_replace( '#/\*.*?\*/#s', ' ', (string) $query );
		$cleaned = preg_replace( '/--[^\n]*\n?/', ' ', (string) $cleaned );
		$cleaned = trim( (string) $cleaned );
		if ( '' === $cleaned ) {
			return false;
		}
		// Reject anything past a terminating semicolon (allowing a trailing one).
		$without_trailing = rtrim( $cleaned, "; \t\n\r" );
		if ( false !== strpos( $without_trailing, ';' ) ) {
			return false;
		}
		return (bool) preg_match( '/^(select|with)\s/i', $cleaned );
	}

	/**
	 * True when the DSN scheme is in our allow-list AND PDO has the driver loaded.
	 *
	 * @param string $dsn PDO DSN.
	 * @return bool
	 */
	public static function is_dsn_allowed( $dsn ) {
		if ( ! class_exists( 'PDO' ) ) {
			return false;
		}
		$pos = strpos( $dsn, ':' );
		if ( false === $pos || 0 === $pos ) {
			return false;
		}
		$scheme = strtolower( substr( $dsn, 0, $pos ) );
		if ( ! in_array( $scheme, self::$allowed_schemes, true ) ) {
			return false;
		}
		$available = PDO::getAvailableDrivers();
		return in_array( $scheme, $available, true );
	}
}
