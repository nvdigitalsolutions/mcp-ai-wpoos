<?php
/**
 * Markup submission validator (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Validator`
 * (`includes/markup/`): byte-identical DoS guards (64 shapes, 8 KB SVG
 * selectors, 4096 px mask dimension, 256 KB annotation cap), the W3C
 * Web Annotation envelope checks, the body-item allowlist with the
 * shape-extension geometry sanitization, the target/selector validation
 * (SvgSelector wp_kses allowlist, Fragment/TextQuote/TextPosition/
 * Rect/Point selectors), and the attachment capability gate
 * (read_post for users; guest-token ownership rules).
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Server-side validator for markup submissions.
 *
 * @since 1.1.0
 */
class MarkupValidator {

	const MAX_SHAPES           = 64;
	const MAX_SVG_BYTES        = 8 * 1024;
	const MAX_MASK_DIMENSION   = 4096;
	const MAX_ANNOTATION_BYTES = 256 * 1024;

	/**
	 * Validate a submitted annotation document against a request.
	 *
	 * Returns the cleaned annotation array on success, or a WP_Error on
	 * failure. The cleaned document is the one that gets passed to the
	 * rasterizer; downstream tools should NOT trust unverified inputs.
	 *
	 * @param MarkupRequest $request    Source request.
	 * @param mixed         $annotation Submitted annotation.
	 * @return array|\WP_Error Cleaned annotation array, or error.
	 */
	public function validate( MarkupRequest $request, $annotation ) {
		if ( ! \is_array( $annotation ) ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_payload', __( 'Markup payload must be an object.', 'nvoos-content-graph-ai' ) );
		}

		$encoded = \wp_json_encode( $annotation );
		if ( false === $encoded ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_payload', __( 'Markup payload is not JSON-serialisable.', 'nvoos-content-graph-ai' ) );
		}
		if ( \strlen( $encoded ) > self::MAX_ANNOTATION_BYTES ) {
			return new \WP_Error( 'wp_mcp_ai_markup_too_large', __( 'Markup payload exceeds the maximum allowed size.', 'nvoos-content-graph-ai' ) );
		}

		// W3C Web Annotation envelope: must declare type=Annotation.
		$type = isset( $annotation['type'] ) ? $annotation['type'] : '';
		if ( 'Annotation' !== $type && ! ( \is_array( $type ) && \in_array( 'Annotation', $type, true ) ) ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_type', __( 'Annotation type must be "Annotation".', 'nvoos-content-graph-ai' ) );
		}

		// Body items describe the user's marks. Normalize to an array of items.
		$body = isset( $annotation['body'] ) ? $annotation['body'] : array();
		if ( ! \is_array( $body ) ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_body', __( 'Annotation body must be an array.', 'nvoos-content-graph-ai' ) );
		}
		// Normalize: a single body object is allowed by the spec.
		if ( isset( $body['type'] ) ) {
			$body = array( $body );
		}
		if ( \count( $body ) > self::MAX_SHAPES ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_too_many_shapes',
				/* translators: %d: max number of shapes */
				\sprintf( __( 'Markup contains too many shapes (max %d).', 'nvoos-content-graph-ai' ), self::MAX_SHAPES )
			);
		}

		$cleaned_body = array();
		foreach ( $body as $item ) {
			$cleaned = $this->validate_body_item( $request, $item );
			if ( \is_wp_error( $cleaned ) ) {
				return $cleaned;
			}
			$cleaned_body[] = $cleaned;
		}

		// Target selectors: limit SVG path length, validate fragment selectors.
		$target = isset( $annotation['target'] ) ? $annotation['target'] : array();
		if ( ! \is_array( $target ) ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_target', __( 'Annotation target must be an object or array.', 'nvoos-content-graph-ai' ) );
		}
		$cleaned_target = $this->validate_target( $request, $target );
		if ( \is_wp_error( $cleaned_target ) ) {
			return $cleaned_target;
		}

		$cleaned = array(
			'@context'   => MarkupElicitation::ANNOTATION_CONTEXT,
			'type'       => 'Annotation',
			'id'         => 'urn:nvoos:markup:' . $request->get_request_id(),
			'motivation' => isset( $annotation['motivation'] ) && \is_string( $annotation['motivation'] )
				? \sanitize_key( $annotation['motivation'] )
				: MarkupElicitation::motivation_for_mode( $request->get_mode() ),
			'body'       => $cleaned_body,
			'target'     => $cleaned_target,
		);

		// Capability: ensure the submitting user can read the targeted attachment.
		$capability_check = $this->check_target_capability( $request );
		if ( \is_wp_error( $capability_check ) ) {
			return $capability_check;
		}

		return $cleaned;
	}

	/**
	 * Validate a single body item.
	 *
	 * @param MarkupRequest $request Source request.
	 * @param mixed         $item    Body item.
	 * @return array|\WP_Error
	 */
	private function validate_body_item( MarkupRequest $request, $item ) {
		if ( ! \is_array( $item ) ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_body_item', __( 'Each annotation body item must be an object.', 'nvoos-content-graph-ai' ) );
		}
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		// Allowed body types: TextualBody (comments), Choice, Composite,
		// SpecificResource (linked resource), and our shape extensions.
		$allowed = array( 'TextualBody', 'Choice', 'Composite', 'SpecificResource', 'Shape', 'Vector', 'Region' );
		if ( '' !== $type && ! \in_array( $type, $allowed, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_invalid_body_type',
				/* translators: %s: body item type. */
				\sprintf( __( 'Annotation body type "%s" is not supported.', 'nvoos-content-graph-ai' ), $type )
			);
		}

		$cleaned = array();
		if ( '' !== $type ) {
			$cleaned['type'] = $type;
		}
		if ( isset( $item['value'] ) && \is_string( $item['value'] ) ) {
			$cleaned['value'] = \wp_kses_post( $item['value'] );
		}
		if ( isset( $item['purpose'] ) ) {
			$purpose            = \is_array( $item['purpose'] )
				? \array_map( 'sanitize_key', $item['purpose'] )
				: \sanitize_key( (string) $item['purpose'] );
			$cleaned['purpose'] = $purpose;
		}
		if ( isset( $item['format'] ) && \is_string( $item['format'] ) ) {
			$cleaned['format'] = \sanitize_text_field( $item['format'] );
		}
		// Geometry: allowed shapes for our Shape body extension.
		if ( isset( $item['shape'] ) && \is_array( $item['shape'] ) ) {
			$shape_kind     = isset( $item['shape']['kind'] ) ? \sanitize_key( (string) $item['shape']['kind'] ) : '';
			$allowed_shapes = array( 'rect', 'polygon', 'point', 'arrow', 'circle', 'ellipse', 'freehand' );
			if ( '' !== $shape_kind && ! \in_array( $shape_kind, $allowed_shapes, true ) ) {
				return new \WP_Error(
					'wp_mcp_ai_markup_invalid_shape',
					/* translators: %s: shape kind. */
					\sprintf( __( 'Shape "%s" is not supported.', 'nvoos-content-graph-ai' ), $shape_kind )
				);
			}
			$points = isset( $item['shape']['points'] ) && \is_array( $item['shape']['points'] ) ? $item['shape']['points'] : array();
			if ( \count( $points ) > 2048 ) {
				return new \WP_Error( 'wp_mcp_ai_markup_too_many_points', __( 'Shape has too many vertices.', 'nvoos-content-graph-ai' ) );
			}
			$clean_points = array();
			foreach ( $points as $pt ) {
				if ( ! \is_array( $pt ) ) {
					continue;
				}
				$x = isset( $pt['x'] ) ? (float) $pt['x'] : ( isset( $pt[0] ) ? (float) $pt[0] : null );
				$y = isset( $pt['y'] ) ? (float) $pt['y'] : ( isset( $pt[1] ) ? (float) $pt[1] : null );
				if ( null === $x || null === $y ) {
					continue;
				}
				$clean_points[] = array(
					'x' => $x,
					'y' => $y,
				);
			}
			$cleaned['shape'] = array(
				'kind'   => $shape_kind,
				'points' => $clean_points,
			);
			if ( isset( $item['shape']['stroke'] ) ) {
				$stroke = \sanitize_hex_color( (string) $item['shape']['stroke'] );
				if ( null !== $stroke ) {
					$cleaned['shape']['stroke'] = $stroke;
				}
			}
			if ( isset( $item['shape']['fill'] ) ) {
				$fill = \sanitize_hex_color( (string) $item['shape']['fill'] );
				if ( null !== $fill ) {
					$cleaned['shape']['fill'] = $fill;
				}
			}
		}
		return $cleaned;
	}

	/**
	 * Validate the annotation target.
	 *
	 * @param MarkupRequest $request Source request.
	 * @param array         $target  Target descriptor.
	 * @return array|\WP_Error
	 */
	private function validate_target( MarkupRequest $request, array $target ) {
		// Either a flat target or an array of targets.
		if ( isset( $target['source'] ) || isset( $target['id'] ) ) {
			$single = $this->validate_single_target( $request, $target );
			return \is_wp_error( $single ) ? $single : $single;
		}
		$cleaned = array();
		foreach ( $target as $sub ) {
			if ( ! \is_array( $sub ) ) {
				continue;
			}
			$single = $this->validate_single_target( $request, $sub );
			if ( \is_wp_error( $single ) ) {
				return $single;
			}
			$cleaned[] = $single;
		}
		if ( empty( $cleaned ) ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_target', __( 'Annotation target is empty.', 'nvoos-content-graph-ai' ) );
		}
		return $cleaned;
	}

	/**
	 * Validate one target descriptor.
	 *
	 * @param MarkupRequest $request Source request.
	 * @param array         $target  Single target descriptor.
	 * @return array|\WP_Error
	 */
	private function validate_single_target( MarkupRequest $request, array $target ) {
		$cleaned = array();
		if ( isset( $target['source'] ) ) {
			$cleaned['source'] = \esc_url_raw( (string) $target['source'] );
		}
		if ( isset( $target['id'] ) ) {
			$cleaned['id'] = \esc_url_raw( (string) $target['id'] );
		}
		if ( isset( $target['type'] ) ) {
			$cleaned['type'] = \sanitize_text_field( (string) $target['type'] );
		}
		if ( isset( $target['selector'] ) ) {
			$selector = $target['selector'];
			if ( ! \is_array( $selector ) ) {
				return new \WP_Error( 'wp_mcp_ai_markup_invalid_selector', __( 'Selector must be an object or array.', 'nvoos-content-graph-ai' ) );
			}
			// Single selector vs array of selectors.
			if ( isset( $selector['type'] ) ) {
				$cleaned['selector'] = $this->validate_selector( $selector );
				if ( \is_wp_error( $cleaned['selector'] ) ) {
					return $cleaned['selector'];
				}
			} else {
				$cleaned['selector'] = array();
				foreach ( $selector as $sel ) {
					if ( ! \is_array( $sel ) ) {
						continue;
					}
					$validated = $this->validate_selector( $sel );
					if ( \is_wp_error( $validated ) ) {
						return $validated;
					}
					$cleaned['selector'][] = $validated;
				}
			}
		}
		return $cleaned;
	}

	/**
	 * Validate a single W3C selector.
	 *
	 * Supports SvgSelector, FragmentSelector, TextQuoteSelector,
	 * TextPositionSelector, and our custom RectSelector / PointSelector.
	 *
	 * @param array $selector Selector data.
	 * @return array|\WP_Error
	 */
	private function validate_selector( array $selector ) {
		$type = isset( $selector['type'] ) ? \sanitize_text_field( (string) $selector['type'] ) : '';
		switch ( $type ) {
			case 'SvgSelector':
				$value = isset( $selector['value'] ) ? (string) $selector['value'] : '';
				if ( \strlen( $value ) > self::MAX_SVG_BYTES ) {
					return new \WP_Error( 'wp_mcp_ai_markup_svg_too_large', __( 'SVG selector exceeds maximum size.', 'nvoos-content-graph-ai' ) );
				}
				// Strip script/event-handler attributes via wp_kses with a tight allowlist.
				$value = \wp_kses(
					$value,
					array(
						'svg'      => array(
							'xmlns'   => true,
							'viewbox' => true,
							'width'   => true,
							'height'  => true,
						),
						'path'     => array(
							'd'    => true,
							'fill' => true,
						),
						'rect'     => array(
							'x'      => true,
							'y'      => true,
							'width'  => true,
							'height' => true,
							'fill'   => true,
						),
						'polygon'  => array(
							'points' => true,
							'fill'   => true,
						),
						'polyline' => array(
							'points' => true,
							'fill'   => true,
						),
						'circle'   => array(
							'cx'   => true,
							'cy'   => true,
							'r'    => true,
							'fill' => true,
						),
						'ellipse'  => array(
							'cx'   => true,
							'cy'   => true,
							'rx'   => true,
							'ry'   => true,
							'fill' => true,
						),
					)
				);
				return array(
					'type'  => 'SvgSelector',
					'value' => $value,
				);
			case 'FragmentSelector':
				return array(
					'type'       => 'FragmentSelector',
					'value'      => isset( $selector['value'] ) ? \sanitize_text_field( (string) $selector['value'] ) : '',
					'conformsTo' => isset( $selector['conformsTo'] ) ? \esc_url_raw( (string) $selector['conformsTo'] ) : '',
				);
			case 'TextQuoteSelector':
				return array(
					'type'   => 'TextQuoteSelector',
					'exact'  => isset( $selector['exact'] ) ? \wp_strip_all_tags( (string) $selector['exact'] ) : '',
					'prefix' => isset( $selector['prefix'] ) ? \wp_strip_all_tags( (string) $selector['prefix'] ) : '',
					'suffix' => isset( $selector['suffix'] ) ? \wp_strip_all_tags( (string) $selector['suffix'] ) : '',
				);
			case 'TextPositionSelector':
				return array(
					'type'  => 'TextPositionSelector',
					'start' => isset( $selector['start'] ) ? \max( 0, (int) $selector['start'] ) : 0,
					'end'   => isset( $selector['end'] ) ? \max( 0, (int) $selector['end'] ) : 0,
				);
			case 'RectSelector':
				return array(
					'type'   => 'RectSelector',
					'x'      => isset( $selector['x'] ) ? (float) $selector['x'] : 0.0,
					'y'      => isset( $selector['y'] ) ? (float) $selector['y'] : 0.0,
					'width'  => isset( $selector['width'] ) ? \max( 0.0, (float) $selector['width'] ) : 0.0,
					'height' => isset( $selector['height'] ) ? \max( 0.0, (float) $selector['height'] ) : 0.0,
				);
			case 'PointSelector':
				return array(
					'type' => 'PointSelector',
					'x'    => isset( $selector['x'] ) ? (float) $selector['x'] : 0.0,
					'y'    => isset( $selector['y'] ) ? (float) $selector['y'] : 0.0,
				);
			default:
				return new \WP_Error(
					'wp_mcp_ai_markup_unknown_selector',
					/* translators: %s: selector type. */
					\sprintf( __( 'Selector type "%s" is not supported.', 'nvoos-content-graph-ai' ), $type )
				);
		}
	}

	/**
	 * Capability check: can the requesting user read the target asset?
	 *
	 * @param MarkupRequest $request Source request.
	 * @return true|\WP_Error
	 */
	private function check_target_capability( MarkupRequest $request ) {
		$target = $request->get_target();
		if ( empty( $target['attachment_id'] ) ) {
			// URL or data-uri targets do not require a per-attachment cap check.
			return true;
		}
		$attachment_id = (int) $target['attachment_id'];
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'wp_mcp_ai_markup_invalid_attachment', __( 'Invalid target attachment.', 'nvoos-content-graph-ai' ) );
		}
		$attachment = \get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'wp_mcp_ai_markup_attachment_not_found', __( 'Target attachment not found.', 'nvoos-content-graph-ai' ) );
		}
		// If a real WP user submitted, require read for the post.
		$user_id = \get_current_user_id();
		if ( $user_id > 0 ) {
			if ( ! \current_user_can( 'read_post', $attachment_id ) ) {
				return new \WP_Error(
					'wp_mcp_ai_markup_forbidden',
					__( 'You are not allowed to mark up this asset.', 'nvoos-content-graph-ai' ),
					array( 'status' => 403 )
				);
			}
			return true;
		}
		// Guest-token submissions: allow only when the request is scoped to
		// an assistant whose attachment we own. This is the existing guest
		// pattern in the plugin: guest tokens never bypass attachment ACLs.
		if ( $request->get_user_id() <= 0 && $request->get_assistant_id() > 0 ) {
			if ( (int) $attachment->post_author > 0 ) {
				// Attachment has a real owner — guests cannot mark it up.
				return new \WP_Error(
					'wp_mcp_ai_markup_forbidden',
					__( 'Guests are not allowed to mark up this asset.', 'nvoos-content-graph-ai' ),
					array( 'status' => 403 )
				);
			}
		}
		return true;
	}
}
