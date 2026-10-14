<?php
/**
 * Pro SPA Surfaces Controller — Reports which hosted SPA surfaces are active.
 *
 * Serves the authoritative list of installed-and-activated NV oOS SPA addons
 * to headless clients (the nvoos-dictate desktop app) so they can render one
 * sub-tab per available surface. Each entry includes the addon's id, title,
 * and a mountable URL (its front-end shortcode page or a host origin).
 *
 * A surface is only reported when its addon is genuinely available on THIS
 * site: the controller probes the addon's canonical class / shortcode
 * (class_exists() / shortcode_exists()), never just "is the plugin folder
 * present". Inactive or missing addons are omitted entirely.
 *
 * @package NV_oOS_Pro
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Surfaces_Controller
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Pro_SPA_Surfaces_Controller {

	/**
	 * REST namespace.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const ROUTE = '/spa-surfaces';

	/**
	 * Register the route.
	 *
	 * @since 2.2.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_surfaces' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * Read-only discovery of which SPA addons are installed. This must work
	 * for the headless dictate app, which authenticates with an assistant
	 * bearer credential (no WordPress cookie). Accept an authenticated user
	 * (cookie/application-password), a valid assistant credential, or a raw
	 * credential header — mirroring the base REST layer. Guests cannot infer
	 * anything sensitive: the response only names installed addons, nothing
	 * per-user, but keep it credential-gated for consistency with the SPA
	 * discovery model.
	 *
	 * @since 2.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) {
		// Authenticated WordPress users with at least 'read' are always good.
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}

		// Otherwise require a valid assistant credential (Bearer cred_xxx.SECRET
		// or raw cred_xxx.SECRET) via the base credential manager's public
		// static validator — the same contract the dictate app's bearer auth
		// already satisfies against /wp-json/mcp-ai/v1/assistants.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$header = $request->get_header( 'Authorization' );
			$token  = '';
			if ( ! empty( $header ) && preg_match( '/^Bearer\s+(.*)$/i', $header, $matches ) ) {
				$token = trim( $matches[1] );
			} elseif ( ! empty( $header ) && apply_filters( 'wp_mcp_ai_accept_raw_credential_header', true ) ) {
				$token = trim( $header );
			}

			if ( '' !== $token && WP_MCP_AI_Credentials::is_token_format( $token ) ) {
				$validated = WP_MCP_AI_Credentials::validate_token( $token );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				return true;
			}
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'A valid assistant credential is required to list SPA surfaces.', 'mcp-ai-wpoos' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * List the active SPA surfaces.
	 *
	 * @since 2.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function list_surfaces( WP_REST_Request $request ) {
		unset( $request );

		$surfaces = self::detect_surfaces();

		return rest_ensure_response(
			array(
				'success'  => true,
				'surfaces' => $surfaces,
			)
		);
	}

	/**
	 * Detect the installed-and-activated SPA surfaces.
	 *
	 * Each entry is a {@see self::surface()} row keyed by a stable id.
	 * The base list is extendable via the `wp_mcp_ai_pro_spa_surfaces` filter so
	 * third-party addons can register their own surface without editing this
	 * controller.
	 *
	 * @since 2.2.0
	 *
	 * @return array<int, array> List of surface descriptors.
	 */
	public static function detect_surfaces() {
		$site_url = home_url( '/' );

		$surfaces = array();

		// 1. Home (Pro SPA v2) — the stable chat/assistant entry, always available
		//    in Pro (this addon). It is a route surface: the client prepends its
		//    own hosted-SPA base to `#/chat`, so it never triggers a full page
		//    load and can be pinned as the hub's Home tab.
		$surfaces[] = self::surface(
			'assistant',
			__( 'Home', 'mcp-ai-wpoos' ),
			$site_url,
			__( 'Hosted NV oOS Pro SPA chat surface.', 'mcp-ai-wpoos' ),
			'#/chat'
		);

		/**
		 * Declarative catalogue of the standalone hosted-SPA addons. Each row is
		 * one iframe-embeddable surface, reported only when its addon is actually
		 * installed-and-activated on this site (shortcode registered or class
		 * loaded). `shortcode` may be empty for standalone plugins that expose no
		 * shortcode (e.g. nvoos-content-graph) — detection then falls back to
		 * `class_exists()`. `route` (optional) marks a surface that lives inside
		 * the hosted Pro SPA routes rather than its own page; its URL is resolved
		 * against the Pro SPA front-end URL (see resolve_surface_url()).
		 *
		 * @var array<int, array{id:string, title:string, shortcode:string, class:string, route:string, description:string}>
		 */
		$catalog = array(
			array(
				'id'          => 'chat-spa',
				'title'       => __( 'Chat', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_chat_spa',
				'class'       => 'NV_oOS_Chat_Spa_Shortcode',
				'description' => __( 'Lightweight React chat surface (chat-spa addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'toolkit-shell',
				'title'       => __( 'Toolkit', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_toolkit_app',
				'class'       => 'NV_oOS_Toolkit_Shell_Shortcode',
				'description' => __( 'Manifest-driven toolkit SPA shell (toolkit-shell addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'docs-hub',
				'title'       => __( 'Docs', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_docs',
				'class'       => 'NV_oOS_Docs_Hub_Shortcode',
				'description' => __( 'Documentation hub (docs-hub addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'document-editor',
				'title'       => __( 'Document Editor', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_document_editor_app',
				'class'       => 'NV_oOS_Document_Editor_Shortcode',
				'description' => __( 'Document editor SPA (document-editor addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'canvas-toolkit',
				'title'       => __( 'Canvas', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_canvas_toolkit_app',
				'class'       => 'NV_oOS_Canvas_Toolkit_Shortcode',
				'description' => __( 'Canvas toolkit SPA (canvas-toolkit addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'media-studio',
				'title'       => __( 'Media Studio', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_media_studio_app',
				'class'       => 'NV_oOS_Media_Studio_Shortcode',
				'description' => __( 'Media Studio SPA (media-studio addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'cloudways-dashboard',
				'title'       => __( 'Cloudways', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_cloudways_dashboard',
				'class'       => 'NV_oOS_CloudwaysDashboard_Shortcode',
				'description' => __( 'Cloudways dashboard SPA (cloudways-dashboard addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'comic-reader',
				'title'       => __( 'Comics', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_comic_reader',
				'class'       => 'NV_oOS_Comic_Reader_Shortcode',
				'description' => __( 'Comic reader SPA (comic-reader addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'content-graph',
				'title'       => __( 'Content Graph', 'mcp-ai-wpoos' ),
				'shortcode'   => '',
				'class'       => 'NvoosContentGraph\Plugin',
				'route'       => '#/knowledge-graph',
				'description' => __( 'NV oOS Content Graph knowledge-graph explorer (nvoos-content-graph plugin).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'librechat',
				'title'       => __( 'LibreChat', 'mcp-ai-wpoos' ),
				'shortcode'   => 'nvoos_librechat',
				'class'       => 'NV_oOS_LibreChat_Shortcode',
				'description' => __( 'LibreChat embed (librechat addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'page-agent',
				'title'       => __( 'Page Agent', 'mcp-ai-wpoos' ),
				'shortcode'   => 'mcp_ai_page_agent',
				'class'       => 'WP_MCP_AI_Page_Agent_Widget',
				'description' => __( 'Page agent widget (page-agent addon).', 'mcp-ai-wpoos' ),
			),
			array(
				'id'          => 'algorave',
				'title'       => __( 'Algorave', 'mcp-ai-wpoos' ),
				'shortcode'   => 'algorave_live_coder',
				'class'       => 'NV_oOS_Algorave',
				'description' => __( 'Algorave live-coder / pattern library (algorave addon).', 'mcp-ai-wpoos' ),
			),
		);

		foreach ( $catalog as $addon ) {
			// Normalize optional keys so every entry has a defined shape.
			$addon += array(
				'shortcode' => '',
				'class'     => '',
				'route'     => '',
			);

			$active = false;
			if ( '' !== $addon['shortcode'] && shortcode_exists( $addon['shortcode'] ) ) {
				$active = true;
			} elseif ( '' !== $addon['class'] && class_exists( $addon['class'] ) ) {
				$active = true;
			}

			if ( ! $active ) {
				continue;
			}

			$url = self::resolve_surface_url( $addon, $site_url );
			if ( '' === $url ) {
				continue;
			}

			$surfaces[] = self::surface(
				$addon['id'],
				$addon['title'],
				$url,
				$addon['description'],
				isset( $addon['route'] ) ? $addon['route'] : ''
			);
		}

		/**
		 * Filter the detected SPA surfaces before they are returned.
		 *
		 * @since 2.2.0
		 *
		 * @param array<int, array> $surfaces List of surface descriptors.
		 */
		return apply_filters( 'wp_mcp_ai_pro_spa_surfaces', array_values( $surfaces ) );
	}

	/**
	 * Resolve the mountable URL for a single active surface.
	 *
	 * Resolution order, per catalog entry:
	 *   1. First published page/post whose content renders the surface's
	 *      shortcode (the "embed page" convention).
	 *   2. Site root as a last-resort fallback.
	 *
	 * Route surfaces (e.g. content-graph) carry a `route` fragment in their
	 * descriptor instead; the headless client prepends its own configured
	 * hosted-SPA URL to that fragment (the SPA URL lives client-side, not on
	 * the site).
	 *
	 * @since 2.2.0
	 *
	 * @param array  $addon    Catalog entry.
	 * @param string $site_url Site home URL (trailing slash).
	 * @return string Resolved URL.
	 */
	private static function resolve_surface_url( array $addon, $site_url ) {
		// First page rendering the shortcode.
		if ( ! empty( $addon['shortcode'] ) ) {
			$page_url = self::find_page_with_shortcode( $addon['shortcode'] );
			if ( '' !== $page_url ) {
				return $page_url;
			}
		}

		// Fall back to the site root (also the placeholder for route surfaces,
		// whose real URL the client derives from its own SPA URL + route).
		return $site_url;
	}

	/**
	 * Find the first published page/post whose content contains the shortcode.
	 *
	 * Scans published pages then posts, newest-first, for the literal shortcode
	 * tag. Results are memoized per shortcode in a short-lived transient to
	 * avoid a content scan on every discovery request.
	 *
	 * @since 2.2.0
	 *
	 * @param string $shortcode Shortcode tag (without brackets).
	 * @return string Permalink of the first matching post, or empty string.
	 */
	private static function find_page_with_shortcode( $shortcode ) {
		$cache_key = 'wp_mcp_ai_pro_spa_page_' . $shortcode;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_string( $cached ) ? $cached : '';
		}

		$found   = '';
		$needle  = '[' . $shortcode;
		$types   = array( 'page', 'post' );
		$scanned = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		foreach ( $scanned as $post_id ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( is_string( $content ) && false !== strpos( $content, $needle ) ) {
				$found = get_permalink( $post_id );
				break;
			}
		}

		set_transient( $cache_key, $found, HOUR_IN_SECONDS );

		return is_string( $found ) ? $found : '';
	}

	/**
	 * Build a single surface descriptor.
	 *
	 * @since 2.2.0
	 *
	 * @param string $id          Stable surface id.
	 * @param string $title       Human-readable title.
	 * @param string $url         Mountable URL for the surface (a page permalink
	 *                            or the site root placeholder for route surfaces).
	 * @param string $description Optional description.
	 * @param string $route       Optional hash-route fragment for SPA-internal
	 *                            surfaces (empty for page-based surfaces).
	 * @return array<string, string> Surface descriptor.
	 */
	private static function surface( $id, $title, $url, $description = '', $route = '' ) {
		$surface = array(
			'id'          => $id,
			'title'       => $title,
			'url'         => $url,
			'description' => $description,
		);

		if ( '' !== $route ) {
			$surface['route'] = $route;
		}

		return $surface;
	}
}
