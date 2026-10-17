<?php
/**
 * Food & Beverage Management Toolkit — Report Exporter.
 *
 * Renders any F&B report — including the operator's three client report
 * templates — as CSV (data) and PDF (presentation). Client report numbering is
 * reconciled onto the canonical R-01…R-14 registry through a small alias map.
 *
 * CSV is deterministic and spreadsheet-importable; PDF is produced by handing
 * the report's sections to the document-generation toolkit's generate_pdf /
 * pro_pdf_document engine (pdfkit). No model authors any figure here — every
 * number flows from the metric engine (G-02 / G-08).
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
 * F&B report exporter (CSV / PDF).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Report_Exporter {

	/**
	 * Client report number → canonical report ID.
	 *
	 * The operator's three templates use their own 1–2–3 numbering; this maps
	 * them onto the canonical registry without changing the report engine.
	 *
	 * @var array<string,string>
	 */
	const CLIENT_ALIASES = array(
		'client-r01' => 'R-14', // Monthly sales report.
		'client-r02' => 'R-05', // Waste and variance report.
		'client-r03' => 'R-08', // Monthly cost review.
	);

	/**
	 * Resolve a report ID (canonical R-* or client alias) to a canonical ID.
	 *
	 * @param string $report_id Canonical (R-01…R-14) or client alias (client-r01…).
	 * @return string Canonical report ID.
	 */
	public static function resolve_report_id( $report_id ) {
		$report_id = (string) $report_id;
		if ( isset( self::CLIENT_ALIASES[ $report_id ] ) ) {
			return self::CLIENT_ALIASES[ $report_id ];
		}

		return $report_id;
	}

	/**
	 * Flatten a built report's sections into CSV rows.
	 *
	 * @param array $report Report array from WP_MCP_AI_Fnb_Report_Builder::build().
	 * @return array<int,array{section:string,key:string,value:string}>
	 */
	private static function flatten( $report ) {
		$rows   = array();
		$rows[] = array( 'section', 'key', 'value' );

		foreach ( $report['sections'] as $section ) {
			$heading = isset( $section['heading'] ) ? (string) $section['heading'] : '';
			$lines   = isset( $section['lines'] ) && is_array( $section['lines'] ) ? $section['lines'] : array();
			foreach ( $lines as $line ) {
				// Attempt a "key: value" split for tidy spreadsheet columns.
				if ( is_string( $line ) && false !== strpos( $line, ':' ) ) {
					list( $key, $value ) = explode( ':', (string) $line, 2 );
					$rows[] = array( $heading, trim( $key ), trim( $value ) );
				} else {
					$rows[] = array( $heading, '', trim( (string) $line ) );
				}
			}
		}

		return $rows;
	}

	/**
	 * Export a report to CSV.
	 *
	 * @param string $report_id Canonical or client report ID.
	 * @param array  $args      Report arguments (month, date, …).
	 * @return array|WP_Error array{success,file,url,mode,report_id}
	 */
	public static function to_csv( $report_id, array $args = array() ) {
		$report_id = self::resolve_report_id( $report_id );
		$report    = WP_MCP_AI_Fnb_Report_Builder::build( $report_id, $args );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$dir = WP_MCP_AI_Fnb_Csv_Exporter::target_directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$slug = sanitize_title( $report['title'] . '-' . ( '' !== ( isset( $args['month'] ) ? $args['month'] : '' ) ? $args['month'] : ( isset( $args['date'] ) ? $args['date'] : '' ) ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $report['title'] );
		}
		$path = trailingslashit( $dir ) . $slug . '.csv';

		$handle = @fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local CSV output.
		if ( false === $handle ) {
			return new WP_Error( 'wp_mcp_ai_fnb_csv_open_failed', __( 'Could not open the CSV output file for writing.', 'mcp-ai-wpoos-pro' ) );
		}

		foreach ( self::flatten( $report ) as $row ) {
			fputcsv( $handle, $row );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'file'      => $path,
			'mode'      => 'csv',
			'url'       => str_replace( wp_normalize_path( WP_CONTENT_DIR ), content_url(), wp_normalize_path( $path ) ),
		);
	}

	/**
	 * Render a report's sections to a simple HTML document (for PDF).
	 *
	 * @param array $report Report array.
	 * @return string
	 */
	private static function to_html( $report ) {
		$title = isset( $report['title'] ) ? (string) $report['title'] : __( 'F&B Report', 'mcp-ai-wpoos-pro' );
		$html  = '<h1>' . esc_html( $title ) . '</h1>';

		foreach ( $report['sections'] as $section ) {
			$heading = isset( $section['heading'] ) ? (string) $section['heading'] : '';
			$lines   = isset( $section['lines'] ) && is_array( $section['lines'] ) ? $section['lines'] : array();
			$html   .= '<h2>' . esc_html( $heading ) . '</h2>';
			$html   .= '<table border="1" cellspacing="0" cellpadding="6" width="100%">';
			foreach ( $lines as $line ) {
				$html .= '<tr><td>' . esc_html( (string) $line ) . '</td></tr>';
			}
			$html .= '</table>';
		}

		return $html;
	}

	/**
	 * Export a report to PDF.
	 *
	 * Prefers a direct DomPDF render (no shell, no manage_options — so a
	 * read+draft assistant with edit_posts can export). Falls back to the
	 * document-generation toolkit's html_to_pdf engine (which requires shell
	 * tools + manage_options) only when DomPDF is unavailable, then returns a
	 * WP_Error so the caller can use CSV.
	 *
	 * @param string $report_id Canonical or client report ID.
	 * @param array  $args      Report arguments.
	 * @return array|WP_Error array{success,attachment_id,url,mode,report_id}
	 */
	public static function to_pdf( $report_id, array $args = array() ) {
		$report_id = self::resolve_report_id( $report_id );
		$report    = WP_MCP_AI_Fnb_Report_Builder::build( $report_id, $args );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$html = self::to_html( $report );

		// Primary: DomPDF (no shell, no manage_options — assistant-friendly).
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			$result = self::render_with_dompdf( $html, $report['title'] );
			if ( ! is_wp_error( $result ) ) {
				$result['success']   = true;
				$result['report_id'] = $report_id;
				$result['mode']      = 'pdf';

				return $result;
			}
			// Fall through to html_to_pdf on DomPDF failure.
		}

		// Fallback: html_to_pdf (gated on shell tools + manage_options).
		$html_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php';
		if ( ! class_exists( 'WP_MCP_AI_Tool_HTML_To_PDF' ) && file_exists( $html_tool_file ) ) {
			require_once $html_tool_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_HTML_To_PDF' ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_no_pdf_engine',
				__( 'The Document Generation toolkit (HTML→PDF) and DomPDF are both unavailable. Export the report as CSV instead.', 'mcp-ai-wpoos-pro' )
			);
		}

		$tool = new WP_MCP_AI_Tool_HTML_To_PDF();
		$out  = $tool->execute(
			array(
				'html'  => $html,
				'title' => $report['title'],
			),
			array()
		);

		// The engine returns a chat/document-response array when successful, or
		// an array with an 'error' key when a gate (shell tools / capability)
		// is not satisfied — that is not a WP_Error, so normalise it here.
		if ( is_array( $out ) && isset( $out['error'] ) && '' !== $out['error'] ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pdf_gate', (string) $out['error'] );
		}

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$out = is_array( $out ) ? $out : array();
		$out['success']    = true;
		$out['report_id']  = $report_id;
		$out['mode']       = 'pdf';

		return $out;
	}

	/**
	 * Render HTML to a PDF via DomPDF and upload to the media library.
	 *
	 * @param string $html  Full HTML document.
	 * @param string $title Report title (used for the filename).
	 * @return array|WP_Error array{attachment_id,url,filename,mime_type,size}
	 */
	private static function render_with_dompdf( $html, $title ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pdf_no_upload', __( 'You do not have permission to upload generated files.', 'mcp-ai-wpoos-pro' ) );
		}

		$dompdf = new \Dompdf\Dompdf();
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'a4', 'portrait' );
		$dompdf->render();

		$pdf_content = $dompdf->output();

		$filename = sanitize_file_name( $title . '.pdf' );
		$upload   = wp_upload_bits( $filename, null, $pdf_content );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pdf_write_failed', $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'filename'      => $filename,
			'mime_type'     => 'application/pdf',
			'size'          => (int) $upload['size'],
		);
	}
}
