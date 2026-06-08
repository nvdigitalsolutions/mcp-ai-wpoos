<?php
/**
 * Transforms a WP_Term (funiq_category) into a Payload-compatible category array.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

use FuniqBridge\Schema;
use WP_Query;

/**
 * Converts a WordPress category term into the shape the Funiq PWA expects.
 */
class CategoryTransformer {

	/**
	 * Transform a WP_Term into a Payload-shaped category document.
	 *
	 * Includes the computed `productsCount` field.
	 *
	 * @param \WP_Term $term Category term.
	 * @return array<string, mixed>
	 */
	public function transform( \WP_Term $term ): array {
		$image_id = get_term_meta( $term->term_id, Schema::TERM_META_IMAGE_ID, true );

		return array(
			'id'            => $term->term_id,
			'name'          => $term->name,
			'image'         => $image_id ? ( wp_get_attachment_url( (int) $image_id ) ?: '' ) : '',
			'productsCount' => $this->countProducts( $term->term_id ),
		);
	}

	/**
	 * Count published products assigned to this category.
	 *
	 * @param int $term_id Category term ID.
	 * @return int
	 */
	private function countProducts( int $term_id ): int {
		$query = new WP_Query(
			array(
				'post_type'      => Schema::CPT_PRODUCT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => Schema::TAX_CATEGORY,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}
}
