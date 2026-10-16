<?php
/**
 * Food & Beverage Management Toolkit — Multi-format Loader.
 *
 * Turns heterogeneous owner input (CSV with any common delimiter, TSV, JSON
 * array-of-objects, or JSONL/NDJSON) into the shared `{columns, rows}` matrix
 * that every reader and the structure matcher already consume. Delimiter,
 * encoding, BOM and header-row detection are all conservative: when a format
 * cannot be resolved confidently the loader returns the unparsed input plus a
 * "could not detect" error rather than guessing a schema (mirrors the
 * toolkit's "never invent figures/records" rule).
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
 * Multi-format F&B ingestion loader.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Loader {

	/**
	 * Supported explicit formats.
	 */
	const FORMAT_CSV   = 'csv';
	const FORMAT_TSV   = 'tsv';
	const FORMAT_JSON  = 'json';
	const FORMAT_JSONL = 'jsonl';
	const FORMAT_PDF   = 'pdf';
	const FORMAT_PHOTO = 'photo';
	const FORMAT_XLSX  = 'xlsx';

	/**
	 * Split a string into lines, normalising CRLF/CR → LF and trimming a trailing
	 * blank final line.
	 *
	 * @param string $text Raw text.
	 * @return array<int,string>
	 */
	private static function lines( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = trim( $text, "\n" );

		return '' === $text ? array() : explode( "\n", $text );
	}

	/**
	 * Strip a UTF-8/UTF-16 BOM and convert to UTF-8 when the input is UTF-16.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function to_utf8( $text ) {
		$text = (string) $text;

		if ( "\xEF\xBB\xBF" === substr( $text, 0, 3 ) ) {
			$text = substr( $text, 3 ); // UTF-8 BOM.
		} elseif ( "\xFF\xFE" === substr( $text, 0, 2 ) ) {
			$text = mb_convert_encoding( substr( $text, 2 ), 'UTF-8', 'UTF-16LE' ); // UTF-16LE BOM.
		} elseif ( "\xFE\xFF" === substr( $text, 0, 2 ) ) {
			$text = mb_convert_encoding( substr( $text, 2 ), 'UTF-8', 'UTF-16BE' ); // UTF-16BE BOM.
		}

		return $text;
	}

	/**
	 * Detect the most likely delimiter from a header-looking line by counting
	 * unquoted occurrences of each candidate.
	 *
	 * @param string $line First non-empty line.
	 * @return string Detected delimiter (default comma).
	 */
	public static function detect_delimiter( $line ) {
		$candidates = array(
			','  => 0,
			"\t" => 0,
			';'  => 0,
			'|'  => 0,
		);
		$in_quotes  = false;

		$prev = '';
		$len  = strlen( $line );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $line[ $i ];
			if ( '"' === $ch && '\\' !== $prev ) {
				$in_quotes = ! $in_quotes;
				continue;
			}
			if ( ! $in_quotes && isset( $candidates[ $ch ] ) ) {
				++$candidates[ $ch ];
			}
			$prev = $ch;
		}

		$best = ',';
		$max  = 0;
		foreach ( $candidates as $delim => $count ) {
			if ( $count > $max ) {
				$max  = $count;
				$best = $delim;
			}
		}

		return $best;
	}

	/**
	 * Parse a delimited text (CSV/TSV) into a matrix, skipping fully-empty rows.
	 *
	 * @param string $text      Raw text.
	 * @param string $delimiter Delimiter (single char).
	 * @return array{columns:array<int,string>,rows:array<int,array<int,string>>}
	 */
	public static function parse_delimited( $text, $delimiter = ',' ) {
		$text    = self::to_utf8( $text );
		$lines   = self::lines( $text );
		$columns = array();
		$rows    = array();
		$first   = true;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed && $first ) {
				continue; // Skip leading blank lines before the header.
			}
			$data = str_getcsv( $line, $delimiter );
			$data = array_map( 'trim', $data );

			$has_value = false;
			foreach ( $data as $cell ) {
				if ( '' !== (string) $cell ) {
					$has_value = true;
					break;
				}
			}
			if ( ! $has_value ) {
				continue;
			}

			if ( $first ) {
				$columns = $data;
				$first   = false;
				continue;
			}

			$rows[] = $data;
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * Normalise an array-of-objects (or array-of-arrays) into a matrix.
	 *
	 * Headers are the union of keys in first-seen order; missing keys become ''.
	 *
	 * @param array<int,array> $objects Records.
	 * @return array{columns:array<int,string>,rows:array<int,array<int,string>>}
	 */
	public static function records_to_matrix( array $objects ) {
		$columns = array();
		foreach ( $objects as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			foreach ( array_keys( $record ) as $key ) {
				$key = (string) $key;
				if ( ! in_array( $key, $columns, true ) ) {
					$columns[] = $key;
				}
			}
		}

		$rows = array();
		foreach ( $objects as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$row = array();
			foreach ( $columns as $col ) {
				$value = array_key_exists( $col, $record ) ? $record[ $col ] : '';
				$row[] = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
			}
			$rows[] = $row;
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * Load a raw string into a matrix, auto-detecting the format.
	 *
	 * @param string $raw    Raw input.
	 * @param string $format Optional explicit format (csv|tsv|json|jsonl).
	 * @return array{columns:array<int,string>,rows:array<int,array<int,string>>,format:string}
	 */
	public static function load( $raw, $format = '' ) {
		$raw = (string) $raw;

		if ( in_array( $format, array( self::FORMAT_JSON, self::FORMAT_JSONL ), true ) ) {
			$objects = self::decode_json( $raw, self::FORMAT_JSONL === $format );
			if ( is_wp_error( $objects ) ) {
				return $objects;
			}
			$matrix           = self::records_to_matrix( $objects );
			$matrix['format'] = $format;

			return $matrix;
		}

		// Delimited: CSV/TSV (or auto). Determine delimiter, then parse.
		$delimiter = self::FORMAT_TSV === $format ? "\t" : null;
		if ( null === $delimiter ) {
				$first_line = '';
			foreach ( self::lines( self::to_utf8( $raw ) ) as $line ) {
				if ( '' !== trim( $line ) ) {
					$first_line = $line;
					break;
				}
			}
			$delimiter = self::detect_delimiter( $first_line );
		}

		$matrix           = self::parse_delimited( $raw, $delimiter );
		$matrix['format'] = "\t" === $delimiter ? self::FORMAT_TSV : self::FORMAT_CSV;

		return $matrix;
	}

	/**
	 * Decode JSON (array-of-objects or a single object) or JSONL into records.
	 *
	 * @param string $raw    Raw JSON/JSONL.
	 * @param bool   $ndjson When true, parse newline-delimited JSON line-by-line.
	 * @return array<int,array>|WP_Error
	 */
	private static function decode_json( $raw, $ndjson = true ) {
		// JSONL: parse one object per line (fall back to single-doc on one line).
		if ( $ndjson ) {
			$records = array();
			foreach ( self::lines( $raw ) as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					$records[] = $row;
				}
			}

			if ( empty( $records ) ) {
				// Single JSON document on one line.
				$single = json_decode( trim( $raw ), true );
				if ( is_array( $single ) ) {
					$records = array_values( $single ) === $single ? array_filter( $single, 'is_array' ) : array( $single );
				}
			}

			if ( empty( $records ) ) {
				return new WP_Error( 'wp_mcp_ai_fnb_invalid_json', __( 'Could not parse JSON or JSONL input.', 'mcp-ai-wpoos-pro' ) );
			}

			return $records;
		}

		// Single JSON document.
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_invalid_json', __( 'JSON input must be an object or an array of objects.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( array_values( $decoded ) === $decoded ) {
			return array_filter( $decoded, 'is_array' );
		}

		return array( $decoded );
	}

	/**
	 * Extract text from a text-layer PDF and parse it into a matrix.
	 *
	 * Uses the pure-PHP `smalot/pdfparser` when available (the same dependency
	 * the `extract_pdf_text` tool falls back to). Scanned/image-only PDFs have
	 * no text layer, so this returns a clear, actionable error pointing at the
	 * OCR tools (`extract_pdf_text` with enable_ocr, or `ocr_pdf_text`) rather
	 * than failing silently.
	 *
	 * @param string $path Absolute PDF path.
	 * @return array|WP_Error
	 */
	public static function extract_pdf_text( $path ) {
		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_pdf_parser_missing',
				__( 'PDF text extraction is unavailable — the smalot/pdfparser library is not installed in the Pro addon. Extract the text with the extract_pdf_text tool first, then import the result.', 'mcp-ai-wpoos-pro' )
			);
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf    = $parser->parseFile( $path );
			$text   = $pdf->getText();
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_pdf_extract_failed',
				/* translators: %s: error message */
				sprintf( __( 'PDF text extraction failed: %s', 'mcp-ai-wpoos-pro' ), $e->getMessage() )
			);
		}

		$text = trim( (string) $text );
		if ( '' === $text ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_pdf_scanned',
				__( 'The PDF appears to be scanned (no extractable text layer). Use the extract_pdf_text tool with enable_ocr=true, or ocr_pdf_text, then import the resulting text.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse the extracted text through the delimited path so a line-item
		// table (comma/whitespace separated) becomes a normal matrix.
		$matrix           = self::parse_delimited( $text, self::detect_delimiter( $text ) );
		$matrix['format'] = self::FORMAT_PDF;

		return $matrix;
	}

	/**
	 * Load a spreadsheet (XLSX/XLS/ODS) into a matrix via PhpSpreadsheet.
	 *
	 * Uses the first worksheet by default (the `sheet` table_map hint is applied
	 * by the caller when present). PhpSpreadsheet is a Pro-addon dependency
	 * (autoloaded on PHP 8.1+), so this degrades to an actionable error when the
	 * class is unavailable rather than fatalling.
	 *
	 * @param string $path  Absolute spreadsheet path.
	 * @param string $sheet Optional worksheet name/index (default first sheet).
	 * @return array|WP_Error
	 */
	public static function load_spreadsheet( $path, $sheet = '' ) {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_phpspreadsheet_missing',
				__( 'XLSX/ODS parsing is unavailable — PhpSpreadsheet is not loaded (Pro addon requires PHP 8.1+). Convert the sheet to CSV with the excel_data_export tool, then import the CSV.', 'mcp-ai-wpoos-pro' )
			);
		}

		try {
			$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $path );
			$reader->setReadDataOnly( true );

			if ( '' !== $sheet && method_exists( $reader, 'setLoadSheetsOnly' ) ) {
				$reader->setLoadSheetsOnly( $sheet );
			}

			$spreadsheet = $reader->load( $path );
			$worksheet   = $spreadsheet->getActiveSheet();
			$data        = $worksheet->toArray( null, true, true, true );
			$spreadsheet->disconnectWorksheets();
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_xlsx_extract_failed',
				/* translators: %s: error message */
				sprintf( __( 'Spreadsheet parsing failed: %s', 'mcp-ai-wpoos-pro' ), $e->getMessage() )
			);
		}

		$matrix           = self::rows_to_matrix( $data );
		$matrix['format'] = self::FORMAT_XLSX;

		return $matrix;
	}

	/**
	 * Convert an indexed row grid (from PhpSpreadsheet's toArray) into a matrix,
	 * treating the first non-empty row as the header.
	 *
	 * @param array<int,array> $grid Indexed rows (may contain empty rows/columns).
	 * @return array{columns:array<int,string>,rows:array<int,array<int,string>>}
	 */
	private static function rows_to_matrix( array $grid ) {
		$columns = array();
		$rows    = array();
		$first   = true;

		foreach ( $grid as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// Drop trailing null/empty cells.
			$row = array_values( $row );
			while ( ! empty( $row ) && ( null === end( $row ) || '' === (string) end( $row ) ) ) {
				array_pop( $row );
			}
			$has_value = false;
			foreach ( $row as $cell ) {
				if ( null !== $cell && '' !== (string) $cell ) {
					$has_value = true;
					break;
				}
			}
			if ( ! $has_value ) {
				continue;
			}

			$row = array_map(
				static function ( $cell ) {
					return null === $cell ? '' : (string) $cell;
				},
				$row
			);

			if ( $first ) {
				$columns = $row;
				$first   = false;
				continue;
			}

			$rows[] = $row;
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * Load a file's raw contents into a matrix, auto-detecting format by content
	 * (and extension as a hint).
	 *
	 * @param string $path Absolute file path.
	 * @return array|WP_Error
	 */
	public static function load_file( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_file_unreadable', __( 'File could not be read.', 'mcp-ai-wpoos-pro' ) );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// PDF: extract text layer (scanned PDFs get a clear OCR pointer).
		if ( 'pdf' === $ext ) {
			return self::extract_pdf_text( $path );
		}

		// Spreadsheet: XLSX/XLS/ODS via PhpSpreadsheet.
		if ( in_array( $ext, array( 'xlsx', 'xls', 'ods' ), true ) ) {
			return self::load_spreadsheet( $path );
		}

		// Photo/image invoice: deferred to the vision OCR tools.
		if ( in_array( $ext, array( 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tiff' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_photo_needs_ocr',
				__( 'Image files require vision OCR. Use analyze_image or extract_image_text to produce the text, then import the result as CSV/JSON.', 'mcp-ai-wpoos-pro' )
			);
		}

		$raw    = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$format = '';
		if ( in_array( $ext, array( 'json', 'jsonl', 'ndjson' ), true ) ) {
			$format = 'jsonl' === $ext || 'ndjson' === $ext ? self::FORMAT_JSONL : self::FORMAT_JSON;
		} elseif ( 'tsv' === $ext ) {
			$format = self::FORMAT_TSV;
		}

		return self::load( $raw, $format );
	}
}
