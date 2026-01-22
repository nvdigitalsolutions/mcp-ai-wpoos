<?php
/**
 * E-commerce Toolkit Research & Add
 *
 * Research & Add implementation for E-commerce toolkit.
 * Manages Products, Customers, and Orders entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * E-commerce Research & Add implementation.
 */
class WP_MCP_AI_Ecommerce_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ecommerce' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for e-commerce toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'products'  => __( 'Products', 'mcp-ai-wpoos-pro' ),
			'customers' => __( 'Customers', 'mcp-ai-wpoos-pro' ),
			'orders'    => __( 'Orders', 'mcp-ai-wpoos-pro' ),
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
		if ( 'ecommerce' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'products':
				return $this->get_products_schema();
			case 'customers':
				return $this->get_customers_schema();
			case 'orders':
				return $this->get_orders_schema();
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
	 * Get products field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_products_schema() {
		return array(
			'product_name'         => array(
				'title'       => __( 'Product Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'short_description'    => array(
				'title' => __( 'Short Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'price'                => array(
				'title' => __( 'Price', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'regular_price'        => array(
				'title' => __( 'Regular Price', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'sale_price'           => array(
				'title' => __( 'Sale Price', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'category'             => array(
				'title' => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'sku'                  => array(
				'title' => __( 'SKU', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'stock_quantity'       => array(
				'title' => __( 'Stock Quantity', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'image_url'            => array(
				'title' => __( 'Image URL', 'mcp-ai-wpoos-pro' ),
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
	 * Get customers field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_customers_schema() {
		return array(
			'customer_name'        => array(
				'title'       => __( 'Customer Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'email'                => array(
				'title'       => __( 'Email', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'phone'                => array(
				'title' => __( 'Phone', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'company'              => array(
				'title' => __( 'Company', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'address'              => array(
				'title' => __( 'Address', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'city'                 => array(
				'title' => __( 'City', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'state'                => array(
				'title' => __( 'State/Province', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'postal_code'          => array(
				'title' => __( 'Postal Code', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'country'              => array(
				'title' => __( 'Country', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'notes'                => array(
				'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
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
	 * Get orders field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_orders_schema() {
		return array(
			'order_number'         => array(
				'title'       => __( 'Order Number', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'customer_id'          => array(
				'title' => __( 'Customer ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'customer_name'        => array(
				'title' => __( 'Customer Name', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'items'                => array(
				'title' => __( 'Order Items (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'subtotal'             => array(
				'title' => __( 'Subtotal', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'tax'                  => array(
				'title' => __( 'Tax', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'shipping'             => array(
				'title' => __( 'Shipping', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'total'                => array(
				'title' => __( 'Total', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'status'               => array(
				'title' => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'payment_method'       => array(
				'title' => __( 'Payment Method', 'mcp-ai-wpoos-pro' ),
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
step="0.01"
				<?php echo esc_attr( $required ); ?>
>
				<?php
				break;

			case 'select':
				// For now, render as text. Can be enhanced later with actual options.
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'products':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Product Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'SKU', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Price', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'customers':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'City', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'orders':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Order Number', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Customer', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Total', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'products':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['product_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $item['sku'] ?? '-' ); ?></td>
<td><?php echo esc_html( isset( $item['price'] ) ? '$' . number_format( (float) $item['price'], 2 ) : '-' ); ?></td>
<td><?php echo esc_html( $item['category'] ?? '-' ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
				<?php
				break;

			case 'customers':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['customer_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $item['email'] ?? '-' ); ?></td>
<td><?php echo esc_html( $item['phone'] ?? '-' ); ?></td>
<td><?php echo esc_html( $item['city'] ?? '-' ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
				<?php
				break;

			case 'orders':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['order_number'] ?? __( '(No number)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $item['customer_name'] ?? '-' ); ?></td>
<td><?php echo esc_html( isset( $item['total'] ) ? '$' . number_format( (float) $item['total'], 2 ) : '-' ); ?></td>
<td><?php echo esc_html( $item['status'] ?? '-' ); ?></td>
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
