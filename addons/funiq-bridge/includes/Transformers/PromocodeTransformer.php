<?php
/**
 * Transforms a WP_Post (funiq_promocode) into a Payload-compatible promocode array.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

use FuniqBridge\Schema;
use WP_Post;

/**
 * Converts a WordPress promocode post into the shape the Funiq PWA expects.
 *
 * Formats `expiresAt` to match Payload's `afterRead` hook output ("Jun 30, 2026").
 */
class PromocodeTransformer {

	/**
	 * Transform a WP_Post into a Payload-shaped promocode document.
	 *
	 * @param WP_Post $post Promocode post object.
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $post ): array {
		$logo_id = get_post_meta( $post->ID, Schema::META_PROMOCODE_LOGO, true );
		$expires = get_post_meta( $post->ID, Schema::META_PROMOCODE_EXPIRES_AT, true );

		return array(
			'id'        => $post->ID,
			'name'      => get_post_meta( $post->ID, Schema::META_PROMOCODE_NAME, true ) ?: '',
			'title'     => get_post_meta( $post->ID, Schema::META_PROMOCODE_TITLE, true ) ?: '',
			'code'      => $post->post_title,
			'discount'  => (int) get_post_meta( $post->ID, Schema::META_PROMOCODE_DISCOUNT, true ),
			'expiresAt' => $expires ? gmdate( 'M j, Y', strtotime( $expires ) ) : '',
			'isActive'  => (bool) get_post_meta( $post->ID, Schema::META_PROMOCODE_IS_ACTIVE, true ),
			'logo'      => $logo_id ? ( wp_get_attachment_url( (int) $logo_id ) ?: '' ) : '',
		);
	}
}
