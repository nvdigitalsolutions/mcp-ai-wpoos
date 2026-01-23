<?php
/**
 * Architectural Design Toolkit Research & Add
 *
 * Research & Add implementation for Architectural Design toolkit.
 * Manages Projects, Drawings, and Specifications entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Architectural Design Research & Add implementation.
 */
class WP_MCP_AI_Architectural_Design_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'architectural_design' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for architectural design toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'projects'       => __( 'Design Projects', 'mcp-ai-wpoos-pro' ),
			'drawings'       => __( 'Drawings', 'mcp-ai-wpoos-pro' ),
			'specifications' => __( 'Specifications', 'mcp-ai-wpoos-pro' ),
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
		if ( 'architectural_design' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'projects':
				return $this->get_projects_schema();
			case 'drawings':
				return $this->get_drawings_schema();
			case 'specifications':
				return $this->get_specifications_schema();
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
	 * Get projects field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_projects_schema() {
		return array(
			'project_name'         => array(
				'title'       => __( 'Project Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'project_type'         => array(
				'title' => __( 'Project Type', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'client_name'          => array(
				'title' => __( 'Client Name', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'location'             => array(
				'title' => __( 'Location', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'budget'               => array(
				'title' => __( 'Budget', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'square_footage'       => array(
				'title' => __( 'Square Footage', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'start_date'           => array(
				'title' => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'completion_date'      => array(
				'title' => __( 'Completion Date', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
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
	 * Get drawings field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_drawings_schema() {
		return array(
			'drawing_name'         => array(
				'title'       => __( 'Drawing Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'drawing_number'       => array(
				'title' => __( 'Drawing Number', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'drawing_type'         => array(
				'title' => __( 'Drawing Type', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'project_id'           => array(
				'title' => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'scale'                => array(
				'title' => __( 'Scale', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'revision'             => array(
				'title' => __( 'Revision', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'file_url'             => array(
				'title' => __( 'File URL', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'status'               => array(
				'title' => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
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
	 * Get specifications field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_specifications_schema() {
		return array(
			'spec_name'            => array(
				'title'       => __( 'Specification Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'spec_number'          => array(
				'title' => __( 'Specification Number', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'category'             => array(
				'title' => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'project_id'           => array(
				'title' => __( 'Project ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'requirements'         => array(
				'title' => __( 'Requirements', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'materials'            => array(
				'title' => __( 'Materials', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'projects':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Project Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Client', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'drawings':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Drawing Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Number', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'specifications':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Specification Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Number', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'projects':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['project_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['project_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['client_name'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Planning' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'drawings':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['drawing_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['drawing_number'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['drawing_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Draft' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'specifications':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['spec_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['spec_number'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['category'] ?? '-' ); ?></td>
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
