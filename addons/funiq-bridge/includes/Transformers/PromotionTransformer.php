<?php
/**
 * Transforms a WP_Post (funiq_promotion) into a Payload-compatible promotion array.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

use FuniqBridge\Schema;
use WP_Post;

/**
 * Converts a WordPress promotion post into the shape the Funiq PWA expects.
 */
class PromotionTransformer {

	/**
	 * Transform a WP_Post into a Payload-shaped promotion document.
	 *
	 * @param WP_Post $post Promotion post object.
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $post ): array {
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'startDate'   => get_post_meta( $post->ID, Schema::META_PROMO_START_DATE, true ) ?: '',
			'endDate'     => get_post_meta( $post->ID, Schema::META_PROMO_END_DATE, true ) ?: '',
			'active'      => (bool) get_post_meta( $post->ID, Schema::META_PROMO_ACTIVE, true ),
		);
	}
}
