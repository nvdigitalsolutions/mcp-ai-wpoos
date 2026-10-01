<?php
/**
 * NV oOS Design System — Email Template CPT
 *
 * Registers the private `nds_email_template` post type that stores email
 * templates: built-in seeds, user-created templates, and AI-generated
 * drafts. Post content is the template HTML; post status gates activation
 * (drafts can be previewed but not activated without review).
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template custom post type.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Template_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'nds_email_template';

	/**
	 * Meta key for the template source ('builtin' | 'ai' | 'custom').
	 *
	 * @var string
	 */
	const META_SOURCE = '_nds_template_source';

	/**
	 * Meta key for the template scope ('full' | 'body').
	 *
	 * @var string
	 */
	const META_SCOPE = '_nds_template_scope';

	/**
	 * Meta key for per-template settings (logo_url, sub_brand, confidential,
	 * admin_email).
	 *
	 * @var string
	 */
	const META_SETTINGS = '_nds_template_settings';

	/**
	 * Whether the post type has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the post type on init.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Email Templates', 'nvoos-design-system' ),
					'singular_name' => __( 'Email Template', 'nvoos-design-system' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title', 'editor', 'revisions' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Create (or reset) a template post.
	 *
	 * @param string $slug      Template slug (post_name).
	 * @param string $title     Human-readable title.
	 * @param string $html      Template HTML (post content).
	 * @param string $source    Source: 'builtin' | 'ai' | 'custom'.
	 * @param string $scope     Scope: 'full' | 'body'.
	 * @param array  $settings  Per-template settings.
	 * @param string $status    Post status ('publish' or 'draft').
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function upsert( $slug, $title, $html, $source = 'custom', $scope = 'full', $settings = array(), $status = 'publish' ) {
		$existing = self::get_by_slug( $slug );

		$postarr = array(
			'post_name'    => sanitize_title( $slug ),
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => $html,
			'post_status'  => 'publish' === $status ? 'publish' : 'draft',
			'post_type'    => self::POST_TYPE,
		);

		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'nds_email_template_insert_failed', __( 'Could not save the email template.', 'nvoos-design-system' ) );
		}

		update_post_meta( $post_id, self::META_SOURCE, sanitize_key( $source ) );
		update_post_meta( $post_id, self::META_SCOPE, 'body' === $scope ? 'body' : 'full' );
		update_post_meta( $post_id, self::META_SETTINGS, self::sanitize_settings( $settings ) );

		return (int) $post_id;
	}

	/**
	 * Look up a template post by slug.
	 *
	 * @param string $slug Template slug.
	 * @return WP_Post|null
	 */
	public static function get_by_slug( $slug ) {
		if ( empty( $slug ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'name'           => sanitize_title( $slug ),
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * List all template posts (published + drafts), newest first.
	 *
	 * @return WP_Post[]
	 */
	public static function get_all_templates() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 50,
				'orderby'        => array(
					'post_status' => 'ASC',
					'date'        => 'DESC',
				),
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Get a template's source meta.
	 *
	 * @param int $post_id Template post ID.
	 * @return string 'builtin' | 'ai' | 'custom'.
	 */
	public static function get_source( $post_id ) {
		$source = get_post_meta( $post_id, self::META_SOURCE, true );
		return in_array( $source, array( 'builtin', 'ai', 'custom' ), true ) ? $source : 'custom';
	}

	/**
	 * Get a template's scope meta.
	 *
	 * @param int $post_id Template post ID.
	 * @return string 'full' | 'body'.
	 */
	public static function get_scope( $post_id ) {
		$scope = get_post_meta( $post_id, self::META_SCOPE, true );
		return 'body' === $scope ? 'body' : 'full';
	}

	/**
	 * Get a template's per-template settings (with defaults).
	 *
	 * @param int $post_id Template post ID.
	 * @return array{logo_url: string, sub_brand: string, confidential: string, admin_email: string}
	 */
	public static function get_settings( $post_id ) {
		$saved = get_post_meta( $post_id, self::META_SETTINGS, true );

		$defaults = array(
			'logo_url'     => '',
			'sub_brand'    => '',
			'confidential' => '',
			'admin_email'  => '',
		);

		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		return array_merge( $defaults, self::sanitize_settings( $saved ) );
	}

	/**
	 * Sanitize per-template settings.
	 *
	 * @param array $settings Raw settings.
	 * @return array{logo_url: string, sub_brand: string, confidential: string, admin_email: string}
	 */
	private static function sanitize_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'logo_url'     => isset( $settings['logo_url'] ) ? esc_url_raw( $settings['logo_url'] ) : '',
			'sub_brand'    => isset( $settings['sub_brand'] ) ? sanitize_text_field( $settings['sub_brand'] ) : '',
			'confidential' => isset( $settings['confidential'] ) ? sanitize_text_field( $settings['confidential'] ) : '',
			'admin_email'  => isset( $settings['admin_email'] ) ? sanitize_email( $settings['admin_email'] ) : '',
		);
	}
}
