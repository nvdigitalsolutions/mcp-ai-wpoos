<?php
/**
 * AI Tool Builder Toolkit Research & Add
 *
 * Research & Add implementation for AI Tool Builder toolkit.
 * Manages Tool Templates and Parameter Schemas entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * AI Tool Builder Research & Add implementation.
 */
class WP_MCP_AI_Ai_Tool_Builder_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ai_tool_builder' );

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
			'tool_templates'    => __( 'Tool Templates', 'mcp-ai-wpoos-pro' ),
			'parameter_schemas' => __( 'Parameter Schemas', 'mcp-ai-wpoos-pro' ),
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
		if ( 'ai_tool_builder' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'tool_templates':
				return $this->get_tool_templates_schema();
			case 'parameter_schemas':
				return $this->get_parameter_schemas_schema();
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
	 * Get tool templates field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_tool_templates_schema() {
		return array(
			'tool_name'            => array(
				'title'       => __( 'Tool Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'tool_slug'            => array(
				'title'       => __( 'Tool Slug', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'category'             => array(
				'title' => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'required_capability'  => array(
				'title' => __( 'Required Capability', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'code_template'        => array(
				'title' => __( 'Code Template', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'test_template'        => array(
				'title' => __( 'Test Template', 'mcp-ai-wpoos-pro' ),
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
	 * Get parameter schemas field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_parameter_schemas_schema() {
		return array(
			'schema_name'          => array(
				'title'       => __( 'Schema Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'json_schema'          => array(
				'title'       => __( 'JSON Schema', 'mcp-ai-wpoos-pro' ),
				'type'        => 'textarea',
				'width'       => '100%',
				'is_required' => true,
			),
			'validation_rules'     => array(
				'title' => __( 'Validation Rules', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'example_usage'        => array(
				'title' => __( 'Example Usage', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'tags'                 => array(
				'title' => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
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
				$rows = in_array( $field_name, array( 'code_template', 'test_template', 'json_schema' ), true ) ? 15 : 5;
				?>
<textarea 
id="item_<?php echo esc_attr( $field_name ); ?>"
name="item_data[<?php echo esc_attr( $field_name ); ?>]"
rows="<?php echo esc_attr( $rows ); ?>"
class="large-text code"
				<?php echo esc_attr( $required ); ?>
><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( in_array( $field_name, array( 'code_template', 'test_template', 'json_schema' ), true ) ) : ?>
<p class="description"><?php esc_html_e( 'Use code editor or paste formatted code.', 'mcp-ai-wpoos-pro' ); ?></p>
<?php endif; ?>
				<?php
				break;

			case 'select':
				$options = array();
				if ( 'category' === $field_name ) {
					$options = array(
						''            => __( 'Select Category', 'mcp-ai-wpoos-pro' ),
						'content'     => __( 'Content Management', 'mcp-ai-wpoos-pro' ),
						'media'       => __( 'Media', 'mcp-ai-wpoos-pro' ),
						'ecommerce'   => __( 'E-commerce', 'mcp-ai-wpoos-pro' ),
						'analytics'   => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
						'integration' => __( 'Integration', 'mcp-ai-wpoos-pro' ),
						'utility'     => __( 'Utility', 'mcp-ai-wpoos-pro' ),
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

			default: // text.
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
			case 'tool_templates':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Capability', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'parameter_schemas':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Schema Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'tool_templates':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['tool_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><code><?php echo esc_html( $item['tool_slug'] ?? '-' ); ?></code></td>
<td><?php echo esc_html( ucfirst( $item['category'] ?? '-' ) ); ?></td>
<td><code><?php echo esc_html( $item['required_capability'] ?? 'edit_posts' ); ?></code></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
				<?php
				break;

			case 'parameter_schemas':
				$description_preview = isset( $item['description'] ) ? wp_trim_words( $item['description'], 10 ) : '-';
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['schema_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $description_preview ); ?></td>
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
