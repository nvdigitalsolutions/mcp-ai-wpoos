<?php
/**
 * NV oOS Design System — Email Template Registry
 *
 * Resolves the active email template and provides template lookup for the
 * wrapper, admin picker, and AI tools.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template registry.
 *
 * Built-in templates ship as static HTML files (emails/templates/*.html) and
 * are materialised as CPT posts on seeding. The active template is stored by
 * slug in the nvoos_nds_active_email_template option.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Template_Registry {

	/**
	 * Built-in template catalogue.
	 *
	 * slug => array( file, title, description, scope )
	 *
	 * @var array<string, array{file: string, title: string, description: string, scope: string}>
	 */
	private static $builtins = array(
		'letterhead'         => array(
			'file'        => 'letterhead.html',
			'title'       => 'Letterhead',
			'description' => 'Brand letterhead with dark header band, gold rule, serif type, and a confidential footer. Ideal for general brand emails.',
			'scope'       => 'full',
		),
		'minimal'            => array(
			'file'        => 'minimal.html',
			'title'       => 'Minimal',
			'description' => 'Clean single-column layout. The safest choice across email clients.',
			'scope'       => 'full',
		),
		'transactional'      => array(
			'file'        => 'transactional.html',
			'title'       => 'Transactional',
			'description' => 'Compact, high-contrast layout for notifications, receipts, and alerts.',
			'scope'       => 'full',
		),
		'newsletter'         => array(
			'file'        => 'newsletter.html',
			'title'       => 'Newsletter',
			'description' => 'Multi-section layout with a heading, dividers, and a call-to-action button for digests and campaigns.',
			'scope'       => 'full',
		),
		'ecommerce-receipt'  => array(
			'file'        => 'ecommerce-receipt.html',
			'title'       => 'Ecommerce Receipt',
			'description' => 'Order-style layout with a totals table. WooCommerce-aware logo fallback.',
			'scope'       => 'full',
		),
	);

	/**
	 * Get the built-in template catalogue.
	 *
	 * @return array<string, array{file: string, title: string, description: string, scope: string}>
	 */
	public static function get_builtins() {
		return self::$builtins;
	}

	/**
	 * Read a built-in template file.
	 *
	 * @param string $slug Built-in template slug.
	 * @return string Template HTML, or empty string when the file is missing.
	 */
	public static function get_builtin_html( $slug ) {
		if ( ! isset( self::$builtins[ $slug ] ) ) {
			return '';
		}

		$file = NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/templates/' . self::$builtins[ $slug ]['file'];

		if ( ! file_exists( $file ) ) {
			return '';
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local template read with the WP Filesystem abstraction where available.
		return $wp_filesystem->get_contents( $file );
	}

	/**
	 * Get the active template HTML.
	 *
	 * Resolution order:
	 *   1. nds_email_active_template filter
	 *   2. nvoos_nds_active_email_template option
	 *   3. 'letterhead' fallback
	 *
	 * The stored slug resolves to a published CPT post first, then to the
	 * built-in static file as a fallback (covers pre-seed and standalone
	 * contexts).
	 *
	 * @return array{html: string, slug: string, settings: array}|null Template payload, or null when unresolvable.
	 */
	public static function get_active() {
		$slug = (string) apply_filters( 'nds_email_active_template', get_option( NV_oOS_Design_System_Plugin::EMAIL_ACTIVE_TEMPLATE_KEY, 'letterhead' ) );
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			$slug = 'letterhead';
		}

		$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( $slug );

		if ( $post instanceof WP_Post && 'publish' === $post->post_status && '' !== trim( $post->post_content ) ) {
			return array(
				'html'     => $post->post_content,
				'slug'     => $slug,
				'settings' => NV_oOS_Design_System_Email_Template_CPT::get_settings( $post->ID ),
			);
		}

		$builtin = self::get_builtin_html( $slug );
		if ( '' !== trim( $builtin ) ) {
			return array(
				'html'     => $builtin,
				'slug'     => $slug,
				'settings' => array(
					'logo_url'     => '',
					'sub_brand'    => '',
					'confidential' => '',
					'admin_email'  => '',
				),
			);
		}

		return null;
	}

	/**
	 * Activate a template by slug, gated by publish status.
	 *
	 * @param string $slug  Template slug.
	 * @param bool   $force Allow activating a draft (explicit override).
	 * @return true|\WP_Error True on success.
	 */
	public static function set_active( $slug, $force = false ) {
		$slug = sanitize_title( $slug );

		$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( $slug );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'nds_email_template_not_found', __( 'The selected email template does not exist.', 'nvoos-design-system' ) );
		}

		if ( 'publish' !== $post->post_status && ! $force ) {
			return new WP_Error(
				'nds_email_template_draft',
				__( 'Draft templates cannot be activated. Review and publish the template first, or pass force=true.', 'nvoos-design-system' )
			);
		}

		update_option( NV_oOS_Design_System_Plugin::EMAIL_ACTIVE_TEMPLATE_KEY, $slug, false );

		return true;
	}

	/**
	 * Get the currently active template slug.
	 *
	 * @return string
	 */
	public static function get_active_slug() {
		$slug = (string) apply_filters( 'nds_email_active_template', get_option( NV_oOS_Design_System_Plugin::EMAIL_ACTIVE_TEMPLATE_KEY, 'letterhead' ) );
		return sanitize_title( $slug ) ? sanitize_title( $slug ) : 'letterhead';
	}
}
