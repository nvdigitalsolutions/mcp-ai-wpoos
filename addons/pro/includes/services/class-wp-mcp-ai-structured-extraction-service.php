<?php
/**
 * Structured Extraction Service
 *
 * Extracts structured data (blocks, tables, form fields) from raw OCR output.
 * Consumed by the pro_unlimited_ocr tool and any future document analysis tools.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.5.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured Extraction Service class.
 *
 * Parses <|det|> markers from Unlimited-OCR output into structured blocks,
 * extracts tables from table blocks, and detects form fields using
 * bounding-box proximity heuristics.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Structured_Extraction_Service {

	/**
	 * Regex for <|det|>CATEGORY [bbox]<|/det|> markers.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const DET_REGEX = '/<\|det\|>([^<\s]+)(?:\s*\[([^\]]*)\])?\s*<\|\\/det\|>(.*)/';

	/**
	 * Proximity threshold (in pixels) for form field label-value pairing.
	 * Labels within this y-distance of a value are considered related.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const FORM_FIELD_PROXIMITY = 20;

	/**
	 * Minimum confidence that a block pair is a form field.
	 * Based on relative positioning (label left, value right).
	 *
	 * @since 1.5.0
	 * @var float
	 */
	const FORM_FIELD_CONFIDENCE = 0.6;

	/**
	 * Parse raw OCR output into structured blocks.
	 *
	 * @since 1.5.0
	 *
	 * @param string $raw_text Raw OCR output with <|det|> markers.
	 * @return array[] Array of blocks with keys: category, text, bbox.
	 */
	public function parse_blocks( $raw_text ) {
		$blocks = array();

		foreach ( explode( "\n", $raw_text ) as $line ) {
			$line = rtrim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( self::DET_REGEX, $line, $matches ) ) {
				$category = trim( $matches[1] );
				$bbox_str = isset( $matches[2] ) ? trim( $matches[2] ) : '';
				$content  = isset( $matches[3] ) ? trim( $matches[3] ) : '';

				// Skip image blocks.
				if ( 'image' === $category ) {
					continue;
				}

				$bbox = $this->parse_bbox( $bbox_str );

				$blocks[] = array(
					'category' => sanitize_key( $category ),
					'text'     => $content,
					'bbox'     => $bbox,
				);
			}
		}

		return $blocks;
	}

	/**
	 * Parse a bbox string "x1,y1,x2,y2" into an array.
	 *
	 * @since 1.5.0
	 *
	 * @param string $bbox_str Comma-separated bbox coordinates.
	 * @return int[] Array of [x1, y1, x2, y2] or empty if invalid.
	 */
	private function parse_bbox( $bbox_str ) {
		if ( '' === $bbox_str ) {
			return array();
		}

		$parts = explode( ',', $bbox_str );
		if ( 4 !== count( $parts ) ) {
			return array();
		}

		return array_map( 'intval', $parts );
	}

	/**
	 * Extract tables from structured blocks.
	 *
	 * Detects <|det|>table blocks and parses pipe-delimited rows into
	 * structured headers + data rows.
	 *
	 * @since 1.5.0
	 *
	 * @param array[] $blocks Structured blocks from parse_blocks().
	 * @return array[] Extracted tables with headers, rows, bbox keys.
	 */
	public function extract_tables( array $blocks ) {
		$tables = array();

		foreach ( $blocks as $block ) {
			if ( 'table' !== $block['category'] ) {
				continue;
			}

			$lines = explode( "\n", $block['text'] );
			if ( count( $lines ) < 2 ) {
				continue;
			}

			// First line is headers.
			$headers = array_map( 'trim', explode( '|', $lines[0] ) );

			// Remaining lines are data rows.
			$rows        = array();
			$num_headers = count( $headers );
			$num_lines   = count( $lines );
			for ( $i = 1; $i < $num_lines; $i++ ) {
				$row = array_map( 'trim', explode( '|', $lines[ $i ] ) );
				// Only include rows matching header column count.
				if ( count( $row ) === $num_headers ) {
					$rows[] = $row;
				}
			}

			if ( ! empty( $headers ) && ! empty( $rows ) ) {
				$tables[] = array(
					'headers'   => $headers,
					'rows'      => $rows,
					'row_count' => count( $rows ),
					'col_count' => $num_headers,
					'bbox'      => $block['bbox'],
				);
			}
		}

		return $tables;
	}

	/**
	 * Extract form fields from structured blocks.
	 *
	 * Uses bounding-box proximity heuristics: a text block on the left
	 * (label) paired with a text block on the right (value) within the
	 * same vertical range is classified as a form field.
	 *
	 * @since 1.5.0
	 *
	 * @param array[] $blocks Structured blocks from parse_blocks().
	 * @return array[] Detected form fields with label, value, bbox, confidence.
	 */
	public function extract_form_fields( array $blocks ) {
		// Filter to text blocks with bbox data.
		$text_blocks = array();
		foreach ( $blocks as $block ) {
			if ( 'text' === $block['category'] && ! empty( $block['bbox'] ) && count( $block['bbox'] ) >= 4 ) {
				$text_blocks[] = $block;
			}
		}

		if ( count( $text_blocks ) < 2 ) {
			return array();
		}

		$fields     = array();
		$paired_ids = array();

		// For each block, look for a right-side partner within vertical proximity.
		$num_blocks = count( $text_blocks );
		for ( $i = 0; $i < $num_blocks; $i++ ) {
			if ( isset( $paired_ids[ $i ] ) ) {
				continue;
			}

			$left = $text_blocks[ $i ];
			if ( empty( $left['text'] ) ) {
				continue;
			}

			$best_match = null;
			$best_dist  = PHP_INT_MAX;

			for ( $j = 0; $j < $num_blocks; $j++ ) {
				if ( $i === $j || isset( $paired_ids[ $j ] ) ) {
					continue;
				}

				$right = $text_blocks[ $j ];
				if ( empty( $right['text'] ) ) {
					continue;
				}

				// Check vertical overlap: y ranges must overlap within threshold.
				$y_overlap = $this->vertical_overlap( $left['bbox'], $right['bbox'], self::FORM_FIELD_PROXIMITY );
				if ( ! $y_overlap ) {
					continue;
				}

				// Check horizontal ordering: left block ends before right block starts.
				$left_x2  = $left['bbox'][2];
				$right_x1 = $right['bbox'][0];
				if ( $left_x2 >= $right_x1 ) {
					continue;
				}

				$dist = $right_x1 - $left_x2;
				if ( $dist < $best_dist ) {
					$best_dist  = $dist;
					$best_match = $j;
				}
			}

			if ( null !== $best_match ) {
				$paired_ids[ $i ]          = true;
				$paired_ids[ $best_match ] = true;

				$right_block = $text_blocks[ $best_match ];

				// Compute confidence based on proximity and text characteristics.
				$confidence = $this->compute_form_field_confidence( $left, $right_block, $best_dist );

				$fields[] = array(
					'label'      => $left['text'],
					'value'      => $right_block['text'],
					'label_bbox' => $left['bbox'],
					'value_bbox' => $right_block['bbox'],
					'confidence' => $confidence,
				);
			}
		}

		return $fields;
	}

	/**
	 * Check whether two bounding boxes overlap vertically within a threshold.
	 *
	 * @since 1.5.0
	 *
	 * @param int[] $bbox_a    First bounding box [x1, y1, x2, y2].
	 * @param int[] $bbox_b    Second bounding box [x1, y1, x2, y2].
	 * @param int   $threshold Maximum vertical gap in pixels.
	 * @return bool True if vertical ranges overlap.
	 */
	private function vertical_overlap( array $bbox_a, array $bbox_b, $threshold ) {
		if ( count( $bbox_a ) < 4 || count( $bbox_b ) < 4 ) {
			return false;
		}

		$a_y1 = $bbox_a[1];
		$a_y2 = $bbox_a[3];
		$b_y1 = $bbox_b[1];
		$b_y2 = $bbox_b[3];

		// Ranges overlap if a_end >= b_start - threshold AND b_end >= a_start - threshold.
		if ( $a_y2 < ( $b_y1 - $threshold ) ) {
			return false;
		}
		if ( $b_y2 < ( $a_y1 - $threshold ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Compute a confidence score for a detected form field pair.
	 *
	 * Higher confidence when the label is shorter (typical for form labels)
	 * and the horizontal distance is small (tightly packed form).
	 *
	 * @since 1.5.0
	 *
	 * @param array $left_block  Left (label) block.
	 * @param array $right_block Right (value) block.
	 * @param int   $distance    Horizontal distance between blocks.
	 * @return float Confidence score (0.0 to 1.0).
	 */
	private function compute_form_field_confidence( array $left_block, array $right_block, $distance ) {
		$confidence = self::FORM_FIELD_CONFIDENCE;

		// Boost confidence for short labels (typical form labels are 1-5 words).
		$label_words = str_word_count( $left_block['text'] );
		if ( $label_words <= 3 ) {
			$confidence += 0.15;
		}

		// Boost confidence for close proximity.
		$max_expected_dist = 200; // pixels.
		if ( $distance < $max_expected_dist ) {
			$confidence += 0.15 * ( 1 - ( $distance / $max_expected_dist ) );
		}

		// Boost confidence if label ends with colon (common form convention).
		if ( preg_match( '/:\s*$/', $left_block['text'] ) ) {
			$confidence += 0.1;
		}

		return min( 1.0, max( 0.0, $confidence ) );
	}

	/**
	 * Full document structure detection pipeline.
	 *
	 * Parses raw OCR output and returns a complete structured document
	 * with blocks, tables, form fields, and summary metadata.
	 *
	 * @since 1.5.0
	 *
	 * @param string $raw_text Raw OCR output with <|det|> markers.
	 * @return array Structured document object.
	 */
	public function detect_document_structure( $raw_text ) {
		$blocks = $this->parse_blocks( $raw_text );

		$tables      = $this->extract_tables( $blocks );
		$form_fields = $this->extract_form_fields( $blocks );

		// Compute block type distribution.
		$category_counts = array();
		foreach ( $blocks as $block ) {
			$cat = $block['category'];
			if ( ! isset( $category_counts[ $cat ] ) ) {
				$category_counts[ $cat ] = 0;
			}
			++$category_counts[ $cat ];
		}

		return array(
			'blocks'      => $blocks,
			'tables'      => $tables,
			'form_fields' => $form_fields,
			'summary'     => array(
				'total_blocks'     => count( $blocks ),
				'category_counts'  => $category_counts,
				'table_count'      => count( $tables ),
				'form_field_count' => count( $form_fields ),
				'has_tables'       => ! empty( $tables ),
				'has_form_fields'  => ! empty( $form_fields ),
			),
		);
	}
}
