<?php
/**
 * Transforms a WP_Term (funiq_color) into a Payload-compatible color array.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

use FuniqBridge\Schema;

/**
 * Converts a WordPress color term into the shape the Funiq PWA expects.
 */
class ColorTransformer {

	/**
	 * Transform a WP_Term into a Payload-shaped color document.
	 *
	 * @param \WP_Term $term Color term.
	 * @return array{id: int, name: string, hexCode: string}
	 */
	public function transform( \WP_Term $term ): array {
		return array(
			'id'      => $term->term_id,
			'name'    => $term->name,
			'hexCode' => get_term_meta( $term->term_id, Schema::TERM_META_HEX_CODE, true ) ?: '',
		);
	}
}
