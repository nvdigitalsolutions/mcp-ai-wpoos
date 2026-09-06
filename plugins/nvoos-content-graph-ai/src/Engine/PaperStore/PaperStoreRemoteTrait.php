<?php
/**
 * Paper Store remote trait (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Paper_Store_Remote`
 * (`includes/paper-store/trait-wp-mcp-ai-paper-store-remote.php`):
 * byte-identical remote-proxying shared by the paper_store_* tool
 * classes — the `pro_required` / `invalid_connection` /
 * `disabled_connection` error envelopes, the connection lookup and
 * enabled-flag gate, the `make_request()` passthrough, the success
 * envelope wrapping via the consuming tool's
 * `format_success_response()`, and the `connection_id` schema
 * fragment.
 *
 * Documented deviations:
 *  - Trait name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The Pro Remote Site Manager resolves per install mode via
 *    `defined( 'WP_MCP_AI_PATH' ) && class_exists( … )` — monolith
 *    installs use the base manager exactly as the base trait does;
 *    standalone installs return the byte-identical `pro_required`
 *    envelope (the monorepo classmap would otherwise resolve the base
 *    class in standalone test runs, masking the true standalone
 *    behavior).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\PaperStore
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\PaperStore;

/**
 * Shared by all paper_store_* tool classes to proxy operations
 * to a remote WordPress site when a connection_id is supplied.
 *
 * @since 1.1.0
 */
trait PaperStoreRemoteTrait {

	/**
	 * Resolve a remote connection and proxy the Paper Store operation.
	 *
	 * @param string $connection_id Remote connection identifier.
	 * @param string $endpoint      API endpoint path (e.g. "paper-store/my-collection").
	 * @param string $method        HTTP method (GET, POST, PUT, DELETE).
	 * @param array  $body          Optional request body.
	 * @return array|\WP_Error Success envelope or error.
	 */
	private function execute_remote( $connection_id, $endpoint, $method = 'GET', $body = array() ) {
		// Per-mode seam: the base Pro Remote Site Manager only exists in
		// monolith installs (the Pro addon does not ship with this addon).
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! \class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new \WP_Error(
				'pro_required',
				__( 'Remote Paper Store operations require the NV oOS Pro addon. Please upgrade to use remote connections.', 'nvoos-content-graph-ai' )
			);
		}

		$connection = \WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			return new \WP_Error(
				'invalid_connection',
				\sprintf(
					/* translators: %s: connection ID */
					__( 'Invalid remote connection ID "%s".', 'nvoos-content-graph-ai' ),
					$connection_id
				)
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new \WP_Error(
				'disabled_connection',
				\sprintf(
					/* translators: %s: connection name */
					__( 'Remote connection "%s" is disabled.', 'nvoos-content-graph-ai' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		$result = \WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, $method, $body );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		// Wrap remote response in success envelope for tool consistency.
		return $this->format_success_response(
			__( 'Remote Paper Store operation completed.', 'nvoos-content-graph-ai' ),
			\is_array( $result ) ? $result : array( 'response' => $result )
		);
	}

	/**
	 * Get the connection_id parameter schema fragment.
	 *
	 * @return array Schema for the connection_id parameter.
	 */
	private function get_connection_id_schema() {
		return array(
			'type'        => 'string',
			'description' => __( 'Optional. Remote connection ID from Remote Sites. When provided, the operation runs against the remote WordPress site\'s Paper Store instead of the local one. Call remote_wp_connection with action "list_connections" to discover available connection IDs.', 'nvoos-content-graph-ai' ),
		);
	}
}
