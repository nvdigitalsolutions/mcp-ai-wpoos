<?php
/**
 * Toolkit Data Store Interface
 *
 * Provides unified API regardless of storage backend (CCT or CPT).
 * Allows toolkits to store Research & Add data using either JetEngine CCT
 * or standard WordPress Custom Post Types.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Interface for toolkit data storage backends.
 *
 * Implementations must handle either JetEngine CCT or WordPress CPT storage.
 */
interface WP_MCP_AI_Toolkit_Data_Store {

/**
 * Create a new item in the data store.
 *
 * @param array $data Item data to store.
 * @return int|WP_Error Item ID on success, WP_Error on failure.
 */
public function create_item( $data );

/**
 * Get an item from the data store.
 *
 * @param int $item_id Item ID to retrieve.
 * @return array|WP_Error Item data on success, WP_Error on failure.
 */
public function get_item( $item_id );

/**
 * Update an existing item in the data store.
 *
 * @param int   $item_id Item ID to update.
 * @param array $data    Updated item data.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
public function update_item( $item_id, $data );

/**
 * Delete an item from the data store.
 *
 * @param int $item_id Item ID to delete.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
public function delete_item( $item_id );

/**
 * Query items from the data store.
 *
 * @param array $args Query arguments.
 * @return array Array of items matching query.
 */
public function query_items( $args = array() );

/**
 * Get storage backend type.
 *
 * @return string 'cct' or 'cpt'.
 */
public function get_storage_type();

/**
 * Get the content type slug (CCT) or post type (CPT).
 *
 * @return string Content type or post type slug.
 */
public function get_content_type_slug();

/**
 * Check if storage backend is available.
 *
 * @return bool True if storage backend is available and working.
 */
public function is_available();

/**
 * Get field schema for this entity type.
 *
 * @return array Field definitions.
 */
public function get_field_schema();
}
