<?php
/**
 * Media Toolkit Research & Add
 *
 * Research & Add implementation for Media toolkit.
 * Manages Media Libraries, Playlists, and Media Items entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Media Research & Add implementation.
 */
class WP_MCP_AI_Media_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'media' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for media toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'libraries' => __( 'Media Libraries', 'mcp-ai-wpoos-pro' ),
			'playlists' => __( 'Playlists', 'mcp-ai-wpoos-pro' ),
			'items'     => __( 'Media Items', 'mcp-ai-wpoos-pro' ),
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
		if ( 'media' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'libraries':
				return $this->get_libraries_schema();
			case 'playlists':
				return $this->get_playlists_schema();
			case 'items':
				return $this->get_items_schema();
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
	 * Get libraries field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_libraries_schema() {
		return array(
			'library_name'         => array(
				'title'       => __( 'Library Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'library_type'         => array(
				'title' => __( 'Library Type', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'storage_location'     => array(
				'title' => __( 'Storage Location', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'total_items'          => array(
				'title' => __( 'Total Items', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'total_size'           => array(
				'title' => __( 'Total Size (bytes)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
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
			'description'          => array(
				'title' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'library_id'           => array(
				'title' => __( 'Library ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'items'                => array(
				'title' => __( 'Items (JSON)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'duration'             => array(
				'title' => __( 'Total Duration (seconds)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
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
	 * Get items field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_items_schema() {
		return array(
			'item_name'            => array(
				'title'       => __( 'Item Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'media_type'           => array(
				'title' => __( 'Media Type', 'mcp-ai-wpoos-pro' ),
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
			'format'               => array(
				'title' => __( 'Format', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'library_id'           => array(
				'title' => __( 'Library ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'metadata'             => array(
				'title' => __( 'Metadata (JSON)', 'mcp-ai-wpoos-pro' ),
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'libraries':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Library Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Items', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Size', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'playlists':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Playlist Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Library', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'items':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Item Name', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'libraries':
				$total_size_formatted = isset( $item['total_size'] ) ? size_format( (int) $item['total_size'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['library_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['library_type'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['total_items'] ?? '0' ); ?></td>
				<td><?php echo esc_html( $total_size_formatted ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'playlists':
				$duration_formatted = isset( $item['duration'] ) ? gmdate( 'H:i:s', (int) $item['duration'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['playlist_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['library_id'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $duration_formatted ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Active' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'items':
				$file_size_formatted = isset( $item['file_size'] ) ? size_format( (int) $item['file_size'] ) : '-';
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['item_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['media_type'] ?? '-' ); ?></td>
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
