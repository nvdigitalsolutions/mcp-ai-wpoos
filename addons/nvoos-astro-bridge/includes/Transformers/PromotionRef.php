<?php
/**
 * Promotion reference resolver — shared promotion-doc builder (Proposal 112
 * parity: migrated Woo products carry the native promotionId in the
 * `_nvoos_bridge_promotion_id` meta and the woo transformer reads it back).
 *
 * The native transformer keeps its private implementation (its golden
 * fixtures are contract-pinned); this helper serves the Woo source and any
 * future consumers.
 *
 * @package NvoosAstroBridge\Transformers
 */

namespace NvoosAstroBridge\Transformers;

use NvoosAstroBridge\Schema;

/**
 * Resolves a promotion post into the contract's promotion object.
 */
final class PromotionRef {

	/**
	 * Resolve a promotion reference into a full promotion object.
	 *
	 * @param int $promo_id Promotion post ID.
	 * @return array<string, mixed>|null
	 */
	public static function resolve( int $promo_id ): ?array {
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
