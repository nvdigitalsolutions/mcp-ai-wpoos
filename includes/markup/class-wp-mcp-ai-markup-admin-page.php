<?php
/**
 * Admin fallback page for MCP URL-mode elicitation.
 *
 * Mounted at `?page=wp-mcp-ai-markup&request=<id>` and used by:
 *
 *  - Older Elementor widget contexts where the inline canvas cannot
 *    be hosted (very small bubbles, embedded iframes).
 *  - Screen readers that benefit from a full-page surface.
 *  - External MCP hosts (Claude Desktop, Cursor, VS Code agent mode)
 *    that follow the URL-mode elicitation channel introduced in MCP
 *    spec 2025-11-25.
 *
 * The page reuses the same widget JS bundle the chat surface uses.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Markup_Admin_Page
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_Admin_Page {

	const MENU_SLUG = 'wp-mcp-ai-markup';

	/**
	 * Hook the menu and asset registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Register the (hidden) admin submenu page.
	 *
	 * The page is hidden from the menu (parent_slug = null) so it does
	 * not pollute the admin sidebar — it is only reachable via the
	 * URL-mode fallback link.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'', // Hidden — no parent menu.
			__( 'Markup Editor', 'mcp-ai-wpoos' ),
			__( 'Markup Editor', 'mcp-ai-wpoos' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the markup widget assets only on this admin page.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public function maybe_enqueue_assets( $hook_suffix ) {
		// Hidden submenu hook suffix shape: `admin_page_{slug}`.
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}
		if ( class_exists( 'WP_MCP_AI_Markup_Assets' ) ) {
			WP_MCP_AI_Markup_Assets::enqueue_widget();
		}
	}

	/**
	 * Render the editor surface.
	 *
	 * Outputs a host element with a `data-markup-host` attribute the
	 * widget loader will hydrate once the request payload has been
	 * fetched. The page also localizes the REST endpoints and a
	 * fresh nonce so the widget can submit back.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to use the markup editor.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request_id is a non-mutating identifier read from the elicitation URL.
		$request_id = isset( $_GET['request'] ) ? sanitize_text_field( wp_unslash( $_GET['request'] ) ) : '';

		// Tighten to the same character class the REST controller accepts.
		if ( '' !== $request_id && ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $request_id ) ) {
			$request_id = '';
		}

		// Server-side ownership check: only render the editor scaffold when
		// the request exists in the store *and* belongs to the current user.
		// This prevents low-entropy request IDs from being enumerated through
		// the rendered HTML — invalid IDs and IDs belonging to other users
		// produce the same "not found" view. The downstream REST handler is
		// still the authoritative gate for actual reads/writes.
		$request_status = '';
		if ( '' !== $request_id && class_exists( 'WP_MCP_AI_Markup_Store' ) ) {
			$store     = new WP_MCP_AI_Markup_Store();
			$persisted = $store->get( $request_id );
			if ( ! $persisted instanceof WP_MCP_AI_Markup_Request ) {
				$request_status = 'not_found';
			} elseif ( $persisted->get_user_id() > 0 &&
						(int) $persisted->get_user_id() !== get_current_user_id() ) {
				// Belongs to another user — render the same "not found" view to avoid leaking ownership.
				$request_status = 'not_found';
			}
		}

		$config = array(
			'requestId' => $request_id,
			'fetchUrl'  => '' === $request_id ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/markup/' . rawurlencode( $request_id ) ) ),
			'submitUrl' => '' === $request_id ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/markup/' . rawurlencode( $request_id ) . '/submit' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'strings'   => array(
				'pageTitle'  => __( 'Markup Editor', 'mcp-ai-wpoos' ),
				'loading'    => __( 'Loading markup request…', 'mcp-ai-wpoos' ),
				'notFound'   => __( 'Markup request not found, expired, or already submitted.', 'mcp-ai-wpoos' ),
				'missingId'  => __( 'No request ID provided.', 'mcp-ai-wpoos' ),
				'submitted'  => __( 'Markup submitted. You may close this tab.', 'mcp-ai-wpoos' ),
				'cancelled'  => __( 'Markup cancelled.', 'mcp-ai-wpoos' ),
				'fetchError' => __( 'Could not load markup request.', 'mcp-ai-wpoos' ),
			),
		);
		wp_add_inline_script(
			WP_MCP_AI_Markup_Assets::HANDLE_CLIENT,
			'window.wpMcpAiMarkupAdmin = ' . wp_json_encode( $config ) . ';',
			'before'
		);
		?>
		<div class="wrap wp-mcp-ai-markup-admin">
			<h1><?php echo esc_html( $config['strings']['pageTitle'] ); ?></h1>
			<?php if ( '' === $request_id ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $config['strings']['missingId'] ); ?></p></div>
			<?php elseif ( 'not_found' === $request_status ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $config['strings']['notFound'] ); ?></p></div>
			<?php else : ?>
				<div id="wp-mcp-ai-markup-admin-status" class="notice notice-info">
					<p><?php echo esc_html( $config['strings']['loading'] ); ?></p>
				</div>
				<div id="wp-mcp-ai-markup-admin-host"
					data-markup-host="<?php echo esc_attr( $request_id ); ?>"
					class="wp-mcp-ai-markup-admin__host"></div>
				<?php
				ob_start();
				?>
					(function () {
						if ( ! window.wpMcpAiMarkupAdmin || ! window.wpMcpAiMarkupAdmin.fetchUrl ) {
							return;
						}
						var cfg  = window.wpMcpAiMarkupAdmin;
						var host = document.getElementById( 'wp-mcp-ai-markup-admin-host' );
						var status = document.getElementById( 'wp-mcp-ai-markup-admin-status' );
						var ready = function () {
							return window.WPMcpAiMarkupClient && window.WPMcpAiMarkupWidget;
						};
						var init = function () {
							if ( ! ready() ) {
								window.setTimeout( init, 50 );
								return;
							}
							window.fetch( cfg.fetchUrl, {
								credentials: 'same-origin',
								headers: { 'X-WP-Nonce': cfg.nonce }
							} ).then( function ( r ) {
								if ( ! r.ok ) {
									throw new Error( 'http_' + r.status );
								}
								return r.json();
							} ).then( function ( payload ) {
								if ( ! payload || ! payload.request_id ) {
									throw new Error( 'invalid_payload' );
								}
								// Inject submit_url / fallback_url if the GET response omits them.
								payload.submit_url   = payload.submit_url   || cfg.submitUrl;
								payload.fallback_url = payload.fallback_url || window.location.href;
								payload.type = 'markup_elicitation';
								if ( status && status.parentNode ) { status.parentNode.removeChild( status ); }
								window.WPMcpAiMarkupClient.handleToolResult( payload, host );
								document.addEventListener( 'wp-mcp-ai-markup:resolved', function () {
									var msg = document.createElement( 'div' );
									msg.className = 'notice notice-success';
									msg.innerHTML = '<p>' + ( cfg.strings.submitted || 'Submitted.' ) + '</p>';
									host.parentNode.insertBefore( msg, host );
								} );
							} ).catch( function () {
								if ( status ) {
									status.className = 'notice notice-error';
									status.innerHTML = '<p>' + ( cfg.strings.notFound || 'Not found.' ) + '</p>';
								}
							} );
						};
						init();
					}());
					<?php
					$js = ob_get_clean();
					wp_print_inline_script_tag( $js );
					?>
			<?php endif; ?>
		</div>
		<?php
	}
}
