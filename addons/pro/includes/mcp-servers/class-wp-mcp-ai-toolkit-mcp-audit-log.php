<?php
/**
 * Toolkit MCP Server — Cross-mount Audit Log.
 *
 * Records every cross-mount read (resources/read and prompts/get) that touches
 * a mounted surface on a per-toolkit MCP server, providing an immutable ring-
 * buffer of up to MAX_ENTRIES entries.  The log is intentionally lightweight:
 * no DB table, no cron — just an option ring buffer.
 *
 * Hooks consumed:
 *   wp_mcp_ai_toolkit_mcp_cross_mount_read — fired by the REST controller
 *     whenever a mounted resource or prompt is read.
 *
 * Option key: wp_mcp_ai_toolkit_mcp_audit_log
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-mount audit log reader/writer.
 */
class WP_MCP_AI_Toolkit_MCP_Audit_Log {

	/**
	 * WordPress option key used to persist the ring buffer.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_toolkit_mcp_audit_log';

	/**
	 * Maximum number of entries retained in the ring buffer.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton (test fixture support).
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_mcp_ai_toolkit_mcp_cross_mount_read', array( $this, 'on_cross_mount_read' ), 10, 6 );
	}

	/**
	 * Handler for the wp_mcp_ai_toolkit_mcp_cross_mount_read action.
	 *
	 * @param string $consumer_slug  Slug of the consumer server.
	 * @param string $source_slug    Slug of the source (mounted) server.
	 * @param string $entity_type    Entity type or prompt name.
	 * @param string $uri            Resource URI or empty string for prompts.
	 * @param string $method         JSON-RPC method ('resources/read'|'prompts/get').
	 * @param mixed  $server         Consumer server instance (unused, for extensibility).
	 * @return void
	 */
	public function on_cross_mount_read( $consumer_slug, $source_slug, $entity_type, $uri, $method, $server ) {
		$this->record(
			array(
				'consumer' => (string) $consumer_slug,
				'source'   => (string) $source_slug,
				'entity'   => (string) $entity_type,
				'uri'      => (string) $uri,
				'method'   => (string) $method,
				'user_id'  => get_current_user_id(),
				'ts'       => time(),
			)
		);
	}

	/**
	 * Append an entry to the ring buffer.
	 *
	 * @param array<string,mixed> $entry Entry data.
	 * @return void
	 */
	public function record( array $entry ) {
		$entries   = $this->load_raw();
		$entries[] = array(
			'ts'       => isset( $entry['ts'] ) ? (int) $entry['ts'] : time(),
			'consumer' => sanitize_key( isset( $entry['consumer'] ) ? (string) $entry['consumer'] : '' ),
			'source'   => sanitize_key( isset( $entry['source'] ) ? (string) $entry['source'] : '' ),
			'entity'   => sanitize_text_field( isset( $entry['entity'] ) ? (string) $entry['entity'] : '' ),
			'uri'      => isset( $entry['uri'] ) ? sanitize_text_field( (string) $entry['uri'] ) : '',
			'method'   => sanitize_key( isset( $entry['method'] ) ? (string) $entry['method'] : '' ),
			'user_id'  => (int) ( isset( $entry['user_id'] ) ? $entry['user_id'] : 0 ),
		);

		// Trim to ring-buffer size.
		$max = (int) apply_filters( 'wp_mcp_ai_toolkit_mcp_audit_max_entries', self::MAX_ENTRIES );
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -$max );
		}

		update_option( self::OPTION_KEY, $entries, false );

		/**
		 * Fires after a cross-mount read entry is recorded.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string,mixed> $entry Sanitised entry that was stored.
		 */
		do_action( 'wp_mcp_ai_toolkit_mcp_audit_recorded', end( $entries ) );
	}

	/**
	 * Retrieve stored entries, most-recent first.
	 *
	 * @param int    $limit  Maximum number of entries to return (1–200).
	 * @param string $filter Optional: filter by consumer slug, source slug, or method.
	 *                       Pass '' to return all.
	 * @param string $filter_field Field to filter on: 'consumer'|'source'|'method'.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_entries( $limit = 50, $filter = '', $filter_field = '' ) {
		$limit   = max( 1, min( self::MAX_ENTRIES, (int) $limit ) );
		$entries = array_reverse( $this->load_raw() );

		if ( '' !== $filter && '' !== $filter_field ) {
			$field   = sanitize_key( $filter_field );
			$needle  = sanitize_text_field( $filter );
			$entries = array_values(
				array_filter(
					$entries,
					function ( $e ) use ( $field, $needle ) {
						return isset( $e[ $field ] ) && (string) $e[ $field ] === $needle;
					}
				)
			);
		}

		return array_slice( $entries, 0, $limit );
	}

	/**
	 * Get a summary of cross-mount activity grouped by consumer→source pair.
	 *
	 * Returns an array of: { consumer, source, count, last_ts }.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_summary() {
		$pairs = array();
		foreach ( $this->load_raw() as $entry ) {
			$key = $entry['consumer'] . '::' . $entry['source'];
			if ( ! isset( $pairs[ $key ] ) ) {
				$pairs[ $key ] = array(
					'consumer' => $entry['consumer'],
					'source'   => $entry['source'],
					'count'    => 0,
					'last_ts'  => 0,
				);
			}
			++$pairs[ $key ]['count'];
			if ( $entry['ts'] > $pairs[ $key ]['last_ts'] ) {
				$pairs[ $key ]['last_ts'] = $entry['ts'];
			}
		}

		// Sort by count descending.
		usort(
			$pairs,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array_values( $pairs );
	}

	/**
	 * Erase the entire ring buffer.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Load the raw option array, always returning an array.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function load_raw() {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
