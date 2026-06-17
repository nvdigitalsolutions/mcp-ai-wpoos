<?php
/**
 * Colors REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;

/**
 * Handles /funiq/v1/colors.
 *
 * Includes hexCode term meta support.
 */
class ColorsController extends TermController {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'colors';

	/**
	 * @return string
	 */
	protected function taxonomy(): string {
		return Schema::TAX_COLOR;
	}

	/**
	 * Save hexCode term meta.
	 *
	 * @param int                   $term_id Term ID.
	 * @param array<string, mixed>  $data    Request body.
	 * @return void
	 */
	protected function save_term_meta( int $term_id, array $data ): void {
		if ( isset( $data['hexCode'] ) ) {
			update_term_meta( $term_id, Schema::TERM_META_HEX_CODE, sanitize_text_field( $data['hexCode'] ) );
		}
	}
}
