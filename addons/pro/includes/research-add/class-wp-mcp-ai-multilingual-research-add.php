<?php
/**
 * Multilingual Toolkit Research & Add
 *
 * Research & Add implementation for Multilingual toolkit.
 * Manages Translation Memory and Glossaries entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Multilingual Research & Add implementation.
 */
class WP_MCP_AI_Multilingual_Research_Add extends WP_MCP_AI_Research_Add_Base {

/**
 * Constructor.
 */
public function __construct() {
parent::__construct( 'multilingual' );

// Register field schemas.
add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
}

/**
 * Get entity types for multilingual toolkit.
 *
 * @return array Entity types.
 */
protected function get_entity_types() {
return array(
'translation_memory' => __( 'Translation Memory', 'mcp-ai-wpoos-pro' ),
'glossaries'         => __( 'Glossaries', 'mcp-ai-wpoos-pro' ),
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
if ( 'multilingual' !== $toolkit_slug ) {
return $schema;
}

switch ( $entity_type ) {
case 'translation_memory':
return $this->get_translation_memory_schema();
case 'glossaries':
return $this->get_glossaries_schema();
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
// Use same schema for both CPT and CCT.
return $this->filter_cpt_field_schema( $schema, $toolkit_slug, $entity_type );
}

/**
 * Get translation memory field schema.
 *
 * @return array Field definitions.
 */
private function get_translation_memory_schema() {
return array(
'source_language'      => array(
'title'       => __( 'Source Language', 'mcp-ai-wpoos-pro' ),
'type'        => 'text',
'width'       => '50%',
'is_required' => true,
),
'target_language'      => array(
'title'       => __( 'Target Language', 'mcp-ai-wpoos-pro' ),
'type'        => 'text',
'width'       => '50%',
'is_required' => true,
),
'source_text'          => array(
'title'       => __( 'Source Text', 'mcp-ai-wpoos-pro' ),
'type'        => 'textarea',
'width'       => '100%',
'is_required' => true,
),
'translated_text'      => array(
'title'       => __( 'Translated Text', 'mcp-ai-wpoos-pro' ),
'type'        => 'textarea',
'width'       => '100%',
'is_required' => true,
),
'quality_score'        => array(
'title' => __( 'Quality Score (0-100)', 'mcp-ai-wpoos-pro' ),
'type'  => 'number',
'width' => '50%',
),
'context'              => array(
'title' => __( 'Context', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'domain'               => array(
'title' => __( 'Domain', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
'created_by_assistant' => array(
'title' => __( 'Created by Assistant', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
);
}

/**
 * Get glossaries field schema.
 *
 * @return array Field definitions.
 */
private function get_glossaries_schema() {
return array(
'term'                 => array(
'title'       => __( 'Term', 'mcp-ai-wpoos-pro' ),
'type'        => 'text',
'width'       => '100%',
'is_required' => true,
),
'definition'           => array(
'title' => __( 'Definition', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'context'              => array(
'title' => __( 'Context', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'translations'         => array(
'title' => __( 'Translations (JSON)', 'mcp-ai-wpoos-pro' ),
'type'  => 'textarea',
'width' => '100%',
),
'industry'             => array(
'title' => __( 'Industry/Domain', 'mcp-ai-wpoos-pro' ),
'type'  => 'text',
'width' => '50%',
),
'tags'                 => array(
'title' => __( 'Tags', 'mcp-ai-wpoos-pro' ),
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
 * Render form fields for current entity.
 *
 * @param array $item Optional. Item data for edit form.
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
 * Render field input based on type.
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
min="0"
max="100"
step="1"
<?php echo esc_attr( $required ); ?>
>
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
 * Render table headers for current entity.
 */
protected function render_table_headers() {
switch ( $this->current_entity ) {
case 'translation_memory':
?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Source Language', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Target Language', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Source Text', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Quality Score', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
<?php
break;

case 'glossaries':
?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Term', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Definition', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Industry', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
<?php
break;

default:
parent::render_table_headers();
}
}

/**
 * Render table row for current entity.
 *
 * @param array $item Item data.
 */
protected function render_table_row( $item ) {
$edit_url   = add_query_arg(
array(
'action' => 'edit',
'id'     => $item['id'],
)
);
$delete_url = wp_nonce_url(
add_query_arg(
array(
'action' => 'delete',
'id'     => $item['id'],
)
),
'delete_item_' . $item['id']
);

switch ( $this->current_entity ) {
case 'translation_memory':
$source_preview = isset( $item['source_text'] ) ? wp_trim_words( $item['source_text'], 10 ) : '-';
?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( strtoupper( $item['source_language'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( strtoupper( $item['target_language'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( $source_preview ); ?></td>
<td><?php echo esc_html( isset( $item['quality_score'] ) ? $item['quality_score'] . '%' : '-' ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
<?php
break;

case 'glossaries':
$definition_preview = isset( $item['definition'] ) ? wp_trim_words( $item['definition'], 10 ) : '-';
?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['term'] ?? __( '(No term)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $definition_preview ); ?></td>
<td><?php echo esc_html( $item['industry'] ?? '-' ); ?></td>
<td><?php echo esc_html( $item['tags'] ?? '-' ); ?></td>
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
