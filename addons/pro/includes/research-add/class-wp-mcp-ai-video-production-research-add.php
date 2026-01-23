<?php
/**
 * Video Production Toolkit Research & Add
 *
 * Research & Add implementation for Video Production toolkit.
 * Manages Video Projects, Scenes, and Assets entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Video Production Research & Add implementation.
 */
class WP_MCP_AI_Video_Production_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'video_production' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for video production toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'projects' => __( 'Video Projects', 'mcp-ai-wpoos-pro' ),
			'scenes'   => __( 'Scenes', 'mcp-ai-wpoos-pro' ),
			'assets'   => __( 'Video Assets', 'mcp-ai-wpoos-pro' ),
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
		if ( 'video_production' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'projects':
				return $this->get_projects_schema();
			case 'scenes':
				return $this->get_scenes_schema();
			case 'assets':
				return $this->get_assets_schema();
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
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'client_name'          => array(
				'title' => __( 'Client Name', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'project_type'         => array(
				'title' => __( 'Project Type', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'resolution'           => array(
				'title' => __( 'Resolution', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'frame_rate'           => array(
				'title' => __( 'Frame Rate (fps)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'duration'             => array(
				'title' => __( 'Target Duration (seconds)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'format'               => array(
				'title' => __( 'Output Format', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
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
	 * Get scenes field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_scenes_schema() {
		return array(
			'scene_name'           => array(
				'title'       => __( 'Scene Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'scene_number'         => array(
				'title' => __( 'Scene Number', 'mcp-ai-wpoos-pro' ),
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
			'duration'             => array(
				'title' => __( 'Duration (seconds)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'location'             => array(
				'title' => __( 'Location', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'camera_settings'      => array(
				'title' => __( 'Camera Settings', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'notes'                => array(
				'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
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
			'duration'             => array(
				'title' => __( 'Duration (seconds)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'resolution'           => array(
				'title' => __( 'Resolution', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'projects':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Project Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Resolution', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'scenes':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Scene Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Number', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'assets':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Asset Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Format', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Size', 'mcp-ai-wpoos-pro' ); ?></th>
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
				<td><?php echo esc_html( $item['resolution'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Pre-production' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'scenes':
				$duration_formatted = isset( $item['duration'] ) ? gmdate( 'i:s', (int) $item['duration'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['scene_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['scene_number'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $duration_formatted ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Planned' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'assets':
				$file_size_formatted = isset( $item['file_size'] ) ? size_format( (int) $item['file_size'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['asset_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['asset_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['format'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $file_size_formatted ); ?></td>
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
