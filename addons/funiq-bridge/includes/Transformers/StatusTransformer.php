<?php
/**
 * Transforms a WP_Term (funiq_status) into a Payload-compatible status array.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

/**
 * Converts a WordPress status term into the shape the Funiq PWA expects.
 */
class StatusTransformer {

	/**
	 * Transform a WP_Term into a Payload-shaped status document.
	 *
	 * @param \WP_Term $term Status term.
	 * @return array{id: int, name: string}
	 */
	public function transform( \WP_Term $term ): array {
		return array(
			'id'   => $term->term_id,
			'name' => $term->name,
		);
	}
}
