<?php
/**
 * Transforms a WP_Post (funiq_product) into a Payload-compatible product array.
 *
 * @package NvoosAstroBridge\Transformers
 */

namespace NvoosAstroBridge\Transformers;

use NvoosAstroBridge\Schema;
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
			'price'        => $this->float_val( get_post_meta( $post->ID, Schema::META_PRICE, true ) ),
			'oldPrice'     => $this->nullable_float( get_post_meta( $post->ID, Schema::META_OLD_PRICE, true ) ),
			'description'  => $post->post_content,
			'category'     => $this->term_single( wp_get_post_terms( $post->ID, Schema::TAX_CATEGORY ) ),
			'brand'        => $this->term_single( wp_get_post_terms( $post->ID, Schema::TAX_BRAND ) ),
			'colors'       => $this->terms_color( wp_get_post_terms( $post->ID, Schema::TAX_COLOR ) ),
			'width'        => $this->float_val( get_post_meta( $post->ID, Schema::META_WIDTH, true ) ),
			'height'       => $this->float_val( get_post_meta( $post->ID, Schema::META_HEIGHT, true ) ),
			'depth'        => $this->float_val( get_post_meta( $post->ID, Schema::META_DEPTH, true ) ),
			'rating'       => $this->nullable_float( get_post_meta( $post->ID, Schema::META_RATING, true ) ),
			'isBestseller' => (bool) get_post_meta( $post->ID, Schema::META_IS_BESTSELLER, true ),
			'isFeatured'   => (bool) get_post_meta( $post->ID, Schema::META_IS_FEATURED, true ),
			// PWA fields (URLs).
			'image'        => $this->media_url( $image_id ),
			'images'       => array_values(
				array_filter(
					array_map( array( $this, 'media_url' ), (array) $gallery )
				)
			),
			// Admin fields (raw attachment IDs for ImageUpload / Media Library).
			'imageId'      => $image_id,
			'imagesIds'    => array_values( array_filter( array_map( 'intval', (array) $gallery ) ) ),
			'statuses'     => $this->terms_basic( wp_get_post_terms( $post->ID, Schema::TAX_STATUS ) ),
			'promotion'    => $promo_id ? $this->promo_ref( (int) $promo_id ) : null,
			'createdAt'    => $post->post_date,
			'updatedAt'    => $post->post_modified,
			'source'       => Schema::SOURCE_NATIVE,
			// Publication status ('publish' | 'draft') — drafts stay out of
			// the public listing and are only reachable with manage access.
			'status'       => $post->post_status,
		);
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Cast a raw meta value to float.
	 *
	 * @param mixed $value Raw meta value.
	 * @return float
	 */
	private function float_val( $value ): float {
		return (float) $value;
	}

	/**
	 * Cast a raw meta value to float, returning null for empty values.
	 *
	 * @param mixed $value Raw meta value.
	 * @return float|null
	 */
	private function nullable_float( $value ): ?float {
		return ( '' !== $value && null !== $value ) ? (float) $value : null;
	}

	/**
	 * Resolve an attachment ID to its URL.
	 *
	 * @param int|null $attachment_id Attachment ID.
	 * @return string Empty string if no valid attachment.
	 */
	private function media_url( $attachment_id ): string {
		if ( empty( $attachment_id ) ) {
			return '';
		}
		$url = wp_get_attachment_url( (int) $attachment_id );
		return $url ?: '';
	}

	/**
	 * Return the first term as { id, name } or null.
	 *
	 * @param \WP_Term[] $terms Term list.
	 * @return array{id: int, name: string}|null
	 */
	private function term_single( array $terms ): ?array {
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
	 * @param \WP_Term[] $terms Term list.
	 * @return list<array{id: int, name: string, hexCode: string}>
	 */
	private function terms_color( array $terms ): array {
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
	 * @param \WP_Term[] $terms Term list.
	 * @return list<array{id: int, name: string}>
	 */
	private function terms_basic( array $terms ): array {
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
	 * @param int $promo_id Promotion post ID.
	 * @return array<string, mixed>|null
	 */
	private function promo_ref( int $promo_id ): ?array {
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
