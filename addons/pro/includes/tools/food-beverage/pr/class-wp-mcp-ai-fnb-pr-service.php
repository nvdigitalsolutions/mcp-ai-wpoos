<?php
/**
 * Food & Beverage Management Toolkit — Purchase-Request Service.
 *
 * Phase 9b: the purchase-request approval lifecycle (Appendix A of Proposal
 * 062) layered on the QMS controlled-document engine. Purchase requests are
 * QMS doc records of kind `purchase-request` — no parallel state machine:
 * every transition goes through `WP_MCP_AI_QMS_Workflow::transition()`, and
 * the F&B-specific preconditions (§A5) attach via the
 * `wp_mcp_ai_qms_state_preconditions` filter in
 * `WP_MCP_AI_Fnb_Pr_Gates`.
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

require_once __DIR__ . '/../class-wp-mcp-ai-fnb-settings.php';
require_once __DIR__ . '/../class-wp-mcp-ai-fnb-data-source.php';

/**
 * Purchase-request engine over the QMS document record CPT.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Pr_Service {

	/**
	 * QMS document-type term slug for purchase requests.
	 *
	 * @var string
	 */
	const DOC_KIND = 'purchase-request';

	const META_SUPPLIER  = '_fnb_pr_supplier_id';
	const META_LINES     = '_fnb_pr_lines';
	const META_TOTAL     = '_fnb_pr_total';
	const META_ORDER_BY  = '_fnb_pr_order_by';
	const META_NEEDED_BY = '_fnb_pr_needed_by';
	const META_BUDGET    = '_fnb_pr_budget_line';
	const META_APPROVER  = '_fnb_pr_approver';
	const META_RELEASER  = '_fnb_pr_releaser';
	const META_PO        = '_fnb_pr_po_number';
	const META_COMMENTS  = '_fnb_pr_comments';
	const META_FINANCE   = '_fnb_pr_finance_cosign';

	/**
	 * Whether the QMS engine backing the PR lifecycle is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_QMS_Doc_Record_CPT' )
			&& class_exists( 'WP_MCP_AI_QMS_Workflow' )
			&& class_exists( 'WP_MCP_AI_QMS_Capabilities' )
			&& WP_MCP_AI_QMS_Capabilities::is_enabled();
	}

	/**
	 * Ensure the `purchase-request` doc-type term exists.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_doc_type_term() {
		if ( ! class_exists( 'WP_MCP_AI_QMS_Taxonomy' ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_no_qms', __( 'The QMS engine is not available — enable the Document Generation toolkit with QMS compliance.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! taxonomy_exists( WP_MCP_AI_QMS_Taxonomy::TAXONOMY ) ) {
			WP_MCP_AI_QMS_Taxonomy::register();
		}
		if ( ! taxonomy_exists( WP_MCP_AI_QMS_Taxonomy::TAXONOMY ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_no_taxonomy', __( 'The QMS document-type taxonomy is not registered.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! term_exists( self::DOC_KIND, WP_MCP_AI_QMS_Taxonomy::TAXONOMY ) ) {
			$result = wp_insert_term(
				__( 'Purchase Request', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_QMS_Taxonomy::TAXONOMY,
				array( 'slug' => self::DOC_KIND )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Whether a doc record is an F&B purchase request.
	 *
	 * @param int $post_id Record ID.
	 * @return bool
	 */
	public static function is_pr_record( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_QMS_Taxonomy' ) || ! taxonomy_exists( WP_MCP_AI_QMS_Taxonomy::TAXONOMY ) ) {
			return false;
		}

		return has_term( self::DOC_KIND, WP_MCP_AI_QMS_Taxonomy::TAXONOMY, $post->ID );
	}

	/**
	 * Next purchase-request document ID (PR-YYYYMM-###).
	 *
	 * @return string
	 */
	public static function next_document_id() {
		$month = gmdate( 'Ym' );
		$key   = 'wp_mcp_ai_fnb_pr_seq_' . $month;
		$seq   = (int) get_option( $key, 0 ) + 1;
		update_option( $key, $seq, false );

		return sprintf( 'PR-%s-%03d', $month, $seq );
	}

	/**
	 * Sanitise and validate purchase-request line items; recompute totals.
	 *
	 * Line totals are always recomputed (qty × unit price) — values supplied
	 * by the model are never trusted (Appendix A4).
	 *
	 * @param array|string $raw Array of line objects or a JSON string.
	 * @return array|WP_Error array{lines: array<int,array>, total: float}
	 */
	public static function sanitize_lines( $raw ) {
		$lines = $raw;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_lines', __( 'lines must be a JSON array of line objects.', 'mcp-ai-wpoos-pro' ) );
			}
			$lines = $decoded;
		}

		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_no_lines', __( 'At least one purchase-request line is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$out   = array();
		$total = 0.0;
		foreach ( $lines as $index => $line ) {
			if ( ! is_array( $line ) ) {
				/* translators: %d: line number */
				return new WP_Error( 'wp_mcp_ai_fnb_pr_bad_line', sprintf( __( 'Line %d is not an object.', 'mcp-ai-wpoos-pro' ), $index + 1 ) );
			}

			$ingredient_id = isset( $line['ingredient_id'] ) ? sanitize_text_field( (string) $line['ingredient_id'] ) : '';
			$pack          = isset( $line['pack'] ) ? sanitize_text_field( (string) $line['pack'] ) : '';
			$qty           = isset( $line['qty'] ) ? WP_MCP_AI_Fnb_Data_Source::float_cell( $line['qty'] ) : 0.0;
			$unit_price    = isset( $line['unit_price'] ) ? WP_MCP_AI_Fnb_Data_Source::float_cell( $line['unit_price'] ) : 0.0;

			if ( '' === $ingredient_id ) {
				/* translators: %d: line number */
				return new WP_Error( 'wp_mcp_ai_fnb_pr_line_missing_ingredient', sprintf( __( 'Line %d is missing ingredient_id.', 'mcp-ai-wpoos-pro' ), $index + 1 ) );
			}
			if ( '' === $pack ) {
				/* translators: %d: line number */
				return new WP_Error( 'wp_mcp_ai_fnb_pr_line_missing_pack', sprintf( __( 'Line %d is missing pack.', 'mcp-ai-wpoos-pro' ), $index + 1 ) );
			}
			if ( $qty <= 0.0 ) {
				/* translators: %d: line number */
				return new WP_Error( 'wp_mcp_ai_fnb_pr_line_invalid_qty', sprintf( __( 'Line %d has a non-positive quantity.', 'mcp-ai-wpoos-pro' ), $index + 1 ) );
			}

			$line_total = round( $qty * $unit_price, 2 );
			$total     += $line_total;

			$out[] = array(
				'ingredient_id' => $ingredient_id,
				'ingredient'    => isset( $line['ingredient'] ) ? sanitize_text_field( (string) $line['ingredient'] ) : $ingredient_id,
				'pack'          => $pack,
				'qty'           => $qty,
				'unit_price'    => $unit_price,
				'line_total'    => $line_total,
			);
		}

		return array(
			'lines' => $out,
			'total' => round( $total, 2 ),
		);
	}

	/**
	 * Render the R-04 purchase-request layout as plain-text record content.
	 *
	 * @param string $supplier_id Supplier ID.
	 * @param array  $lines       Sanitised line items.
	 * @param float  $total       Recomputed total.
	 * @param string $order_by    Order-by date (Y-m-d) or empty.
	 * @param string $needed_by   Needed-by date (Y-m-d) or empty.
	 * @param string $budget_line Budget line or empty.
	 * @param string $notes       Free-text notes.
	 * @return string
	 */
	public static function render_r04_content( $supplier_id, $lines, $total, $order_by, $needed_by, $budget_line, $notes ) {
		$out   = array();
		$out[] = 'R-04 Purchase request';
		$out[] = 'Status: Draft';
		$out[] = 'Supplier: ' . $supplier_id;
		$out[] = 'Order by: ' . ( '' !== $order_by ? $order_by : 'not specified' );
		$out[] = 'Needed by: ' . ( '' !== $needed_by ? $needed_by : 'not specified' );
		$out[] = 'Budget line: ' . ( '' !== $budget_line ? $budget_line : 'not specified' );
		$out[] = '';
		$out[] = 'Lines:';
		foreach ( $lines as $line ) {
			$out[] = sprintf(
				'- %s (%s): %s x %s @ %s LKR = %s LKR',
				$line['ingredient'],
				$line['ingredient_id'],
				$line['pack'],
				rtrim( rtrim( number_format( $line['qty'], 2, '.', '' ), '0' ), '.' ),
				number_format( $line['unit_price'], 2 ),
				number_format( $line['line_total'], 2 )
			);
		}
		$out[] = '';
		$out[] = 'Total: ' . number_format( $total, 2 ) . ' LKR';
		if ( '' !== $notes ) {
			$out[] = '';
			$out[] = 'Notes: ' . $notes;
		}

		return implode( "\n", $out );
	}

	/**
	 * Create a purchase request as a QMS draft record.
	 *
	 * @param array $args    Arguments (supplier_id, lines, order_by, needed_by, budget_line, notes).
	 * @param int   $user_id Actor user ID.
	 * @return array|WP_Error Normalised PR payload.
	 */
	public static function create_draft( array $args, $user_id ) {
		$supplier_id = isset( $args['supplier_id'] ) ? sanitize_text_field( (string) $args['supplier_id'] ) : '';
		if ( '' === $supplier_id ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_missing_supplier', __( 'supplier_id is required at draft time (Appendix A4).', 'mcp-ai-wpoos-pro' ) );
		}

		$sanitized = self::sanitize_lines( isset( $args['lines'] ) ? $args['lines'] : array() );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
		if ( $sanitized['total'] <= 0.0 ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_zero_total', __( 'The purchase-request total must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$term = self::ensure_doc_type_term();
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$order_by    = isset( $args['order_by'] ) ? sanitize_text_field( (string) $args['order_by'] ) : '';
		$needed_by   = isset( $args['needed_by'] ) ? sanitize_text_field( (string) $args['needed_by'] ) : '';
		$budget_line = isset( $args['budget_line'] ) ? sanitize_text_field( (string) $args['budget_line'] ) : '';
		$notes       = isset( $args['notes'] ) ? sanitize_textarea_field( (string) $args['notes'] ) : '';

		$document_id = self::next_document_id();
		$content     = self::render_r04_content( $supplier_id, $sanitized['lines'], $sanitized['total'], $order_by, $needed_by, $budget_line, $notes );

		$post_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sprintf( '[R-04] Purchase request %s — Draft — for %s', $document_id, $supplier_id ),
				'post_content' => $content,
				'post_author'  => (int) $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_qms_document_id', $document_id );
		update_post_meta( $post_id, '_qms_revision', '1.0' );
		update_post_meta( $post_id, '_qms_status', WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT );
		update_post_meta( $post_id, '_qms_owner_id', (int) $user_id );

		update_post_meta( $post_id, self::META_SUPPLIER, $supplier_id );
		update_post_meta( $post_id, self::META_LINES, wp_json_encode( $sanitized['lines'] ) );
		update_post_meta( $post_id, self::META_TOTAL, $sanitized['total'] );
		update_post_meta( $post_id, self::META_ORDER_BY, $order_by );
		update_post_meta( $post_id, self::META_NEEDED_BY, $needed_by );
		update_post_meta( $post_id, self::META_BUDGET, $budget_line );

		$term_object = get_term_by( 'slug', self::DOC_KIND, WP_MCP_AI_QMS_Taxonomy::TAXONOMY );
		if ( $term_object && ! is_wp_error( $term_object ) ) {
			wp_set_object_terms( $post_id, array( (int) $term_object->term_id ), WP_MCP_AI_QMS_Taxonomy::TAXONOMY, false );
		}

		WP_MCP_AI_QMS_Doc_Record_CPT::recompute_hash( $post_id );

		if ( class_exists( 'WP_MCP_AI_QMS_Audit_Log' ) ) {
			WP_MCP_AI_QMS_Audit_Log::record(
				array(
					'event'      => 'fnb_pr_created',
					'actor_id'   => (int) $user_id,
					'post_id'    => (int) $post_id,
					'doc_id'     => $document_id,
					'revision'   => '1.0',
					'to_state'   => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
					'after_hash' => (string) get_post_meta( $post_id, '_qms_content_hash', true ),
				)
			);
		}

		return self::get_pr( $post_id );
	}

	/**
	 * Normalised purchase-request payload.
	 *
	 * @param int $post_id Record ID.
	 * @return array|WP_Error
	 */
	public static function get_pr( $post_id ) {
		if ( ! self::is_pr_record( $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_record', __( 'Not an F&B purchase-request record.', 'mcp-ai-wpoos-pro' ) );
		}

		$record = WP_MCP_AI_QMS_Doc_Record_CPT::get_record( $post_id );
		if ( null === $record ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_record', __( 'Purchase-request record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$lines = json_decode( (string) get_post_meta( $post_id, self::META_LINES, true ), true );
		$lines = is_array( $lines ) ? $lines : array();

		$record['supplier_id']    = (string) get_post_meta( $post_id, self::META_SUPPLIER, true );
		$record['lines']          = $lines;
		$record['total']          = (float) get_post_meta( $post_id, self::META_TOTAL, true );
		$record['order_by']       = (string) get_post_meta( $post_id, self::META_ORDER_BY, true );
		$record['needed_by']      = (string) get_post_meta( $post_id, self::META_NEEDED_BY, true );
		$record['budget_line']    = (string) get_post_meta( $post_id, self::META_BUDGET, true );
		$record['approver']       = (int) get_post_meta( $post_id, self::META_APPROVER, true );
		$record['releaser']       = (int) get_post_meta( $post_id, self::META_RELEASER, true );
		$record['po_number']      = (string) get_post_meta( $post_id, self::META_PO, true );
		$record['comments']       = (string) get_post_meta( $post_id, self::META_COMMENTS, true );
		$record['finance_cosign'] = (int) get_post_meta( $post_id, self::META_FINANCE, true );

		return $record;
	}

	/**
	 * Supplier cut-off metadata from spec table 4.0 (graceful when absent).
	 *
	 * @param string $supplier_id Supplier ID.
	 * @return array|null array{cutoff: string, delivery_days: float, lead_time: float, required_days: float}
	 */
	public static function supplier_cutoff( $supplier_id ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '4.0' );
		if ( is_wp_error( $table ) ) {
			return null;
		}
		foreach ( $table['rows'] as $row ) {
			if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $table['header_index'], $row, 'supplier_id' ) !== $supplier_id ) {
				continue;
			}

			$delivery_days = self::parse_days( WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $table['header_index'], $row, 'delivery_days' ) );
			$lead_time     = self::parse_days( WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $table['header_index'], $row, 'lead_time' ) );

			return array(
				'cutoff'        => (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $table['header_index'], $row, 'order_cutoff' ),
				'delivery_days' => $delivery_days,
				'lead_time'     => $lead_time,
				'required_days' => $delivery_days + $lead_time,
			);
		}

		return null;
	}

	/**
	 * Parse a day-count cell ("2 days", "1 day", weekday lists, numerics).
	 *
	 * @param mixed $value Raw cell value.
	 * @return float
	 */
	private static function parse_days( $value ) {
		$days = WP_MCP_AI_Fnb_Data_Source::float_cell( $value );
		if ( $days > 0 ) {
			return $days;
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)/', (string) $value, $match ) ) {
			return (float) $match[1];
		}

		return 0.0;
	}

	/**
	 * Any open purchase request sharing this supplier and an ingredient.
	 *
	 * @param int    $exclude_id  PR to exclude (itself).
	 * @param string $supplier_id Supplier ID.
	 * @param array  $lines       Sanitised line items.
	 * @return array<int,array> Matching open PR payloads.
	 */
	public static function open_for_supplier_ingredients( $exclude_id, $supplier_id, $lines ) {
		$ingredients = array();
		foreach ( $lines as $line ) {
			$ingredients[] = $line['ingredient_id'];
		}
		$ingredients = array_values( array_unique( array_filter( $ingredients ) ) );

		$open = self::list_prs(
			array(
				'status' => '',
				'limit'  => 100,
				'open'   => true,
			)
		);

		$matches = array();
		foreach ( $open as $pr ) {
			if ( (int) $pr['id'] === (int) $exclude_id ) {
				continue;
			}
			if ( $pr['supplier_id'] !== $supplier_id ) {
				continue;
			}
			foreach ( $pr['lines'] as $line ) {
				if ( in_array( $line['ingredient_id'], $ingredients, true ) ) {
					$matches[] = $pr;
					break;
				}
			}
		}

		return $matches;
	}

	/**
	 * Submit-time preconditions (Appendix A5: draft → in_review).
	 *
	 * @param int $post_id Record ID.
	 * @return array{gate: true|WP_Error, warnings: array<int,string>}
	 */
	public static function check_submit_preconditions( $post_id ) {
		$warnings = array();

		if ( ! self::is_pr_record( $post_id ) ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_record', __( 'Not an F&B purchase-request record.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		$pr = self::get_pr( $post_id );
		if ( is_wp_error( $pr ) ) {
			return array(
				'gate'     => $pr,
				'warnings' => $warnings,
			);
		}

		$sanitized = self::sanitize_lines( $pr['lines'] );
		if ( is_wp_error( $sanitized ) ) {
			return array(
				'gate'     => $sanitized,
				'warnings' => $warnings,
			);
		}
		if ( $sanitized['total'] <= 0.0 ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_zero_total', __( 'The purchase-request total must be greater than zero.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}
		if ( '' === $pr['supplier_id'] ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_missing_supplier', __( 'supplier_id is required before submission.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		// A2-05: order_by must respect the supplier's cut-off / delivery days.
		$cutoff = self::supplier_cutoff( $pr['supplier_id'] );
		if ( null === $cutoff ) {
			$warnings[] = __( 'Supplier cut-off data unavailable — order-by timing was not verified.', 'mcp-ai-wpoos-pro' );
		} elseif ( '' !== $pr['order_by'] && '' !== $pr['needed_by'] && $cutoff['required_days'] > 0 ) {
			$span_days = ( strtotime( $pr['needed_by'] . ' 12:00:00 UTC' ) - strtotime( $pr['order_by'] . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS;
			if ( $span_days < $cutoff['required_days'] ) {
				return array(
					'gate'     => new WP_Error(
						'wp_mcp_ai_fnb_pr_cutoff_violation',
						/* translators: 1: supplier lead + delivery days, 2: span days */
						sprintf( __( 'Order-by date is too close to needed-by: the supplier needs %1$s delivery + lead days but the request allows only %2$s.', 'mcp-ai-wpoos-pro' ), (int) $cutoff['required_days'], (int) $span_days )
					),
					'warnings' => $warnings,
				);
			}
		}

		// Duplicate-open-PR scan (same supplier + ingredient, open statuses).
		$dupes = self::open_for_supplier_ingredients( $post_id, $pr['supplier_id'], $sanitized['lines'] );
		if ( ! empty( $dupes ) ) {
			return array(
				'gate'     => new WP_Error(
					'wp_mcp_ai_fnb_pr_duplicate_open',
					sprintf(
						/* translators: %s: document id */
						__( 'An open purchase request for the same supplier and ingredient already exists (%s).', 'mcp-ai-wpoos-pro' ),
						$dupes[0]['document_id']
					)
				),
				'warnings' => $warnings,
			);
		}

		// Non-blocking flags: days-of-cover vs shelf life (A2-06).
		foreach ( $sanitized['lines'] as $line ) {
			if ( class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
				$cover = WP_MCP_AI_Fnb_Metrics::m14_days_of_cover( $line['ingredient_id'] );
				if ( is_array( $cover ) ) {
					$warnings[] = sprintf(
						/* translators: 1: ingredient, 2: days of cover */
						__( '%1$s: days of cover %2$s — check shelf life before ordering more.', 'mcp-ai-wpoos-pro' ),
						$line['ingredient_id'],
						number_format( (float) $cover['value'], 1 )
					);
				}
			}
		}

		return array(
			'gate'     => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Approve-time preconditions (Appendix A5: in_review → approved).
	 *
	 * @param int $post_id Record ID.
	 * @param int $actor_id Actor user ID.
	 * @return array{gate: true|WP_Error, warnings: array<int,string>}
	 */
	public static function check_approve_preconditions( $post_id, $actor_id ) {
		$warnings = array();

		if ( ! self::is_pr_record( $post_id ) ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_record', __( 'Not an F&B purchase-request record.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		$owner_id = (int) get_post_meta( $post_id, '_qms_owner_id', true );
		if ( $actor_id > 0 && $owner_id === $actor_id ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_self_approval', __( 'The creator cannot approve their own purchase request (segregation of duties).', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		$budget_line = (string) get_post_meta( $post_id, self::META_BUDGET, true );
		if ( '' === $budget_line ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_missing_budget_line', __( 'The purchase request has no budget line — return it to draft with a budget_line before approving.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		// Non-blocking: M-20 budget variance against the PR total.
		if ( class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
			$variance = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( gmdate( 'Y-m' ) );
			if ( is_array( $variance ) && ! empty( $variance['rows'] ) ) {
				foreach ( $variance['rows'] as $row ) {
					if ( isset( $row['line'] ) && $row['line'] === $budget_line && isset( $row['variance'] ) ) {
						$total  = (float) get_post_meta( $post_id, self::META_TOTAL, true );
						$impact = (float) $row['variance'] + $total;
						if ( $impact > 0 ) {
							$warnings[] = sprintf(
								/* translators: 1: budget line, 2: projected overage */
								__( '%1$s: this request pushes the budget line over by %2$s LKR.', 'mcp-ai-wpoos-pro' ),
								$budget_line,
								number_format( round( $impact ), 0 )
							);
						}
						break;
					}
				}
			}
		}

		return array(
			'gate'     => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Release-time preconditions (Appendix A5: approved → released).
	 *
	 * @param int $post_id Record ID.
	 * @param int $actor_id Actor user ID.
	 * @return array{gate: true|WP_Error, warnings: array<int,string>}
	 */
	public static function check_release_preconditions( $post_id, $actor_id ) {
		unset( $actor_id );
		$warnings = array();

		if ( ! self::is_pr_record( $post_id ) ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_invalid_record', __( 'Not an F&B purchase-request record.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		// Freeze semantics: content must be unchanged since approval.
		$stored = (string) get_post_meta( $post_id, '_qms_content_hash', true );
		$fresh  = WP_MCP_AI_QMS_Doc_Record_CPT::compute_hash( $post_id );
		if ( '' !== $stored && '' !== $fresh && ! hash_equals( $stored, $fresh ) ) {
			return array(
				'gate'     => new WP_Error( 'wp_mcp_ai_fnb_pr_hash_mismatch', __( 'The purchase request was edited after approval — return it to draft, amend, and re-approve before release.', 'mcp-ai-wpoos-pro' ) ),
				'warnings' => $warnings,
			);
		}

		// Auto-approval threshold: above it, a finance co-sign is required.
		$threshold = (float) WP_MCP_AI_Fnb_Settings::get( 'pr_auto_approval_threshold', 0 );
		$total     = (float) get_post_meta( $post_id, self::META_TOTAL, true );
		$co_sign   = (int) get_post_meta( $post_id, self::META_FINANCE, true );
		if ( $threshold > 0 && $total > $threshold && 0 === $co_sign ) {
			return array(
				'gate'     => new WP_Error(
					'wp_mcp_ai_fnb_pr_needs_cosign',
					/* translators: 1: total, 2: threshold */
					sprintf( __( 'Total %1$s LKR exceeds the auto-approval threshold (%2$s LKR) — a finance co-sign is required before release.', 'mcp-ai-wpoos-pro' ), number_format( $total, 2 ), number_format( $threshold, 2 ) )
				),
				'warnings' => $warnings,
			);
		}

		return array(
			'gate'     => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * List purchase requests (optionally open-only / by status).
	 *
	 * @param array $args Query args: status, open, limit.
	 * @return array<int,array>
	 */
	public static function list_prs( array $args = array() ) {
		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$open   = ! empty( $args['open'] );
		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
		$limit  = max( 1, min( 100, $limit ) );

		if ( ! self::is_available() || ! post_type_exists( WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE ) ) {
			return array();
		}

		$query_args = array(
			'post_type'        => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => WP_MCP_AI_QMS_Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => self::DOC_KIND,
				),
			),
		);

		if ( '' !== $status ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_qms_status',
					'value' => $status,
				),
			);
		} elseif ( $open ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_qms_status',
					'value'   => array(
						WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
						WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
						WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
					),
					'compare' => 'IN',
				),
			);
		}

		$posts = get_posts( $query_args );

		$out = array();
		foreach ( $posts as $post_id ) {
			$pr = self::get_pr( $post_id );
			if ( ! is_wp_error( $pr ) ) {
				$out[] = $pr;
			}
		}

		return $out;
	}

	/**
	 * Read the audit trail for a purchase request (or all PRs).
	 *
	 * @param int $post_id Record ID or 0 for all PR audit rows.
	 * @param int $limit   Row limit.
	 * @return array<int,array>
	 */
	public static function read_audit( $post_id = 0, $limit = 50 ) {
		if ( ! class_exists( 'WP_MCP_AI_QMS_Audit_Log' ) ) {
			return array();
		}

		$limit = max( 1, min( 200, absint( $limit ) ) );

		if ( $post_id > 0 ) {
			return WP_MCP_AI_QMS_Audit_Log::query(
				array(
					'post_id' => $post_id,
					'limit'   => $limit,
				)
			);
		}

		$rows = WP_MCP_AI_QMS_Audit_Log::query( array( 'limit' => 200 ) );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['doc_id'] ) && 0 === strpos( (string) $row['doc_id'], 'PR-' ) ) {
				$out[] = $row;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Bump a semantic revision by one minor step (1.0 → 1.1).
	 *
	 * @param string $revision Current revision.
	 * @return string
	 */
	public static function bump_revision( $revision ) {
		$value = (float) $revision;

		return number_format( $value + 0.1, 1 );
	}
}
