<?php
/**
 * Food & Beverage Management Toolkit — Table Reader Contract.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a table reader adapter (Drive Sheets, CSV fixtures, CPT/CCT).
 *
 * @since 1.6.0
 */
interface WP_MCP_AI_Fnb_Table_Reader {

	/**
	 * Read a table by spec ID.
	 *
	 * @param string $table_id Spec table ID (1.0…17.0 or setup name).
	 * @return array|WP_Error array{columns: string[], rows: array<int,array>}
	 */
	public function read_table( $table_id );
}
