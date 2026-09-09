<?php
/**
 * WP_MCP_AI_Comic_Research_Add (ecosystem port - Wave F2, comic-creation final slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swap with the `src/` root for the research-add-base require.
 *
 * Research & Add implementation for the Comic Creation toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Comic_Creation_Toolkit
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
			'comics'     => __( 'Comics', 'nvoos-content-graph-pro' ),
			'panels'     => __( 'Panels', 'nvoos-content-graph-pro' ),
			'characters' => __( 'Characters', 'nvoos-content-graph-pro' ),
			'scripts'    => __( 'Scripts', 'nvoos-content-graph-pro' ),
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
				'title'       => __( 'Comic Title', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'comic_style'          => array(
				'title'       => __( 'Art Style', 'nvoos-content-graph-pro' ),
				'type'        => 'select',
				'width'       => '50%',
				'is_required' => true,
			),
			'series_name'          => array(
				'title' => __( 'Series Name', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'issue_number'         => array(
				'title' => __( 'Issue Number', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'reading_direction'    => array(
				'title' => __( 'Reading Direction', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'page_layout'          => array(
				'title' => __( 'Page Layout', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Synopsis / Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'panels_count'         => array(
				'title' => __( 'Total Panels', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'page_count'           => array(
				'title' => __( 'Page Count', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
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
	 * Get panels field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_panels_schema() {
		return array(
			'panel_number'         => array(
				'title'       => __( 'Panel Number', 'nvoos-content-graph-pro' ),
				'type'        => 'number',
				'width'       => '50%',
				'is_required' => true,
			),
			'comic_id'             => array(
				'title'       => __( 'Comic ID', 'nvoos-content-graph-pro' ),
				'type'        => 'number',
				'width'       => '50%',
				'is_required' => true,
			),
			'panel_image_url'      => array(
				'title' => __( 'Panel Image URL', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'speech_bubbles'       => array(
				'title' => __( 'Speech Bubbles', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'panel_layout'         => array(
				'title' => __( 'Panel Layout', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'panel_style'          => array(
				'title' => __( 'Panel Style', 'nvoos-content-graph-pro' ),
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
	 * Get characters field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_characters_schema() {
		return array(
			'character_name'         => array(
				'title'       => __( 'Character Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'character_role'         => array(
				'title' => __( 'Role', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'comic_id'               => array(
				'title' => __( 'Comic ID', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'appearance_description' => array(
				'title' => __( 'Appearance Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'personality_traits'     => array(
				'title' => __( 'Personality Traits', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'reference_image_url'    => array(
				'title' => __( 'Reference Image URL', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'created_by_assistant'   => array(
				'title' => __( 'Created by Assistant', 'nvoos-content-graph-pro' ),
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
				'title'       => __( 'Script Title', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'comic_id'             => array(
				'title' => __( 'Comic ID', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'author'               => array(
				'title' => __( 'Author / Writer', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'script_content'       => array(
				'title'       => __( 'Full Script', 'nvoos-content-graph-pro' ),
				'type'        => 'textarea',
				'width'       => '100%',
				'is_required' => true,
			),
			'page_count'           => array(
				'title' => __( 'Page Count', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'panel_breakdown'      => array(
				'title' => __( 'Panel Breakdown', 'nvoos-content-graph-pro' ),
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
	 * Render table headers for current entity.
	 */
	protected function render_table_headers() {
		switch ( $this->current_entity ) {
			case 'comics':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Comic Title', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Style', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Series', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'panels':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Panel #', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Comic ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Style', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Layout', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'characters':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Character Name', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Role', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Comic ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Traits', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'scripts':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Script Title', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Author', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Pages', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nvoos-content-graph-pro' ); ?></th>
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
				<td><?php echo esc_html( $item['comic_title'] ?? __( '(No title)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['comic_style'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $series_info ? $series_info : '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Draft' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'panels':
				$panel_label = isset( $item['panel_number'] ) ? '# ' . $item['panel_number'] : __( '(No number)', 'nvoos-content-graph-pro' );
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $panel_label ); ?></td>
				<td><?php echo esc_html( $item['comic_id'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['panel_style'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['panel_layout'] ?? '-' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'characters':
				$traits         = isset( $item['personality_traits'] ) ? $item['personality_traits'] : '';
				$traits_display = mb_strlen( $traits ) > 30 ? mb_substr( $traits, 0, 30 ) . '...' : $traits;
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['character_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['character_role'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['comic_id'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $traits_display ? $traits_display : '-' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'scripts':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['script_title'] ?? __( '(No title)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['author'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['page_count'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Draft' ); ?></td>
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
