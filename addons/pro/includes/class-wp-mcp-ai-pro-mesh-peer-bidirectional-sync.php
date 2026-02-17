<?php
/**
 * Mesh Peer Bidirectional Sync for Pro
 *
 * Keeps mesh_peer_sites (base settings) and remote_sites (Pro) in sync.
 * When Pro is active, users can manage mesh peers from either location.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles bidirectional synchronization of mesh peers.
 */
class WP_MCP_AI_Pro_Mesh_Peer_Bidirectional_Sync {

	/**
	 * Sync lock to prevent infinite loops.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		// Sync from Remote Sites to mesh_peer_sites when a mesh peer connection is saved.
		add_action( 'wp_mcp_ai_pro_remote_site_saved', array( $this, 'sync_from_remote_sites' ), 10, 2 );

		// Sync from mesh_peer_sites to Remote Sites when mesh settings are saved.
		add_action( 'update_option_wp_mcp_ai_settings', array( $this, 'sync_from_mesh_settings' ), 10, 3 );

		// Sync deletion from Remote Sites.
		add_action( 'wp_mcp_ai_pro_remote_site_deleted', array( $this, 'handle_remote_site_deletion' ), 10, 1 );
	}

	/**
	 * Sync mesh peer from Remote Sites to mesh_peer_sites setting.
	 *
	 * Called when a mesh_peer connection is saved in Remote Sites.
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $connection    Connection data.
	 */
	public function sync_from_remote_sites( $connection_id, $connection ) {
		// Prevent infinite sync loops.
		if ( self::$syncing ) {
			return;
		}

		// Only sync mesh_peer connections.
		if ( empty( $connection['connection_type'] ) || 'mesh_peer' !== $connection['connection_type'] ) {
			return;
		}

		// Get current mesh_peer_sites.
		$settings    = WP_MCP_AI_Admin_Settings::get_settings();
		$mesh_peers  = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] ) ? $settings['mesh_peer_sites'] : array();

		// Generate mesh peer ID based on URL (consistent with mesh peer sync).
		$url          = isset( $connection['url'] ) ? $connection['url'] : '';
		$mesh_peer_id = 'mesh_' . md5( $url );

		// Decrypt API key if stored encrypted.
		$api_key = '';
		if ( ! empty( $connection['api_key'] ) ) {
			$api_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		}

		// Build mesh peer data.
		$mesh_peer = array(
			'name'    => isset( $connection['name'] ) ? $connection['name'] : '',
			'url'     => $url,
			'api_key' => $api_key,
		);

		// Find existing peer by URL.
		$found_index = null;
		foreach ( $mesh_peers as $index => $existing_peer ) {
			if ( isset( $existing_peer['url'] ) && $existing_peer['url'] === $url ) {
				$found_index = $index;
				break;
			}
		}

		// Update or add peer.
		if ( null !== $found_index ) {
			// Update existing peer.
			$mesh_peers[ $found_index ] = $mesh_peer;
		} else {
			// Add new peer.
			$mesh_peers[] = $mesh_peer;
		}

		// Save to settings (set sync flag to prevent loop).
		self::$syncing = true;
		$settings['mesh_peer_sites'] = $mesh_peers;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		self::$syncing = false;
	}

	/**
	 * Sync mesh peers from mesh_peer_sites to Remote Sites.
	 *
	 * Called when mesh settings are saved.
	 *
	 * @param mixed $old_value Old option value.
	 * @param mixed $value     New option value.
	 * @param string $option   Option name.
	 */
	public function sync_from_mesh_settings( $old_value, $value, $option ) {
		// Prevent infinite sync loops.
		if ( self::$syncing ) {
			return;
		}

		// Only process if mesh_peer_sites changed.
		$old_mesh_peers = isset( $old_value['mesh_peer_sites'] ) && is_array( $old_value['mesh_peer_sites'] ) ? $old_value['mesh_peer_sites'] : array();
		$new_mesh_peers = isset( $value['mesh_peer_sites'] ) && is_array( $value['mesh_peer_sites'] ) ? $value['mesh_peer_sites'] : array();

		// Check if mesh peers actually changed.
		if ( wp_json_encode( $old_mesh_peers ) === wp_json_encode( $new_mesh_peers ) ) {
			return;
		}

		// Set sync flag.
		self::$syncing = true;

		// Get all remote site connections.
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		// Build map of existing mesh peer connections by URL.
		$mesh_connections = array();
		foreach ( $connections as $conn_id => $conn ) {
			if ( isset( $conn['connection_type'] ) && 'mesh_peer' === $conn['connection_type'] ) {
				$url = isset( $conn['url'] ) ? $conn['url'] : '';
				if ( ! empty( $url ) ) {
					$mesh_connections[ $url ] = $conn_id;
				}
			}
		}

		// Sync new/updated peers to Remote Sites.
		foreach ( $new_mesh_peers as $peer ) {
			if ( empty( $peer['url'] ) || empty( $peer['name'] ) ) {
				continue;
			}

			$url = $peer['url'];

			// Check if connection exists.
			if ( isset( $mesh_connections[ $url ] ) ) {
				// Update existing connection.
				$connection_id = $mesh_connections[ $url ];
				$connection    = $connections[ $connection_id ];

				// Update name if changed.
				if ( $connection['name'] !== $peer['name'] ) {
					$connection['name'] = $peer['name'];
				}

				// Update API key if provided.
				if ( ! empty( $peer['api_key'] ) ) {
					$connection['api_key'] = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $peer['api_key'] );
				}

				$connection['connection_type'] = 'mesh_peer';
				$connection['auth_type']       = 'custom_header';

				WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection );

				// Remove from map (so we know it's been processed).
				unset( $mesh_connections[ $url ] );
			} else {
				// Create new connection.
				$connection = array(
					'name'            => $peer['name'],
					'url'             => $url,
					'connection_type' => 'mesh_peer',
					'auth_type'       => 'custom_header',
					'api_key'         => ! empty( $peer['api_key'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $peer['api_key'] ) : '',
				);

				WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection );
			}
		}

		// Delete connections that were removed from mesh_peer_sites.
		foreach ( $mesh_connections as $url => $connection_id ) {
			// Check if this peer still exists in new_mesh_peers.
			$still_exists = false;
			foreach ( $new_mesh_peers as $peer ) {
				if ( isset( $peer['url'] ) && $peer['url'] === $url ) {
					$still_exists = true;
					break;
				}
			}

			if ( ! $still_exists ) {
				WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
			}
		}

		// Release sync flag.
		self::$syncing = false;
	}

	/**
	 * Handle deletion of mesh peer from Remote Sites.
	 *
	 * @param string $connection_id Connection ID.
	 */
	public function handle_remote_site_deletion( $connection_id ) {
		// Prevent infinite sync loops.
		if ( self::$syncing ) {
			return;
		}

		// Get connection details before it's deleted (this hook fires before deletion).
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Only process mesh_peer connections.
		if ( empty( $connection ) || empty( $connection['connection_type'] ) || 'mesh_peer' !== $connection['connection_type'] ) {
			return;
		}

		$url = isset( $connection['url'] ) ? $connection['url'] : '';
		if ( empty( $url ) ) {
			return;
		}

		// Get current mesh_peer_sites.
		$settings   = WP_MCP_AI_Admin_Settings::get_settings();
		$mesh_peers = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] ) ? $settings['mesh_peer_sites'] : array();

		// Find and remove peer by URL.
		$updated = false;
		foreach ( $mesh_peers as $index => $peer ) {
			if ( isset( $peer['url'] ) && $peer['url'] === $url ) {
				unset( $mesh_peers[ $index ] );
				$updated = true;
				break;
			}
		}

		if ( $updated ) {
			// Re-index array.
			$mesh_peers = array_values( $mesh_peers );

			// Save to settings (set sync flag to prevent loop).
			self::$syncing = true;
			$settings['mesh_peer_sites'] = $mesh_peers;
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
			self::$syncing = false;
		}
	}
}
