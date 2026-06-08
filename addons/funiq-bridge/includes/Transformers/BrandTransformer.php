<?php
/**
 * Transforms a WP_Post (funiq_brand) into a Payload-compatible brand array.
 *
 * While Brand uses TermTransformer for listing, this dedicated transformer
 * handles special cases where a brand might carry additional data beyond
 * id + name.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

/**
 * Converts a WordPress brand term into the shape the Funiq PWA expects.
 */
class BrandTransformer {

	/**
	 * Transform a WP_Term into a Payload-shaped brand document.
	 *
	 * @param \WP_Term $term Brand term.
	 * @return array{id: int, name: string}
	 */
	public function transform( \WP_Term $term ): array {
		return array(
			'id'   => $term->term_id,
			'name' => $term->name,
		);
	}
}
