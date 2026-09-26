<?php
/**
 * Tool for composing a deterministic text layout description from boxes.
 *
 * The text-mode analogue of Set-of-Mark prompting: detector bounding boxes
 * are converted into grid quadrants, relative positions, and size buckets so
 * a text-only model can reason about image structure without receiving a
 * single pixel. Implements the "model already knows the layout" strategy.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cloud-vision-client.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides an assistant tool that turns object bounding boxes into a
 * deterministic text description of an image's spatial layout.
 *
 * Accepts box JSON from any detector (vision_object_localization,
 * analyze_image_objects) or runs Cloud Vision object localization itself when
 * credentials are configured and auto_detect is requested.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Describe_Image_Layout implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Size buckets by fractional image area.
	 *
	 * @var array
	 */
	const SIZE_BUCKETS = array(
		array( 'tiny', 0.0, 0.01 ),
		array( 'small', 0.01, 0.05 ),
		array( 'medium', 0.05, 0.2 ),
		array( 'large', 0.2, 1.01 ),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'describe_image_layout';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Describe Image Layout', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts object bounding boxes into a deterministic text description of an image spatial layout: grid quadrants, relative positions, and sizes. Lets a text-only model reason about where things are in an image without receiving the image itself. Feed it boxes from vision_object_localization or analyze_image_objects, or use auto_detect.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'When a text-only model needs spatial awareness of an image (e.g. "the logo is top-right") without vision tokens, or when you need a stable, comparable layout fingerprint of an image.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Questions about image content semantics — the layout describes positions, not what objects are; pair with detect_image_content for labels.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'vision_object_localization', 'detect_image_content', 'identify_image', 'analyze_image_objects' ),
			'notes'           => __( 'Boxes are normalized 0–1 coordinates. auto_detect requires a Google Cloud Vision key and sends image bytes or URL to Google.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'boxes'              => array(
					'type'        => 'string',
					'description' => __( 'JSON array of boxes: [{"label":"cat","box":[x1,y1,x2,y2],"score":0.9}, ...] with normalized 0-1 coordinates. Boxes may also use Google normalizedVertices format.', 'mcp-ai-wpoos' ),
				),
				'source_tool_result' => array(
					'type'        => 'string',
					'description' => __( 'Raw JSON result from vision_object_localization (the responses array). Parsed automatically when boxes is not provided.', 'mcp-ai-wpoos' ),
				),
				'auto_detect'        => array(
					'type'        => 'boolean',
					'description' => __( 'When true, runs Cloud Vision object localization on the provided image and describes the detected boxes. Requires image_url, image_content, or attachment_id and a configured Google Cloud Vision key.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'image_url'          => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Image URL (used with auto_detect).', 'mcp-ai-wpoos' ),
				),
				'image_content'      => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content (used with auto_detect).', 'mcp-ai-wpoos' ),
				),
				'attachment_id'      => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID (used with auto_detect).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_describe_image_layout_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_describe_image_layout_forbidden',
				__( 'You do not have permission to use Describe Image Layout.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$boxes = array();

		if ( ! empty( $arguments['boxes'] ) ) {
			$boxes = $this->parse_boxes_json( $arguments['boxes'] );

			if ( is_wp_error( $boxes ) ) {
				return $boxes;
			}
		} elseif ( ! empty( $arguments['source_tool_result'] ) ) {
			$boxes = $this->parse_localization_result( $arguments['source_tool_result'] );

			if ( is_wp_error( $boxes ) ) {
				return $boxes;
			}
		} elseif ( ! empty( $arguments['auto_detect'] ) ) {
			$detected = $this->run_auto_detect( $arguments, $context );

			if ( is_wp_error( $detected ) ) {
				return $detected;
			}

			$boxes = $this->parse_localization_result( wp_json_encode( $detected ) );

			if ( is_wp_error( $boxes ) ) {
				return $boxes;
			}
		}

		if ( empty( $boxes ) ) {
			return new WP_Error(
				'wp_mcp_ai_describe_image_layout_no_boxes',
				__( 'No boxes available. Provide boxes JSON, a source_tool_result, or enable auto_detect with an image.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$layout = $this->compose_layout( $boxes );

		return $this->format_success_response(
			__( 'Image layout composed from bounding boxes.', 'mcp-ai-wpoos' ),
			$layout
		);
	}

	/**
	 * Parse the boxes JSON parameter.
	 *
	 * @param string $json JSON string.
	 * @return array|WP_Error Normalized box list, or WP_Error.
	 */
	private function parse_boxes_json( $json ) {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_describe_image_layout_invalid_json',
				__( 'The boxes parameter must be a valid JSON array.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		return $this->normalize_boxes( $decoded );
	}

	/**
	 * Parse a raw vision_object_localization response into boxes.
	 *
	 * @param string $json Raw tool result JSON.
	 * @return array|WP_Error Normalized box list, or WP_Error.
	 */
	private function parse_localization_result( $json ) {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_describe_image_layout_invalid_source',
				__( 'The source_tool_result must be valid JSON.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Accept the Google Vision shape: responses[0].localizedObjectAnnotations[].
		$annotations = array();
		if ( isset( $decoded['responses'][0]['localizedObjectAnnotations'] ) ) {
			$annotations = $decoded['responses'][0]['localizedObjectAnnotations'];
		} elseif ( isset( $decoded['localizedObjectAnnotations'] ) ) {
			$annotations = $decoded['localizedObjectAnnotations'];
		}

		$boxes = array();

		foreach ( $annotations as $annotation ) {
			$box = $this->vertices_to_box( $annotation );

			if ( is_wp_error( $box ) ) {
				continue;
			}

			$boxes[] = array(
				'label' => isset( $annotation['name'] ) ? $annotation['name'] : '',
				'box'   => $box,
				'score' => isset( $annotation['score'] ) ? $annotation['score'] : 0,
			);
		}

		return $boxes;
	}

	/**
	 * Run Cloud Vision object localization for auto_detect.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Decoded API response, or WP_Error.
	 */
	private function run_auto_detect( array $arguments, array $context ) {
		$client = new WP_MCP_AI_Cloud_Vision_Client();

		$image_url     = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		$image_content = isset( $arguments['image_content'] ) ? sanitize_text_field( $arguments['image_content'] ) : '';

		if ( empty( $image_url ) && empty( $image_content ) && ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$image_url     = (string) wp_get_attachment_url( $attachment_id );
		}

		$image = $client->build_image_source( $image_url, $image_content );

		if ( empty( $image ) ) {
			return new WP_Error(
				'wp_mcp_ai_describe_image_layout_missing_image',
				__( 'auto_detect requires image_url, image_content, or attachment_id.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		return $client->annotate(
			array(
				array(
					'type'       => 'OBJECT_LOCALIZATION',
					'maxResults' => 20,
				),
			),
			$image,
			$context,
			$arguments,
			$this
		);
	}

	/**
	 * Normalize raw box entries into the internal shape.
	 *
	 * @param array $entries Raw box entries.
	 * @return array Normalized boxes.
	 */
	private function normalize_boxes( array $entries ) {
		$boxes = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$box = array();

			if ( isset( $entry['box'] ) && is_array( $entry['box'] ) ) {
				$box = $entry['box'];
			} elseif ( isset( $entry['boundingPoly'] ) ) {
				$converted = $this->vertices_to_box( array( 'boundingPoly' => $entry['boundingPoly'] ) );

				if ( is_wp_error( $converted ) ) {
					continue;
				}

				$box = $converted;
			}

			$normalized = $this->normalize_coordinates( $box );

			if ( is_wp_error( $normalized ) ) {
				continue;
			}

			$boxes[] = array(
				'label' => sanitize_text_field( isset( $entry['label'] ) ? $entry['label'] : '' ),
				'box'   => $normalized,
				'score' => isset( $entry['score'] ) ? (float) $entry['score'] : 0.0,
			);
		}

		return $boxes;
	}

	/**
	 * Convert Google normalizedVertices into a [x1,y1,x2,y2] box.
	 *
	 * @param array $annotation Annotation containing boundingPoly.
	 * @return array|WP_Error Box, or WP_Error when vertices are unusable.
	 */
	private function vertices_to_box( array $annotation ) {
		if ( empty( $annotation['boundingPoly']['normalizedVertices'] ) ) {
			return new WP_Error( 'wp_mcp_ai_layout_no_vertices', __( 'No normalized vertices.', 'mcp-ai-wpoos' ) );
		}

		$xs = array();
		$ys = array();

		foreach ( $annotation['boundingPoly']['normalizedVertices'] as $vertex ) {
			if ( isset( $vertex['x'], $vertex['y'] ) ) {
				$xs[] = (float) $vertex['x'];
				$ys[] = (float) $vertex['y'];
			}
		}

		if ( empty( $xs ) || empty( $ys ) ) {
			return new WP_Error( 'wp_mcp_ai_layout_no_vertices', __( 'No normalized vertices.', 'mcp-ai-wpoos' ) );
		}

		return array( min( $xs ), min( $ys ), max( $xs ), max( $ys ) );
	}

	/**
	 * Clamp and validate a [x1,y1,x2,y2] coordinate set.
	 *
	 * @param array $box Raw box.
	 * @return array|WP_Error Normalized box, or WP_Error.
	 */
	private function normalize_coordinates( array $box ) {
		if ( 4 !== count( $box ) ) {
			return new WP_Error( 'wp_mcp_ai_layout_invalid_box', __( 'Boxes need four coordinates.', 'mcp-ai-wpoos' ) );
		}

		$x1 = min( 1.0, max( 0.0, (float) $box[0] ) );
		$y1 = min( 1.0, max( 0.0, (float) $box[1] ) );
		$x2 = min( 1.0, max( 0.0, (float) $box[2] ) );
		$y2 = min( 1.0, max( 0.0, (float) $box[3] ) );

		return array( $x1, $y1, $x2, $y2 );
	}

	/**
	 * Compose the deterministic layout description.
	 *
	 * @param array $boxes Normalized boxes.
	 * @return array Layout description (structured + prose).
	 */
	private function compose_layout( array $boxes ) {
		$objects = array();
		$prose   = array();

		foreach ( $boxes as $index => $box_data ) {
			list( $x1, $y1, $x2, $y2 ) = $box_data['box'];

			$center_x = ( $x1 + $x2 ) / 2;
			$center_y = ( $y1 + $y2 ) / 2;
			$width    = $x2 - $x1;
			$height   = $y2 - $y1;
			$area     = $width * $height;

			$quadrant = $this->quadrant_for( $center_x, $center_y );
			$size     = $this->size_bucket_for( $area );

			$label = '' !== $box_data['label'] ? $box_data['label'] : sprintf(
				/* translators: %d: 1-based object index */
				__( 'object %d', 'mcp-ai-wpoos' ),
				$index + 1
			);

			$objects[] = array(
				'label'      => sanitize_text_field( $label ),
				'score'      => round( (float) $box_data['score'], 3 ),
				'quadrant'   => $quadrant,
				'position'   => array(
					'x1' => round( $x1, 3 ),
					'y1' => round( $y1, 3 ),
					'x2' => round( $x2, 3 ),
					'y2' => round( $y2, 3 ),
				),
				'area_ratio' => round( $area, 3 ),
				'size'       => $size,
			);

			$prose[] = sprintf(
				/* translators: 1: label, 2: quadrant, 3: size bucket, 4-5: x percentages, 6-7: y percentages */
				__( 'A %1$s occupies the %2$s quadrant (%3$s size), spanning x %4$d–%5$d%% and y %6$d–%7$d%% of the image.', 'mcp-ai-wpoos' ),
				esc_html( $label ),
				$quadrant,
				$size,
				(int) round( $x1 * 100 ),
				(int) round( $x2 * 100 ),
				(int) round( $y1 * 100 ),
				(int) round( $y2 * 100 )
			);
		}

		return array(
			'object_count' => count( $objects ),
			'objects'      => $objects,
			'layout_prose' => implode( ' ', $prose ),
		);
	}

	/**
	 * Map a center point to its 3×3 grid quadrant.
	 *
	 * @param float $x Center x (0–1).
	 * @param float $y Center y (0–1).
	 * @return string Quadrant name.
	 */
	private function quadrant_for( $x, $y ) {
		$col = $x < 0.3333 ? 'left' : ( $x > 0.6667 ? 'right' : 'center' );
		$row = $y < 0.3333 ? 'top' : ( $y > 0.6667 ? 'bottom' : 'center' );

		if ( 'center' === $col && 'center' === $row ) {
			return 'center';
		}

		if ( 'center' === $row ) {
			return 'center-' . $col;
		}

		if ( 'center' === $col ) {
			return $row . '-center';
		}

		return $row . '-' . $col;
	}

	/**
	 * Map a fractional area to a size bucket.
	 *
	 * @param float $area Fractional area (0–1).
	 * @return string Size bucket.
	 */
	private function size_bucket_for( $area ) {
		foreach ( self::SIZE_BUCKETS as $bucket ) {
			if ( $area >= $bucket[1] && $area < $bucket[2] ) {
				return $bucket[0];
			}
		}

		return 'large';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'local-only',           // Boxes-only mode makes no external calls (auto_detect does).
			'cacheable',            // Deterministic for identical box input.
		);
	}
}
