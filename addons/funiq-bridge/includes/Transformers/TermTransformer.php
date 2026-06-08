<?php
/**
 * Transforms a WP_Term (funiq_brand, funiq_color, funiq_status) into a simple { id, name } object.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

use FuniqBridge\Schema;

/**
 * Converts a WordPress term into a simple Payload-compatible object.
 */
class TermTransformer {

	/**
	 * Transform a WP_Term into { id, name, hexCode? }.
	 *
	 * @param \WP_Term $term The term object.
	 * @return array{id: int, name: string, hexCode?: string}
	 */
	public function transform( \WP_Term $term ): array {
		$data = array(
			'id'   => $term->term_id,
			'name' => $term->name,
		);

		// Include hexCode for color terms.
		$hex = get_term_meta( $term->term_id, Schema::TERM_META_HEX_CODE, true );
		if ( $hex ) {
			$data['hexCode'] = $hex;
		}

		return $data;
	}
}
