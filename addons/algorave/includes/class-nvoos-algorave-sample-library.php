<?php
/**
 * NV oOS Algorave — Sample Library
 *
 * Manages audio samples via the WordPress media library.
 * Provides upload, browse, and categorization for audio samples
 * used in algorave patterns.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sample library manager.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Sample_Library {

	/**
	 * Allowed audio MIME types for sample uploads.
	 *
	 * @var array
	 */
	const ALLOWED_MIME_TYPES = array(
		'audio/wav',
		'audio/x-wav',
		'audio/mpeg',
		'audio/mp3',
		'audio/ogg',
		'audio/flac',
		'audio/webm',
	);

	/**
	 * Taxonomy for sample categories.
	 *
	 * @var string
	 */
	const TAXONOMY = 'algorave_sample_category';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_audio_mimes' ) );
	}

	/**
	 * Register the sample category taxonomy on attachments.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			array(
				'labels'            => array(
					'name'          => _x( 'Sample Categories', 'taxonomy general name', 'nvoos-algorave' ),
					'singular_name' => _x( 'Sample Category', 'taxonomy singular name', 'nvoos-algorave' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
			)
		);
	}

	/**
	 * Ensure audio MIME types are allowed for upload.
	 *
	 * @since 1.0.0
	 *
	 * @param array $mimes Existing allowed MIME types.
	 * @return array Modified MIME types.
	 */
	public static function allow_audio_mimes( $mimes ) {
		$mimes['wav']  = 'audio/wav';
		$mimes['mp3']  = 'audio/mpeg';
		$mimes['ogg']  = 'audio/ogg';
		$mimes['flac'] = 'audio/flac';
		$mimes['webm'] = 'audio/webm';
		return $mimes;
	}

	/**
	 * Browse available audio samples.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments.
	 * @return array Array of sample data.
	 */
	public static function browse( $args = array() ) {
		$defaults = array(
			'posts_per_page' => 20,
			'category'       => '',
			'search'         => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'audio/wav', 'audio/mpeg', 'audio/ogg', 'audio/flac', 'audio/webm' ),
			'posts_per_page' => absint( $args['posts_per_page'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['category'] ) ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $args['category'] ),
				),
			);
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		$query   = new WP_Query( $query_args );
		$samples = array();

		foreach ( $query->posts as $post ) {
			$samples[] = array(
				'id'       => $post->ID,
				'name'     => $post->post_title,
				'url'      => wp_get_attachment_url( $post->ID ),
				'mime'     => $post->post_mime_type,
				'duration' => get_post_meta( $post->ID, '_algorave_sample_duration', true ),
				'category' => wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'names' ) ),
			);
		}

		return array(
			'samples' => $samples,
			'total'   => $query->found_posts,
		);
	}
}

// Initialize.
NV_oOS_Algorave_Sample_Library::init();
