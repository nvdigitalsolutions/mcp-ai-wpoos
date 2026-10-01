<?php
/**
 * Fashion Batch Jobs & Review Queue (Pro).
 *
 * Phase 2 of the fashion photography enhancement plan. Stores batch jobs as
 * a private CPT (`mcp_ai_fashion_job`) with per-variant state, processes
 * variants via Action Scheduler (group `nvoos_media_studio_batch`, inline
 * fallback when AS is unavailable), and exposes approve/reject/reroll
 * review actions.
 *
 * Cost discipline follows the D-3 tripwires implemented in the base
 * AI service: unknown pricing / per-image > ceiling / per-job > ceiling
 * require confirmation; the hard cap blocks the job outright.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fashion batch job service.
 */
class WP_MCP_AI_Fashion_Batch {

	/**
	 * Job post type + Action Scheduler identifiers.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_fashion_job';
	const AS_HOOK   = 'nvoos_fashion_batch_process';
	const AS_GROUP  = 'nvoos_media_studio_batch';

	/**
	 * Job statuses.
	 *
	 * @var string
	 */
	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_REVIEW     = 'review';
	const STATUS_COMPLETED  = 'completed';
	const STATUS_FAILED     = 'failed';

	/**
	 * Variant statuses.
	 *
	 * @var string
	 */
	const VARIANT_PENDING   = 'pending';
	const VARIANT_GENERATED = 'generated';
	const VARIANT_APPROVED  = 'approved';
	const VARIANT_REJECTED  = 'rejected';
	const VARIANT_REPLACED  = 'replaced';
	const VARIANT_FAILED    = 'failed';

	/**
	 * Job meta keys.
	 *
	 * @var string
	 */
	const META_STATUS     = '_fashion_job_status';
	const META_USER       = '_fashion_job_user_id';
	const META_TRANSFORM  = '_fashion_job_transform';
	const META_ARGS       = '_fashion_job_args';
	const META_ESTIMATE   = '_fashion_job_estimate_usd';
	const META_PER_IMAGE  = '_fashion_job_per_image_usd';
	const META_TOTAL      = '_fashion_job_total';
	const META_DONE       = '_fashion_job_done';
	const META_VARIANTS   = '_fashion_job_variants';
	const META_ACTION_IDS = '_fashion_job_action_ids';
	const META_PRODUCT_ID = '_fashion_job_product_id';
	const META_COLLECTION = '_fashion_job_collection_id';

	/**
	 * Initialize the job post type and the AS processor hook.
	 *
	 * @return void
	 */
	public static function init() {
		self::register_post_type();
		add_action( self::AS_HOOK, array( __CLASS__, 'handle_async_process' ), 10, 3 );
	}

	/**
	 * Register the (private) job post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- slug is the class constant, per repo convention.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Fashion Batch Jobs', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Fashion Batch Job', 'mcp-ai-wpoos-pro' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'has_archive'     => false,
			)
		);
	}

	/**
	 * Action Scheduler callback — processes one variant.
	 *
	 * @param int $job_id      Job post ID.
	 * @param int $variant_key Variant array key.
	 * @param int $source_id   Source attachment ID.
	 * @return void
	 */
	public static function handle_async_process( $job_id, $variant_key, $source_id ) {
		self::process_variant( absint( $job_id ), absint( $variant_key ), absint( $source_id ) );
	}

	/**
	 * Create a batch job.
	 *
	 * @param int[]  $attachment_ids Source attachment IDs.
	 * @param string $transform      Transform slug.
	 * @param array  $args           Transform arguments (description, color, background_style,
	 *                               aspect_ratio, identity_id, product_id, collection_id, confirmed).
	 * @param int    $user_id        Acting user ID.
	 * @return array|WP_Error Job payload or error.
	 */
	public static function create_job( $attachment_ids, $transform, $args = array(), $user_id = 0 ) {
		$service_class = 'NV_oOS_Media_Studio_AI_Service';
		if ( ! class_exists( $service_class ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'The Media Studio AI service is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$transform = sanitize_key( $transform );
		if ( ! in_array( $transform, $service_class::TRANSFORMS, true ) ) {
			return new WP_Error( 'nvoos_ms_invalid_transform', __( 'Unknown transform.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$ids = array();
		foreach ( (array) $attachment_ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'nvoos_ms_job_no_sources', __( 'Provide at least one source attachment.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'nvoos_ms_job_too_large', __( 'A batch job may contain at most 100 sources.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		foreach ( $ids as $source_id ) {
			$attachment = get_post( $source_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Source image not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
			}
			if ( $user_id > 0 && ! user_can( $user_id, 'read', $source_id ) ) {
				return new WP_Error( 'nvoos_ms_forbidden', __( 'You cannot use this attachment.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
			}
		}

		$identity_id = isset( $args['identity_id'] ) ? absint( $args['identity_id'] ) : 0;

		// Consent / acknowledgment gate (mirrors the single-run flow).
		$consent = $service_class::check_consent( $transform, $identity_id, $user_id );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		// Cost review gate (D-3) — hard cap blocks, other tripwires require confirm.
		$review = $service_class::review_required( $transform, $args, count( $ids ) );
		if ( $review['required'] ) {
			if ( ! empty( $review['blocked'] ) ) {
				return new WP_Error( 'nvoos_ms_hard_cap', __( 'Estimated cost exceeds the configured hard cap.', 'mcp-ai-wpoos-pro' ), array( 'status' => 402 ) );
			}
			if ( empty( $args['confirmed'] ) ) {
				$error = new WP_Error(
					'nvoos_ms_review_required',
					__( 'This batch job requires review before running.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 409 )
				);
				$error->add_data(
					array(
						'status' => 409,
						'review' => $review,
					),
					'nvoos_ms_review_required'
				);
				return $error;
			}
		}

		$job_args = array(
			'transform'        => $transform,
			'description'      => isset( $args['description'] ) ? $service_class::sanitize_user_text( $args['description'] ) : '',
			'color'            => isset( $args['color'] ) ? sanitize_hex_color( $args['color'] ) : '',
			'background_style' => isset( $args['background_style'] ) ? sanitize_key( $args['background_style'] ) : 'studio',
			'aspect_ratio'     => isset( $args['aspect_ratio'] ) ? sanitize_key( $args['aspect_ratio'] ) : 'auto',
			'mime_type'        => isset( $args['mime_type'] ) ? sanitize_key( $args['mime_type'] ) : 'png',
			'identity_id'      => $identity_id,
		);

		$variants = array();
		foreach ( $ids as $index => $source_id ) {
			$variants[] = array(
				'key'           => $index,
				'source_id'     => $source_id,
				'status'        => self::VARIANT_PENDING,
				'attachment_id' => 0,
				'url'           => '',
				'error'         => '',
				'reroll_of'     => 0,
			);
		}

		$job_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: %s: transform slug */
					__( 'Fashion batch — %s', 'mcp-ai-wpoos-pro' ),
					$transform
				),
				'post_author' => $user_id > 0 ? $user_id : 0,
				'meta_input'  => array(
					self::META_STATUS     => self::STATUS_PENDING,
					self::META_USER       => $user_id,
					self::META_TRANSFORM  => $transform,
					self::META_ARGS       => wp_json_encode( $job_args ),
					self::META_ESTIMATE   => $review['estimate_usd'],
					self::META_PER_IMAGE  => $review['per_image_usd'],
					self::META_TOTAL      => count( $variants ),
					self::META_DONE       => 0,
					self::META_VARIANTS   => $variants,
					self::META_ACTION_IDS => array(),
					self::META_PRODUCT_ID => isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0,
					self::META_COLLECTION => isset( $args['collection_id'] ) ? absint( $args['collection_id'] ) : 0,
				),
			)
		);

		if ( is_wp_error( $job_id ) || 0 === $job_id ) {
			return new WP_Error( 'nvoos_ms_job_create_failed', __( 'Could not create the batch job.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		// Dispatch: Action Scheduler when available, inline otherwise.
		$dispatched = false;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_ids = array();
			try {
				foreach ( $variants as $variant ) {
					$action_id = as_enqueue_async_action(
						self::AS_HOOK,
						array(
							'job_id'      => $job_id,
							'variant_key' => $variant['key'],
							'source_id'   => $variant['source_id'],
						),
						self::AS_GROUP
					);
					if ( $action_id ) {
						$action_ids[] = $action_id;
					}
				}
			} catch ( Throwable $e ) {
				$action_ids = array();
			}
			update_post_meta( $job_id, self::META_ACTION_IDS, $action_ids );
			if ( ! empty( $action_ids ) ) {
				self::transition_status( $job_id, self::STATUS_PROCESSING );
				$dispatched = true;
			}
		}

		if ( ! $dispatched ) {
			// Inline fallback (no AS, or enqueue failed — e.g. missing tables).
			self::transition_status( $job_id, self::STATUS_PROCESSING );
			foreach ( $variants as $variant ) {
				self::process_variant( $job_id, $variant['key'], $variant['source_id'] );
			}
		}

		return self::get_job( $job_id, $user_id );
	}

	/**
	 * Process one variant of a job (generation step).
	 *
	 * @param int $job_id      Job post ID.
	 * @param int $variant_key Variant array key.
	 * @param int $source_id   Source attachment ID.
	 * @return array|WP_Error Variant payload or error.
	 */
	public static function process_variant( $job_id, $variant_key, $source_id ) {
		$service_class = 'NV_oOS_Media_Studio_AI_Service';
		if ( ! class_exists( $service_class ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'The Media Studio AI service is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$job = get_post( absint( $job_id ) );
		if ( ! $job || self::POST_TYPE !== $job->post_type ) {
			return new WP_Error( 'nvoos_ms_job_not_found', __( 'Batch job not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$variants    = self::get_variants( $job_id );
		$variant_key = absint( $variant_key );
		if ( ! isset( $variants[ $variant_key ] ) ) {
			return new WP_Error( 'nvoos_ms_variant_not_found', __( 'Variant not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$variant = $variants[ $variant_key ];
		if ( ! in_array( $variant['status'], array( self::VARIANT_PENDING, self::VARIANT_FAILED ), true ) ) {
			return $variant; // Already processed — idempotent.
		}

		$transform = sanitize_key( (string) get_post_meta( $job_id, self::META_TRANSFORM, true ) );
		$job_args  = json_decode( (string) get_post_meta( $job_id, self::META_ARGS, true ), true );
		if ( ! is_array( $job_args ) ) {
			$job_args = array();
		}

		$result = $service_class::execute_transform(
			$transform,
			absint( $source_id ),
			array_merge( $job_args, array( 'confirmed' => true ) ),
			absint( get_post_meta( $job_id, self::META_USER, true ) )
		);

		if ( is_wp_error( $result ) ) {
			$variant['status'] = self::VARIANT_FAILED;
			$variant['error']  = $result->get_error_message();
		} elseif ( is_array( $result ) && ! empty( $result['attachment_id'] ) ) {
			$variant['status']        = self::VARIANT_GENERATED;
			$variant['attachment_id'] = absint( $result['attachment_id'] );
			$variant['url']           = isset( $result['url'] ) ? esc_url_raw( $result['url'] ) : '';
		} else {
			$variant['status'] = self::VARIANT_FAILED;
			$variant['error']  = __( 'The provider returned no usable image.', 'mcp-ai-wpoos-pro' );
		}

		$variants[ $variant_key ] = $variant;
		update_post_meta( $job_id, self::META_VARIANTS, $variants );
		update_post_meta( $job_id, self::META_DONE, min( (int) get_post_meta( $job_id, self::META_DONE, true ) + 1, count( $variants ) ) );

		self::maybe_finish( $job_id );

		return $variant;
	}

	/**
	 * Review one variant: approve, reject, or re-roll.
	 *
	 * @param int    $job_id      Job post ID.
	 * @param int    $variant_key Variant array key.
	 * @param string $decision    approve|reject|reroll.
	 * @param int    $user_id     Acting user ID.
	 * @param array  $options     Optional product_id / collection_id overrides.
	 * @return array|WP_Error Updated variant payload or error.
	 */
	public static function review_variant( $job_id, $variant_key, $decision, $user_id = 0, $options = array() ) {
		$job = get_post( absint( $job_id ) );
		if ( ! $job || self::POST_TYPE !== $job->post_type ) {
			return new WP_Error( 'nvoos_ms_job_not_found', __( 'Batch job not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! self::can_manage_job( $job_id, $user_id ) ) {
			return new WP_Error( 'nvoos_ms_forbidden', __( 'You cannot review this batch job.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		$decision = sanitize_key( $decision );
		if ( ! in_array( $decision, array( 'approve', 'reject', 'reroll' ), true ) ) {
			return new WP_Error( 'nvoos_ms_invalid_decision', __( 'Invalid review decision.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$variants    = self::get_variants( $job_id );
		$variant_key = absint( $variant_key );
		if ( ! isset( $variants[ $variant_key ] ) ) {
			return new WP_Error( 'nvoos_ms_variant_not_found', __( 'Variant not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$variant = $variants[ $variant_key ];

		if ( 'approve' === $decision ) {
			if ( self::VARIANT_GENERATED !== $variant['status'] ) {
				return new WP_Error( 'nvoos_ms_variant_not_generated', __( 'Only generated variants can be approved.', 'mcp-ai-wpoos-pro' ), array( 'status' => 409 ) );
			}
			$variant['status'] = self::VARIANT_APPROVED;

			// Export pipeline: WooCommerce gallery + media collection.
			$product_id    = isset( $options['product_id'] ) ? absint( $options['product_id'] ) : absint( get_post_meta( $job_id, self::META_PRODUCT_ID, true ) );
			$collection_id = isset( $options['collection_id'] ) ? absint( $options['collection_id'] ) : absint( get_post_meta( $job_id, self::META_COLLECTION, true ) );

			$export_errors = array();
			if ( $product_id > 0 && ! empty( $variant['attachment_id'] ) ) {
				$attach_result = self::attach_to_product_gallery( $variant['attachment_id'], $product_id );
				if ( is_wp_error( $attach_result ) ) {
					$export_errors[] = $attach_result->get_error_message();
				}
			}
			if ( $collection_id > 0 && ! empty( $variant['attachment_id'] ) ) {
				$collection_result = self::add_to_collection( array( $variant['attachment_id'] ), $collection_id );
				if ( is_wp_error( $collection_result ) ) {
					$export_errors[] = $collection_result->get_error_message();
				}
			}
			$variant['export_error'] = implode( '; ', $export_errors );
		} elseif ( 'reject' === $decision ) {
			$variant['status'] = self::VARIANT_REJECTED;
		} else { // reroll.
			$new_key                  = count( $variants );
			$variant['status']        = self::VARIANT_REPLACED;
			$variants[ $variant_key ] = $variant;

			$variants[] = array(
				'key'           => $new_key,
				'source_id'     => absint( $variant['source_id'] ),
				'status'        => self::VARIANT_PENDING,
				'attachment_id' => 0,
				'url'           => '',
				'error'         => '',
				'reroll_of'     => $variant_key,
			);

			update_post_meta( $job_id, self::META_VARIANTS, $variants );
			update_post_meta( $job_id, self::META_TOTAL, count( $variants ) );
			self::transition_status( $job_id, self::STATUS_PROCESSING );

			$source_id = $variants[ $new_key ]['source_id'];
			$enqueued  = false;
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				try {
					$action_id = as_enqueue_async_action(
						self::AS_HOOK,
						array(
							'job_id'      => $job_id,
							'variant_key' => $new_key,
							'source_id'   => $source_id,
						),
						self::AS_GROUP
					);
					$enqueued  = (bool) $action_id;
				} catch ( Throwable $e ) {
					$enqueued = false;
				}
			}
			if ( ! $enqueued ) {
				self::process_variant( $job_id, $new_key, $source_id );
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'media_studio_batch_review',
					sprintf( 'Media Studio batch reroll: job %d variant %d → %d', $job_id, $variant_key, $new_key ),
					array(
						'job_id'  => $job_id,
						'user_id' => $user_id,
					)
				);
			}

			return self::get_variant_payload( $job_id, $new_key );
		}

		$variants[ $variant_key ] = $variant;
		update_post_meta( $job_id, self::META_VARIANTS, $variants );

		self::maybe_finish( $job_id );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'media_studio_batch_review',
				sprintf( 'Media Studio batch review: job %d variant %d → %s', $job_id, $variant_key, $decision ),
				array(
					'job_id'  => $job_id,
					'user_id' => $user_id,
				)
			);
		}

		return self::get_variant_payload( $job_id, $variant_key );
	}

	/**
	 * Attach an attachment to a WooCommerce product gallery.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $product_id    Product ID.
	 * @return true|WP_Error
	 */
	public static function attach_to_product_gallery( $attachment_id, $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'nvoos_ms_wc_unavailable', __( 'WooCommerce is not active.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product ) {
			return new WP_Error( 'nvoos_ms_product_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		$gallery   = $product->get_gallery_image_ids();
		$gallery[] = absint( $attachment_id );
		$product->set_gallery_image_ids( array_values( array_unique( $gallery ) ) );
		$product->save();
		return true;
	}

	/**
	 * Add attachments to a media collection.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $collection_id  Collection post ID.
	 * @return true|WP_Error
	 */
	public static function add_to_collection( $attachment_ids, $collection_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Media_Collection_CPT' ) ) {
			return new WP_Error( 'nvoos_ms_collection_unavailable', __( 'The media collection system is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}
		$collection = get_post( absint( $collection_id ) );
		if ( ! $collection || WP_MCP_AI_Media_Collection_CPT::POST_TYPE !== $collection->post_type ) {
			return new WP_Error( 'nvoos_ms_collection_not_found', __( 'Collection not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$items = get_post_meta( $collection_id, '_mcp_ai_collection_items', true );
		$items = is_array( $items ) ? $items : array();
		foreach ( (array) $attachment_ids as $attachment_id ) {
			$items[] = absint( $attachment_id );
		}
		update_post_meta( $collection_id, '_mcp_ai_collection_items', array_values( array_unique( $items ) ) );
		return true;
	}

	/**
	 * Permission check: job author or manage_options.
	 *
	 * @param int $job_id  Job post ID.
	 * @param int $user_id Acting user ID.
	 * @return bool
	 */
	public static function can_manage_job( $job_id, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$owner = absint( get_post_meta( absint( $job_id ), self::META_USER, true ) );
		return $owner > 0 && $owner === $user_id;
	}

	/**
	 * List jobs visible to a user.
	 *
	 * @param int $user_id Acting user ID.
	 * @param int $limit   Max jobs.
	 * @return array
	 */
	public static function get_jobs( $user_id = 0, $limit = 50 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$query   = array(
			'post_type'     => self::POST_TYPE,
			'post_status'   => 'publish',
			'numberposts'   => min( 100, max( 1, absint( $limit ) ) ),
			'orderby'       => 'date',
			'order'         => 'DESC',
			'no_found_rows' => true,
		);
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$query['meta_query'] = array(
				array(
					'key'   => self::META_USER,
					'value' => $user_id,
				),
			);
		}

		$jobs = array();
		foreach ( get_posts( $query ) as $post ) {
			$jobs[] = self::get_job_payload( $post->ID, true );
		}
		return $jobs;
	}

	/**
	 * Fetch one job with permission check.
	 *
	 * @param int $job_id  Job post ID.
	 * @param int $user_id Acting user ID.
	 * @return array|WP_Error
	 */
	public static function get_job( $job_id, $user_id = 0 ) {
		$job = get_post( absint( $job_id ) );
		if ( ! $job || self::POST_TYPE !== $job->post_type ) {
			return new WP_Error( 'nvoos_ms_job_not_found', __( 'Batch job not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! self::can_manage_job( $job_id, $user_id ) ) {
			return new WP_Error( 'nvoos_ms_forbidden', __( 'You cannot view this batch job.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
		return self::get_job_payload( $job_id, false );
	}

	/**
	 * Transition a job to a new status.
	 *
	 * @param int    $job_id Job post ID.
	 * @param string $status New status.
	 * @return void
	 */
	protected static function transition_status( $job_id, $status ) {
		$allowed = array( self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_REVIEW, self::STATUS_COMPLETED, self::STATUS_FAILED );
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}
		update_post_meta( absint( $job_id ), self::META_STATUS, $status );
	}

	/**
	 * Move the job to review/completed/failed when every variant is settled.
	 *
	 * @param int $job_id Job post ID.
	 * @return void
	 */
	protected static function maybe_finish( $job_id ) {
		$variants = self::get_variants( $job_id );
		$done     = absint( get_post_meta( $job_id, self::META_DONE, true ) );
		$total    = absint( get_post_meta( $job_id, self::META_TOTAL, true ) );

		if ( $done < max( 1, $total ) ) {
			return;
		}

		$failed_count = 0;
		foreach ( $variants as $variant ) {
			if ( self::VARIANT_FAILED === $variant['status'] ) {
				++$failed_count;
			}
		}

		if ( count( $variants ) === $failed_count ) {
			self::transition_status( $job_id, self::STATUS_FAILED );
			return;
		}

		$settled = true;
		foreach ( $variants as $variant ) {
			if ( in_array( $variant['status'], array( self::VARIANT_GENERATED, self::VARIANT_PENDING ), true ) ) {
				$settled = false;
				break;
			}
		}

		self::transition_status( $job_id, $settled ? self::STATUS_COMPLETED : self::STATUS_REVIEW );
	}

	/**
	 * Read the variants array.
	 *
	 * @param int $job_id Job post ID.
	 * @return array
	 */
	protected static function get_variants( $job_id ) {
		$variants = get_post_meta( $job_id, self::META_VARIANTS, true );
		return is_array( $variants ) ? $variants : array();
	}

	/**
	 * Build a variant payload for REST responses.
	 *
	 * @param int $job_id      Job post ID.
	 * @param int $variant_key Variant array key.
	 * @return array
	 */
	protected static function get_variant_payload( $job_id, $variant_key ) {
		$variants = self::get_variants( $job_id );
		$variant  = isset( $variants[ $variant_key ] ) ? $variants[ $variant_key ] : array();

		return array(
			'key'           => absint( $variant_key ),
			'source_id'     => isset( $variant['source_id'] ) ? absint( $variant['source_id'] ) : 0,
			'status'        => isset( $variant['status'] ) ? sanitize_key( $variant['status'] ) : self::VARIANT_FAILED,
			'attachment_id' => isset( $variant['attachment_id'] ) ? absint( $variant['attachment_id'] ) : 0,
			'url'           => isset( $variant['url'] ) ? esc_url_raw( $variant['url'] ) : '',
			'error'         => isset( $variant['error'] ) ? sanitize_text_field( $variant['error'] ) : '',
			'export_error'  => isset( $variant['export_error'] ) ? sanitize_text_field( $variant['export_error'] ) : '',
			'reroll_of'     => isset( $variant['reroll_of'] ) ? absint( $variant['reroll_of'] ) : 0,
		);
	}

	/**
	 * Build the full job payload for REST responses.
	 *
	 * @param int  $job_id   Job post ID.
	 * @param bool $compact  Skip variant details (list view).
	 * @return array
	 */
	protected static function get_job_payload( $job_id, $compact ) {
		$variants_raw = self::get_variants( $job_id );

		$variants = array();
		if ( ! $compact ) {
			foreach ( $variants_raw as $key => $variant ) {
				$variants[] = self::get_variant_payload( $job_id, absint( $key ) );
			}
		}

		$estimate = get_post_meta( $job_id, self::META_ESTIMATE, true );

		return array(
			'id'            => absint( $job_id ),
			'status'        => sanitize_key( (string) get_post_meta( $job_id, self::META_STATUS, true ) ),
			'transform'     => sanitize_key( (string) get_post_meta( $job_id, self::META_TRANSFORM, true ) ),
			'total'         => absint( get_post_meta( $job_id, self::META_TOTAL, true ) ),
			'done'          => absint( get_post_meta( $job_id, self::META_DONE, true ) ),
			'estimate_usd'  => '' === $estimate || null === $estimate ? null : (float) $estimate,
			'per_image_usd' => get_post_meta( $job_id, self::META_PER_IMAGE, true ),
			'product_id'    => absint( get_post_meta( $job_id, self::META_PRODUCT_ID, true ) ),
			'collection_id' => absint( get_post_meta( $job_id, self::META_COLLECTION, true ) ),
			'created'       => get_the_date( 'c', $job_id ),
			'variants'      => $variants,
		);
	}
}
