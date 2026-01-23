<?php
/**
 * Image Production Toolkit Research & Add
 *
 * Research & Add implementation for Image Production toolkit.
 * Manages Image Projects, Assets, and Compositions entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Image Production Research & Add implementation.
 */
class WP_MCP_AI_Image_Production_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'image_production' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for image production toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'projects'     => __( 'Image Projects', 'mcp-ai-wpoos-pro' ),
			'assets'       => __( 'Image Assets', 'mcp-ai-wpoos-pro' ),
			'compositions' => __( 'Compositions', 'mcp-ai-wpoos-pro' ),
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
		if ( 'image_production' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'projects':
				return $this->get_projects_schema();
			case 'assets':
				return $this->get_assets_schema();
			case 'compositions':
				return $this->get_compositions_schema();
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
				'type'  => 'select',
				'width' => '50%',
			),
			'client_name'          => array(
				'title' => __( 'Client Name', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'canvas_width'         => array(
				'title' => __( 'Canvas Width (px)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'canvas_height'        => array(
				'title' => __( 'Canvas Height (px)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'color_mode'           => array(
				'title' => __( 'Color Mode', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'resolution'           => array(
				'title' => __( 'Resolution (DPI)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'deadline'             => array(
				'title' => __( 'Deadline', 'mcp-ai-wpoos-pro' ),
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
	 * Get assets field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_assets_schema() {
		return array(
			'asset_name'           => array(
				'title'       => __( 'Asset Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'asset_type'           => array(
				'title' => __( 'Asset Type', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'file_url'             => array(
				'title' => __( 'File URL', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'file_size'            => array(
				'title' => __( 'File Size (bytes)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'width'                => array(
				'title' => __( 'Width (px)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'height'               => array(
				'title' => __( 'Height (px)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'format'               => array(
				'title' => __( 'Format', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'tags'                 => array(
				'title' => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
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
	 * Get compositions field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_compositions_schema() {
		return array(
			'composition_name'     => array(
				'title'       => __( 'Composition Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
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
			'layers'               => array(
				'title' => __( 'Layers (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'effects'              => array(
				'title' => __( 'Effects (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'output_file_url'      => array(
				'title' => __( 'Output File URL', 'mcp-ai-wpoos-pro' ),
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'projects':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Project Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Dimensions', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'assets':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Asset Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Dimensions', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Size', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'compositions':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Composition Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Project ID', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'projects':
				$dimensions = '';
				if ( ! empty( $item['canvas_width'] ) && ! empty( $item['canvas_height'] ) ) {
					$dimensions = $item['canvas_width'] . 'x' . $item['canvas_height'] . ' px';
				}
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['project_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['project_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $dimensions ?: '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Active' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'assets':
				$dimensions = '';
				if ( ! empty( $item['width'] ) && ! empty( $item['height'] ) ) {
					$dimensions = $item['width'] . 'x' . $item['height'] . ' px';
				}
				$file_size_formatted = isset( $item['file_size'] ) ? size_format( (int) $item['file_size'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['asset_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['asset_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $dimensions ?: '-' ); ?></td>
				<td><?php echo esc_html( $file_size_formatted ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'compositions':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['composition_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['project_id'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Draft' ); ?></td>
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
