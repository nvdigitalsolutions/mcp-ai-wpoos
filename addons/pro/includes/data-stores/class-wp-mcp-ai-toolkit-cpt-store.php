<?php
/**
 * Toolkit CPT Data Store
 *
 * WordPress Custom Post Type implementation of toolkit data storage.
 * This is the fallback storage backend that works without any dependencies.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/interfaces/interface-wp-mcp-ai-toolkit-data-store.php';

/**
 * CPT-based data store for toolkit entities.
 */
class WP_MCP_AI_Toolkit_CPT_Store implements WP_MCP_AI_Toolkit_Data_Store {

/**
 * Toolkit slug.
 *
 * @var string
 */
private $toolkit_slug;

/**
 * Entity type.
 *
 * @var string
 */
private $entity_type;

/**
 * Custom post type slug.
 *
 * @var string
 */
private $post_type;

/**
 * Field schema for this entity.
 *
 * @var array
 */
private $field_schema;

/**
 * Constructor.
 *
 * @param string $toolkit_slug Toolkit identifier.
 * @param string $entity_type  Entity type.
 */
public function __construct( $toolkit_slug, $entity_type ) {
$this->toolkit_slug = $toolkit_slug;
$this->entity_type  = $entity_type;
$this->post_type    = $this->generate_post_type_slug();
$this->field_schema = $this->load_field_schema();

// Register the custom post type.
add_action( 'init', array( $this, 'register_post_type' ) );
}

/**
 * Generate post type slug from toolkit and entity.
 *
 * @return string Post type slug (max 20 characters).
 */
private function generate_post_type_slug() {
// Format: mcp_{toolkit}_{entity} (truncated to 20 chars).
$slug = 'mcp_' . $this->toolkit_slug . '_' . $this->entity_type;
return substr( $slug, 0, 20 );
}

/**
 * Load field schema for this entity type.
 *
 * @return array Field definitions.
 */
private function load_field_schema() {
$schema = array();

// Allow toolkits to define their field schemas.
$schema = apply_filters(
'wp_mcp_ai_toolkit_cpt_field_schema',
$schema,
$this->toolkit_slug,
$this->entity_type
);

return $schema;
}

/**
 * Register custom post type.
 */
public function register_post_type() {
$labels = array(
'name'               => sprintf( '%s %s', ucwords( str_replace( '_', ' ', $this->toolkit_slug ) ), ucwords( str_replace( '_', ' ', $this->entity_type ) ) ),
'singular_name'      => sprintf( '%s %s', ucwords( str_replace( '_', ' ', $this->toolkit_slug ) ), rtrim( ucwords( str_replace( '_', ' ', $this->entity_type ) ), 's' ) ),
'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
'add_new_item'       => __( 'Add New Item', 'mcp-ai-wpoos-pro' ),
'edit_item'          => __( 'Edit Item', 'mcp-ai-wpoos-pro' ),
'new_item'           => __( 'New Item', 'mcp-ai-wpoos-pro' ),
'view_item'          => __( 'View Item', 'mcp-ai-wpoos-pro' ),
'search_items'       => __( 'Search Items', 'mcp-ai-wpoos-pro' ),
'not_found'          => __( 'No items found', 'mcp-ai-wpoos-pro' ),
'not_found_in_trash' => __( 'No items found in trash', 'mcp-ai-wpoos-pro' ),
);

$args = array(
'labels'              => $labels,
'public'              => false,
'show_ui'             => true,
'show_in_menu'        => false,
'capability_type'     => 'post',
'hierarchical'        => false,
'supports'            => array( 'title', 'editor', 'custom-fields' ),
'has_archive'         => false,
'rewrite'             => false,
'query_var'           => false,
'show_in_rest'        => true,
'rest_base'           => $this->post_type,
);

// Allow customization of CPT args.
$args = apply_filters(
'wp_mcp_ai_toolkit_cpt_args',
$args,
$this->toolkit_slug,
$this->entity_type
);

register_post_type( $this->post_type, $args );
}

/**
 * Create a new item.
 *
 * @param array $data Item data.
 * @return int|WP_Error Post ID on success, WP_Error on failure.
 */
public function create_item( $data ) {
$post_data = array(
'post_type'   => $this->post_type,
'post_status' => 'publish',
);

// Extract title from data.
if ( isset( $data['title'] ) ) {
$post_data['post_title'] = sanitize_text_field( $data['title'] );
unset( $data['title'] );
}

// Extract content from data.
if ( isset( $data['content'] ) ) {
$post_data['post_content'] = wp_kses_post( $data['content'] );
unset( $data['content'] );
}

// Create the post.
$post_id = wp_insert_post( $post_data, true );

if ( is_wp_error( $post_id ) ) {
return $post_id;
}

// Save remaining data as post meta.
foreach ( $data as $key => $value ) {
$meta_key = sanitize_key( $key );
update_post_meta( $post_id, $meta_key, $value );
}

// Store toolkit and entity type for filtering.
update_post_meta( $post_id, '_toolkit_slug', $this->toolkit_slug );
update_post_meta( $post_id, '_entity_type', $this->entity_type );

return $post_id;
}

/**
 * Get an item.
 *
 * @param int $item_id Post ID.
 * @return array|WP_Error Item data on success, WP_Error on failure.
 */
public function get_item( $item_id ) {
$post = get_post( $item_id );

if ( ! $post || $post->post_type !== $this->post_type ) {
return new WP_Error( 'item_not_found', __( 'Item not found', 'mcp-ai-wpoos-pro' ) );
}

$data = array(
'id'      => $post->ID,
'title'   => $post->post_title,
'content' => $post->post_content,
);

// Get all meta fields.
$meta = get_post_meta( $post->ID );
foreach ( $meta as $key => $values ) {
// Skip WordPress internal meta and our internal tracking.
if ( strpos( $key, '_' ) === 0 ) {
continue;
}
$data[ $key ] = maybe_unserialize( $values[0] );
}

return $data;
}

/**
 * Update an item.
 *
 * @param int   $item_id Post ID.
 * @param array $data    Updated data.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
public function update_item( $item_id, $data ) {
$post = get_post( $item_id );

if ( ! $post || $post->post_type !== $this->post_type ) {
return new WP_Error( 'item_not_found', __( 'Item not found', 'mcp-ai-wpoos-pro' ) );
}

$post_data = array( 'ID' => $item_id );

// Update title if provided.
if ( isset( $data['title'] ) ) {
$post_data['post_title'] = sanitize_text_field( $data['title'] );
unset( $data['title'] );
}

// Update content if provided.
if ( isset( $data['content'] ) ) {
$post_data['post_content'] = wp_kses_post( $data['content'] );
unset( $data['content'] );
}

// Update the post if title or content changed.
if ( count( $post_data ) > 1 ) {
$result = wp_update_post( $post_data, true );
if ( is_wp_error( $result ) ) {
return $result;
}
}

// Update meta fields.
foreach ( $data as $key => $value ) {
$meta_key = sanitize_key( $key );
update_post_meta( $item_id, $meta_key, $value );
}

return true;
}

/**
 * Delete an item.
 *
 * @param int $item_id Post ID.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
public function delete_item( $item_id ) {
$post = get_post( $item_id );

if ( ! $post || $post->post_type !== $this->post_type ) {
return new WP_Error( 'item_not_found', __( 'Item not found', 'mcp-ai-wpoos-pro' ) );
}

$result = wp_delete_post( $item_id, true );

if ( ! $result ) {
return new WP_Error( 'delete_failed', __( 'Failed to delete item', 'mcp-ai-wpoos-pro' ) );
}

return true;
}

/**
 * Query items.
 *
 * @param array $args Query arguments.
 * @return array Array of items.
 */
public function query_items( $args = array() ) {
$defaults = array(
'post_type'      => $this->post_type,
'posts_per_page' => 20,
'orderby'        => 'date',
'order'          => 'DESC',
);

$query_args = wp_parse_args( $args, $defaults );
$query      = new WP_Query( $query_args );

$items = array();
if ( $query->have_posts() ) {
while ( $query->have_posts() ) {
$query->the_post();
$items[] = $this->get_item( get_the_ID() );
}
wp_reset_postdata();
}

return $items;
}

/**
 * Get storage type.
 *
 * @return string Always 'cpt'.
 */
public function get_storage_type() {
return 'cpt';
}

/**
 * Get post type slug.
 *
 * @return string Post type slug.
 */
public function get_content_type_slug() {
return $this->post_type;
}

/**
 * Check if storage is available.
 *
 * @return bool Always true (CPT is always available).
 */
public function is_available() {
return true;
}

/**
 * Get field schema.
 *
 * @return array Field definitions.
 */
public function get_field_schema() {
return $this->field_schema;
}
}
