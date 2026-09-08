<?php
/**
 * PM Research & Add Integration (ecosystem port — Wave F2, PM admin slice A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-project-management-research-add.php` (or `research-add/`) for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain\n * `nvoos-content-graph-pro`; the research-add-base require resolves from the addon's `src/admin/class-wp-mcp-ai-research-add-base.php` copy (same batch).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
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
 * Project Management Research & Add implementation.
 */
class WP_MCP_AI_Project_Management_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'project_management' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for project management toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'projects'   => __( 'Projects', 'nvoos-content-graph-pro' ),
			'tasks'      => __( 'Tasks', 'nvoos-content-graph-pro' ),
			'milestones' => __( 'Milestones', 'nvoos-content-graph-pro' ),
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
		if ( 'project_management' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'projects':
				return $this->get_projects_schema();
			case 'tasks':
				return $this->get_tasks_schema();
			case 'milestones':
				return $this->get_milestones_schema();
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
			'project_manager'      => array(
				'title' => __( 'Project Manager', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'start_date'           => array(
				'title' => __( 'Start Date', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'end_date'             => array(
				'title' => __( 'End Date', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'budget'               => array(
				'title' => __( 'Budget', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'priority'             => array(
				'title' => __( 'Priority', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'progress'             => array(
				'title' => __( 'Progress (%)', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
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
	 * Get tasks field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_tasks_schema() {
		return array(
			'task_name'            => array(
				'title'       => __( 'Task Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'project_id'           => array(
				'title' => __( 'Project ID', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'assigned_to'          => array(
				'title' => __( 'Assigned To', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'priority'             => array(
				'title' => __( 'Priority', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'due_date'             => array(
				'title' => __( 'Due Date', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'estimated_hours'      => array(
				'title' => __( 'Estimated Hours', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'actual_hours'         => array(
				'title' => __( 'Actual Hours', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'dependencies'         => array(
				'title' => __( 'Dependencies (Task IDs)', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
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
	 * Get milestones field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_milestones_schema() {
		return array(
			'milestone_name'       => array(
				'title'       => __( 'Milestone Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'description'          => array(
				'title' => __( 'Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'project_id'           => array(
				'title' => __( 'Project ID', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'due_date'             => array(
				'title' => __( 'Due Date', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'status'               => array(
				'title' => __( 'Status', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'completion_date'      => array(
				'title' => __( 'Completion Date', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'deliverables'         => array(
				'title' => __( 'Deliverables', 'nvoos-content-graph-pro' ),
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
				<th><?php esc_html_e( 'Client', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Progress', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'tasks':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Task Name', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Assigned To', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Priority', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'milestones':
				?>
				<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Milestone Name', 'nvoos-content-graph-pro' ); ?></th>
				<th><?php esc_html_e( 'Due Date', 'nvoos-content-graph-pro' ); ?></th>
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
			case 'projects':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['project_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['client_name'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Planning' ); ?></td>
				<td><?php echo esc_html( ( $item['progress'] ?? 0 ) . '%' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'tasks':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['task_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['assigned_to'] ?? 'Unassigned' ); ?></td>
				<td><?php echo esc_html( $item['priority'] ?? 'Medium' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'To Do' ); ?></td>
				<td class="item-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
					<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
				</td>
				<?php
				break;

			case 'milestones':
				?>
				<td><?php echo esc_html( $item['id'] ); ?></td>
				<td><?php echo esc_html( $item['milestone_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
				<td><?php echo esc_html( $item['due_date'] ?? '-' ); ?></td>
				<td><?php echo esc_html( $item['status'] ?? 'Pending' ); ?></td>
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
