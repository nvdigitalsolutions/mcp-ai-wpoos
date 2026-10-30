<?php
/**
 * Colors REST controller.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Sources\WooTaxonomy;

/**
 * Handles /funiq/v1/colors.
 *
 * Includes hexCode term meta support.
 */
class ColorsController extends TermController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'colors';

	/**
	 * Get the taxonomy slug.
	 *
	 * Woo-mode serves the pa_color attribute when the store defines one;
	 * the built-in taxonomy otherwise.
	 *
	 * @return string
	 */
	protected function taxonomy(): string {
		return WooTaxonomy::color_taxonomy();
	}

	/**
	 * Save hexCode term meta.
	 *
	 * @param int                  $term_id Term ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	protected function save_term_meta( int $term_id, array $data ): void {
		if ( isset( $data['hexCode'] ) ) {
			update_term_meta( $term_id, Schema::TERM_META_HEX_CODE, sanitize_text_field( $data['hexCode'] ) );
		}
	}
}
