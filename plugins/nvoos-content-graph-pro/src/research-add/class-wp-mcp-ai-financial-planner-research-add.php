<?php
/**
 * Financial admin page (ecosystem port — Wave F2, financial admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/research-add/class-wp-mcp-ai-financial-planner-research-add.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the
 * `src/` root (base-class/yfinance-service requires).
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
 * Financial Planner Research & Add implementation.
 */
class WP_MCP_AI_Financial_Planner_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'financial_planner' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for financial planner toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'budget_categories' => __( 'Budget Categories', 'nvoos-content-graph-pro' ),
			'goal_templates'    => __( 'Goal Templates', 'nvoos-content-graph-pro' ),
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
		if ( 'financial_planner' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'budget_categories':
				return $this->get_budget_categories_schema();
			case 'goal_templates':
				return $this->get_goal_templates_schema();
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
	 * Get budget categories field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_budget_categories_schema() {
		return array(
			'category_name'        => array(
				'title'       => __( 'Category Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'category_type'        => array(
				'title'       => __( 'Type', 'nvoos-content-graph-pro' ),
				'type'        => 'select',
				'width'       => '50%',
				'is_required' => true,
			),
			'amount'               => array(
				'title' => __( 'Amount', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'frequency'            => array(
				'title' => __( 'Frequency', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'description'          => array(
				'title' => __( 'Description', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'is_fixed'             => array(
				'title' => __( 'Fixed Expense', 'nvoos-content-graph-pro' ),
				'type'  => 'checkbox',
				'width' => '50%',
			),
			'notes'                => array(
				'title' => __( 'Notes', 'nvoos-content-graph-pro' ),
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
	 * Get goal templates field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_goal_templates_schema() {
		return array(
			'goal_name'            => array(
				'title'       => __( 'Goal Name', 'nvoos-content-graph-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'goal_type'            => array(
				'title' => __( 'Goal Type', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'target_amount'        => array(
				'title' => __( 'Target Amount', 'nvoos-content-graph-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'deadline'             => array(
				'title' => __( 'Deadline', 'nvoos-content-graph-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'strategy'             => array(
				'title' => __( 'Strategy', 'nvoos-content-graph-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'priority'             => array(
				'title' => __( 'Priority', 'nvoos-content-graph-pro' ),
				'type'  => 'select',
				'width' => '50%',
			),
			'notes'                => array(
				'title' => __( 'Notes', 'nvoos-content-graph-pro' ),
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
				<?php esc_html_e( 'Yes', 'nvoos-content-graph-pro' ); ?>
</label>
				<?php
				break;

			case 'select':
				$options = array();
				if ( 'category_type' === $field_name ) {
					$options = array(
						''        => __( 'Select Type', 'nvoos-content-graph-pro' ),
						'income'  => __( 'Income', 'nvoos-content-graph-pro' ),
						'expense' => __( 'Expense', 'nvoos-content-graph-pro' ),
						'savings' => __( 'Savings', 'nvoos-content-graph-pro' ),
						'debt'    => __( 'Debt', 'nvoos-content-graph-pro' ),
					);
				} elseif ( 'frequency' === $field_name ) {
					$options = array(
						''          => __( 'Select Frequency', 'nvoos-content-graph-pro' ),
						'daily'     => __( 'Daily', 'nvoos-content-graph-pro' ),
						'weekly'    => __( 'Weekly', 'nvoos-content-graph-pro' ),
						'biweekly'  => __( 'Bi-weekly', 'nvoos-content-graph-pro' ),
						'monthly'   => __( 'Monthly', 'nvoos-content-graph-pro' ),
						'quarterly' => __( 'Quarterly', 'nvoos-content-graph-pro' ),
						'yearly'    => __( 'Yearly', 'nvoos-content-graph-pro' ),
					);
				} elseif ( 'goal_type' === $field_name ) {
					$options = array(
						''           => __( 'Select Goal Type', 'nvoos-content-graph-pro' ),
						'retirement' => __( 'Retirement', 'nvoos-content-graph-pro' ),
						'emergency'  => __( 'Emergency Fund', 'nvoos-content-graph-pro' ),
						'home'       => __( 'Home Purchase', 'nvoos-content-graph-pro' ),
						'education'  => __( 'Education', 'nvoos-content-graph-pro' ),
						'debt'       => __( 'Debt Payoff', 'nvoos-content-graph-pro' ),
						'investment' => __( 'Investment', 'nvoos-content-graph-pro' ),
						'other'      => __( 'Other', 'nvoos-content-graph-pro' ),
					);
				} elseif ( 'priority' === $field_name ) {
					$options = array(
						''       => __( 'Select Priority', 'nvoos-content-graph-pro' ),
						'high'   => __( 'High', 'nvoos-content-graph-pro' ),
						'medium' => __( 'Medium', 'nvoos-content-graph-pro' ),
						'low'    => __( 'Low', 'nvoos-content-graph-pro' ),
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
			case 'budget_categories':
				?>
<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Category Name', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Type', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Amount', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Frequency', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
				<?php
				break;

			case 'goal_templates':
				?>
<th><?php esc_html_e( 'ID', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Goal Name', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Type', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Target Amount', 'nvoos-content-graph-pro' ); ?></th>
<th><?php esc_html_e( 'Priority', 'nvoos-content-graph-pro' ); ?></th>
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
			case 'budget_categories':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['category_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
<td><?php echo esc_html( ucfirst( $item['category_type'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( isset( $item['amount'] ) ? '$' . number_format( (float) $item['amount'], 2 ) : '-' ); ?></td>
<td><?php echo esc_html( ucfirst( $item['frequency'] ?? '-' ) ); ?></td>
<td class="item-actions">
<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?></a>
<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'nvoos-content-graph-pro' ); ?>');"><?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?></a>
</td>
				<?php
				break;

			case 'goal_templates':
				?>
<td><?php echo esc_html( $item['id'] ); ?></td>
<td><?php echo esc_html( $item['goal_name'] ?? __( '(No name)', 'nvoos-content-graph-pro' ) ); ?></td>
<td><?php echo esc_html( ucfirst( $item['goal_type'] ?? '-' ) ); ?></td>
<td><?php echo esc_html( isset( $item['target_amount'] ) ? '$' . number_format( (float) $item['target_amount'], 2 ) : '-' ); ?></td>
<td><?php echo esc_html( ucfirst( $item['priority'] ?? '-' ) ); ?></td>
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
