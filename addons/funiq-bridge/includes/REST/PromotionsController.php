<?php
/**
 * Promotions REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use FuniqBridge\Transformers\PromotionTransformer;
use WP_Post;

/**
 * Handles /funiq/v1/promotions.
 */
class PromotionsController extends PostTypeController {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'promotions';

	/**
	 * @return string
	 */
	protected function post_type(): string {
		return Schema::CPT_PROMOTION;
	}

	/**
	 * @param WP_Post $post
	 * @return array<string, mixed>
	 */
	protected function transform( WP_Post $post ): array {
		return ( new PromotionTransformer() )->transform( $post );
	}

	/**
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @return array<string, mixed>
	 */
	protected function post_args( array $data, ?int $id ): array {
		$args = array(
			'post_type' => Schema::CPT_PROMOTION,
		);

		if ( null !== $id ) {
			$args['ID'] = $id;
		}

		if ( isset( $data['title'] ) ) {
			$args['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['description'] ) ) {
			$args['post_content'] = wp_kses_post( $data['description'] );
		}

		return $args;
	}

	/**
	 * @param int                   $post_id
	 * @param array<string, mixed>  $data
	 * @return void
	 */
	protected function save_meta( int $post_id, array $data ): void {
		$fields = array(
			Schema::META_PROMO_START_DATE => 'startDate',
			Schema::META_PROMO_END_DATE   => 'endDate',
			Schema::META_PROMO_ACTIVE     => 'active',
		);

		foreach ( $fields as $key => $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$value = $field === 'active' ? (bool) $data[ $field ] : $data[ $field ];
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
