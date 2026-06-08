<?php
/**
 * Transforms a WP_Post (funiq_product) into a Payload-compatible product array.
 *
 * @package FuniqBridge\Transformers
 */

namespace FuniqBridge\Transformers;

use FuniqBridge\Schema;
use WP_Post;

/**
 * Converts a WordPress product post into the shape the Funiq PWA expects.
 */
class ProductTransformer {

	/**
	 * Transform a WP_Post into a Payload-shaped product document.
	 *
	 * @param WP_Post $post Product post object.
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $post ): array {
		$image_id = (int) get_post_thumbnail_id( $post->ID ) ?: null;
		$gallery  = get_post_meta( $post->ID, Schema::META_GALLERY, true ) ?: array();
		$promo_id = get_post_meta( $post->ID, Schema::META_PROMOTION_ID, true );

		return array(
			'id'           => $post->ID,
			'name'         => $post->post_title,
			'price'        => $this->floatVal( get_post_meta( $post->ID, Schema::META_PRICE, true ) ),
			'oldPrice'     => $this->nullableFloat( get_post_meta( $post->ID, Schema::META_OLD_PRICE, true ) ),
			'description'  => $post->post_content,
			'category'     => $this->termSingle( wp_get_post_terms( $post->ID, Schema::TAX_CATEGORY ) ),
			'brand'        => $this->termSingle( wp_get_post_terms( $post->ID, Schema::TAX_BRAND ) ),
			'colors'       => $this->termsColor( wp_get_post_terms( $post->ID, Schema::TAX_COLOR ) ),
			'width'        => $this->floatVal( get_post_meta( $post->ID, Schema::META_WIDTH, true ) ),
			'height'       => $this->floatVal( get_post_meta( $post->ID, Schema::META_HEIGHT, true ) ),
			'depth'        => $this->floatVal( get_post_meta( $post->ID, Schema::META_DEPTH, true ) ),
			'rating'       => $this->nullableFloat( get_post_meta( $post->ID, Schema::META_RATING, true ) ),
			'isBestseller' => (bool) get_post_meta( $post->ID, Schema::META_IS_BESTSELLER, true ),
			'isFeatured'   => (bool) get_post_meta( $post->ID, Schema::META_IS_FEATURED, true ),
			// PWA fields (URLs).
			'image'        => $this->mediaUrl( $image_id ),
			'images'       => array_values(
				array_filter(
					array_map( array( $this, 'mediaUrl' ), (array) $gallery )
				)
			),
			// Admin fields (raw attachment IDs for ImageUpload / Media Library).
			'imageId'      => $image_id,
			'imagesIds'    => array_values( array_filter( array_map( 'intval', (array) $gallery ) ) ),
			'statuses'     => $this->termsBasic( wp_get_post_terms( $post->ID, Schema::TAX_STATUS ) ),
			'promotion'    => $promo_id ? $this->promoRef( (int) $promo_id ) : null,
			'createdAt'    => $post->post_date,
			'updatedAt'    => $post->post_modified,
		);
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * @param mixed $value
	 * @return float
	 */
	private function floatVal( $value ): float {
		return (float) $value;
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	private function nullableFloat( $value ): ?float {
		return ( '' !== $value && null !== $value ) ? (float) $value : null;
	}

	/**
	 * @param int|null $attachment_id
	 * @return string Empty string if no valid attachment.
	 */
	private function mediaUrl( $attachment_id ): string {
		if ( empty( $attachment_id ) ) {
			return '';
		}
		$url = wp_get_attachment_url( (int) $attachment_id );
		return $url ?: '';
	}

	/**
	 * Return the first term as { id, name } or null.
	 *
	 * @param \WP_Term[] $terms
	 * @return array{id: int, name: string}|null
	 */
	private function termSingle( array $terms ): ?array {
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}
		return array(
			'id'   => $terms[0]->term_id,
			'name' => $terms[0]->name,
		);
	}

	/**
	 * Return terms as { id, name, hexCode }.
	 *
	 * @param \WP_Term[] $terms
	 * @return list<array{id: int, name: string, hexCode: string}>
	 */
	private function termsColor( array $terms ): array {
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			function ( \WP_Term $t ): array {
				return array(
					'id'      => $t->term_id,
					'name'    => $t->name,
					'hexCode' => get_term_meta( $t->term_id, Schema::TERM_META_HEX_CODE, true ) ?: '',
				);
			},
			$terms
		);
	}

	/**
	 * Return terms as { id, name }.
	 *
	 * @param \WP_Term[] $terms
	 * @return list<array{id: int, name: string}>
	 */
	private function termsBasic( array $terms ): array {
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			function ( \WP_Term $t ): array {
				return array(
					'id'   => $t->term_id,
					'name' => $t->name,
				);
			},
			$terms
		);
	}

	/**
	 * Resolve a promotion reference into a full promotion object.
	 *
	 * @param int $promo_id
	 * @return array<string, mixed>|null
	 */
	private function promoRef( int $promo_id ): ?array {
		$p = get_post( $promo_id );
		if ( ! $p || Schema::CPT_PROMOTION !== $p->post_type ) {
			return null;
		}
		return array(
			'id'          => $p->ID,
			'title'       => $p->post_title,
			'description' => $p->post_content,
			'startDate'   => get_post_meta( $p->ID, Schema::META_PROMO_START_DATE, true ) ?: '',
			'endDate'     => get_post_meta( $p->ID, Schema::META_PROMO_END_DATE, true ) ?: '',
			'active'      => (bool) get_post_meta( $p->ID, Schema::META_PROMO_ACTIVE, true ),
		);
	}
}
