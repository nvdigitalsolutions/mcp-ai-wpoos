<?php
/**
 * Video Production Toolkit Research & Add page (ecosystem port - Wave F2, video-production admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in monolith
 * installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root (the toolkit-settings/research-add base
 * classes resolve from the addon's already-ported `src/admin/` copies).
 *
 * Video Production Toolkit Research & Add
 *
 * Research & Add implementation for Video Production toolkit.
 * Manages Video Projects, Scenes, and Assets entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-research-add-base.php';

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
			'projects' => __( 'Video Projects', 'nvoos-content-graph-pro' ),
			'scenes'   => __( 'Scenes', 'nvoos-content-graph-pro' ),
			'assets'   => __( 'Video Assets', 'nvoos-content-graph-pro' ),
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
				'title'       => __( 'Project Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'client_name'          => array(
				'title' => __( 'Client Name', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'project_type'         => array(
				'title' => __( 'Project Type', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'resolution'           => array(
				'title' => __( 'Resolution', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'frame_rate'           => array(
				'title' => __( 'Frame Rate (fps)', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'duration'             => array(
				'title' => __( 'Target Duration (seconds)', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'format'               => array(
				'title' => __( 'Output Format', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'deadline'             => array(
				'title' => __( 'Deadline', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'created_by_assistant' => array(
				'title' => __( 'Created by Assistant', 'nvoos-content-graph-pro' ),
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
				'title'       => __( 'Scene Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'scene_number'         => array(
				'title' => __( 'Scene Number', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'project_id'           => array(
				'title' => __( 'Project ID', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'duration'             => array(
				'title' => __( 'Duration (seconds)', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'location'             => array(
				'title' => __( 'Location', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'camera_settings'      => array(
				'title' => __( 'Camera Settings', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'notes'                => array(
				'title' => __( 'Notes', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'status'               => array(
				'title' => __( 'Status', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'created_by_assistant' => array(
				'title' => __( 'Created by Assistant', 'nvoos-content-graph-pro' ),
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
				'title'       => __( 'Asset Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'asset_type'           => array(
				'title' => __( 'Asset Type', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'file_url'             => array(
				'title' => __( 'File URL', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'file_size'            => array(
				'title' => __( 'File Size (bytes)', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'duration'             => array(
				'title' => __( 'Duration (seconds)', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'resolution'           => array(
				'title' => __( 'Resolution', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'format'               => array(
				'title' => __( 'Format', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'tags'                 => array(
				'title' => __( 'Tags', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'description'          => array(
				'title' => __( 'Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'created_by_assistant' => array(
				'title' => __( 'Created by Assistant', 'nvoos-content-graph-pro' ),
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
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Project Name', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Resolution', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'scenes':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Scene Name', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Number', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'assets':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Asset Name', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Format', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Size', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
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
				<td><?php echo esc_html( $item['project_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['project_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['resolution'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Pre-production' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'scenes':
				$duration_formatted = isset( $item['duration'] ) ? gmdate( 'i:s', (int) $item['duration'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['scene_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['scene_number'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $duration_formatted ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Planned' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'assets':
				$file_size_formatted = isset( $item['file_size'] ) ? size_format( (int) $item['file_size'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['asset_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['asset_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['format'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $file_size_formatted ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			default:
				parent::render_table_row( $item );
		}
	}
}
