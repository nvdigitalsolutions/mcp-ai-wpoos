<?php
/**
 * Promocodes REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use FuniqBridge\Transformers\PromocodeTransformer;
use WP_Post;

/**
 * Handles /funiq/v1/promocodes.
 */
class PromocodesController extends PostTypeController {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'promocodes';

	/**
	 * @return string
	 */
	protected function post_type(): string {
		return Schema::CPT_PROMOCODE;
	}

	/**
	 * @param WP_Post $post
	 * @return array<string, mixed>
	 */
	protected function transform( WP_Post $post ): array {
		return ( new PromocodeTransformer() )->transform( $post );
	}

	/**
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @return array<string, mixed>
	 */
	protected function post_args( array $data, ?int $id ): array {
		$args = array(
			'post_type' => Schema::CPT_PROMOCODE,
		);

		if ( null !== $id ) {
			$args['ID'] = $id;
		}

		if ( isset( $data['code'] ) ) {
			$args['post_title'] = sanitize_text_field( $data['code'] );
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
			Schema::META_PROMOCODE_DISCOUNT   => 'discount',
			Schema::META_PROMOCODE_EXPIRES_AT => 'expiresAt',
			Schema::META_PROMOCODE_IS_ACTIVE  => 'isActive',
			Schema::META_PROMOCODE_NAME       => 'name',
			Schema::META_PROMOCODE_TITLE      => 'title',
			Schema::META_PROMOCODE_LOGO       => 'logo',
		);

		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];

			if ( 'discount' === $field ) {
				$value = (int) $value;
			} elseif ( 'isActive' === $field ) {
				$value = (bool) $value;
			} elseif ( 'logo' === $field && is_numeric( $value ) ) {
				$value = (int) $value;
			}

			update_post_meta( $post_id, $key, $value );
		}
	}
}
