<?php
/**
 * Mesh Peer Synchronization
 *
 * Synchronizes mesh peer sites configuration with ai_peer CPT posts.
 * When mesh peers are added/updated in settings, corresponding CPT posts are created/updated.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Mesh;

use NvoosContentGraphAiPlatform\Federation\Settings;
use NvoosContentGraphAiPlatform\Federation\PeerCpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles synchronization between mesh_peer_sites setting and ai_peer CPT.
 */
class MeshPeerSync {

	/**
	 * Meta key to store mesh peer configuration reference.
	 */
	const META_MESH_PEER_ID = '_wp_mcp_ai_mesh_peer_id';

	/**
	 * Meta key to store connection type (mesh vs federation).
	 */
	const META_CONNECTION_TYPE = '_wp_mcp_ai_connection_type';

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		// Hook into option update to sync mesh peers.
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $this, 'sync_mesh_peers_on_option_update' ), 10, 3 );
	}

	/**
	 * Sync mesh peers when settings option is updated.
	 *
	 * @param mixed  $old_value Old option value.
	 * @param mixed  $new_value New option value.
	 * @param string $option    Option name.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the update_option_{option} hook signature.
	public function sync_mesh_peers_on_option_update( $old_value, $new_value, $option ) {
		// Check if mesh_peer_sites has changed.
		$old_peers = isset( $old_value['mesh_peer_sites'] ) && is_array( $old_value['mesh_peer_sites'] ) ? $old_value['mesh_peer_sites'] : array();
		$new_peers = isset( $new_value['mesh_peer_sites'] ) && is_array( $new_value['mesh_peer_sites'] ) ? $new_value['mesh_peer_sites'] : array();

		// Only proceed if mesh_peer_sites has actually changed.
		if ( $old_peers === $new_peers ) {
			return;
		}

		$this->sync_mesh_peers( $new_peers );
	}

	/**
	 * Synchronize mesh peer sites with ai_peer CPT posts.
	 *
	 * @param array $mesh_peers Array of mesh peer configurations.
	 */
	public function sync_mesh_peers( $mesh_peers ) {
		if ( ! is_array( $mesh_peers ) ) {
			$mesh_peers = array();
		}

		// Get existing mesh peer CPT posts.
		$existing_mesh_posts = $this->get_existing_mesh_peer_posts();

		// Track which posts we've processed.
		$processed_post_ids = array();

		// Process each mesh peer.
		foreach ( $mesh_peers as $index => $peer ) {
			// Validate peer data.
			if ( ! $this->is_valid_mesh_peer( $peer ) ) {
				continue;
			}

			$name    = sanitize_text_field( $peer['name'] );
			$url     = esc_url_raw( $peer['url'] );
			$api_key = sanitize_text_field( $peer['api_key'] );

			// Generate a unique identifier for this mesh peer.
			$mesh_peer_id = $this->generate_mesh_peer_id( $url );

			// Check if a CPT post already exists for this mesh peer.
			$post_id = $this->find_mesh_peer_post_by_id( $mesh_peer_id, $existing_mesh_posts );

			if ( $post_id ) {
				// Update existing post.
				$this->update_mesh_peer_post( $post_id, $name, $url, $mesh_peer_id );
				$processed_post_ids[] = $post_id;
			} else {
				// Create new post.
				$post_id = $this->create_mesh_peer_post( $name, $url, $mesh_peer_id );
				if ( $post_id && ! is_wp_error( $post_id ) ) {
					$processed_post_ids[] = $post_id;
				}
			}
		}

		// Remove CPT posts for mesh peers that no longer exist in settings.
		$this->cleanup_removed_mesh_peers( $existing_mesh_posts, $processed_post_ids );
	}

	/**
	 * Validate mesh peer data.
	 *
	 * @param array $peer Peer data.
	 * @return bool True if valid, false otherwise.
	 */
	private function is_valid_mesh_peer( $peer ) {
		if ( ! is_array( $peer ) ) {
			return false;
		}

		// Check required fields.
		if ( empty( $peer['name'] ) || empty( $peer['url'] ) ) {
			return false;
		}

		// Validate URL format.
		$url = esc_url_raw( $peer['url'] );
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Generate a unique ID for a mesh peer based on URL.
	 *
	 * @param string $url Peer URL.
	 * @return string Unique identifier.
	 */
	private function generate_mesh_peer_id( $url ) {
		return 'mesh_' . md5( $url );
	}

	/**
	 * Get existing ai_peer posts that are mesh connections.
	 *
	 * @return array Array of post IDs keyed by mesh_peer_id.
	 */
	private function get_existing_mesh_peer_posts() {
		$query = new \WP_Query(
			array(
				'post_type'              => PeerCpt::POST_TYPE,
				'posts_per_page'         => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary for finding mesh peers.
				'meta_query'             => array(
					array(
						'key'     => self::META_CONNECTION_TYPE,
						'value'   => 'mesh',
						'compare' => '=',
					),
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$posts_by_mesh_id = array();
		foreach ( $query->posts as $post_id ) {
			$mesh_peer_id = get_post_meta( $post_id, self::META_MESH_PEER_ID, true );
			if ( $mesh_peer_id ) {
				$posts_by_mesh_id[ $mesh_peer_id ] = $post_id;
			}
		}

		return $posts_by_mesh_id;
	}

	/**
	 * Find mesh peer post by mesh_peer_id.
	 *
	 * @param string $mesh_peer_id Mesh peer identifier.
	 * @param array  $existing_posts Existing posts array.
	 * @return int|null Post ID or null if not found.
	 */
	private function find_mesh_peer_post_by_id( $mesh_peer_id, $existing_posts ) {
		return isset( $existing_posts[ $mesh_peer_id ] ) ? $existing_posts[ $mesh_peer_id ] : null;
	}

	/**
	 * Create a new ai_peer CPT post for a mesh peer.
	 *
	 * @param string $name Name of the peer.
	 * @param string $url  URL of the peer.
	 * @param string $mesh_peer_id Unique mesh peer identifier.
	 * @return int|\WP_Error Post ID or WP_Error on failure.
	 */
	private function create_mesh_peer_post( $name, $url, $mesh_peer_id ) {
		$post_data = array(
			'post_title'   => $name,
			'post_type'    => PeerCpt::POST_TYPE,
			'post_status'  => 'publish',
			'post_content' => sprintf(
				/* translators: %s: peer URL */
				__( 'Mesh peer connection to %s', 'nvoos-content-graph-ai-platform' ),
				$url
			),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store mesh-specific metadata.
		update_post_meta( $post_id, self::META_MESH_PEER_ID, $mesh_peer_id );
		update_post_meta( $post_id, self::META_CONNECTION_TYPE, 'mesh' );
		update_post_meta( $post_id, PeerCpt::META_SITE_URL, $url );
		update_post_meta( $post_id, PeerCpt::META_SITE_NAME, $name );

		// Set default health status as unknown until tested.
		update_post_meta( $post_id, PeerCpt::META_HEALTH_STATUS, 'unknown' );
		update_post_meta( $post_id, PeerCpt::META_LAST_VERIFIED, current_time( 'mysql', true ) );

		// Set default capabilities for mesh peers.
		$default_capabilities = array(
			'query_remote_site',
			'distributed_processing',
		);
		update_post_meta( $post_id, PeerCpt::META_CAPABILITIES, wp_json_encode( $default_capabilities ) );

		return $post_id;
	}

	/**
	 * Update an existing mesh peer post.
	 *
	 * @param int    $post_id Post ID to update.
	 * @param string $name New name.
	 * @param string $url New URL.
	 * @param string $mesh_peer_id Mesh peer identifier.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Kept for signature parity with the base plugin copy.
	private function update_mesh_peer_post( $post_id, $name, $url, $mesh_peer_id ) {
		// Update post title if changed.
		$current_title = get_the_title( $post_id );
		if ( $current_title !== $name ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $name,
				)
			);
		}

		// Update metadata.
		update_post_meta( $post_id, PeerCpt::META_SITE_URL, $url );
		update_post_meta( $post_id, PeerCpt::META_SITE_NAME, $name );
		update_post_meta( $post_id, PeerCpt::META_LAST_VERIFIED, current_time( 'mysql', true ) );
	}

	/**
	 * Remove ai_peer posts for mesh peers that were removed from settings.
	 *
	 * @param array $existing_posts All existing mesh peer posts.
	 * @param array $processed_post_ids Post IDs that were just processed.
	 */
	private function cleanup_removed_mesh_peers( $existing_posts, $processed_post_ids ) {
		foreach ( $existing_posts as $mesh_peer_id => $post_id ) {
			if ( ! in_array( $post_id, $processed_post_ids, true ) ) {
				// This mesh peer was removed from settings, delete the CPT post.
				wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * Manual sync trigger (useful for initial setup or troubleshooting).
	 */
	public static function manual_sync() {
		$settings   = Settings::get_settings();
		$mesh_peers = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] ) ? $settings['mesh_peer_sites'] : array();

		$sync = new self();
		$sync->sync_mesh_peers( $mesh_peers );
	}
}
