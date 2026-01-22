<?php
/**
 * Social Media Toolkit Research & Add
 *
 * Research & Add implementation for Social Media toolkit.
 * Manages Content Calendar and Post Templates entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Social Media Research & Add implementation.
 */
class WP_MCP_AI_Social_Media_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'social_media' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for social media toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'content_calendar' => __( 'Content Calendar', 'mcp-ai-wpoos-pro' ),
			'post_templates'   => __( 'Post Templates', 'mcp-ai-wpoos-pro' ),
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
		if ( 'social_media' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'content_calendar':
				return $this->get_content_calendar_schema();
			case 'post_templates':
				return $this->get_post_templates_schema();
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
	 * Get content calendar field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_content_calendar_schema() {
		return array(
			'post_title'           => array(
				'title'       => __( 'Post Title', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'platform'             => array(
				'title'       => __( 'Platform', 'mcp-ai-wpoos-pro' ),
				'type'        => 'select',
				'width'       => '50%',
				'is_required' => true,
			),
			'content'              => array(
				'title' => __( 'Content', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'scheduled_date'       => array(
				'title' => __( 'Scheduled Date', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'scheduled_time'       => array(
				'title' => __( 'Scheduled Time', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'hashtags'             => array(
				'title' => __( 'Hashtags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'media_urls'           => array(
				'title' => __( 'Media URLs (comma-separated)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'target_audience'      => array(
				'title' => __( 'Target Audience', 'mcp-ai-wpoos-pro' ),
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
	 * Get post templates field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_post_templates_schema() {
		return array(
			'template_name'        => array(
				'title'       => __( 'Template Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'platform'             => array(
				'title'       => __( 'Platform', 'mcp-ai-wpoos-pro' ),
				'type'        => 'select',
				'width'       => '50%',
				'is_required' => true,
			),
			'template_content'     => array(
				'title' => __( 'Template Content', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'variables'            => array(
				'title' => __( 'Variables (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'category'             => array(
				'title' => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'tags'                 => array(
				'title' => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'default_hashtags'     => array(
				'title' => __( 'Default Hashtags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
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

			case 'select':
				// Define platform options based on field name.
				$options = array();
				if ( 'platform' === $field_name ) {
					$options = array(
						''          => __( 'Select Platform', 'mcp-ai-wpoos-pro' ),
						'facebook'  => __( 'Facebook', 'mcp-ai-wpoos-pro' ),
						'twitter'   => __( 'Twitter/X', 'mcp-ai-wpoos-pro' ),
						'instagram' => __( 'Instagram', 'mcp-ai-wpoos-pro' ),
						'linkedin'  => __( 'LinkedIn', 'mcp-ai-wpoos-pro' ),
						'tiktok'    => __( 'TikTok', 'mcp-ai-wpoos-pro' ),
						'pinterest' => __( 'Pinterest', 'mcp-ai-wpoos-pro' ),
					);
				} elseif ( 'status' === $field_name ) {
					$options = array(
						''          => __( 'Select Status', 'mcp-ai-wpoos-pro' ),
						'draft'     => __( 'Draft', 'mcp-ai-wpoos-pro' ),
						'scheduled' => __( 'Scheduled', 'mcp-ai-wpoos-pro' ),
						'published' => __( 'Published', 'mcp-ai-wpoos-pro' ),
						'failed'    => __( 'Failed', 'mcp-ai-wpoos-pro' ),
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'content_calendar':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Post Title', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Platform', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Scheduled Date', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'post_templates':
				?>
<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Template Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Platform', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'content_calendar':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['post_title'] ?? __( '(No title)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( ucfirst( $item['platform'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( $item['scheduled_date'] ?? '-' ); ?></td>
<td><?php echo esc_html( ucfirst( $item['status'] ?? '-' ) ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
</td>
				<?php
				break;

			case 'post_templates':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['template_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
<td><?php echo esc_html( ucfirst( $item['platform'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( $item['category'] ?? '-' ); ?></td>
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
