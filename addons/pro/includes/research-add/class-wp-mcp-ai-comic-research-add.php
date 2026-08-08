<?php
/**
 * Comic Creation Toolkit Research & Add
 *
 * Research & Add implementation for Comic Creation toolkit.
 * Manages Comics, Panels, Characters, and Scripts entities.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Comic Creation Research & Add implementation.
 */
class WP_MCP_AI_Comic_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'comic_creation' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for comic creation toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'comics'     => __( 'Comics', 'mcp-ai-wpoos-pro' ),
			'panels'     => __( 'Panels', 'mcp-ai-wpoos-pro' ),
			'characters' => __( 'Characters', 'mcp-ai-wpoos-pro' ),
			'scripts'    => __( 'Scripts', 'mcp-ai-wpoos-pro' ),
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
		if ( 'comic_creation' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'comics':
				return $this->get_comics_schema();
			case 'panels':
				return $this->get_panels_schema();
			case 'characters':
				return $this->get_characters_schema();
			case 'scripts':
				return $this->get_scripts_schema();
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
	 * Get comics field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_comics_schema() {
		return array(
			'comic_title'          => array(
				'title'       => __( 'Comic Title', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'comic_style'          => array(
				'title'       => __( 'Art Style', 'mcp-ai-wpoos-pro' ),
				'type'        => 'select',
				'width'       => '50%',
				'is_required' => true,
			),
			'series_name'          => array(
				'title' => __( 'Series Name', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'issue_number'         => array(
				'title' => __( 'Issue Number', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'reading_direction'    => array(
				'title' => __( 'Reading Direction', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'page_layout'          => array(
				'title' => __( 'Page Layout', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Synopsis / Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'panels_count'         => array(
				'title' => __( 'Total Panels', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'page_count'           => array(
				'title' => __( 'Page Count', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
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
	 * Get panels field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_panels_schema() {
		return array(
			'panel_number'         => array(
				'title'       => __( 'Panel Number', 'mcp-ai-wpoos-pro' ),
				'type'        => 'number',
				'width'       => '50%',
				'is_required' => true,
			),
			'comic_id'             => array(
				'title'       => __( 'Comic ID', 'mcp-ai-wpoos-pro' ),
				'type'        => 'number',
				'width'       => '50%',
				'is_required' => true,
			),
			'panel_image_url'      => array(
				'title' => __( 'Panel Image URL', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'speech_bubbles'       => array(
				'title' => __( 'Speech Bubbles', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'panel_layout'         => array(
				'title' => __( 'Panel Layout', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'panel_style'          => array(
				'title' => __( 'Panel Style', 'mcp-ai-wpoos-pro' ),
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
	 * Get characters field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_characters_schema() {
		return array(
			'character_name'         => array(
				'title'       => __( 'Character Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'character_role'         => array(
				'title' => __( 'Role', 'mcp-ai-wpoos-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'comic_id'               => array(
				'title' => __( 'Comic ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'appearance_description' => array(
				'title' => __( 'Appearance Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'personality_traits'     => array(
				'title' => __( 'Personality Traits', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'reference_image_url'    => array(
				'title' => __( 'Reference Image URL', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'created_by_assistant'   => array(
				'title' => __( 'Created by Assistant', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
		);
	}

	/**
	 * Get scripts field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_scripts_schema() {
		return array(
			'script_title'         => array(
				'title'       => __( 'Script Title', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'comic_id'             => array(
				'title' => __( 'Comic ID', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'author'               => array(
				'title' => __( 'Author / Writer', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'script_content'       => array(
				'title'       => __( 'Full Script', 'mcp-ai-wpoos-pro' ),
				'type'        => 'textarea',
				'width'       => '100%',
				'is_required' => true,
			),
			'page_count'           => array(
				'title' => __( 'Page Count', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'panel_breakdown'      => array(
				'title' => __( 'Panel Breakdown', 'mcp-ai-wpoos-pro' ),
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'comics':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Comic Title', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Style', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Series', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'panels':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Panel #', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Comic ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Style', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Layout', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'characters':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Character Name', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Role', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Comic ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Traits', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
				<?php
				break;

			case 'scripts':
				?>
				<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Script Title', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Author', 'mcp-ai-wpoos-pro' ); ?></th>
				<th><?php esc_html_e( 'Pages', 'mcp-ai-wpoos-pro' ); ?></th>
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
			case 'comics':
				$series_info = '';
				if ( ! empty( $item['series_name'] ) ) {
					$series_info = $item['series_name'];
					if ( ! empty( $item['issue_number'] ) ) {
						$series_info .= ' #' . $item['issue_number'];
					}
				}
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['comic_title'] ?? __( '(No title)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['comic_style'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $series_info ? $series_info : '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Draft' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'panels':
				$panel_label = isset( $item['panel_number'] ) ? '# ' . $item['panel_number'] : __( '(No number)', 'mcp-ai-wpoos-pro' );
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $panel_label ); ?></td>
				<td><?php echo esc_html( $item['comic_id'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['panel_style'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['panel_layout'] ?? '-' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'characters':
				$traits         = isset( $item['personality_traits'] ) ? $item['personality_traits'] : '';
				$traits_display = mb_strlen( $traits ) > 30 ? mb_substr( $traits, 0, 30 ) . '...' : $traits;
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['character_name'] ?? __( '(No name)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['character_role'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['comic_id'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $traits_display ? $traits_display : '-' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'mcp-ai-wpoos-pro' ); ?>');"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'scripts':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['script_title'] ?? __( '(No title)', 'mcp-ai-wpoos-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['author'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['page_count'] ?? '-' ); ?></td>
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
