<?php
/**
 * F&B tool: read a data table.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Read a spec data table (1.0…17.0 or setup names).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Read_Table extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_read_table';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Read Table', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read a Food & Beverage data table (1.0 Menu … 17.0 Asset log, plus "assumptions") as rows with source citations. Use before any metric or report so every figure is grounded in data.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'table_id'      => array(
					'type'        => 'string',
					'description' => __( 'Spec table ID: 1.0…17.0 or "assumptions".', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites Drive connection ID override for this table. Omit to auto-route: the table\'s mapped source (F&B settings → Table Sources), then the toolkit default Drive Connection, then the only enabled Drive connection.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum rows to return (default 200).', 'mcp-ai-wpoos-pro' ),
					'default'     => 200,
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

		$table_id            = isset( $arguments['table_id'] ) ? sanitize_text_field( (string) $arguments['table_id'] ) : '';
		$connection_override = isset( $arguments['connection_id'] ) ? sanitize_key( (string) $arguments['connection_id'] ) : '';
		$limit               = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 200;
		if ( $limit < 1 ) {
			$limit = 200;
		}

		$table = WP_MCP_AI_Fnb_Data_Source::get_table( $table_id, null, $connection_override );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		$rows = array_slice( $table['rows'], 0, $limit );

		$out = array(
			'success'    => true,
			'table_id'   => $table['table_id'],
			'label'      => $table['label'],
			'columns'    => $table['columns'],
			'rows'       => $rows,
			'count'      => count( $rows ),
			'total_rows' => $table['count'],
			'truncated'  => count( $rows ) < $table['count'],
			'source'     => $table['source'],
		);

		if ( isset( $table['connection_id'] ) ) {
			$out['connection_id']   = $table['connection_id'];
			$out['connection_name'] = isset( $table['connection_name'] ) ? $table['connection_name'] : '';
		}

		return $out;
	}
}
