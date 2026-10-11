<?php
/**
 * F&B tool: search a data table.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Filter a spec table by column value and/or date range.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Search_Table extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_search_table';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Search Table', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Filter a Food & Beverage data table by a column value (e.g. item_id, ingredient_id, supplier_id) and/or a date range, returning the matching rows.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'table_id'    => array(
					'type'        => 'string',
					'description' => __( 'Spec table ID: 1.0…17.0.', 'mcp-ai-wpoos-pro' ),
				),
				'column'      => array(
					'type'        => 'string',
					'description' => __( 'Canonical column to filter on (e.g. item_id, ingredient_id, date).', 'mcp-ai-wpoos-pro' ),
				),
				'value'       => array(
					'type'        => 'string',
					'description' => __( 'Value to match (case-insensitive substring).', 'mcp-ai-wpoos-pro' ),
				),
				'date_column' => array(
					'type'        => 'string',
					'description' => __( 'Date column for range filtering (default "date").', 'mcp-ai-wpoos-pro' ),
					'default'     => 'date',
				),
				'from'        => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'to'          => array(
					'type'        => 'string',
					'description' => __( 'End date (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum rows (default 500).', 'mcp-ai-wpoos-pro' ),
					'default'     => 500,
				),
			),
			'required'   => array( 'table_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$table_id    = isset( $arguments['table_id'] ) ? sanitize_text_field( (string) $arguments['table_id'] ) : '';
		$column      = isset( $arguments['column'] ) ? sanitize_key( (string) $arguments['column'] ) : '';
		$value       = isset( $arguments['value'] ) ? sanitize_text_field( (string) $arguments['value'] ) : '';
		$date_column = isset( $arguments['date_column'] ) ? sanitize_key( (string) $arguments['date_column'] ) : 'date';
		$from        = isset( $arguments['from'] ) ? sanitize_text_field( (string) $arguments['from'] ) : null;
		$to          = isset( $arguments['to'] ) ? sanitize_text_field( (string) $arguments['to'] ) : null;
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 500;
		if ( $limit < 1 ) {
			$limit = 500;
		}

		$result = WP_MCP_AI_Fnb_Data_Source::search_table( $table_id, $column, $value, $date_column, $from, $to, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}
}
