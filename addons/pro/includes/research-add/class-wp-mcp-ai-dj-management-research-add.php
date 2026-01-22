<?php
/**
 * DJ Management Toolkit Research & Add
 *
 * Research & Add implementation for DJ Management toolkit.
 * Manages Equipment, Playlists, and Packages entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * DJ Management Research & Add implementation.
 */
class WP_MCP_AI_Dj_Management_Research_Add extends WP_MCP_AI_Research_Add_Base {

/**
 * Constructor.
 */
public function __construct() {
parent::__construct( 'dj_management' );

add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
}

/**
 * Get entity types.
 *
 * @return array Entity types.
 */
protected function get_entity_types() {
return array(
'equipment' => __( 'Equipment', 'mcp-ai-wpoos-pro' ),
'playlists' => __( 'Playlists', 'mcp-ai-wpoos-pro' ),
'packages'  => __( 'Packages', 'mcp-ai-wpoos-pro' ),
);
}

/**
 * Filter CPT field schema.
 *
 * @param array  $schema       Field schema.
 * @param string $toolkit_slug Toolkit slug.
 * @param string $entity_type  Entity type.
 * @return array Filtered schema.
 */
public function filter_cpt_field_schema( $schema, $toolkit_slug, $entity_type ) {
if ( 'dj_management' !== $toolkit_slug ) {
return $schema;
}

switch ( $entity_type ) {
case 'equipment':
return $this->get_equipment_schema();
case 'playlists':
return $this->get_playlists_schema();
case 'packages':
return $this->get_packages_schema();
}

return $schema;
}

/**
 * Filter CCT field schema.
 *
 * @param array  $schema       Field schema.
 * @param string $toolkit_slug Toolkit slug.
 * @param string $entity_type  Entity type.
 * @return array Filtered schema.
 */
public function filter_cct_field_schema( $schema, $toolkit_slug, $entity_type ) {
return $this->filter_cpt_field_schema( $schema, $toolkit_slug, $entity_type );
}

/**
 * Get equipment field schema.
 *
 * @return array Field definitions.
 */
private function get_equipment_schema() {
return array(
'equipment_name'       => array(
'title'       => __( 'Equipment Name', 'mcp-ai-wpoos-pro' ),
'type'        => 'text',
'width'       => '100%',
'is_required' => true,
),
'equipment_type'       => array(
'title' => __( 'Type', 'mcp-ai-wpoos-pro' ),
'type'  => 'select',
'width' => '50%',
),
'quantity'             => array(
'title' => __( 'Quantity', 'mcp-ai-wpoos-pro' ),
'type'  => 'number',
'width' => '50%',
),
'condition'            => array(
'title' => __( 'Condition', 'mcp-ai-wpoos-pro' ),
'type'  => 'select',
'width' => '50%',
),
'rental_price'         => array(
'title' => __( 'Rental Price', 'mcp-ai-wpoos-pro' ),
'type'  => 'number',
'width' => '50%',
),
'notes'                => array(
'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'created_by_assistant' => array(
'title' => __( 'Created by Assistant', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
);
}

/**
 * Get playlists field schema.
 *
 * @return array Field definitions.
 */
private function get_playlists_schema() {
return array(
'playlist_name'        => array(
'title'       => __( 'Playlist Name', 'mcp-ai-wpoos-pro' ),
'type'        => 'text',
'width'       => '100%',
'is_required' => true,
),
'genre'                => array(
'title' => __( 'Genre', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
'songs'                => array(
'title' => __( 'Songs (JSON)', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'duration'             => array(
'title' => __( 'Duration (minutes)', 'mcp-ai-wpoos-pro' ),
'type'  => 'number',
'width' => '50%',
),
'event_type'           => array(
'title' => __( 'Event Type', 'mcp-ai-wpoos-pro' ),
'type'  => 'select',
'width' => '50%',
),
'mood'                 => array(
'title' => __( 'Mood', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
'notes'                => array(
'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'created_by_assistant' => array(
'title' => __( 'Created by Assistant', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
);
}

/**
 * Get packages field schema.
 *
 * @return array Field definitions.
 */
private function get_packages_schema() {
return array(
'package_name'         => array(
'title'       => __( 'Package Name', 'mcp-ai-wpoos-pro' ),
'type'        => 'text',
'width'       => '100%',
'is_required' => true,
),
'description'          => array(
'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'price'                => array(
'title' => __( 'Price', 'mcp-ai-wpoos-pro' ),
'type'  => 'number',
'width' => '50%',
),
'inclusions'           => array(
'title' => __( 'Inclusions', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'duration'             => array(
'title' => __( 'Duration (hours)', 'mcp-ai-wpoos-pro' ),
'type'  => 'number',
'width' => '50%',
),
'event_type'           => array(
'title' => __( 'Event Type', 'mcp-ai-wpoos-pro' ),
'type'  => 'select',
'width' => '50%',
),
'notes'                => array(
'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'created_by_assistant' => array(
'title' => __( 'Created by Assistant', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
);
}

/**
 * Render form fields.
 *
 * @param array $item Item data.
 */
protected function render_form_fields( $item = array() ) {
$store  = $this->get_current_data_store();
$schema = $store ? $store->get_field_schema() : array();

if ( empty( $schema ) ) {
parent::render_form_fields( $item );
return;
}

?>
<table class="form-table">
<?php foreach ( $schema as $field_name => $field_def ) : ?>
<tr>
<th scope="row">
<label for="item_<?php echo esc_attr( $field_name ); ?>">
<?php echo esc_html( $field_def['title'] ); ?>
<?php if ( ! empty( $field_def['is_required'] ) ) : ?>
<span class="required">*</span>
<?php endif; ?>
</label>
</th>
<td>
<?php $this->render_field_input( $field_name, $field_def, $item ); ?>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php
}

/**
 * Render field input.
 *
 * @param string $field_name Field name.
 * @param array  $field_def  Field definition.
 * @param array  $item       Item data.
 */
private function render_field_input( $field_name, $field_def, $item = array() ) {
$value    = isset( $item[ $field_name ] ) ? $item[ $field_name ] : '';
$type     = isset( $field_def['type'] ) ? $field_def['type'] : 'text';
$required = ! empty( $field_def['is_required'] ) ? 'required' : '';

switch ( $type ) {
case 'textarea':
?>
<textarea 
id="item_<?php echo esc_attr( $field_name ); ?>"
name="item_data[<?php echo esc_attr( $field_name ); ?>]"
rows="5"
class="large-text"
<?php echo esc_attr( $required ); ?>
><?php echo esc_textarea( $value ); ?></textarea>
<?php
break;

case 'number':
?>
<input 
type="number"
id="item_<?php echo esc_attr( $field_name ); ?>"
name="item_data[<?php echo esc_attr( $field_name ); ?>]"
value="<?php echo esc_attr( $value ); ?>"
class="regular-text"
step="0.01"
<?php echo esc_attr( $required ); ?>
>
<?php
break;

case 'select':
$options = array();
if ( 'equipment_type' === $field_name ) {
$options = array(
''           => __( 'Select Type', 'mcp-ai-wpoos-pro' ),
'speakers'   => __( 'Speakers', 'mcp-ai-wpoos-pro' ),
'mixer'      => __( 'Mixer', 'mcp-ai-wpoos-pro' ),
'turntable'  => __( 'Turntable', 'mcp-ai-wpoos-pro' ),
'controller' => __( 'Controller', 'mcp-ai-wpoos-pro' ),
'lighting'   => __( 'Lighting', 'mcp-ai-wpoos-pro' ),
'microphone' => __( 'Microphone', 'mcp-ai-wpoos-pro' ),
'other'      => __( 'Other', 'mcp-ai-wpoos-pro' ),
);
} elseif ( 'condition' === $field_name ) {
$options = array(
''           => __( 'Select Condition', 'mcp-ai-wpoos-pro' ),
'excellent'  => __( 'Excellent', 'mcp-ai-wpoos-pro' ),
'good'       => __( 'Good', 'mcp-ai-wpoos-pro' ),
'fair'       => __( 'Fair', 'mcp-ai-wpoos-pro' ),
'needs_repair' => __( 'Needs Repair', 'mcp-ai-wpoos-pro' ),
);
} elseif ( 'event_type' === $field_name ) {
$options = array(
''            => __( 'Select Event Type', 'mcp-ai-wpoos-pro' ),
'wedding'     => __( 'Wedding', 'mcp-ai-wpoos-pro' ),
'corporate'   => __( 'Corporate', 'mcp-ai-wpoos-pro' ),
'birthday'    => __( 'Birthday', 'mcp-ai-wpoos-pro' ),
'club'        => __( 'Club/Nightlife', 'mcp-ai-wpoos-pro' ),
'concert'     => __( 'Concert', 'mcp-ai-wpoos-pro' ),
'private'     => __( 'Private Party', 'mcp-ai-wpoos-pro' ),
'other'       => __( 'Other', 'mcp-ai-wpoos-pro' ),
);
}

?>
<select 
id="item_<?php echo esc_attr( $field_name ); ?>"
name="item_data[<?php echo esc_attr( $field_name ); ?>]"
class="regular-text"
<?php echo esc_attr( $required ); ?>
>
<?php foreach ( $options as $opt_value => $opt_label ) : ?>
<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
<?php echo esc_html( $opt_label ); ?>
</option>
<?php endforeach; ?>
</select>
<?php
break;

default: // text
?>
<input 
type="text"
id="item_<?php echo esc_attr( $field_name ); ?>"
name="item_data[<?php echo esc_attr( $field_name ); ?>]"
value="<?php echo esc_attr( $value ); ?>"
class="regular-text"
<?php echo esc_attr( $required ); ?>
>
<?php
break;
}
}

/**
 * Render table headers.
 */
protected function render_table_headers() {
switch ( $this->current_entity ) {
case 'equipment':
?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Equipment Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Quantity', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Condition', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Rental Price', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
<?php
break;

case 'playlists':
?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Playlist Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Genre', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Event Type', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
<?php
break;

case 'packages':
?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Package Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Price', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Event Type', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
<?php
break;

default:
parent::render_table_headers();
}
}

/**
 * Render table row.
 *
 * @param array $item Item data.
 */
protected function render_table_row( $item ) {
$edit_url   = add_query_arg( array( 'action' => 'edit', 'id' => $item['id'] ) );
$delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'id' => $item['id'] ) ), 'delete_item_' . $item['id'] );

switch ( $this->current_entity ) {
case 'equipment':
?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['equipment_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( ucfirst( $item['equipment_type'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( $item['quantity'] ?? '-' ); ?></td>
<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $item['condition'] ?? '-' ) ) ); ?></td>
<td><?php echo esc_html( isset( $item['rental_price'] ) ? '$' . number_format( (float) $item['rental_price'], 2 ) : '-' ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
<?php
break;

case 'playlists':
?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['playlist_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $item['genre'] ?? '-' ); ?></td>
<td><?php echo esc_html( isset( $item['duration'] ) ? $item['duration'] . ' min' : '-' ); ?></td>
<td><?php echo esc_html( ucfirst( $item['event_type'] ?? '-' ) ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
<?php
break;

case 'packages':
?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['package_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( isset( $item['price'] ) ? '$' . number_format( (float) $item['price'], 2 ) : '-' ); ?></td>
<td><?php echo esc_html( isset( $item['duration'] ) ? $item['duration'] . ' hrs' : '-' ); ?></td>
<td><?php echo esc_html( ucfirst( $item['event_type'] ?? '-' ) ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
<?php
break;

default:
parent::render_table_row( $item );
}
}
}
