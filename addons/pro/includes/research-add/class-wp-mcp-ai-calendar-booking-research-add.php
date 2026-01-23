<?php
/**
 * Calendar Booking Toolkit Research & Add
 *
 * Research & Add implementation for Calendar Booking toolkit.
 * Manages Services, Staff, and Time Slots entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Calendar Booking Research & Add implementation.
 */
class WP_MCP_AI_Calendar_Booking_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'calendar_booking' );

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
			'services'   => __( 'Services', 'mcp-ai-wpoos-pro' ),
			'staff'      => __( 'Staff', 'mcp-ai-wpoos-pro' ),
			'time_slots' => __( 'Time Slots', 'mcp-ai-wpoos-pro' ),
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
		if ( 'calendar_booking' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'services':
				return $this->get_services_schema();
			case 'staff':
				return $this->get_staff_schema();
			case 'time_slots':
				return $this->get_time_slots_schema();
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
	 * Get services field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_services_schema() {
		return array(
			'service_name'         => array(
				'title'       => __( 'Service Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'duration'             => array(
				'title' => __( 'Duration (minutes)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'price'                => array(
				'title' => __( 'Price', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'availability_rules'   => array(
				'title' => __( 'Availability Rules (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'category'             => array(
				'title' => __( 'Category', 'mcp-ai-wpoos-pro' ),
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
	 * Get staff field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_staff_schema() {
		return array(
			'staff_name'           => array(
				'title'       => __( 'Staff Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'email'                => array(
				'title' => __( 'Email', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'phone'                => array(
				'title' => __( 'Phone', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'services'             => array(
				'title' => __( 'Services (comma-separated IDs)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'availability'         => array(
				'title' => __( 'Availability (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'bio'                  => array(
				'title' => __( 'Bio', 'mcp-ai-wpoos-pro' ),
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
	 * Get time slots field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_time_slots_schema() {
		return array(
			'staff_id'             => array(
				'title'       => __( 'Staff ID', 'mcp-ai-wpoos-pro' ),
				'type'        => 'number',
				'width'       => '50%',
				'is_required' => true,
			),
			'day_of_week'          => array(
				'title' => __( 'Day of Week', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'start_time'           => array(
				'title' => __( 'Start Time', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'end_time'             => array(
				'title' => __( 'End Time', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'is_available'         => array(
				'title' => __( 'Available', 'mcp-ai-wpoos-pro' ),
				'type'  => 'checkbox',
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

			case 'checkbox':
				?>
<label>
<input 
type="checkbox"
id="item_<?php echo esc_attr( $field_name ); ?>"
name="item_data[<?php echo esc_attr( $field_name ); ?>]"
value="1"
				<?php checked( $value, 1 ); ?>
>
				<?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?>
</label>
				<?php
				break;

			case 'select':
				$options = array();
				if ( 'day_of_week' === $field_name ) {
					$options = array(
						''          => __( 'Select Day', 'mcp-ai-wpoos-pro' ),
						'monday'    => __( 'Monday', 'mcp-ai-wpoos-pro' ),
						'tuesday'   => __( 'Tuesday', 'mcp-ai-wpoos-pro' ),
						'wednesday' => __( 'Wednesday', 'mcp-ai-wpoos-pro' ),
						'thursday'  => __( 'Thursday', 'mcp-ai-wpoos-pro' ),
						'friday'    => __( 'Friday', 'mcp-ai-wpoos-pro' ),
						'saturday'  => __( 'Saturday', 'mcp-ai-wpoos-pro' ),
						'sunday'    => __( 'Sunday', 'mcp-ai-wpoos-pro' ),
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
			case 'services':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Service Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Price', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'staff':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Staff Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'time_slots':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Staff ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Day', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Start Time', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'End Time', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'services':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['service_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( isset( $item['duration'] ) ? $item['duration'] . ' min' : '-' ); ?></td>
<td><?php echo esc_html( isset( $item['price'] ) ? '$' . number_format( (float) $item['price'], 2 ) : '-' ); ?></td>
<td><?php echo esc_html( $item['category'] ?? '-' ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
				<?php
				break;

			case 'staff':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['staff_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( $item['email'] ?? '-' ); ?></td>
<td><?php echo esc_html( $item['phone'] ?? '-' ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
				<?php
				break;

			case 'time_slots':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['staff_id'] ?? '-' ); ?></td>
<td><?php echo esc_html( ucfirst( $item['day_of_week'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( $item['start_time'] ?? '-' ); ?></td>
<td><?php echo esc_html( $item['end_time'] ?? '-' ); ?></td>
<td><?php echo ! empty( $item['is_available'] ) ? '✓' : '✗'; ?></td>
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
