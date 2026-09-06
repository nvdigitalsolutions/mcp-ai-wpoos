<?php
/**
 * Vision Analysis — Count Normalizer
 *
 * Pure, stateless helpers that convert raw detection/VLM outputs into the
 * canonical per-category count breakdown used by the Vision Analysis toolkit:
 *
 *   array( 'label' => string, 'count' => int, 'avg_confidence' => float, 'boxes' => array[] )
 *
 * Keeping this math in one place means the tool, the HF vision service, and
 * the test suite can never drift apart on grouping semantics.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes detector and VLM outputs into a count breakdown.
 *
 * @since 1.1.68
 */
class WP_MCP_AI_Vision_Count_Normalizer {

	/**
	 * Maximum number of boxes retained per breakdown entry.
	 *
	 * Full box lists bloat the LLM context; the cap keeps responses bounded
	 * while still supporting annotation and auditing.
	 *
	 * @var int
	 */
	const MAX_BOXES_PER_LABEL = 50;

	/**
	 * Group raw detections into per-label breakdown entries.
	 *
	 * Accepts the canonical detector shapes produced by
	 * WP_MCP_AI_HF_Vision_Inference_Service (HF: `label`, `confidence`, `box`;
	 * Ollama: `label`, `confidence`, `count`, `bounding_box`) and returns
	 * entries sorted by count descending.
	 *
	 * @param array $detections    Raw detections list.
	 * @param bool  $include_boxes Whether to retain bounding boxes per entry.
	 * @return array<int, array{label: string, count: int, avg_confidence: float, boxes: array<int, array{x: float, y: float, width: float, height: float}>}>
	 */
	public static function group_detections( array $detections, $include_boxes = true ) {
		$groups = array();

		foreach ( $detections as $det ) {
			if ( ! is_array( $det ) ) {
				continue;
			}

			$label = isset( $det['label'] ) ? sanitize_text_field( (string) $det['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}

			$confidence = isset( $det['confidence'] ) ? (float) $det['confidence'] : 0.0;
			$per_row    = isset( $det['count'] ) && absint( $det['count'] ) > 0 ? absint( $det['count'] ) : 1;

			$box = null;
			if ( $include_boxes ) {
				if ( isset( $det['box'] ) && is_array( $det['box'] ) ) {
					$box = self::normalize_box( $det['box'] );
				} elseif ( isset( $det['bounding_box'] ) && is_array( $det['bounding_box'] ) ) {
					$box = self::normalize_box( $det['bounding_box'] );
				}
			}

			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = array(
					'label'          => $label,
					'count'          => 0,
					'confidence_sum' => 0.0,
					'boxes'          => array(),
				);
			}

			$groups[ $label ]['count']          += $per_row;
			$groups[ $label ]['confidence_sum'] += $confidence * $per_row;

			if ( $include_boxes && null !== $box ) {
				$groups[ $label ]['boxes'][] = $box;
			}
		}

		$breakdown = array();
		foreach ( $groups as $group ) {
			$entry = array(
				'label'          => $group['label'],
				'count'          => (int) $group['count'],
				'avg_confidence' => $group['count'] > 0 ? round( $group['confidence_sum'] / $group['count'], 4 ) : 0.0,
			);

			if ( $include_boxes ) {
				$entry['boxes'] = array_slice( $group['boxes'], 0, self::MAX_BOXES_PER_LABEL );
			}

			$breakdown[] = $entry;
		}

		return self::sort_breakdown( $breakdown );
	}

	/**
	 * Normalize a VLM counting response into the canonical breakdown.
	 *
	 * Accepts `counts` / `items` / `detections` lists of
	 * `{label, count, confidence}` and coerces every field defensively.
	 *
	 * @param array $parsed Decoded JSON from a VLM response.
	 * @return array<int, array{label: string, count: int, avg_confidence: float, boxes: array}>
	 */
	public static function normalize_vlm_counts( array $parsed ) {
		$raw = array();

		foreach ( array( 'counts', 'items', 'detections' ) as $key ) {
			if ( isset( $parsed[ $key ] ) && is_array( $parsed[ $key ] ) ) {
				$raw = $parsed[ $key ];
				break;
			}
		}

		$breakdown = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] )
				: ( isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '' );
			if ( '' === $label ) {
				continue;
			}

			$count      = isset( $item['count'] ) ? absint( $item['count'] ) : 0;
			$confidence = isset( $item['confidence'] ) ? (float) $item['confidence']
				: ( isset( $item['score'] ) ? (float) $item['score'] : 0.0 );

			// A VLM sometimes returns one entry per instance instead of
			// aggregated counts; treat a missing count as a single instance.
			if ( $count < 1 ) {
				$count = 1;
			}

			$breakdown[] = array(
				'label'          => $label,
				'count'          => $count,
				'avg_confidence' => min( 1.0, max( 0.0, $confidence ) ),
				'boxes'          => array(),
			);
		}

		return self::sort_breakdown( $breakdown );
	}

	/**
	 * Merge breakdown entries using a label → canonical-label alias map.
	 *
	 * Used by hybrid mode: the detector owns the counts and boxes, the VLM
	 * only renames mislabeled categories. Entries whose alias is empty or
	 * equal to their own label are kept as-is.
	 *
	 * @param array<int, array>     $breakdown Canonical breakdown.
	 * @param array<string, string> $aliases Original label → canonical label.
	 * @return array<int, array{label: string, count: int, avg_confidence: float, boxes: array}>
	 */
	public static function merge_label_aliases( array $breakdown, array $aliases ) {
		$merged = array();

		foreach ( $breakdown as $entry ) {
			$label = isset( $entry['label'] ) ? $entry['label'] : '';
			$alias = isset( $aliases[ $label ] ) ? sanitize_text_field( (string) $aliases[ $label ] ) : '';

			$canonical = ( '' !== $alias && strtolower( $alias ) !== strtolower( $label ) ) ? $alias : $label;

			if ( ! isset( $merged[ $canonical ] ) ) {
				$merged[ $canonical ] = array(
					'label'          => $canonical,
					'count'          => 0,
					'confidence_sum' => 0.0,
					'boxes'          => array(),
				);
			}

			$count                                   = isset( $entry['count'] ) ? absint( $entry['count'] ) : 0;
			$confidence                              = isset( $entry['avg_confidence'] ) ? (float) $entry['avg_confidence'] : 0.0;
			$merged[ $canonical ]['count']          += $count;
			$merged[ $canonical ]['confidence_sum'] += $confidence * $count;

			if ( ! empty( $entry['boxes'] ) && is_array( $entry['boxes'] ) ) {
				$merged[ $canonical ]['boxes'] = array_merge(
					$merged[ $canonical ]['boxes'],
					array_slice( $entry['boxes'], 0, self::MAX_BOXES_PER_LABEL )
				);
			}
		}

		$breakdown = array();
		foreach ( $merged as $group ) {
			$entry = array(
				'label'          => $group['label'],
				'count'          => (int) $group['count'],
				'avg_confidence' => $group['count'] > 0 ? round( $group['confidence_sum'] / $group['count'], 4 ) : 0.0,
			);

			$entry['boxes'] = array_slice( $group['boxes'], 0, self::MAX_BOXES_PER_LABEL );

			$breakdown[] = $entry;
		}

		return self::sort_breakdown( $breakdown );
	}

	/**
	 * Sort a breakdown by count descending (label ascending as tiebreak).
	 *
	 * @param array<int, array> $breakdown Canonical breakdown.
	 * @return array<int, array>
	 */
	public static function sort_breakdown( array $breakdown ) {
		usort(
			$breakdown,
			function ( $a, $b ) {
				$count_diff = ( isset( $b['count'] ) ? absint( $b['count'] ) : 0 ) - ( isset( $a['count'] ) ? absint( $a['count'] ) : 0 );
				if ( 0 !== $count_diff ) {
					return $count_diff <=> 0;
				}
				return strcasecmp( isset( $a['label'] ) ? $a['label'] : '', isset( $b['label'] ) ? $b['label'] : '' );
			}
		);

		return $breakdown;
	}

	/**
	 * Sum the total item count across a breakdown.
	 *
	 * @param array<int, array> $breakdown Canonical breakdown.
	 * @return int
	 */
	public static function total_from_breakdown( array $breakdown ) {
		$total = 0;
		foreach ( $breakdown as $entry ) {
			$total += isset( $entry['count'] ) ? absint( $entry['count'] ) : 0;
		}
		return $total;
	}

	/**
	 * Extract the first JSON object from a VLM text response.
	 *
	 * Tolerates markdown code fences, leading prose, and trailing prose.
	 *
	 * @param string $content Raw model output.
	 * @return array|null Decoded JSON object, or null when none is found.
	 */
	public static function extract_json( $content ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return null;
		}

		// Strip markdown code fences first (either with or without a language tag).
		$stripped = preg_replace( '/```(?:json)?\s*/i', '', $content );
		if ( null !== $stripped ) {
			$content = preg_replace( '/```\s*$/', '', $stripped );
		}

		// Direct decode attempt (cleanest case).
		$decoded = json_decode( $content, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		// Fall back to extracting the first balanced {...} block.
		if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Build the human-readable summary message for a breakdown.
	 *
	 * @param array<int, array> $breakdown Canonical breakdown.
	 * @param int               $total     Total item count (computed when 0).
	 * @return string
	 */
	public static function build_message( array $breakdown, $total = 0 ) {
		if ( empty( $breakdown ) ) {
			return __( 'No objects were detected in the image.', 'mcp-ai-wpoos-pro' );
		}

		if ( $total < 1 ) {
			$total = self::total_from_breakdown( $breakdown );
		}

		$parts = array();
		foreach ( $breakdown as $entry ) {
			$parts[] = sprintf(
				'%1$s (%2$d)',
				esc_html( $entry['label'] ),
				absint( $entry['count'] )
			);
		}

		return sprintf(
			/* translators: 1: total item count, 2: comma-separated label (count) list */
			__( 'Found %1$d items: %2$s.', 'mcp-ai-wpoos-pro' ),
			absint( $total ),
			implode( ', ', $parts )
		);
	}

	/**
	 * Normalize a bounding box to the canonical normalized shape.
	 *
	 * Accepts OWLv2 `{xmin,ymin,xmax,ymax}` and `{x,y,width,height}` shapes
	 * in either normalized (0–1) or absolute pixel space. Absolute values
	 * larger than 1.0 are assumed to be pixels and are left untouched —
	 * callers that need normalized values should divide by image dimensions.
	 *
	 * @param array $box Raw box data.
	 * @return array{x: float, y: float, width: float, height: float}
	 */
	public static function normalize_box( array $box ) {
		if ( isset( $box['xmin'] ) ) {
			$xmin = (float) $box['xmin'];
			$ymin = isset( $box['ymin'] ) ? (float) $box['ymin'] : 0.0;
			$xmax = isset( $box['xmax'] ) ? (float) $box['xmax'] : 0.0;
			$ymax = isset( $box['ymax'] ) ? (float) $box['ymax'] : 0.0;

			return array(
				'x'      => $xmin,
				'y'      => $ymin,
				'width'  => max( 0.0, $xmax - $xmin ),
				'height' => max( 0.0, $ymax - $ymin ),
			);
		}

		return array(
			'x'      => isset( $box['x'] ) ? (float) $box['x'] : 0.0,
			'y'      => isset( $box['y'] ) ? (float) $box['y'] : 0.0,
			'width'  => isset( $box['width'] ) ? max( 0.0, (float) $box['width'] ) : 0.0,
			'height' => isset( $box['height'] ) ? max( 0.0, (float) $box['height'] ) : 0.0,
		);
	}
}
