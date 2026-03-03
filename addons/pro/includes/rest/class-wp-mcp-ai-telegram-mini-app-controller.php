<?php
/**
* Telegram Mini App REST Controller
*
* Provides a dedicated Mini App URL (Telegram Web App) for BotFather configuration.
* This endpoint returns a standalone HTML page that integrates with Telegram's
* Web App JavaScript SDK, enabling the bot's "Open App" / menu button feature.
*
* Industry-standard Telegram Mini App features implemented:
*   - Full Telegram theme variable integration (all theme params)
*   - Tab navigation: Chat, History, About
*   - User header with avatar/initials from initDataUnsafe
*   - Telegram Back Button and Main Button integration
*   - HapticFeedback on all user interactions
*   - Conversation history from localStorage
*   - About page with share, bot info, quick actions
*   - Viewport/keyboard change handling (stable height)
*   - Native Telegram popup API instead of browser dialogs
*   - Share bot via switchInlineQuery
*   - Color scheme (dark/light) change handler
*   - Server-side initData HMAC-SHA256 validation endpoint
*
* BotFather configuration steps:
*   1. Copy the Mini App URL shown in the Telegram Configuration admin section.
*   2. In Telegram, open @BotFather and send /newapp (or /setmenubutton).
*   3. Select your bot and paste the Mini App URL when prompted.
*   4. Users can then tap the "Open App" button to launch the AI chat interface.
*
* @see https://core.telegram.org/bots/webapps
* @see https://core.telegram.org/bots/api#setmenubutton
*
* @package WP_MCP_AI_Pro
* @since 1.0.0
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
* Telegram Mini App REST controller.
*
* Registers:
*   GET  /mcp-ai/v1/telegram-mini-app          – Full Mini App HTML shell.
*   POST /mcp-ai/v1/telegram-mini-app/validate – Server-side initData verification.
*/
class WP_MCP_AI_Telegram_Mini_App_Controller extends WP_REST_Controller {

	/**
	* REST API namespace.
	*
	* @var string
	*/
	protected $namespace = 'mcp-ai/v1';

	/**
	* REST API endpoint base.
	*
	* @var string
	*/
	protected $rest_base = 'telegram-mini-app';

	/**
	* Maximum initData age accepted by the validate endpoint (seconds).
	* Telegram recommends rejecting data older than 24 hours.
	*/
	const INIT_DATA_MAX_AGE = 86400;

	/**
	* User meta key that stores the linked Telegram user ID.
	*/
	const META_TELEGRAM_ID = '_wp_mcp_ai_telegram_id';

	/**
	* User meta key that stores the Telegram username (without '@').
	*/
	const META_TELEGRAM_USERNAME = '_wp_mcp_ai_telegram_username';

	/**
	* Constructor – registers REST routes.
	*/
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Authenticate via TMA session token early so that WordPress's nonce
		// verification (rest_cookie_check_errors) sees the correct current user.
		// Without this hook the user-specific nonce returned by /validate is
		// rejected (403) when the auth cookie does not persist across fetch()
		// calls in Telegram's built-in WebView.
		add_filter( 'determine_current_user', array( $this, 'authenticate_via_tma_token' ), 20 );

		// Safety net: when Telegram's WebView *does* persist the auth cookie
		// but the nonce verification fails (e.g. session-token mismatch),
		// rest_cookie_check_errors (priority 100) returns a WP_Error with code
		// 'rest_cookie_invalid_nonce'.  This filter (priority 101) runs right
		// after and clears the error when a valid TMA session token is present.
		add_filter( 'rest_authentication_errors', array( $this, 'allow_tma_token_auth' ), 101 );
	}

	/**
	* Register REST routes.
	*
	* @since 1.0.0
	*/
	public function register_routes() {
		// Main Mini App page (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_mini_app' ),
				// Telegram Mini Apps require public access: this endpoint is opened
				// by Telegram's built-in browser on behalf of end users who may not
				// be authenticated WordPress users.
				'permission_callback' => '__return_true',
				'args'                => array(
					'assistant' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Optional assistant slug to pre-select in the chat interface.', 'mcp-ai-wpoos-pro' ),
					),
				),
			)
		);

		// initData validation endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_validate_init_data' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'init_data' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => array( $this, 'sanitize_init_data' ),
						'description'       => __( 'Raw initData string from window.Telegram.WebApp.initData.', 'mcp-ai-wpoos-pro' ),
					),
				),
			)
		);

		// Content (CPT posts) data endpoint (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_type' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'      => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
						'minimum'  => 1,
					),
					'per_page'  => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 20,
						'minimum'  => 1,
						'maximum'  => 100,
					),
					'search'    => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Content update / create endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/content',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_update_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'           => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					),
					'post_type'    => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'title'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content'      => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
					'status'       => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'draft',
						'sanitize_callback' => 'sanitize_key',
					),
					'date'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Tools & slash-commands data endpoint (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_tools' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Tool execution endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_execute_tool' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'slug'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'arguments' => array(
						'required' => false,
						'type'     => 'object',
						'default'  => array(),
					),
				),
			)
		);

		// Media library data endpoint (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_media' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page'     => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
						'minimum'  => 1,
					),
					'per_page' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 20,
						'minimum'  => 1,
						'maximum'  => 100,
					),
					'search'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'     => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// User settings endpoint (GET for loading, POST for saving).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_save_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'action' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Settings action: save_preferences, link_account, or unlink_account.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			)
		);

		// Analytics endpoint for Home tab dashboard (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'days' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 7,
						'minimum'  => 1,
						'maximum'  => 90,
					),
				),
			)
		);

		// Shop balance endpoint (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/shop/balance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_shop_balance' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// =========================================================================
	// Permission check for authenticated CMS endpoints
	// =========================================================================

	/**
	* Authenticate the current user via the TMA session token.
	*
	* Hooked into `determine_current_user` (priority 20) so that WordPress
	* resolves the correct user *before* the REST cookie/nonce check runs via
	* `rest_cookie_check_errors`.  Without this, the user-specific nonce
	* returned by /validate is rejected with a 403 when Telegram's built-in
	* WebView does not persist the auth cookie that was set during the
	* /validate call.  By identifying the user here, the nonce—which was
	* created for that user—passes verification and the subsequent
	* `check_permission` call returns true via `current_user_can('read')`.
	*
	* @since 1.0.0
	*
	* @param int|false $user_id Currently resolved user ID, or false.
	* @return int|false Authenticated user ID, or the original value.
	*/
	public function authenticate_via_tma_token( $user_id ) {
		// Do not override an already-authenticated user.
		if ( $user_id ) {
			return $user_id;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_token = isset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] )
			? wp_unslash( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] )
			: '';

		if ( '' === $raw_token ) {
			return $user_id;
		}

		$sanitized  = sanitize_text_field( $raw_token );

		// The token is always a 40-character lowercase hex string produced by
		// bin2hex(random_bytes(20)).  Reject anything that doesn't match.
		if ( ! preg_match( '/^[0-9a-f]{40}$/', $sanitized ) ) {
			return $user_id;
		}

		$token_hash = hash( 'sha256', $sanitized );

		$stored_user_id = get_transient( 'wp_mcp_ai_tma_' . $token_hash );
		if ( $stored_user_id ) {
			return (int) $stored_user_id;
		}

		return $user_id;
	}

	/**
	* Clear a cookie-nonce authentication error when a valid TMA token is present.
	*
	* WordPress's `rest_cookie_check_errors` (priority 100) returns a WP_Error
	* with code `rest_cookie_invalid_nonce` when the auth cookie is present but
	* the X-WP-Nonce header does not pass `wp_verify_nonce()`.  This can happen
	* in Telegram's WebView when the browser persists the auth cookie set by
	* the /validate endpoint but the nonce was generated before the cookie's
	* session token was written to `$_COOKIE`.  Rather than blocking the
	* request, we validate the TMA session token and, if it resolves a valid
	* user, clear the error so the request proceeds.
	*
	* @since 1.0.0
	*
	* @param WP_Error|mixed $result Current authentication result.
	* @return true|WP_Error|mixed Cleared result on success, or passthrough.
	*/
	public function allow_tma_token_auth( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_token = isset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] )
			? wp_unslash( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] )
			: '';

		if ( '' === $raw_token ) {
			return $result;
		}

		$sanitized = sanitize_text_field( $raw_token );

		if ( ! preg_match( '/^[0-9a-f]{40}$/', $sanitized ) ) {
			return $result;
		}

		$token_hash = hash( 'sha256', $sanitized );
		$user_id    = get_transient( 'wp_mcp_ai_tma_' . $token_hash );

		if ( ! $user_id ) {
			return $result;
		}

		wp_set_current_user( (int) $user_id );
		return true;
	}

	/**
	* Check that the current user can read content.
	*
	* Used as the permission_callback for the /content, /tools, and /media
	* sub-endpoints. These are read-only GET endpoints so the 'read'
	* capability is sufficient.  When the request originates from inside
	* Telegram's WebView the auth cookie set by wp_set_auth_cookie() during
	* the /validate call may not persist across fetch() requests.  In that
	* case we fall back to a short-lived TMA session token returned by the
	* /validate endpoint and sent via the X-WP-MCP-AI-TMA-Token header.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Current REST request.
	* @return bool True when the current user has the 'read' capability.
	*/
	public function check_permission( $request ) {
		// Standard cookie + nonce auth.
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		// Fallback: Telegram Mini App session token for WebView environments
		// where cookies from REST responses may not persist.
		$tma_token = $request->get_header( 'X-WP-MCP-AI-TMA-Token' );
		if ( ! empty( $tma_token ) ) {
			$sanitized = sanitize_text_field( $tma_token );
			// Tokens are 40-character lowercase hex strings (bin2hex(random_bytes(20))).
			if ( preg_match( '/^[0-9a-f]{40}$/', $sanitized ) ) {
				$token_hash = hash( 'sha256', $sanitized );
				$user_id    = get_transient( 'wp_mcp_ai_tma_' . $token_hash );
				if ( $user_id ) {
					wp_set_current_user( (int) $user_id );
					return current_user_can( 'read' );
				}
			}
		}

		return false;
	}

	// =========================================================================
	// Main Mini App page handler
	// =========================================================================

	/**
	* Handle the Telegram Mini App request.
	*
	* Returns a standalone HTML page with the Telegram Web App JavaScript SDK
	* and the AI chat interface wrapped in an industry-standard Mini App shell.
	* Telegram opens this page inside its built-in browser when the user taps
	* the bot's "Open App" / menu button.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return void Outputs the HTML page directly and exits.
	*/
	public function handle_mini_app( $request ) {
		// Resolve the active Telegram connection once; used both for assistant
		// lookup and for extracting the bot username shown in the Settings tab.
		$connection = $this->get_active_telegram_connection();

		// Collect styles and scripts enqueued by WordPress.
		ob_start();
		wp_head();
		$head_output = ob_get_clean();

		ob_start();
		wp_footer();
		$footer_output = ob_get_clean();

		/**
		* Filters the Mini App page title.
		*
		* @since 1.0.0
		*
		* @param string $title Default page title.
		*/
		$page_title = apply_filters( 'wp_mcp_ai_telegram_mini_app_title', get_bloginfo( 'name' ) );

		// Extract the bot username from the already-resolved connection.
		$bot_username = '';
		if ( $connection && ! empty( $connection['bot_username'] ) ) {
			$bot_username = ltrim( sanitize_text_field( $connection['bot_username'] ), '@' );
		}

		/**
		* Filters the bot username shown in the Mini App About tab.
		*
		* @since 1.0.0
		*
		* @param string $bot_username Bot username without '@'.
		* @param array  $connection   Active Telegram connection or empty array.
		*/
		$bot_username = apply_filters( 'wp_mcp_ai_telegram_mini_app_bot_username', $bot_username, $connection ? $connection : array() );

		$validate_url = rest_url( $this->namespace . '/' . $this->rest_base . '/validate' );
		$content_url  = rest_url( $this->namespace . '/' . $this->rest_base . '/content' );
		$tools_url    = rest_url( $this->namespace . '/' . $this->rest_base . '/tools' );
		$media_url    = rest_url( $this->namespace . '/' . $this->rest_base . '/media' );
		$settings_url  = rest_url( $this->namespace . '/' . $this->rest_base . '/settings' );
		$analytics_url = rest_url( $this->namespace . '/' . $this->rest_base . '/analytics' );
		$shop_url      = rest_url( $this->namespace . '/' . $this->rest_base . '/shop/balance' );
		$login_url     = wp_login_url( rest_url( $this->namespace . '/' . $this->rest_base ) );

		// Serve Chart.js from the local plugin bundle so the analytics
		// dashboard works reliably inside Telegram's WebView where CDN
		// requests can be blocked or fail SRI checks.
		$chart_js_url = esc_url( WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js' );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// Prevent Telegram WebView from caching stale versions of the Mini App
		// shell.  Without these headers the WebView may continue to serve a
		// previously fetched page even after the plugin has been updated.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone HTML page; individual values escaped inline.
		echo '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>' . esc_html( $page_title ) . '</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script src="' . $chart_js_url . '"></script>
<style>' . $this->get_mini_app_css() . '</style>
' . $head_output . '
</head>
<body class="wp-mcp-ai-telegram-mini-app">

<div class="tma-shell" id="tma-shell">

  <!-- ── Header ──────────────────────────────────────────────── -->
  <header class="tma-header">
    <div class="tma-avatar-wrap" id="tma-avatar-wrap">
      <img src="" alt="" class="tma-avatar-img" id="tma-avatar-img" style="display:none">
      <div class="tma-avatar-initials" id="tma-avatar-initials">📋</div>
    </div>
    <div class="tma-header-info">
      <div class="tma-header-name" id="tma-user-name">' . esc_html( $page_title ) . '</div>
      <div class="tma-header-status" id="tma-header-status">Content Manager</div>
    </div>
    <div class="tma-header-actions">
      <button class="tma-icon-btn" id="tma-search-btn" title="Search" onclick="tmaToggleSearch()">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </button>
    </div>
  </header>

  <!-- ── Search bar (hidden by default) ──────────────────────── -->
  <div class="tma-search-bar" id="tma-search-bar" style="display:none">
    <input type="search" id="tma-search-input" placeholder="Search…" autocomplete="off"
           oninput="tmaOnSearch(this.value)" />
  </div>

  <!-- ── Tab content ──────────────────────────────────────────── -->
  <div class="tma-content">

    <!-- Home tab (default) -->
    <div class="tma-tab-pane tma-active" id="tma-tab-home">
      <div class="tma-home-wrap" id="tma-home-wrap">
        <div class="tma-empty">Loading analytics…</div>
      </div>
    </div>

    <!-- Content tab -->
    <div class="tma-tab-pane" id="tma-tab-content">
      <div class="tma-cpt-bar" id="tma-cpt-bar">
        <div class="tma-cpt-scroll" id="tma-cpt-scroll">
          <div class="tma-empty">Loading types…</div>
        </div>
      </div>
      <div class="tma-content-actions" id="tma-content-actions">
        <button class="tma-btn-new-post" onclick="tmaNewPost()">+ New</button>
      </div>
      <div class="tma-post-list" id="tma-post-list">
        <div class="tma-empty">Loading content…</div>
      </div>
      <div class="tma-pagination" id="tma-content-pagination"></div>
      <!-- Inline editor overlay -->
      <div class="tma-editor-overlay" id="tma-editor-overlay" style="display:none">
        <div class="tma-editor-panel">
          <div class="tma-editor-header">
            <button class="tma-editor-close" onclick="tmaCloseEditor()">✕</button>
            <span class="tma-editor-title" id="tma-editor-heading">Edit Post</span>
            <button class="tma-editor-fullscreen" onclick="tmaToggleFullscreen()" title="Toggle fullscreen">⛶</button>
          </div>
          <div class="tma-editor-body">
            <input type="hidden" id="tma-editor-id" value="0" />
            <input type="hidden" id="tma-editor-post-type" value="post" />
            <div class="tma-editor-field">
              <label class="tma-editor-label" for="tma-editor-post-title">Title</label>
              <input type="text" id="tma-editor-post-title" class="tma-editor-input" placeholder="Post title…" />
            </div>
            <div class="tma-editor-field">
              <label class="tma-editor-label" for="tma-editor-post-content">Content</label>
              <textarea id="tma-editor-post-content" class="tma-editor-textarea" rows="8" placeholder="Write your content…"></textarea>
            </div>
            <div class="tma-editor-field">
              <label class="tma-editor-label" for="tma-editor-post-status">Status</label>
              <select id="tma-editor-post-status" class="tma-editor-select" onchange="tmaOnStatusChange(this.value)">
                <option value="draft">Draft</option>
                <option value="publish">Published</option>
                <option value="pending">Pending Review</option>
                <option value="future">Scheduled</option>
              </select>
            </div>
            <div class="tma-editor-field" id="tma-editor-schedule-field" style="display:none">
              <label class="tma-editor-label" for="tma-editor-schedule-date">Schedule Date</label>
              <input type="datetime-local" id="tma-editor-schedule-date" class="tma-editor-input" />
            </div>
            <div id="tma-editor-error" class="tma-editor-error" style="display:none"></div>
            <div class="tma-editor-actions">
              <button class="tma-settings-btn tma-btn-secondary" onclick="tmaCloseEditor()">Cancel</button>
              <button class="tma-settings-btn tma-btn-primary" id="tma-editor-save" onclick="tmaSavePost()">Save</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tools tab -->
    <div class="tma-tab-pane" id="tma-tab-tools">
      <div class="tma-section-title">Available Tools</div>
      <div id="tma-toolkit-filter" class="tma-filter-bar"></div>
      <div id="tma-tools-list" class="tma-cards-list">
        <div class="tma-empty">Loading tools…</div>
      </div>
      <!-- Tool execution overlay -->
      <div class="tma-editor-overlay" id="tma-tool-exec-overlay" style="display:none">
        <div class="tma-editor-panel">
          <div class="tma-editor-header">
            <button class="tma-editor-close" onclick="tmaCloseToolExec()">✕</button>
            <span class="tma-editor-title" id="tma-tool-exec-heading">Execute Tool</span>
          </div>
          <div class="tma-editor-body">
            <div id="tma-tool-exec-form"></div>
            <div id="tma-tool-exec-error" class="tma-editor-error" style="display:none"></div>
            <div class="tma-editor-actions">
              <button class="tma-settings-btn tma-btn-secondary" onclick="tmaCloseToolExec()">Cancel</button>
              <button class="tma-settings-btn tma-btn-primary" id="tma-tool-exec-run" onclick="tmaRunTool()">▶ Run</button>
            </div>
            <div id="tma-tool-exec-result" class="tma-tool-result" style="display:none"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Commands tab -->
    <div class="tma-tab-pane" id="tma-tab-commands">
      <div class="tma-section-title">Slash Commands</div>
      <div id="tma-slash-list" class="tma-cards-list">
        <div class="tma-empty">Loading commands…</div>
      </div>
    </div>

    <!-- Media tab -->
    <div class="tma-tab-pane" id="tma-tab-media">
      <div class="tma-filter-bar" id="tma-media-filter">
        <button class="tma-filter-btn tma-active" data-mime="" onclick="tmaMediaFilter(this,\'\')">All</button>
        <button class="tma-filter-btn" data-mime="image" onclick="tmaMediaFilter(this,\'image\')">Images</button>
        <button class="tma-filter-btn" data-mime="video" onclick="tmaMediaFilter(this,\'video\')">Video</button>
        <button class="tma-filter-btn" data-mime="audio" onclick="tmaMediaFilter(this,\'audio\')">Audio</button>
        <button class="tma-filter-btn" data-mime="application" onclick="tmaMediaFilter(this,\'application\')">Docs</button>
      </div>
      <div class="tma-media-grid" id="tma-media-grid">
        <div class="tma-empty">Loading media…</div>
      </div>
      <div class="tma-pagination" id="tma-media-pagination"></div>
    </div>

    <!-- Shop tab -->
    <div class="tma-tab-pane" id="tma-tab-shop">
      <div class="tma-shop-wrap" id="tma-shop-wrap">
        <div class="tma-empty">Loading shop…</div>
      </div>
    </div>

    <!-- Settings tab -->
    <div class="tma-tab-pane" id="tma-tab-settings">
      <div class="tma-settings-wrap" id="tma-settings-wrap">
        <div class="tma-empty">Loading settings…</div>
      </div>
    </div>

  </div><!-- /.tma-content -->

  <!-- ── Bottom navigation ────────────────────────────────────── -->
  <nav class="tma-nav" role="navigation" aria-label="Tabs">
    <button class="tma-nav-btn tma-active" id="tma-nav-home" data-tab="home" onclick="tmaSwitchTab(\'home\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span class="tma-nav-label">Home</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-content" data-tab="content" onclick="tmaSwitchTab(\'content\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
      <span class="tma-nav-label">Content</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-tools" data-tab="tools" onclick="tmaSwitchTab(\'tools\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      <span class="tma-nav-label">Tools</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-commands" data-tab="commands" onclick="tmaSwitchTab(\'commands\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
      <span class="tma-nav-label">Commands</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-media" data-tab="media" onclick="tmaSwitchTab(\'media\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <span class="tma-nav-label">Media</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-shop" data-tab="shop" onclick="tmaSwitchTab(\'shop\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
      <span class="tma-nav-label">Shop</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-settings" data-tab="settings" onclick="tmaSwitchTab(\'settings\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      <span class="tma-nav-label">Settings</span>
    </button>
  </nav>

</div><!-- /.tma-shell -->

' . $footer_output . '
<script>
/* =========================================================
   NV oOS – Telegram Mini App Shell (CMS Edition)
   Content, Tools, Media & Settings management for Telegram.
   ========================================================= */
(function () {
  \'use strict\';

  /* ── Config injected by PHP ── */
  var TMA_BOT_USERNAME   = ' . wp_json_encode( $bot_username ) . ';
  var TMA_VALIDATE_URL   = ' . wp_json_encode( $validate_url ) . ';
  var TMA_CONTENT_URL    = ' . wp_json_encode( $content_url ) . ';
  var TMA_TOOLS_URL      = ' . wp_json_encode( $tools_url ) . ';
  var TMA_TOOLS_EXEC_URL = ' . wp_json_encode( $tools_url . '/execute' ) . ';
  var TMA_MEDIA_URL      = ' . wp_json_encode( $media_url ) . ';
  var TMA_SETTINGS_URL   = ' . wp_json_encode( $settings_url ) . ';
  var TMA_ANALYTICS_URL  = ' . wp_json_encode( $analytics_url ) . ';
  var TMA_SHOP_URL       = ' . wp_json_encode( $shop_url ) . ';
  var TMA_LOGIN_URL      = ' . wp_json_encode( $login_url ) . ';
  var TMA_SITE_NAME      = ' . wp_json_encode( get_bloginfo( 'name' ) ) . ';
  var TMA_NONCE          = ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';
  var TMA_SESSION_TOKEN  = null;
  var TMA_STORAGE_PREFIX = \'wp_mcp_ai_chat_\';

  /* ── Telegram WebApp SDK ── */
  var twa = (window.Telegram && window.Telegram.WebApp) ? window.Telegram.WebApp : null;

  /* ── Apply Telegram theme variables ── */
  function applyTheme() {
    if (!twa) return;
    var tp  = twa.themeParams || {};
    var r   = document.documentElement;
    var map = {
      \'--tma-bg\'            : tp.bg_color,
      \'--tma-text\'          : tp.text_color,
      \'--tma-hint\'          : tp.hint_color,
      \'--tma-link\'          : tp.link_color,
      \'--tma-btn\'           : tp.button_color,
      \'--tma-btn-text\'      : tp.button_text_color,
      \'--tma-secondary-bg\'  : tp.secondary_bg_color,
      \'--tma-header-bg\'     : tp.header_bg_color,
      \'--tma-accent\'        : tp.accent_text_color,
      \'--tma-section-bg\'    : tp.section_bg_color,
      \'--tma-section-header\': tp.section_header_text_color,
      \'--tma-subtitle\'      : tp.subtitle_text_color,
      \'--tma-destructive\'   : tp.destructive_text_color,
    };
    Object.keys(map).forEach(function (prop) {
      if (map[prop]) r.style.setProperty(prop, map[prop]);
    });
    if (tp.bg_color) document.body.style.background = tp.bg_color;
  }

  /* ── Viewport height management ── */
  function updateViewport() {
    var h = twa ? twa.viewportStableHeight : window.innerHeight;
    document.documentElement.style.setProperty(\'--tma-vh\', h + \'px\');
  }

  /* ── Tab state ── */
  var activeTab         = \'home\';
  var contentPage       = 1;
  var contentPostType   = \'post\';
  var contentSearch     = \'\';
  var mediaPage         = 1;
  var mediaMimeFilter   = \'\';
  var mediaSearch       = \'\';
  var toolkitFilter     = \'\';
  var allTools          = [];
  var allSlashCmds      = [];
  var searchTimeout     = null;
  var homeDays          = 7;
  var authRetried       = false;

  /* ── Public: switch tabs ── */
  window.tmaSwitchTab = function (tabName) {
    if (tabName === activeTab) return;
    haptic(\'selectionChanged\');
    document.querySelectorAll(\'.tma-tab-pane\').forEach(function (el) {
      el.classList.remove(\'tma-active\');
    });
    document.querySelectorAll(\'.tma-nav-btn\').forEach(function (el) {
      el.classList.remove(\'tma-active\');
    });
    var pane = document.getElementById(\'tma-tab-\' + tabName);
    var btn  = document.getElementById(\'tma-nav-\' + tabName);
    if (pane) pane.classList.add(\'tma-active\');
    if (btn)  btn.classList.add(\'tma-active\');
    activeTab = tabName;
    updateBackButton();
    updateMainButton();
    /* Lazy-load tab data on first visit */
    if (tabName === \'home\')    loadHome();
    if (tabName === \'content\') loadContent();
    if (tabName === \'tools\')   loadTools();
    if (tabName === \'commands\') loadCommands();
    if (tabName === \'media\')   loadMedia();
    if (tabName === \'shop\')    loadShop();
    if (tabName === \'settings\') loadSettings();
    /* Reset search bar context */
    clearSearch();
  };

  /* ── Back Button ── */
  function updateBackButton() {
    if (!twa || !twa.BackButton) return;
    if (activeTab === \'home\') {
      twa.BackButton.hide();
    } else {
      twa.BackButton.show();
    }
  }

  /* ── Main Button ── */
  function updateMainButton() {
    if (!twa || !twa.MainButton) return;
    twa.MainButton.hide();
  }

  /* ── Authenticated fetch helper ── */
  function authFetch(url) {
    var h = { \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    return fetch(url, {
      credentials : \'same-origin\',
      headers     : h,
    });
  }

  /* ── HTML escaping ── */
  function escHtml(str) {
    var d = document.createElement(\'div\');
    d.textContent = String(str || \'\');
    return d.innerHTML;
  }

  /* ── Search bar toggle ── */
  window.tmaToggleSearch = function () {
    haptic(\'light\');
    var bar = document.getElementById(\'tma-search-bar\');
    if (!bar) return;
    var visible = bar.style.display !== \'none\';
    bar.style.display = visible ? \'none\' : \'flex\';
    if (!visible) {
      var inp = document.getElementById(\'tma-search-input\');
      if (inp) { inp.value = \'\'; inp.focus(); }
    } else {
      clearSearch();
    }
  };

  function clearSearch() {
    contentSearch = \'\';
    mediaSearch   = \'\';
    var bar = document.getElementById(\'tma-search-bar\');
    if (bar) bar.style.display = \'none\';
    var inp = document.getElementById(\'tma-search-input\');
    if (inp) inp.value = \'\';
  }

  window.tmaOnSearch = function (val) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function () {
      if (activeTab === \'content\') {
        contentSearch = val;
        contentPage   = 1;
        loadContent();
      } else if (activeTab === \'media\') {
        mediaSearch = val;
        mediaPage   = 1;
        loadMedia();
      } else if (activeTab === \'tools\') {
        renderTools(val);
      } else if (activeTab === \'commands\') {
        renderSlashCommands(val);
      }
    }, 300);
  };

  /* =========================================================
     HOME TAB – Analytics Dashboard
     ========================================================= */
  var homeLoaded  = false;
  var homeCharts  = {};

  function loadHome() {
    if (homeLoaded) return;
    var wrap = document.getElementById(\'tma-home-wrap\');
    if (!wrap) return;
    wrap.innerHTML = \'<div class="tma-empty">Loading analytics…</div>\';

    authFetch(TMA_ANALYTICS_URL + \'?days=\' + homeDays)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadHome(); }).catch(function () { showLoginFallback(\'home\'); });
          }
          showLoginPrompt(\'home\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        homeLoaded = true;
        renderHome(data);
      })
      .catch(function () {
        if (wrap) wrap.innerHTML = \'<div class="tma-empty tma-error">Failed to load analytics.</div>\';
      });
  }

  function renderHome(data) {
    var wrap = document.getElementById(\'tma-home-wrap\');
    if (!wrap) return;
    var s = data.summary || {};
    var html = \'\';

    /* ── Date range picker ── */
    html += \'<div class="tma-date-range-bar">\';
    html += \'<button class="tma-range-btn\' + (homeDays === 7 ? \' tma-active\' : \'\') + \'" onclick="tmaSetDateRange(7)">7 days</button>\';
    html += \'<button class="tma-range-btn\' + (homeDays === 30 ? \' tma-active\' : \'\') + \'" onclick="tmaSetDateRange(30)">30 days</button>\';
    html += \'<button class="tma-range-btn\' + (homeDays === 90 ? \' tma-active\' : \'\') + \'" onclick="tmaSetDateRange(90)">90 days</button>\';
    html += \'</div>\';

    /* ── KPI cards row ── */
    html += \'<div class="tma-kpi-row">\';
    html += kpiCard(\'🪙\', \'Tokens\', formatNum(s.total_tokens || 0));
    html += kpiCard(\'💰\', \'Cost\', \'$\' + (s.total_cost || 0).toFixed(4));
    html += kpiCard(\'🛠️\', \'Tools\', formatNum(s.tools_used || 0));
    html += kpiCard(\'📨\', \'Requests\', formatNum(s.total_requests || 0));
    html += \'</div>\';

    /* ── Token usage trend chart ── */
    html += \'<div class="tma-chart-section">\';
    html += \'<div class="tma-chart-title">Token Usage (\' + homeDays + \' days)</div>\';
    html += \'<div class="tma-chart-wrap"><canvas id="tma-chart-tokens"></canvas></div>\';
    html += \'</div>\';

    /* ── Cost by provider chart ── */
    html += \'<div class="tma-chart-section">\';
    html += \'<div class="tma-chart-title">Cost by Provider</div>\';
    html += \'<div class="tma-chart-wrap tma-chart-doughnut"><canvas id="tma-chart-cost"></canvas></div>\';
    html += \'</div>\';

    /* ── Tool usage chart ── */
    html += \'<div class="tma-chart-section">\';
    html += \'<div class="tma-chart-title">Top Tools</div>\';
    html += \'<div class="tma-chart-wrap"><canvas id="tma-chart-tools"></canvas></div>\';
    html += \'</div>\';

    /* ── Per-toolkit usage breakdown ── */
    var toolsByToolkit = {};
    (data.by_tool || []).forEach(function (t) {
      var tkLabel = \'Ungrouped\';
      allTools.forEach(function (at) {
        if (at.slug === t.tool && at.toolkit) tkLabel = at.toolkit;
      });
      if (!toolsByToolkit[tkLabel]) toolsByToolkit[tkLabel] = 0;
      toolsByToolkit[tkLabel] += (t.total_tokens || 0);
    });
    var tkLabels = Object.keys(toolsByToolkit);
    if (tkLabels.length > 1) {
      html += \'<div class="tma-chart-section">\';
      html += \'<div class="tma-chart-title">Usage by Toolkit</div>\';
      html += \'<div class="tma-chart-wrap tma-chart-doughnut"><canvas id="tma-chart-toolkit"></canvas></div>\';
      html += \'</div>\';
    }

    /* ── Export button ── */
    html += \'<div style="padding:8px 12px"><button class="tma-btn-new-post" style="width:100%" onclick="tmaExportAnalytics()">📤 Export to Chat</button></div>\';

    wrap.innerHTML = html;

    /* ── Render charts with Chart.js ── */
    setTimeout(function () { renderHomeCharts(data); }, 50);
  }

  function kpiCard(icon, label, value) {
    return \'<div class="tma-kpi-card">\' +
      \'<div class="tma-kpi-icon">\' + icon + \'</div>\' +
      \'<div class="tma-kpi-value">\' + escHtml(value) + \'</div>\' +
      \'<div class="tma-kpi-label">\' + escHtml(label) + \'</div>\' +
    \'</div>\';
  }

  function formatNum(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + \'M\';
    if (n >= 1000) return (n / 1000).toFixed(1) + \'K\';
    return String(n);
  }

  function renderHomeCharts(data) {
    if (typeof Chart === \'undefined\') {
      document.querySelectorAll(\'.tma-chart-wrap\').forEach(function (el) {
        el.innerHTML = \'<div class="tma-empty" style="padding:12px;font-size:12px">Chart library unavailable.</div>\';
      });
      return;
    }
    var tmaColors = {
      primary  : getComputedStyle(document.documentElement).getPropertyValue(\'--tma-btn\').trim() || \'#2481cc\',
      accent   : getComputedStyle(document.documentElement).getPropertyValue(\'--tma-accent\').trim() || \'#2481cc\',
      text     : getComputedStyle(document.documentElement).getPropertyValue(\'--tma-text\').trim() || \'#000\',
      hint     : getComputedStyle(document.documentElement).getPropertyValue(\'--tma-hint\').trim() || \'#999\',
      border   : getComputedStyle(document.documentElement).getPropertyValue(\'--tma-border\').trim() || \'rgba(0,0,0,.1)\',
    };
    var palette = [\'#4361ee\',\'#3a0ca3\',\'#7209b7\',\'#f72585\',\'#4cc9f0\',\'#06d6a0\',\'#ffd166\',\'#ef476f\'];
    var chartDefaults = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false, labels: { color: tmaColors.text, font: { size: 11 } } } },
      scales: {}
    };

    /* ── Token trend (line) ── */
    var daily = data.daily || [];
    var tokenCtx = document.getElementById(\'tma-chart-tokens\');
    if (tokenCtx && daily.length) {
      homeCharts.tokens = new Chart(tokenCtx.getContext(\'2d\'), {
        type: \'line\',
        data: {
          labels: daily.map(function (d) { return d.label || d.date; }),
          datasets: [{
            label: \'Tokens\',
            data: daily.map(function (d) { return d.total_tokens || 0; }),
            borderColor: tmaColors.primary,
            backgroundColor: tmaColors.primary + \'33\',
            fill: true,
            tension: 0.3,
            pointRadius: 3,
            pointBackgroundColor: tmaColors.primary,
          }]
        },
        options: Object.assign({}, chartDefaults, {
          scales: {
            x: { ticks: { color: tmaColors.hint, font: { size: 10 } }, grid: { color: tmaColors.border } },
            y: { beginAtZero: true, ticks: { color: tmaColors.hint, font: { size: 10 }, callback: function (v) { return formatNum(v); } }, grid: { color: tmaColors.border } }
          }
        })
      });
    }

    /* ── Cost by provider (doughnut) ── */
    var providers = data.by_provider || [];
    var costCtx = document.getElementById(\'tma-chart-cost\');
    if (costCtx && providers.length) {
      homeCharts.cost = new Chart(costCtx.getContext(\'2d\'), {
        type: \'doughnut\',
        data: {
          labels: providers.map(function (p) { return p.provider || \'Unknown\'; }),
          datasets: [{
            data: providers.map(function (p) { return parseFloat(p.total_cost) || 0; }),
            backgroundColor: palette.slice(0, providers.length),
            borderWidth: 1,
            borderColor: getComputedStyle(document.documentElement).getPropertyValue(\'--tma-section-bg\').trim() || \'#fff\',
          }]
        },
        options: Object.assign({}, chartDefaults, {
          plugins: { legend: { display: true, position: \'bottom\', labels: { color: tmaColors.text, font: { size: 10 }, boxWidth: 10, padding: 8 } } },
          cutout: \'60%\',
        })
      });
    } else if (costCtx) {
      costCtx.parentNode.innerHTML = \'<div class="tma-empty" style="padding:16px">No cost data yet.</div>\';
    }

    /* ── Top tools (horizontal bar) ── */
    var tools = (data.by_tool || []).slice(0, 8);
    var toolCtx = document.getElementById(\'tma-chart-tools\');
    if (toolCtx && tools.length) {
      homeCharts.tools = new Chart(toolCtx.getContext(\'2d\'), {
        type: \'bar\',
        data: {
          labels: tools.map(function (t) { return t.tool || \'—\'; }),
          datasets: [{
            label: \'Tokens\',
            data: tools.map(function (t) { return t.total_tokens || 0; }),
            backgroundColor: palette.slice(0, tools.length),
            borderRadius: 4,
          }]
        },
        options: Object.assign({}, chartDefaults, {
          indexAxis: \'y\',
          scales: {
            x: { beginAtZero: true, ticks: { color: tmaColors.hint, font: { size: 10 }, callback: function (v) { return formatNum(v); } }, grid: { color: tmaColors.border } },
            y: { ticks: { color: tmaColors.text, font: { size: 10 } }, grid: { display: false } }
          }
        })
      });
    } else if (toolCtx) {
      toolCtx.parentNode.innerHTML = \'<div class="tma-empty" style="padding:16px">No tool usage yet.</div>\';
    }

    /* ── Usage by toolkit (doughnut) ── */
    var tkCtx = document.getElementById(\'tma-chart-toolkit\');
    if (tkCtx) {
      var toolsByToolkit = {};
      (data.by_tool || []).forEach(function (t) {
        var tkLabel = \'Ungrouped\';
        allTools.forEach(function (at) {
          if (at.slug === t.tool && at.toolkit) tkLabel = at.toolkit;
        });
        if (!toolsByToolkit[tkLabel]) toolsByToolkit[tkLabel] = 0;
        toolsByToolkit[tkLabel] += (t.total_tokens || 0);
      });
      var tkLabels = Object.keys(toolsByToolkit);
      var tkValues = tkLabels.map(function (k) { return toolsByToolkit[k]; });
      if (tkLabels.length > 0) {
        homeCharts.toolkit = new Chart(tkCtx.getContext(\'2d\'), {
          type: \'doughnut\',
          data: {
            labels: tkLabels,
            datasets: [{ data: tkValues, backgroundColor: palette.slice(0, tkLabels.length), borderWidth: 1, borderColor: getComputedStyle(document.documentElement).getPropertyValue(\'--tma-section-bg\').trim() || \'#fff\' }]
          },
          options: Object.assign({}, chartDefaults, {
            plugins: { legend: { display: true, position: \'bottom\', labels: { color: tmaColors.text, font: { size: 10 }, boxWidth: 10, padding: 8 } } },
            cutout: \'60%\',
          })
        });
      }
    }
  }

  /* ── Date range selection ── */
  window.tmaSetDateRange = function (days) {
    haptic(\'selectionChanged\');
    homeDays = days;
    homeLoaded = false;
    Object.keys(homeCharts).forEach(function (k) { if (homeCharts[k]) { homeCharts[k].destroy(); homeCharts[k] = null; } });
    loadHome();
  };

  /* ── Export analytics to Telegram chat ── */
  window.tmaExportAnalytics = function () {
    haptic(\'light\');
    var wrap = document.getElementById(\'tma-home-wrap\');
    var kpis = wrap ? wrap.querySelectorAll(\'.tma-kpi-card\') : [];
    var lines = [\'📊 Analytics Summary (\' + homeDays + \' days)\'];
    kpis.forEach(function (card) {
      var lbl = card.querySelector(\'.tma-kpi-label\');
      var val = card.querySelector(\'.tma-kpi-value\');
      if (lbl && val) lines.push(lbl.textContent + \': \' + val.textContent);
    });
    var text = lines.join(\'\\n\');
    if (twa && twa.sendData) {
      twa.sendData(text);
    } else {
      showToast(\'Open via Telegram to export\', true);
    }
  };

  /* =========================================================
     CONTENT TAB
     ========================================================= */
  var contentLoaded = false;

  function loadContent() {
    if (!contentLoaded) contentLoaded = true;
    var listEl = document.getElementById(\'tma-post-list\');
    if (listEl) listEl.innerHTML = \'<div class="tma-empty">Loading…</div>\';

    var url = TMA_CONTENT_URL + \'?post_type=\' + encodeURIComponent(contentPostType) +
              \'&page=\' + contentPage +
              \'&per_page=20\' +
              (contentSearch ? \'&search=\' + encodeURIComponent(contentSearch) : \'\');

    authFetch(url)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadContent(); }).catch(function () { showLoginFallback(\'content\'); });
          }
          showLoginPrompt(\'content\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        renderCptBar(data.post_types || []);
        renderPostList(data.posts || [], data.total || 0, data.pages || 1);
      })
      .catch(function () {
        if (listEl) listEl.innerHTML = \'<div class="tma-empty tma-error">Failed to load content.</div>\';
      });
  }

  var contentToolkitFilter = \'\';
  var cachedCptTypes = [];

  function renderCptBar(types) {
    cachedCptTypes = types;
    var scroll = document.getElementById(\'tma-cpt-scroll\');
    if (!scroll) return;
    if (!types.length) {
      scroll.innerHTML = \'<div class="tma-empty">No content types available.</div>\';
      return;
    }

    /* Collect unique toolkit labels for filter */
    var toolkits = [];
    types.forEach(function (t) {
      if (t.toolkit && toolkits.indexOf(t.toolkit) === -1) toolkits.push(t.toolkit);
    });

    var html = \'\';
    /* Toolkit filter pills (only when there are multiple toolkits) */
    if (toolkits.length > 1) {
      html += \'<div class="tma-toolkit-pills">\';
      html += \'<button class="tma-filter-btn\' + (!contentToolkitFilter ? \' tma-active\' : \'\') + \'" onclick="tmaContentToolkitFilter(this,\\\'\\\')">All</button>\';
      toolkits.forEach(function (tk) {
        html += \'<button class="tma-filter-btn\' + (contentToolkitFilter === tk ? \' tma-active\' : \'\') + \'" onclick="tmaContentToolkitFilter(this,\\\'\' + escHtml(tk) + \'\\\')">\' + escHtml(tk) + \'</button>\';
      });
      html += \'</div>\';
    }

    /* CPT buttons filtered by toolkit */
    var filtered = contentToolkitFilter ? types.filter(function (t) { return t.toolkit === contentToolkitFilter; }) : types;
    html += \'<div class="tma-cpt-buttons">\';
    filtered.forEach(function (t) {
      var active = t.name === contentPostType ? \' tma-active\' : \'\';
      var badge  = t.count > 0 ? \'<span class="tma-badge">\' + escHtml(String(t.count)) + \'</span>\' : \'\';
      var tk     = t.toolkit ? \'<span class="tma-tk-dot" title="\' + escHtml(t.toolkit) + \'">●</span>\' : \'\';
      html += \'<button class="tma-cpt-btn\' + active + \'" onclick="tmaSelectCpt(\\\'\' + escHtml(t.name) + \'\\\')">\' +
              escHtml(t.label) + badge + tk + \'</button>\';
    });
    html += \'</div>\';
    scroll.innerHTML = html;
  }

  window.tmaContentToolkitFilter = function (btn, tk) {
    haptic(\'selectionChanged\');
    contentToolkitFilter = tk;
    renderCptBar(cachedCptTypes);
  };

  window.tmaSelectCpt = function (typeName) {
    haptic(\'selectionChanged\');
    contentPostType = typeName;
    contentPage     = 1;
    document.querySelectorAll(\'.tma-cpt-btn\').forEach(function (b) {
      b.classList.toggle(\'tma-active\', b.textContent.trim().startsWith(
        document.querySelector(\'.tma-cpt-btn.tma-active\') ?
        b.getAttribute(\'onclick\').match(/\'([^\']+)\'/)?.[1] === typeName :
        false
      ));
    });
    /* Re-render CPT bar active state */
    document.querySelectorAll(\'.tma-cpt-btn\').forEach(function (b) {
      var match = b.getAttribute(\'onclick\').match(/\'([^\']+)\'/);
      if (match) b.classList.toggle(\'tma-active\', match[1] === typeName);
    });
    loadContent();
  };

  function renderPostList(posts, total, pages) {
    var listEl  = document.getElementById(\'tma-post-list\');
    var pageEl  = document.getElementById(\'tma-content-pagination\');
    if (!listEl) return;

    /* Cache posts so the editor can access post_content. */
    contentPostsCache = posts;

    if (!posts.length) {
      listEl.innerHTML = \'<div class="tma-empty">No items found.</div>\';
      if (pageEl) pageEl.innerHTML = \'\';
      return;
    }

    var html = \'\';
    posts.forEach(function (p, idx) {
      var statusClass = p.status === \'publish\' ? \'tma-status-pub\' : \'tma-status-draft\';
      var statusText  = p.status === \'publish\' ? \'Published\' : (p.status === \'draft\' ? \'Draft\' : p.status);
      var date        = p.modified ? new Date(p.modified).toLocaleDateString(undefined, { month: \'short\', day: \'numeric\' }) : \'\';
      html += \'<div class="tma-post-card">\' +
        \'<div class="tma-post-header">\' +
          \'<span class="tma-post-title">\' + escHtml(p.title || \'(no title)\') + \'</span>\' +
          \'<span class="tma-post-status \' + statusClass + \'">\' + statusText + \'</span>\' +
        \'</div>\' +
        (p.excerpt ? \'<div class="tma-post-excerpt">\' + escHtml(p.excerpt) + \'</div>\' : \'\') +
        \'<div class="tma-post-meta">\' +
          \'<span>\' + escHtml(date) + \'</span>\' +
          \'<span class="tma-post-actions">\' +
            \'<button class="tma-post-edit-btn" onclick="tmaEditPost(\' + idx + \')">✏️ Edit</button>\' +
            (p.link ? \'<a class="tma-post-edit" href="\' + escHtml(p.link) + \'" target="_blank">Open ›</a>\' : \'\') +
          \'</span>\' +
        \'</div>\' +
      \'</div>\';
    });
    listEl.innerHTML = html;

    /* Pagination */
    if (pageEl) {
      var pHtml = \'\';
      if (contentPage > 1) {
        pHtml += \'<button class="tma-page-btn" onclick="tmaContentPage(\' + (contentPage - 1) + \')">‹ Prev</button>\';
      }
      pHtml += \'<span class="tma-page-info">\' + contentPage + \' / \' + pages + \'</span>\';
      if (contentPage < pages) {
        pHtml += \'<button class="tma-page-btn" onclick="tmaContentPage(\' + (contentPage + 1) + \')">Next ›</button>\';
      }
      pageEl.innerHTML = pHtml;
    }
  }

  /* ── Content Editor Functions ── */
  var contentPostsCache = [];

  window.tmaNewPost = function () {
    haptic(\'light\');
    document.getElementById(\'tma-editor-id\').value = \'0\';
    document.getElementById(\'tma-editor-post-type\').value = contentPostType;
    document.getElementById(\'tma-editor-post-title\').value = \'\';
    document.getElementById(\'tma-editor-post-content\').value = \'\';
    document.getElementById(\'tma-editor-post-status\').value = \'draft\';
    document.getElementById(\'tma-editor-heading\').textContent = \'New \' + contentPostType;
    var errEl = document.getElementById(\'tma-editor-error\');
    if (errEl) errEl.style.display = \'none\';
    document.getElementById(\'tma-editor-overlay\').style.display = \'flex\';
  };

  window.tmaEditPost = function (idx) {
    haptic(\'light\');
    var p = contentPostsCache[idx];
    if (!p) return;
    document.getElementById(\'tma-editor-id\').value = String(p.id);
    document.getElementById(\'tma-editor-post-type\').value = contentPostType;
    document.getElementById(\'tma-editor-post-title\').value = p.title || \'\';
    document.getElementById(\'tma-editor-post-content\').value = p.post_content || \'\';
    document.getElementById(\'tma-editor-post-status\').value = p.status || \'draft\';
    document.getElementById(\'tma-editor-heading\').textContent = \'Edit: \' + escHtml(p.title || \'(no title)\');
    var errEl = document.getElementById(\'tma-editor-error\');
    if (errEl) errEl.style.display = \'none\';
    document.getElementById(\'tma-editor-overlay\').style.display = \'flex\';
  };

  window.tmaCloseEditor = function () {
    haptic(\'light\');
    document.getElementById(\'tma-editor-overlay\').style.display = \'none\';
  };

  window.tmaOnStatusChange = function (val) {
    var schedField = document.getElementById(\'tma-editor-schedule-field\');
    if (schedField) schedField.style.display = val === \'future\' ? \'block\' : \'none\';
  };

  window.tmaToggleFullscreen = function () {
    haptic(\'light\');
    var panel = document.querySelector(\'.tma-editor-panel\');
    if (!panel) return;
    if (document.fullscreenElement) {
      document.exitFullscreen();
    } else if (panel.requestFullscreen) {
      panel.requestFullscreen();
    }
  };

  window.tmaSavePost = function () {
    haptic(\'light\');
    var saveBtn = document.getElementById(\'tma-editor-save\');
    var errEl   = document.getElementById(\'tma-editor-error\');
    var title   = document.getElementById(\'tma-editor-post-title\').value.trim();
    if (!title) {
      if (errEl) { errEl.textContent = \'Title is required.\'; errEl.style.display = \'block\'; }
      return;
    }
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = \'Saving…\'; }
    var status = document.getElementById(\'tma-editor-post-status\').value || \'draft\';
    var payload = {
      id:        parseInt(document.getElementById(\'tma-editor-id\').value, 10) || 0,
      post_type: document.getElementById(\'tma-editor-post-type\').value || contentPostType,
      title:     title,
      content:   document.getElementById(\'tma-editor-post-content\').value,
      status:    status,
    };
    if (status === \'future\') {
      var schedDate = document.getElementById(\'tma-editor-schedule-date\');
      if (schedDate && schedDate.value) payload.date = schedDate.value;
    }
    var h = { \'Content-Type\': \'application/json\', \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    fetch(TMA_CONTENT_URL, {
      method: \'POST\', credentials: \'same-origin\', headers: h,
      body: JSON.stringify(payload),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = \'Save\'; }
        if (json && json.success) {
          haptic(\'notificationOccurred\', \'success\');
          tmaCloseEditor();
          contentLoaded = false;
          loadContent();
        } else {
          var msg = (json && json.message) ? json.message : \'Failed to save.\';
          if (errEl) { errEl.textContent = msg; errEl.style.display = \'block\'; }
          haptic(\'notificationOccurred\', \'error\');
        }
      })
      .catch(function () {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = \'Save\'; }
        if (errEl) { errEl.textContent = \'Network error.\'; errEl.style.display = \'block\'; }
      });
  };

  window.tmaContentPage = function (p) {
    haptic(\'light\');
    contentPage = p;
    loadContent();
    document.getElementById(\'tma-tab-content\').scrollTop = 0;
  };

  /* =========================================================
     TOOLS TAB
     ========================================================= */
  var toolsLoaded = false;

  function loadTools() {
    if (toolsLoaded) { renderTools(\'\'); return; }
    var toolsEl = document.getElementById(\'tma-tools-list\');
    var slashEl = document.getElementById(\'tma-slash-list\');
    if (toolsEl) toolsEl.innerHTML = \'<div class="tma-empty">Loading…</div>\';

    authFetch(TMA_TOOLS_URL)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadTools(); }).catch(function () { showLoginFallback(\'tools\'); });
          }
          showLoginPrompt(\'tools\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        toolsLoaded  = true;
        allTools     = data.tools || [];
        allSlashCmds = data.slash_commands || [];
        renderToolkitFilter(data.toolkits || []);
        renderTools(\'\');
      })
      .catch(function () {
        if (toolsEl) toolsEl.innerHTML = \'<div class="tma-empty tma-error">Failed to load tools.</div>\';
      });
  }

  function renderToolkitFilter(toolkits) {
    var bar = document.getElementById(\'tma-toolkit-filter\');
    if (!bar) return;
    if (!toolkits.length) { bar.style.display = \'none\'; return; }
    var html = \'<button class="tma-filter-btn tma-active" data-tk="" onclick="tmaToolkitFilter(this,\\\'\\\')">All</button>\';
    toolkits.forEach(function (tk) {
      html += \'<button class="tma-filter-btn" data-tk="\' + escHtml(tk.label) +
              \'" onclick="tmaToolkitFilter(this,\\\'\' + escHtml(tk.label) + \'\\\')">\' +
              escHtml(tk.label) + \'</button>\';
    });
    bar.innerHTML = html;
  }

  window.tmaToolkitFilter = function (btn, label) {
    haptic(\'selectionChanged\');
    toolkitFilter = label;
    document.querySelectorAll(\'#tma-toolkit-filter .tma-filter-btn\').forEach(function (b) {
      b.classList.remove(\'tma-active\');
    });
    btn.classList.add(\'tma-active\');
    renderTools(\'\');
  };

  function renderTools(search) {
    var toolsEl = document.getElementById(\'tma-tools-list\');
    var q       = (search || \'\').toLowerCase();

    var filtered = allTools.filter(function (t) {
      var matchSearch  = !q || t.name.toLowerCase().indexOf(q) >= 0 || t.description.toLowerCase().indexOf(q) >= 0;
      var matchToolkit = !toolkitFilter || t.toolkit === toolkitFilter;
      return matchSearch && matchToolkit;
    });

    if (toolsEl) {
      if (!filtered.length) {
        toolsEl.innerHTML = \'<div class="tma-empty">No tools found.</div>\';
      } else {
        var html = \'\';
        filtered.forEach(function (t) {
          var tk = t.toolkit ? \'<span class="tma-card-badge">\' + escHtml(t.toolkit) + \'</span>\' : \'\';
          var gr = t.group ? \'<span class="tma-card-group">\' + escHtml(t.group) + \'</span>\' : \'\';
          html += \'<div class="tma-tool-card">\' +
            \'<div class="tma-card-title">\' + escHtml(t.name) + tk + \'</div>\' +
            (t.description ? \'<div class="tma-card-desc">\' + escHtml(t.description) + \'</div>\' : \'\') +
            gr +
            \'<div class="tma-card-actions"><button class="tma-tool-exec-btn" onclick="tmaOpenToolExec(\\\'\' + escHtml(t.slug) + \'\\\')">▶ Execute</button></div>\' +
          \'</div>\';
        });
        toolsEl.innerHTML = html;
      }
    }
  }

  /* ── Tool Execution ── */
  var currentToolSlug = \'\';
  var currentToolParams = {};

  window.tmaOpenToolExec = function (slug) {
    haptic(\'light\');
    var tool = allTools.find(function (t) { return t.slug === slug; });
    if (!tool) return;
    currentToolSlug = slug;
    currentToolParams = (tool.parameters && tool.parameters.properties) ? tool.parameters.properties : {};
    var required = (tool.parameters && tool.parameters.required) ? tool.parameters.required : [];
    document.getElementById(\'tma-tool-exec-heading\').textContent = tool.name;
    var formEl = document.getElementById(\'tma-tool-exec-form\');
    var errEl  = document.getElementById(\'tma-tool-exec-error\');
    var resEl  = document.getElementById(\'tma-tool-exec-result\');
    if (errEl) errEl.style.display = \'none\';
    if (resEl) { resEl.style.display = \'none\'; resEl.innerHTML = \'\'; }
    var html = \'\';
    var keys = Object.keys(currentToolParams);
    if (!keys.length) {
      html = \'<div class="tma-settings-help">This tool has no parameters. Click Run to execute.</div>\';
    } else {
      keys.forEach(function (key) {
        var p = currentToolParams[key];
        var label = key + (required.indexOf(key) >= 0 ? \' *\' : \'\');
        var desc  = p.description ? \'<div class="tma-editor-label" style="font-weight:400;font-size:11px;color:var(--tma-hint)">\' + escHtml(p.description) + \'</div>\' : \'\';
        html += \'<div class="tma-editor-field">\';
        html += \'<label class="tma-editor-label">\' + escHtml(label) + \'</label>\';
        if (p.enum && p.enum.length) {
          html += \'<select class="tma-editor-select" data-tool-param="\' + escHtml(key) + \'">\';
          p.enum.forEach(function (v) {
            html += \'<option value="\' + escHtml(String(v)) + \'">\' + escHtml(String(v)) + \'</option>\';
          });
          html += \'</select>\';
        } else if (p.type === \'boolean\') {
          html += \'<label class="tma-toggle"><input type="checkbox" data-tool-param="\' + escHtml(key) + \'"><span class="tma-toggle-slider"></span></label>\';
        } else if (p.type === \'integer\' || p.type === \'number\') {
          html += \'<input type="number" class="tma-editor-input" data-tool-param="\' + escHtml(key) + \'" placeholder="\' + escHtml(p.description || key) + \'" />\';
        } else {
          html += \'<input type="text" class="tma-editor-input" data-tool-param="\' + escHtml(key) + \'" placeholder="\' + escHtml(p.description || key) + \'" />\';
        }
        html += desc;
        html += \'</div>\';
      });
    }
    formEl.innerHTML = html;
    document.getElementById(\'tma-tool-exec-overlay\').style.display = \'flex\';
  };

  window.tmaCloseToolExec = function () {
    haptic(\'light\');
    document.getElementById(\'tma-tool-exec-overlay\').style.display = \'none\';
  };

  window.tmaRunTool = function () {
    haptic(\'light\');
    var runBtn = document.getElementById(\'tma-tool-exec-run\');
    var errEl  = document.getElementById(\'tma-tool-exec-error\');
    var resEl  = document.getElementById(\'tma-tool-exec-result\');
    if (errEl) errEl.style.display = \'none\';
    if (resEl) { resEl.style.display = \'none\'; resEl.innerHTML = \'\'; }
    if (runBtn) { runBtn.disabled = true; runBtn.textContent = \'Running…\'; }

    var args = {};
    document.querySelectorAll(\'[data-tool-param]\').forEach(function (el) {
      var key = el.getAttribute(\'data-tool-param\');
      var p   = currentToolParams[key];
      if (el.type === \'checkbox\') {
        args[key] = el.checked;
      } else if (p && (p.type === \'integer\' || p.type === \'number\') && el.value !== \'\') {
        args[key] = p.type === \'integer\' ? parseInt(el.value, 10) : parseFloat(el.value);
      } else if (el.value !== \'\') {
        args[key] = el.value;
      }
    });

    var h = { \'Content-Type\': \'application/json\', \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    fetch(TMA_TOOLS_EXEC_URL, {
      method: \'POST\', credentials: \'same-origin\', headers: h,
      body: JSON.stringify({ slug: currentToolSlug, arguments: args }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (runBtn) { runBtn.disabled = false; runBtn.textContent = \'▶ Run\'; }
        if (json && json.success) {
          haptic(\'notificationOccurred\', \'success\');
          if (resEl) {
            resEl.style.display = \'block\';
            resEl.innerHTML = \'<div class="tma-tool-result-header">✅ Result</div><pre class="tma-tool-result-pre">\' + escHtml(typeof json.result === \'string\' ? json.result : JSON.stringify(json.result, null, 2)) + \'</pre>\';
          }
        } else {
          var msg = (json && json.message) ? json.message : \'Tool execution failed.\';
          if (errEl) { errEl.textContent = msg; errEl.style.display = \'block\'; }
          haptic(\'notificationOccurred\', \'error\');
        }
      })
      .catch(function () {
        if (runBtn) { runBtn.disabled = false; runBtn.textContent = \'▶ Run\'; }
        if (errEl) { errEl.textContent = \'Network error.\'; errEl.style.display = \'block\'; }
      });
  };

  /* =========================================================
     COMMANDS TAB
     ========================================================= */
  var commandsLoaded = false;

  function loadCommands() {
    if (commandsLoaded) { renderSlashCommands(\'\'); return; }
    var slashEl = document.getElementById(\'tma-slash-list\');
    if (slashEl) slashEl.innerHTML = \'<div class="tma-empty">Loading…</div>\';

    authFetch(TMA_TOOLS_URL)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadCommands(); }).catch(function () { showLoginFallback(\'commands\'); });
          }
          showLoginPrompt(\'commands\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        commandsLoaded = true;
        allSlashCmds = data.slash_commands || [];
        renderSlashCommands(\'\');
      })
      .catch(function () {
        if (slashEl) slashEl.innerHTML = \'<div class="tma-empty tma-error">Failed to load commands.</div>\';
      });
  }

  function renderSlashCommands(search) {
    var slashEl = document.getElementById(\'tma-slash-list\');
    var q = (search || \'\').toLowerCase();
    var filteredCmds = allSlashCmds.filter(function (c) {
      return !q || c.name.toLowerCase().indexOf(q) >= 0 || c.description.toLowerCase().indexOf(q) >= 0;
    });
    if (slashEl) {
      if (!filteredCmds.length) {
        slashEl.innerHTML = \'<div class="tma-empty">No slash commands found.</div>\';
      } else {
        var html = \'\';
        filteredCmds.forEach(function (c) {
          html += \'<div class="tma-tool-card tma-slash-card">\' +
            \'<div class="tma-card-title tma-mono">\' + escHtml(c.name) + \'</div>\' +
            (c.description ? \'<div class="tma-card-desc">\' + escHtml(c.description) + \'</div>\' : \'\') +
            \'<div class="tma-card-usage">\' + escHtml(c.usage) + \'</div>\' +
          \'</div>\';
        });
        slashEl.innerHTML = html;
      }
    }
  }

  /* =========================================================
     MEDIA TAB
     ========================================================= */
  var mediaLoaded = false;

  function loadMedia() {
    if (!mediaLoaded) mediaLoaded = true;
    var gridEl = document.getElementById(\'tma-media-grid\');
    if (gridEl) gridEl.innerHTML = \'<div class="tma-empty">Loading…</div>\';

    var url = TMA_MEDIA_URL + \'?page=\' + mediaPage + \'&per_page=20\' +
              (mediaMimeFilter ? \'&type=\' + encodeURIComponent(mediaMimeFilter) : \'\') +
              (mediaSearch ? \'&search=\' + encodeURIComponent(mediaSearch) : \'\');

    authFetch(url)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadMedia(); }).catch(function () { showLoginFallback(\'media\'); });
          }
          showLoginPrompt(\'media\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        renderMediaGrid(data.items || [], data.total || 0, data.pages || 1);
      })
      .catch(function () {
        if (gridEl) gridEl.innerHTML = \'<div class="tma-empty tma-error">Failed to load media.</div>\';
      });
  }

  window.tmaMediaFilter = function (btn, mime) {
    haptic(\'selectionChanged\');
    mediaMimeFilter = mime;
    mediaPage       = 1;
    document.querySelectorAll(\'#tma-media-filter .tma-filter-btn\').forEach(function (b) {
      b.classList.remove(\'tma-active\');
    });
    btn.classList.add(\'tma-active\');
    loadMedia();
  };

  function renderMediaGrid(items, total, pages) {
    var gridEl = document.getElementById(\'tma-media-grid\');
    var pageEl = document.getElementById(\'tma-media-pagination\');
    if (!gridEl) return;

    if (!items.length) {
      gridEl.innerHTML = \'<div class="tma-empty">No media found.</div>\';
      if (pageEl) pageEl.innerHTML = \'\';
      return;
    }

    var html = \'\';
    items.forEach(function (item) {
      var isImg  = item.mime_type && item.mime_type.indexOf(\'image/\') === 0;
      var isVid  = item.mime_type && item.mime_type.indexOf(\'video/\') === 0;
      var isAud  = item.mime_type && item.mime_type.indexOf(\'audio/\') === 0;
      var thumb;
      if (isImg && item.thumb) {
        thumb = \'<img src="\' + escHtml(item.thumb) + \'" alt="\' + escHtml(item.title) + \'" class="tma-media-thumb" loading="lazy">\';
      } else if (isVid && item.thumb) {
        thumb = \'<div class="tma-media-icon-wrap"><img src="\' + escHtml(item.thumb) + \'" alt="\' + escHtml(item.title) + \'" class="tma-media-thumb" loading="lazy"><span class="tma-media-play-badge">▶</span></div>\';
      } else if (isVid && item.url) {
        thumb = \'<div class="tma-media-icon-wrap"><video class="tma-media-thumb tma-media-video-preview" src="\' + escHtml(item.url) + \'" muted preload="metadata" playsinline></video><span class="tma-media-play-badge">▶</span></div>\';
      } else if (isAud && item.url) {
        thumb = \'<div class="tma-media-icon tma-media-icon--audio"><div class="tma-media-icon-emoji">\' + mimeIcon(item.mime_type) + \'</div><audio class="tma-media-audio-preview" src="\' + escHtml(item.url) + \'" controls preload="none"></audio></div>\';
      } else {
        thumb = \'<div class="tma-media-icon tma-media-icon--\' + mimeTypeClass(item.mime_type) + \'">\' + mimeIcon(item.mime_type) + \'</div>\';
      }
      var typeLabel = mimeLabel(item.mime_type);
      var metaParts = [];
      if (typeLabel) metaParts.push(typeLabel);
      if (item.filesize) metaParts.push(item.filesize);
      html += \'<div class="tma-media-item" onclick="tmaOpenMedia(\\\'\' + escHtml(item.url) + \'\\\')">\' +
        thumb +
        \'<div class="tma-media-info">\' +
          \'<div class="tma-media-title">\' + escHtml(item.title || \'(untitled)\') + \'</div>\' +
          \'<div class="tma-media-meta">\' + escHtml(metaParts.join(\' • \')) + \'</div>\' +
        \'</div>\' +
      \'</div>\';
    });
    gridEl.innerHTML = html;

    if (pageEl) {
      var pHtml = \'\';
      if (mediaPage > 1) {
        pHtml += \'<button class="tma-page-btn" onclick="tmaMediaPage(\' + (mediaPage - 1) + \')">‹ Prev</button>\';
      }
      pHtml += \'<span class="tma-page-info">\' + mediaPage + \' / \' + pages + \'</span>\';
      if (mediaPage < pages) {
        pHtml += \'<button class="tma-page-btn" onclick="tmaMediaPage(\' + (mediaPage + 1) + \')">Next ›</button>\';
      }
      pageEl.innerHTML = pHtml;
    }
  }

  window.tmaMediaPage = function (p) {
    haptic(\'light\');
    mediaPage = p;
    loadMedia();
    document.getElementById(\'tma-tab-media\').scrollTop = 0;
  };

  window.tmaOpenMedia = function (url) {
    haptic(\'light\');
    if (twa && twa.openLink) {
      twa.openLink(url);
    } else {
      window.open(url, \'_blank\');
    }
  };

  function mimeIcon(mime) {
    if (!mime) return \'📄\';
    if (mime.indexOf(\'image/\') === 0)       return \'🖼️\';
    if (mime.indexOf(\'video/\') === 0)       return \'🎬\';
    if (mime.indexOf(\'audio/\') === 0)       return \'🎵\';
    if (mime.indexOf(\'application/pdf\') === 0)             return \'📕\';
    if (mime.indexOf(\'application/msword\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.wordprocessingml\') === 0 ||
        mime.indexOf(\'application/vnd.oasis.opendocument.text\') === 0)   return \'📝\';
    if (mime.indexOf(\'application/vnd.ms-excel\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.spreadsheetml\') === 0 ||
        mime.indexOf(\'application/vnd.oasis.opendocument.spreadsheet\') === 0 ||
        mime.indexOf(\'text/csv\') === 0)                     return \'📊\';
    if (mime.indexOf(\'application/vnd.ms-powerpoint\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.presentationml\') === 0 ||
        mime.indexOf(\'application/vnd.oasis.opendocument.presentation\') === 0) return \'📽️\';
    if (mime.indexOf(\'application/zip\') === 0 ||
        mime.indexOf(\'application/x-rar\') === 0 ||
        mime.indexOf(\'application/x-7z\') === 0 ||
        mime.indexOf(\'application/gzip\') === 0 ||
        mime.indexOf(\'application/x-tar\') === 0)            return \'🗜️\';
    if (mime.indexOf(\'application/json\') === 0 ||
        mime.indexOf(\'application/xml\') === 0 ||
        mime.indexOf(\'text/xml\') === 0)                     return \'🔧\';
    if (mime.indexOf(\'text/html\') === 0)                    return \'🌐\';
    if (mime.indexOf(\'text/css\') === 0)                     return \'🎨\';
    if (mime.indexOf(\'text/javascript\') === 0 ||
        mime.indexOf(\'application/javascript\') === 0 ||
        mime.indexOf(\'text/x-python\') === 0 ||
        mime.indexOf(\'application/x-httpd-php\') === 0)      return \'💻\';
    if (mime.indexOf(\'text/plain\') === 0)                   return \'📄\';
    if (mime.indexOf(\'font/\') === 0 ||
        mime.indexOf(\'application/font\') === 0)             return \'🔤\';
    if (mime.indexOf(\'application/epub\') === 0)             return \'📚\';
    if (mime.indexOf(\'application/\') === 0)                 return \'📦\';
    return \'📄\';
  }

  function mimeTypeClass(mime) {
    if (!mime) return \'generic\';
    if (mime.indexOf(\'image/\') === 0)       return \'image\';
    if (mime.indexOf(\'video/\') === 0)       return \'video\';
    if (mime.indexOf(\'audio/\') === 0)       return \'audio\';
    if (mime.indexOf(\'application/pdf\') === 0) return \'pdf\';
    if (mime.indexOf(\'application/msword\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.wordprocessingml\') === 0 ||
        mime.indexOf(\'application/vnd.oasis.opendocument.text\') === 0) return \'word\';
    if (mime.indexOf(\'application/vnd.ms-excel\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.spreadsheetml\') === 0 ||
        mime.indexOf(\'application/vnd.oasis.opendocument.spreadsheet\') === 0 ||
        mime.indexOf(\'text/csv\') === 0)     return \'excel\';
    if (mime.indexOf(\'application/vnd.ms-powerpoint\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.presentationml\') === 0 ||
        mime.indexOf(\'application/vnd.oasis.opendocument.presentation\') === 0) return \'ppt\';
    if (mime.indexOf(\'application/zip\') === 0 ||
        mime.indexOf(\'application/x-rar\') === 0 ||
        mime.indexOf(\'application/x-7z\') === 0 ||
        mime.indexOf(\'application/gzip\') === 0 ||
        mime.indexOf(\'application/x-tar\') === 0) return \'archive\';
    if (mime.indexOf(\'text/\') === 0)        return \'text\';
    if (mime.indexOf(\'font/\') === 0 ||
        mime.indexOf(\'application/font\') === 0) return \'font\';
    if (mime.indexOf(\'application/epub\') === 0) return \'epub\';
    return \'generic\';
  }

  function mimeLabel(mime) {
    if (!mime) return \'File\';
    if (mime.indexOf(\'image/\') === 0)       return \'Image\';
    if (mime.indexOf(\'video/\') === 0)       return \'Video\';
    if (mime.indexOf(\'audio/\') === 0)       return \'Audio\';
    if (mime.indexOf(\'application/pdf\') === 0)             return \'PDF\';
    if (mime.indexOf(\'application/msword\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.wordprocessingml\') === 0) return \'Word\';
    if (mime.indexOf(\'application/vnd.ms-excel\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.spreadsheetml\') === 0)   return \'Excel\';
    if (mime.indexOf(\'application/vnd.ms-powerpoint\') === 0 ||
        mime.indexOf(\'application/vnd.openxmlformats-officedocument.presentationml\') === 0)  return \'PowerPoint\';
    if (mime.indexOf(\'text/csv\') === 0)                     return \'CSV\';
    if (mime.indexOf(\'application/json\') === 0)             return \'JSON\';
    if (mime.indexOf(\'application/xml\') === 0 || mime.indexOf(\'text/xml\') === 0) return \'XML\';
    if (mime.indexOf(\'application/zip\') === 0)              return \'ZIP\';
    if (mime.indexOf(\'text/plain\') === 0)                   return \'Text\';
    if (mime.indexOf(\'text/html\') === 0)                    return \'HTML\';
    if (mime.indexOf(\'application/\') === 0)                 return \'Document\';
    return \'File\';
  }

  /* =========================================================
     AUTH / LOGIN PROMPT
     ========================================================= */
  var tmaAuthAttempts = 0;
  var tmaGlobalRetries = 0;
  var TMA_MAX_AUTO_RETRIES = 3;

  function showLoginPrompt(tabName) {
    var el = document.getElementById(\'tma-tab-\' + tabName);
    if (!el) return;
    /* Inside Telegram: attempt one automatic sign-in, then show manual retry.
       tmaAuthAttempts is a per-tab counter (reset by tmaRetryAuth on success)
       that allows one auto-retry per tab switch.  tmaGlobalRetries is a
       session-wide cap that prevents infinite loops when auth keeps failing
       because tmaRetryAuth resets the per-tab counter on every cycle. */
    if (twa && twa.initData) {
      if (tmaAuthAttempts < 1 && tmaGlobalRetries < TMA_MAX_AUTO_RETRIES) {
        ++tmaAuthAttempts;
        ++tmaGlobalRetries;
        el.innerHTML = \'<div class="tma-login-prompt">\' +
          \'<div class="tma-login-icon">🔐</div>\' +
          \'<div class="tma-login-title">Authenticating…</div>\' +
          \'<div class="tma-login-sub">Signing you in with Telegram.</div>\' +
        \'</div>\';
        tmaRetryAuth(tabName);
        return;
      }
      /* Already attempted – go straight to the manual-retry fallback. */
      showLoginFallback(tabName);
      return;
    }
    /* Fallback for direct browser access (no Telegram context) */
    el.innerHTML = \'<div class="tma-login-prompt">\' +
      \'<div class="tma-login-icon">🔒</div>\' +
      \'<div class="tma-login-title">Login Required</div>\' +
      \'<div class="tma-login-sub">Sign in to manage your content.</div>\' +
      \'<a class="tma-login-btn" href="\' + escHtml(TMA_LOGIN_URL) + \'" target="_blank">Sign In</a>\' +
    \'</div>\';
  }

  /* ── Inline Telegram re-authentication ── */
  function tmaRetryAuth(tabName) {
    validateInitData()
      .then(function () {
        tmaAuthAttempts = 0;
        authRetried = false;
        if (tabName === \'home\')     { homeLoaded = false; loadHome(); }
        else if (tabName === \'content\')  { contentLoaded = false; loadContent(); }
        else if (tabName === \'tools\')  { toolsLoaded = false; loadTools(); }
        else if (tabName === \'commands\') { commandsLoaded = false; loadCommands(); }
        else if (tabName === \'media\')  { mediaLoaded = false; loadMedia(); }
        else if (tabName === \'settings\') { settingsLoaded = false; loadSettings(); }
      })
      .catch(function () {
        showLoginFallback(tabName);
      });
  }

  window.tmaRetryAuthClick = function (tabName) {
    haptic(\'light\');
    authRetried = false;
    tmaAuthAttempts = 0;
    var el = document.getElementById(\'tma-tab-\' + tabName);
    if (el) {
      el.innerHTML = \'<div class="tma-login-prompt">\' +
        \'<div class="tma-login-icon">🔐</div>\' +
        \'<div class="tma-login-title">Authenticating…</div>\' +
        \'<div class="tma-login-sub">Signing you in with Telegram.</div>\' +
      \'</div>\';
    }
    tmaRetryAuth(tabName);
  };

  function showLoginFallback(tabName) {
    var el = document.getElementById(\'tma-tab-\' + tabName);
    if (!el) return;
    if (twa && twa.initData) {
      el.innerHTML = \'<div class="tma-login-prompt">\' +
        \'<div class="tma-login-icon">🔒</div>\' +
        \'<div class="tma-login-title">Authentication Failed</div>\' +
        \'<div class="tma-login-sub">Could not sign in automatically. Please try again.</div>\' +
        \'<button class="tma-login-btn" onclick="tmaRetryAuthClick(\\\'\' + escHtml(tabName) + \'\\\')">Retry</button>\' +
      \'</div>\';
    } else {
      el.innerHTML = \'<div class="tma-login-prompt">\' +
        \'<div class="tma-login-icon">🔒</div>\' +
        \'<div class="tma-login-title">Login Required</div>\' +
        \'<div class="tma-login-sub">Sign in to manage your content.</div>\' +
        \'<a class="tma-login-btn" href="\' + escHtml(TMA_LOGIN_URL) + \'" target="_blank">Sign In</a>\' +
      \'</div>\';
    }
  }

  /* ── User info from initDataUnsafe ── */
  function renderUserInfo() {
    if (!twa) return;
    var user = (twa.initDataUnsafe && twa.initDataUnsafe.user) ? twa.initDataUnsafe.user : null;
    if (!user) return;

    var fullName = [user.first_name, user.last_name].filter(Boolean).join(\' \');
    var nameEl   = document.getElementById(\'tma-user-name\');
    if (nameEl && fullName) nameEl.textContent = fullName;

    var statusEl = document.getElementById(\'tma-header-status\');
    if (statusEl && user.username) statusEl.textContent = \'@\' + user.username;

    var imgEl  = document.getElementById(\'tma-avatar-img\');
    var initEl = document.getElementById(\'tma-avatar-initials\');
    if (imgEl && user.photo_url) {
      imgEl.src = user.photo_url;
      imgEl.style.display = \'block\';
      if (initEl) initEl.style.display = \'none\';
    } else if (initEl && user.first_name) {
      initEl.textContent = user.first_name[0].toUpperCase();
    }
  }

  /* ── initData server-side validation ── */
  function validateInitData() {
    if (!twa || !twa.initData || !TMA_VALIDATE_URL) return Promise.resolve();
    return fetch(TMA_VALIDATE_URL, {
      method      : \'POST\',
      credentials : \'same-origin\',
      headers     : { \'Content-Type\': \'application/json\' },
      body        : JSON.stringify({ init_data: twa.initData }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.valid) {
          /* Update the REST nonce so Content/Tools/Media requests are authenticated. */
          if (json.wp_nonce) { TMA_NONCE = json.wp_nonce; }
          /* Store session token for WebView environments where cookies may not persist. */
          if (json.tma_token) { TMA_SESSION_TOKEN = json.tma_token; }
          var statusEl = document.getElementById(\'tma-header-status\');
          if (statusEl) {
            statusEl.textContent = (json.wp_nonce || json.tma_token) ? \'✓ Signed In\' : \'✓ Verified\';
          }
          if (!json.wp_nonce && !json.tma_token) {
            return Promise.reject(\'auth_incomplete\');
          }
        } else {
          return Promise.reject(json && json.message ? json.message : \'validation_failed\');
        }
      })
      .catch(function (err) {
        return Promise.reject(typeof err === \'string\' ? err : \'network_error\');
      });
  }

  /* =========================================================
     SHOP TAB
     ========================================================= */
  var shopLoaded = false;

  function loadShop() {
    if (shopLoaded) return;
    var wrap = document.getElementById(\'tma-shop-wrap\');
    if (!wrap) return;
    wrap.innerHTML = \'<div class="tma-empty">Loading shop…</div>\';

    authFetch(TMA_SHOP_URL)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadShop(); }).catch(function () { showLoginFallback(\'shop\'); });
          }
          showLoginPrompt(\'shop\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        shopLoaded = true;
        renderShop(data);
      })
      .catch(function () {
        if (wrap) wrap.innerHTML = \'<div class="tma-empty tma-error">Failed to load shop.</div>\';
      });
  }

  function renderShop(data) {
    var wrap = document.getElementById(\'tma-shop-wrap\');
    if (!wrap) return;
    var html = \'\';

    /* ── Balance Card ── */
    html += \'<div class="tma-shop-balance-card">\';
    html += \'<div class="tma-shop-balance-icon">⭐</div>\';
    html += \'<div class="tma-shop-balance-amount">\' + escHtml(String(data.balance || 0)) + \'</div>\';
    html += \'<div class="tma-shop-balance-label">Stars Balance</div>\';
    html += \'</div>\';

    /* ── Pricing Cards ── */
    html += \'<div class="tma-settings-section">\';
    html += \'<div class="tma-settings-section-title">Purchase Stars</div>\';
    html += \'<div class="tma-shop-pricing">\';

    var packs = data.pricing || [
      { stars: 50, label: \'Starter\', description: \'Good for trying out\' },
      { stars: 200, label: \'Standard\', description: \'Most popular\' },
      { stars: 500, label: \'Pro\', description: \'Best value\' },
      { stars: 1000, label: \'Enterprise\', description: \'For power users\' },
    ];

    packs.forEach(function (pack) {
      var popular = pack.label === \'Standard\' ? \' tma-shop-popular\' : \'\';
      html += \'<div class="tma-shop-price-card\' + popular + \'">\';
      if (popular) html += \'<div class="tma-shop-popular-badge">Popular</div>\';
      html += \'<div class="tma-shop-price-stars">⭐ \' + escHtml(String(pack.stars)) + \'</div>\';
      html += \'<div class="tma-shop-price-label">\' + escHtml(pack.label) + \'</div>\';
      html += \'<div class="tma-shop-price-desc">\' + escHtml(pack.description) + \'</div>\';
      html += \'<button class="tma-shop-buy-btn" onclick="tmaBuyStars(\' + pack.stars + \')">Purchase</button>\';
      html += \'</div>\';
    });

    html += \'</div></div>\';

    /* ── Recent Transactions ── */
    if (data.recent_payments && data.recent_payments.length) {
      html += \'<div class="tma-settings-section">\';
      html += \'<div class="tma-settings-section-title">Recent Transactions</div>\';
      html += \'<div class="tma-settings-card">\';
      data.recent_payments.forEach(function (tx) {
        var date = tx.date ? new Date(tx.date).toLocaleDateString(undefined, { month: \'short\', day: \'numeric\' }) : \'\';
        html += \'<div class="tma-settings-item">\';
        html += \'<div class="tma-settings-item-icon">💳</div>\';
        html += \'<div class="tma-settings-item-body">\';
        html += \'<div class="tma-settings-item-label">⭐ +\' + escHtml(String(tx.amount || 0)) + \'</div>\';
        html += \'<div class="tma-settings-item-value">\' + escHtml(date) + \'</div>\';
        html += \'</div></div>\';
      });
      html += \'</div></div>\';
    }

    /* ── Info ── */
    html += \'<div class="tma-settings-section">\';
    html += \'<div class="tma-settings-card">\';
    html += \'<div class="tma-settings-help">Stars are used for premium AI tool executions and content generation. Purchases are processed securely via Telegram Stars.</div>\';
    html += \'</div></div>\';

    wrap.innerHTML = html;
  }

  window.tmaBuyStars = function (amount) {
    haptic(\'light\');
    /* Telegram Stars payments require invoking the Telegram WebApp payment API.
       This sends the user to the bot chat with a pre-configured invoice. */
    if (twa && twa.openTelegramLink && TMA_BOT_USERNAME) {
      twa.openTelegramLink(\'https://t.me/\' + TMA_BOT_USERNAME + \'?start=buy_\' + amount);
    } else {
      showToast(\'Open the bot chat to purchase Stars\', true);
    }
  };

  /* =========================================================
     SETTINGS TAB
     ========================================================= */
  var settingsLoaded = false;
  var settingsData   = {};

  function loadSettings() {
    if (settingsLoaded) return;
    var wrap = document.getElementById(\'tma-settings-wrap\');
    if (!wrap) return;
    wrap.innerHTML = \'<div class="tma-empty">Loading settings…</div>\';

    authFetch(TMA_SETTINGS_URL)
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          if (!authRetried && twa && twa.initData) {
            authRetried = true;
            return validateInitData().then(function () { loadSettings(); }).catch(function () { showLoginFallback(\'settings\'); });
          }
          showLoginPrompt(\'settings\'); return null;
        }
        authRetried = false;
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        settingsLoaded = true;
        settingsData = data;
        renderSettings(data);
      })
      .catch(function () {
        if (wrap) wrap.innerHTML = \'<div class="tma-empty tma-error">Failed to load settings.</div>\';
      });
  }

  function renderSettings(data) {
    var wrap = document.getElementById(\'tma-settings-wrap\');
    if (!wrap) return;
    var html = \'\';

    /* ── Account section ── */
    html += \'<div class="tma-settings-section">\';
    html += \'<div class="tma-settings-section-title">Account</div>\';
    html += \'<div class="tma-settings-card">\';

    /* Telegram identity */
    var tgUser = (twa && twa.initDataUnsafe && twa.initDataUnsafe.user) ? twa.initDataUnsafe.user : null;
    if (tgUser) {
      var tgName = [tgUser.first_name, tgUser.last_name].filter(Boolean).join(\' \');
      html += \'<div class="tma-settings-item">\';
      html += \'<div class="tma-settings-item-icon">✈️</div>\';
      html += \'<div class="tma-settings-item-body">\';
      html += \'<div class="tma-settings-item-label">Telegram</div>\';
      html += \'<div class="tma-settings-item-value">\' + escHtml(tgName) + (tgUser.username ? \' (@\' + escHtml(tgUser.username) + \')\' : \'\') + \'</div>\';
      html += \'</div></div>\';
    }

    /* WordPress link status */
    html += \'<div class="tma-settings-item">\';
    html += \'<div class="tma-settings-item-icon">🔗</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">WordPress Account</div>\';
    if (data.wp_linked) {
      html += \'<div class="tma-settings-item-value tma-linked">✓ Linked as \' + escHtml(data.wp_display_name || data.wp_username || \'\') + \'</div>\';
      html += \'</div>\';
      html += \'<button class="tma-settings-action tma-destructive-btn" onclick="tmaUnlinkAccount()">Unlink</button>\';
    } else {
      html += \'<div class="tma-settings-item-value tma-unlinked">Not linked</div>\';
      html += \'</div>\';
      html += \'<button class="tma-settings-action" onclick="tmaShowLinkAccount()">Link</button>\';
    }
    html += \'</div>\';

    html += \'</div></div>\';

    /* ── Account linking form (hidden by default) ── */
    html += \'<div class="tma-settings-section tma-link-form" id="tma-link-form" style="display:none">\';
    html += \'<div class="tma-settings-section-title">Link WordPress Account</div>\';
    html += \'<div class="tma-settings-card">\';
    html += \'<div class="tma-settings-help">Enter your WordPress username and password to connect your accounts. This links your Telegram identity with an existing WordPress user.</div>\';
    html += \'<div class="tma-settings-field">\';
    html += \'<label class="tma-settings-label" for="tma-link-username">WordPress Username</label>\';
    html += \'<input type="text" id="tma-link-username" class="tma-settings-input" autocomplete="username" placeholder="your_username" />\';
    html += \'</div>\';
    html += \'<div class="tma-settings-field">\';
    html += \'<label class="tma-settings-label" for="tma-link-password">Password</label>\';
    html += \'<input type="password" id="tma-link-password" class="tma-settings-input" autocomplete="current-password" placeholder="••••••••" />\';
    html += \'</div>\';
    html += \'<div id="tma-link-error" class="tma-settings-error" style="display:none"></div>\';
    html += \'<div class="tma-settings-actions">\';
    html += \'<button class="tma-settings-btn tma-btn-secondary" onclick="tmaHideLinkAccount()">Cancel</button>\';
    html += \'<button class="tma-settings-btn tma-btn-primary" id="tma-link-submit" onclick="tmaLinkAccount()">Link Account</button>\';
    html += \'</div>\';
    html += \'</div></div>\';

    /* ── Preferences section ── */
    html += \'<div class="tma-settings-section">\';
    html += \'<div class="tma-settings-section-title">Preferences</div>\';
    html += \'<div class="tma-settings-card">\';

    /* Theme */
    html += \'<div class="tma-settings-item">\';
    html += \'<div class="tma-settings-item-icon">🎨</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">Theme</div>\';
    html += \'<div class="tma-settings-item-value">\' + escHtml(twa && twa.colorScheme ? twa.colorScheme : \'system\') + \'</div>\';
    html += \'</div></div>\';

    /* Language preference */
    var currentLang = (data.preferences && data.preferences.language) ? data.preferences.language : \'auto\';
    html += \'<div class="tma-settings-item">\';
    html += \'<div class="tma-settings-item-icon">🌐</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">Language</div>\';
    html += \'</div>\';
    html += \'<select class="tma-settings-select" id="tma-pref-language" onchange="tmaSavePref(\\\'language\\\', this.value)">\';
    html += \'<option value="auto"\' + (currentLang === \'auto\' ? \' selected\' : \'\') + \'>Auto-detect</option>\';
    html += \'<option value="en"\' + (currentLang === \'en\' ? \' selected\' : \'\') + \'>English</option>\';
    html += \'<option value="es"\' + (currentLang === \'es\' ? \' selected\' : \'\') + \'>Español</option>\';
    html += \'<option value="fr"\' + (currentLang === \'fr\' ? \' selected\' : \'\') + \'>Français</option>\';
    html += \'<option value="de"\' + (currentLang === \'de\' ? \' selected\' : \'\') + \'>Deutsch</option>\';
    html += \'<option value="pt"\' + (currentLang === \'pt\' ? \' selected\' : \'\') + \'>Português</option>\';
    html += \'<option value="ru"\' + (currentLang === \'ru\' ? \' selected\' : \'\') + \'>Русский</option>\';
    html += \'<option value="zh"\' + (currentLang === \'zh\' ? \' selected\' : \'\') + \'>中文</option>\';
    html += \'<option value="ja"\' + (currentLang === \'ja\' ? \' selected\' : \'\') + \'>日本語</option>\';
    html += \'<option value="ar"\' + (currentLang === \'ar\' ? \' selected\' : \'\') + \'>العربية</option>\';
    html += \'</select>\';
    html += \'</div>\';

    /* Notifications toggle */
    var notifEnabled = data.preferences && data.preferences.notifications !== false;
    html += \'<div class="tma-settings-item">\';
    html += \'<div class="tma-settings-item-icon">🔔</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">Notifications</div>\';
    html += \'<div class="tma-settings-item-value">\' + (notifEnabled ? \'Enabled\' : \'Disabled\') + \'</div>\';
    html += \'</div>\';
    html += \'<label class="tma-toggle"><input type="checkbox" id="tma-pref-notifications"\' + (notifEnabled ? \' checked\' : \'\') + \' onchange="tmaSavePref(\\\'notifications\\\', this.checked)"><span class="tma-toggle-slider"></span></label>\';
    html += \'</div>\';

    /* Compact mode toggle */
    var compactMode = data.preferences && data.preferences.compact_mode === true;
    html += \'<div class="tma-settings-item">\';
    html += \'<div class="tma-settings-item-icon">📐</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">Compact Mode</div>\';
    html += \'<div class="tma-settings-item-value">\' + (compactMode ? \'On\' : \'Off\') + \'</div>\';
    html += \'</div>\';
    html += \'<label class="tma-toggle"><input type="checkbox" id="tma-pref-compact"\' + (compactMode ? \' checked\' : \'\') + \' onchange="tmaSavePref(\\\'compact_mode\\\', this.checked)"><span class="tma-toggle-slider"></span></label>\';
    html += \'</div>\';

    html += \'</div></div>\';

    /* ── Content Display section ── */
    html += \'<div class="tma-settings-section">\';
    html += \'<div class="tma-settings-section-title">Content Display</div>\';
    html += \'<div class="tma-settings-card">\';
    html += \'<div class="tma-settings-help">Choose which post types appear in the Content tab. When none are selected, all available types are shown.</div>\';

    var enabledCpts = (data.preferences && Array.isArray(data.preferences.enabled_post_types))
                      ? data.preferences.enabled_post_types : null;
    var availCpts   = data.available_post_types || [];
    availCpts.forEach(function (cpt) {
      var isOn = enabledCpts === null || enabledCpts.indexOf(cpt.name) !== -1;
      var tkHint = cpt.toolkit ? \' <span class="tma-tk-dot" title="\' + escHtml(cpt.toolkit) + \'">● \' + escHtml(cpt.toolkit) + \'</span>\' : \'\';
      html += \'<div class="tma-settings-item">\';
      html += \'<div class="tma-settings-item-icon">📄</div>\';
      html += \'<div class="tma-settings-item-body">\';
      html += \'<div class="tma-settings-item-label">\' + escHtml(cpt.label) + tkHint + \'</div>\';
      html += \'<div class="tma-settings-item-value">\' + escHtml(cpt.name) + \'</div>\';
      html += \'</div>\';
      html += \'<label class="tma-toggle"><input type="checkbox" data-cpt="\' + escHtml(cpt.name) + \'"\' + (isOn ? \' checked\' : \'\') + \' onchange="tmaSaveContentDisplay()"><span class="tma-toggle-slider"></span></label>\';
      html += \'</div>\';
    });

    html += \'</div></div>\';

    /* ── About section ── */
    html += \'<div class="tma-settings-section">\';
    html += \'<div class="tma-settings-section-title">About</div>\';
    html += \'<div class="tma-settings-card">\';

    html += \'<div class="tma-settings-item" onclick="tmaShareBot()">\';
    html += \'<div class="tma-settings-item-icon">📤</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">Share this Bot</div>\';
    html += \'<div class="tma-settings-item-value">\' + (TMA_BOT_USERNAME ? \'@\' + escHtml(TMA_BOT_USERNAME) : \'Share with friends\') + \'</div>\';
    html += \'</div>\';
    html += \'<span class="tma-settings-arrow">›</span>\';
    html += \'</div>\';

    html += \'<div class="tma-settings-item">\';
    html += \'<div class="tma-settings-item-icon">🌐</div>\';
    html += \'<div class="tma-settings-item-body">\';
    html += \'<div class="tma-settings-item-label">Website</div>\';
    html += \'<div class="tma-settings-item-value">\' + escHtml(TMA_SITE_NAME) + \'</div>\';
    html += \'</div></div>\';

    if (data.assistant_name) {
      html += \'<div class="tma-settings-item">\';
      html += \'<div class="tma-settings-item-icon">🤖</div>\';
      html += \'<div class="tma-settings-item-body">\';
      html += \'<div class="tma-settings-item-label">AI Assistant</div>\';
      html += \'<div class="tma-settings-item-value">\' + escHtml(data.assistant_name) + \'</div>\';
      html += \'</div></div>\';
    }

    html += \'</div></div>\';

    /* ── Feedback indicator (hidden by default) ── */
    html += \'<div class="tma-settings-toast" id="tma-settings-toast" style="display:none"></div>\';

    wrap.innerHTML = html;
  }

  /* ── Save a preference ── */
  window.tmaSavePref = function (key, value) {
    haptic(\'light\');
    var body = {};
    body[key] = value;
    var h = { \'Content-Type\': \'application/json\', \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    fetch(TMA_SETTINGS_URL, {
      method      : \'POST\',
      credentials : \'same-origin\',
      headers     : h,
      body        : JSON.stringify({ action: \'save_preferences\', preferences: body }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success) {
          showToast(\'✓ Saved\');
          haptic(\'notificationOccurred\', \'success\');
        } else {
          showToast(\'Failed to save\', true);
          haptic(\'notificationOccurred\', \'error\');
        }
      })
      .catch(function () {
        showToast(\'Network error\', true);
      });
  };

  /* ── Save Content Display toggles ── */
  window.tmaSaveContentDisplay = function () {
    haptic(\'light\');
    var checks = document.querySelectorAll(\'[data-cpt]\');
    var selected = [];
    var allChecked = true;
    checks.forEach(function (cb) {
      if (cb.checked) {
        selected.push(cb.getAttribute(\'data-cpt\'));
      } else {
        allChecked = false;
      }
    });
    /* When all are checked, store null (show all = default). */
    var value = allChecked ? null : selected;
    var h = { \'Content-Type\': \'application/json\', \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    fetch(TMA_SETTINGS_URL, {
      method: \'POST\', credentials: \'same-origin\', headers: h,
      body: JSON.stringify({ action: \'save_preferences\', preferences: { enabled_post_types: value } }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success) {
          showToast(\'✓ Content display saved\');
          haptic(\'notificationOccurred\', \'success\');
          /* Force Content tab to reload with new filter. */
          contentLoaded = false;
        } else {
          showToast(\'Failed to save\', true);
          haptic(\'notificationOccurred\', \'error\');
        }
      })
      .catch(function () { showToast(\'Network error\', true); });
  };

  /* ── Show / Hide link account form ── */
  window.tmaShowLinkAccount = function () {
    haptic(\'light\');
    var form = document.getElementById(\'tma-link-form\');
    if (form) form.style.display = \'block\';
    var inp = document.getElementById(\'tma-link-username\');
    if (inp) inp.focus();
  };

  window.tmaHideLinkAccount = function () {
    haptic(\'light\');
    var form = document.getElementById(\'tma-link-form\');
    if (form) form.style.display = \'none\';
    var err = document.getElementById(\'tma-link-error\');
    if (err) { err.style.display = \'none\'; err.textContent = \'\'; }
  };

  /* ── Link Telegram account to existing WordPress account ── */
  window.tmaLinkAccount = function () {
    haptic(\'light\');
    var usernameEl = document.getElementById(\'tma-link-username\');
    var passwordEl = document.getElementById(\'tma-link-password\');
    var errorEl    = document.getElementById(\'tma-link-error\');
    var submitEl   = document.getElementById(\'tma-link-submit\');
    var username   = usernameEl ? usernameEl.value.trim() : \'\';
    var password   = passwordEl ? passwordEl.value : \'\';

    if (!username || !password) {
      if (errorEl) { errorEl.textContent = \'Please enter both username and password.\'; errorEl.style.display = \'block\'; }
      return;
    }
    if (errorEl) errorEl.style.display = \'none\';
    if (submitEl) { submitEl.disabled = true; submitEl.textContent = \'Linking…\'; }

    var h = { \'Content-Type\': \'application/json\', \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    fetch(TMA_SETTINGS_URL, {
      method      : \'POST\',
      credentials : \'same-origin\',
      headers     : h,
      body        : JSON.stringify({ action: \'link_account\', username: username, password: password }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (submitEl) { submitEl.disabled = false; submitEl.textContent = \'Link Account\'; }
        if (json && json.success) {
          haptic(\'notificationOccurred\', \'success\');
          showToast(\'✓ Account linked!\');
          /* Refresh settings UI */
          settingsLoaded = false;
          loadSettings();
        } else {
          haptic(\'notificationOccurred\', \'error\');
          if (errorEl) { errorEl.textContent = (json && json.message) ? json.message : \'Failed to link account.\'; errorEl.style.display = \'block\'; }
        }
      })
      .catch(function () {
        if (submitEl) { submitEl.disabled = false; submitEl.textContent = \'Link Account\'; }
        if (errorEl) { errorEl.textContent = \'Network error. Please try again.\'; errorEl.style.display = \'block\'; }
      });
  };

  /* ── Unlink Telegram account from WordPress account ── */
  window.tmaUnlinkAccount = function () {
    haptic(\'light\');
    if (twa && twa.showPopup) {
      twa.showPopup({
        title   : \'Unlink Account\',
        message : \'Are you sure you want to unlink your WordPress account from Telegram?\',
        buttons : [
          { id: \'cancel\', type: \'cancel\', text: \'Cancel\' },
          { id: \'unlink\', type: \'destructive\', text: \'Unlink\' }
        ]
      }, function (btnId) {
        if (btnId === \'unlink\') doUnlink();
      });
    } else {
      doUnlink();
    }
  };

  function doUnlink() {
    var h = { \'Content-Type\': \'application/json\', \'X-WP-Nonce\': TMA_NONCE };
    if (TMA_SESSION_TOKEN) { h[\'X-WP-MCP-AI-TMA-Token\'] = TMA_SESSION_TOKEN; }
    fetch(TMA_SETTINGS_URL, {
      method      : \'POST\',
      credentials : \'same-origin\',
      headers     : h,
      body        : JSON.stringify({ action: \'unlink_account\' }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success) {
          haptic(\'notificationOccurred\', \'success\');
          showToast(\'Account unlinked\');
          settingsLoaded = false;
          loadSettings();
        } else {
          haptic(\'notificationOccurred\', \'error\');
          showToast((json && json.message) ? json.message : \'Failed to unlink\', true);
        }
      })
      .catch(function () {
        showToast(\'Network error\', true);
      });
  }

  /* ── Share bot ── */
  window.tmaShareBot = function () {
    haptic(\'light\');
    if (twa && twa.switchInlineQuery) {
      twa.switchInlineQuery(\'Check out this bot!\', [\'users\', \'groups\']);
    } else if (TMA_BOT_USERNAME) {
      var url = \'https://t.me/\' + TMA_BOT_USERNAME;
      if (twa && twa.openTelegramLink) { twa.openTelegramLink(url); }
      else { window.open(url, \'_blank\'); }
    }
  };

  /* ── Toast notification ── */
  function showToast(msg, isError) {
    var el = document.getElementById(\'tma-settings-toast\');
    if (!el) return;
    el.textContent = msg;
    el.className = \'tma-settings-toast\' + (isError ? \' tma-toast-error\' : \'\');
    el.style.display = \'block\';
    setTimeout(function () { el.style.display = \'none\'; }, 2500);
  }

  /* ── Haptic helper ── */
  function haptic(type, style) {
    if (!twa || !twa.HapticFeedback) return;
    try {
      if (type === \'selectionChanged\')      twa.HapticFeedback.selectionChanged();
      else if (type === \'notificationOccurred\') twa.HapticFeedback.notificationOccurred(style || \'success\');
      else                                  twa.HapticFeedback.impactOccurred(style || type);
    } catch (e) {}
  }

  /* ── Init ── */
  function init() {
    if (twa) {
      twa.ready();
      twa.expand();
      applyTheme();
      updateViewport();
      renderUserInfo();

      twa.onEvent(\'themeChanged\',    applyTheme);
      twa.onEvent(\'viewportChanged\', updateViewport);

      if (twa.BackButton) {
        twa.BackButton.onClick(function () {
          if (activeTab !== \'home\') tmaSwitchTab(\'home\');
        });
      }
      if (twa.MainButton) {
        twa.MainButton.hide();
      }
      /* Validate initData first so the WP auth cookie and nonce are ready
         before the Content/Tools/Media tab API calls are made. When
         validation fails fall back to the Settings tab which is always usable. */
      validateInitData().then(loadHome).catch(function () {
        tmaSwitchTab(\'settings\');
      });
    } else {
      /* No Telegram WebApp context (e.g. direct browser access). */
      loadHome();
    }
  }

  if (document.readyState === \'loading\') {
    document.addEventListener(\'DOMContentLoaded\', init);
  } else {
    init();
  }
})();
</script>
</body>
</html>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// =========================================================================
	// Shop / balance data endpoints
	// =========================================================================

	/**
	 * Return the current user's Stars balance, pricing packs, and recent payments.
	 *
	 * @since 1.1.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_shop_balance( $request ) {
		$user_id = get_current_user_id();
		$balance = $user_id ? (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true ) : 0;

		// Load recent payment history.
		$history = $user_id ? get_user_meta( $user_id, '_wp_mcp_ai_tma_payment_history', true ) : array();
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		// Return the 10 most recent.
		$recent = array_slice( array_reverse( $history ), 0, 10 );

		// Configurable pricing packs from settings.
		$settings       = get_option( 'wp_mcp_ai_settings', array() );
		$pricing_config = isset( $settings['telegram_stars_pricing'] ) ? $settings['telegram_stars_pricing'] : array();

		// Default packs if not configured.
		if ( empty( $pricing_config ) || ! is_array( $pricing_config ) ) {
			$pricing_config = array(
				array(
					'stars'       => 50,
					'label'       => __( 'Starter', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Good for trying out', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'stars'       => 200,
					'label'       => __( 'Standard', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Most popular', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'stars'       => 500,
					'label'       => __( 'Pro', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Best value', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'stars'       => 1000,
					'label'       => __( 'Enterprise', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'For power users', 'mcp-ai-wpoos-pro' ),
				),
			);
		}

		return rest_ensure_response(
			array(
				'balance'         => $balance,
				'pricing'         => $pricing_config,
				'recent_payments' => $recent,
			)
		);
	}

	// =========================================================================
	// Content management data endpoints
	// =========================================================================

	/**
	* Return a paginated list of posts for the requested post type together
	* with the full list of CPTs (base + any active pro-toolkit CPTs) that the
	* current user can edit.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return WP_REST_Response|WP_Error
	*/
	public function handle_content( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$page      = absint( $request->get_param( 'page' ) );
		$per_page  = absint( $request->get_param( 'per_page' ) );
		$search    = $request->get_param( 'search' );

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_invalid_post_type',
				__( 'Invalid post type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$post_type_obj = get_post_type_object( $post_type );

		// Load the user's enabled_post_types preference to filter the CPT bar.
		$user_id     = get_current_user_id();
		$preferences = $user_id ? get_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', true ) : array();
		$enabled_post_types = ( is_array( $preferences ) && isset( $preferences['enabled_post_types'] ) && is_array( $preferences['enabled_post_types'] ) )
			? $preferences['enabled_post_types']
			: null; // null = show all (default).

		// When the current user cannot edit the requested post type, return an
		// empty post list instead of a hard 403 so that the Mini App client does
		// not misinterpret it as an authentication failure and enter a retry loop.
		// The CPT bar (built below) is already filtered by capability, so the UI
		// will show "No content found" rather than getting stuck.
		$posts = array();
		$query = null;

		if ( $post_type_obj && current_user_can( $post_type_obj->cap->edit_posts ) ) {
			$query_args = array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			);

			if ( ! empty( $search ) ) {
				$query_args['s'] = $search;
			}

			$query = new WP_Query( $query_args );
		}

		if ( $query ) {
			foreach ( $query->posts as $post ) {
				$excerpt = $post->post_excerpt;
				if ( empty( $excerpt ) ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '…' );
				}
				$posts[] = array(
					'id'           => $post->ID,
					'title'        => get_the_title( $post ),
					'status'       => $post->post_status,
					'date'         => $post->post_date,
					'modified'     => $post->post_modified,
					'link'         => (string) get_edit_post_link( $post->ID, 'raw' ),
					'excerpt'      => $excerpt,
					'post_content' => $post->post_content,
				);
			}
		}

		// Build the list of all accessible CPTs, enriched with active-toolkit info.
		$all_types      = get_post_types( array( 'show_ui' => true ), 'objects' );
		$active_toolkits = $this->get_active_toolkits();
		$cpt_list       = array();

		foreach ( $all_types as $type ) {
			if ( ! current_user_can( $type->cap->edit_posts ) ) {
				continue;
			}

			// When the user has configured an enabled_post_types allowlist, skip
			// any post type not explicitly included.
			if ( null !== $enabled_post_types && ! in_array( $type->name, $enabled_post_types, true ) ) {
				continue;
			}

			// Determine which toolkit (if any) registered this CPT.
			$toolkit_label = '';
			foreach ( $active_toolkits as $tk ) {
				if ( in_array( $type->name, $tk['post_types'], true ) ) {
					$toolkit_label = $tk['label'];
					break;
				}
			}

			$counts    = wp_count_posts( $type->name );
			$cpt_list[] = array(
				'name'    => $type->name,
				'label'   => $type->label,
				'count'   => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'toolkit' => $toolkit_label,
			);
		}

		return rest_ensure_response(
			array(
				'posts'      => $posts,
				'total'      => $query ? (int) $query->found_posts : 0,
				'pages'      => $query ? (int) $query->max_num_pages : 0,
				'post_types' => $cpt_list,
			)
		);
	}

	/**
	 * Create or update a post from the Mini App content editor.
	 *
	 * When `id` is 0 a new post is created; otherwise the existing post is updated.
	 * The user must have the appropriate capability for the target post type.
	 *
	 * @since 1.1.3
	 *
	 * @param WP_REST_Request $request Request object with title, content, status, post_type, and optional id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_content( $request ) {
		$post_id   = absint( $request->get_param( 'id' ) );
		$post_type = $request->get_param( 'post_type' );
		$title     = $request->get_param( 'title' );
		$content   = $request->get_param( 'content' );
		$status    = $request->get_param( 'status' );

		// Validate post type.
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_invalid_post_type',
				__( 'Invalid post type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$post_type_obj = get_post_type_object( $post_type );

		// Sanitize status to an allowed value.
		$allowed_statuses = array( 'draft', 'publish', 'pending', 'future' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		// Build the base post data array.
		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => wp_kses_post( $content ),
			'post_status'  => $status,
		);

		// Only set scheduling dates when status is 'future' and a valid date was provided.
		if ( 'future' === $status ) {
			$date_input = $request->get_param( 'date' );
			if ( ! empty( $date_input ) ) {
				// datetime-local input sends format 'YYYY-MM-DDTHH:MM'.
				$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date_input );
				if ( ! $dt ) {
					// Fall back to strtotime for other common formats.
					$ts = strtotime( $date_input );
					if ( $ts ) {
						$dt = new DateTime( '@' . $ts );
					}
				}
				if ( $dt ) {
					$post_data['post_date']     = $dt->format( 'Y-m-d H:i:s' );
					$post_data['post_date_gmt'] = get_gmt_from_date( $post_data['post_date'] );
				}
			}
		}

		// Creating a new post.
		if ( 0 === $post_id ) {
			if ( ! current_user_can( $post_type_obj->cap->create_posts ) ) {
				return new WP_Error(
					'wp_mcp_ai_telegram_forbidden',
					__( 'You do not have permission to create this content.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			$post_data['post_author'] = get_current_user_id();
			$new_post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $new_post_id ) ) {
				return $new_post_id;
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'id'      => $new_post_id,
					'message' => __( 'Content created successfully.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Updating an existing post.
		$existing = get_post( $post_id );
		if ( ! $existing || $existing->post_type !== $post_type ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_not_found',
				__( 'Post not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_forbidden',
				__( 'You do not have permission to edit this content.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$post_data['ID'] = $post_id;
		unset( $post_data['post_type'] ); // Cannot change post type on update.
		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $post_id,
				'message' => __( 'Content updated successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	* Return all available tools (from the tool registry, grouped by active toolkit)
	* and all registered slash commands accessible to the current user.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return WP_REST_Response
	*/
	public function handle_tools( $request ) {
		$result = array(
			'toolkits'       => array(),
			'tools'          => array(),
			'slash_commands' => array(),
		);

		// Enumerate active pro toolkits so the UI can group tools.
		$active_toolkits          = $this->get_active_toolkits();
		$result['toolkits']       = array_values( $active_toolkits );

		// Collect tools from the tool registry.
		if ( function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			$registry = wp_mcp_ai_get_tool_registry();
			if ( $registry ) {
				$group_map  = method_exists( $registry, 'get_tool_group_map' ) ? $registry->get_tool_group_map() : array();
				$all_tools  = $registry->get_all_tools();

				foreach ( $all_tools as $slug => $tool ) {
					// Resolve which toolkit this tool belongs to (if any).
					$toolkit_label = '';
					foreach ( $active_toolkits as $tk ) {
						if ( in_array( $slug, $tk['tool_slugs'], true ) ) {
							$toolkit_label = $tk['label'];
							break;
						}
					}

				$params = method_exists( $tool, 'get_parameters_schema' ) ? $tool->get_parameters_schema() : array();

					$result['tools'][] = array(
						'slug'        => $slug,
						'name'        => method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug,
						'description' => method_exists( $tool, 'get_description' ) ? $tool->get_description() : '',
						'group'       => isset( $group_map[ $slug ] ) ? $group_map[ $slug ] : '',
						'toolkit'     => $toolkit_label,
						'parameters'  => $params,
					);
				}
			}
		}

		// Collect slash commands the current user can run.
		if ( function_exists( 'wp_mcp_ai_get_slash_command_handler' ) ) {
			$handler = wp_mcp_ai_get_slash_command_handler();
			if ( $handler && method_exists( $handler, 'get_commands' ) ) {
				$commands = $handler->get_commands( true );
				foreach ( $commands as $name => $config ) {
					$result['slash_commands'][] = array(
						'name'        => '/' . $name,
						'description' => isset( $config['description'] ) ? $config['description'] : '',
						'usage'       => isset( $config['usage'] ) ? $config['usage'] : '/' . $name,
						'aliases'     => isset( $config['aliases'] ) ? $config['aliases'] : array(),
					);
				}
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Execute a tool from the Mini App and return the result.
	 *
	 * @since 1.1.3
	 *
	 * @param WP_REST_Request $request Request with 'slug' and optional 'arguments'.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_execute_tool( $request ) {
		$slug      = $request->get_param( 'slug' );
		$arguments = $request->get_param( 'arguments' );

		if ( ! is_array( $arguments ) ) {
			$arguments = array();
		}

		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return new WP_Error(
				'wp_mcp_ai_tma_no_registry',
				__( 'Tool registry not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry ) {
			return new WP_Error(
				'wp_mcp_ai_tma_no_registry',
				__( 'Tool registry not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool = $registry->get_tool( $slug );
		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tma_tool_not_found',
				__( 'Tool not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Build execution context.
		$context = array(
			'user_id' => get_current_user_id(),
			'source'  => 'telegram_mini_app',
		);

		$result = $tool->execute( $arguments, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'result'  => $result,
			)
		);
	}

	/**
	* Return a paginated list of media library items.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return WP_REST_Response
	*/
	public function handle_media( $request ) {
		// Return an empty list when the user lacks upload_files instead of a
		// hard 403 that the Mini App client would misinterpret as an auth failure.
		if ( ! current_user_can( 'upload_files' ) ) {
			return rest_ensure_response(
				array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
				)
			);
		}

		$page     = absint( $request->get_param( 'page' ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$search   = $request->get_param( 'search' );
		$type     = $request->get_param( 'type' );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		// Filter by MIME type prefix (e.g. 'image', 'video', 'application').
		if ( ! empty( $type ) ) {
			$query_args['post_mime_type'] = sanitize_text_field( $type );
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$thumb     = wp_get_attachment_image_src( $post->ID, 'thumbnail' );
			$full_url  = wp_get_attachment_url( $post->ID );
			$mime_type = get_post_mime_type( $post->ID );
			$filepath  = get_attached_file( $post->ID );
			$filesize  = ( $filepath && file_exists( $filepath ) ) ? size_format( (int) filesize( $filepath ) ) : '';

			$items[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => $full_url,
				'thumb'     => $thumb ? $thumb[0] : '',
				'mime_type' => $mime_type,
				'date'      => $post->post_date,
				'filesize'  => $filesize,
			);
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			)
		);
	}

	// =========================================================================
	// Settings endpoints
	// =========================================================================

	/**
	* Return the current user's Mini App settings, account link status,
	* preferences, and contextual information for the Settings tab.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return WP_REST_Response
	*/
	public function handle_get_settings( $request ) {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		// Check if a Telegram ID is linked.
		$telegram_id = get_user_meta( $user_id, self::META_TELEGRAM_ID, true );
		$wp_linked   = ! empty( $telegram_id );

		// User preferences stored as user meta.
		$preferences = get_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', true );
		if ( ! is_array( $preferences ) ) {
			$preferences = array(
				'language'      => 'auto',
				'notifications' => true,
				'compact_mode'  => false,
			);
		}

		// Resolve the active assistant name.
		$connection     = $this->get_active_telegram_connection();
		$assistant_slug = $this->resolve_mini_app_assistant( new WP_REST_Request(), $connection );
		$assistant_name = '';
		if ( ! empty( $assistant_slug ) ) {
			if ( is_numeric( $assistant_slug ) ) {
				$assistant_post = get_post( (int) $assistant_slug );
				if ( $assistant_post ) {
					$assistant_name = $assistant_post->post_title;
				}
			} else {
				$assistant_name = $assistant_slug;
			}
		}

		// Group & channel settings for this connection.
		$group_settings = array(
			'enable_groups'   => ! empty( $connection['enable_groups'] ),
			'enable_channels' => ! empty( $connection['enable_channels'] ),
			'require_mention' => ! empty( $connection['require_mention'] ),
		);

		// Build the list of all accessible CPTs for the Content Display settings.
		$all_types       = get_post_types( array( 'show_ui' => true ), 'objects' );
		$active_toolkits = $this->get_active_toolkits();
		$available_cpts  = array();

		foreach ( $all_types as $type ) {
			if ( ! current_user_can( $type->cap->edit_posts ) ) {
				continue;
			}
			$toolkit_label = '';
			foreach ( $active_toolkits as $tk ) {
				if ( in_array( $type->name, $tk['post_types'], true ) ) {
					$toolkit_label = $tk['label'];
					break;
				}
			}
			$available_cpts[] = array(
				'name'    => $type->name,
				'label'   => $type->label,
				'toolkit' => $toolkit_label,
			);
		}

		return rest_ensure_response(
			array(
				'wp_linked'       => $wp_linked,
				'wp_username'     => $user ? $user->user_login : '',
				'wp_display_name' => $user ? $user->display_name : '',
				'wp_email'        => $user ? $user->user_email : '',
				'preferences'     => $preferences,
				'assistant_name'  => $assistant_name,
				'group_settings'  => $group_settings,
				'available_post_types' => $available_cpts,
			)
		);
	}

	/**
	* Handle settings write operations: save preferences, link/unlink accounts.
	*
	* The `action` parameter determines which operation to perform:
	*   - `save_preferences` – Persist user preferences (language, notifications, etc.).
	*   - `link_account`     – Link the current Telegram identity to an existing WP user.
	*   - `unlink_account`   – Remove the Telegram↔WP link for the current user.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object. Expects JSON body with 'action' plus action-specific fields.
	* @return WP_REST_Response|WP_Error
	*/
	public function handle_save_settings( $request ) {
		$action  = $request->get_param( 'action' );
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_tma_not_authenticated',
				__( 'You must be authenticated to change settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 401 )
			);
		}

		switch ( $action ) {
			case 'save_preferences':
				return $this->handle_save_preferences( $request, $user_id );

			case 'link_account':
				return $this->handle_link_account( $request, $user_id );

			case 'unlink_account':
				return $this->handle_unlink_account( $user_id );

			default:
				return new WP_Error(
					'wp_mcp_ai_tma_invalid_action',
					__( 'Invalid settings action.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	* Save user preferences.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request with 'preferences' JSON object.
	* @param int             $user_id Current WordPress user ID.
	* @return WP_REST_Response
	*/
	protected function handle_save_preferences( $request, $user_id ) {
		$incoming = $request->get_param( 'preferences' );
		if ( ! is_array( $incoming ) ) {
			$incoming = array();
		}

		$existing = get_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', true );
		if ( ! is_array( $existing ) ) {
			$existing = array(
				'language'      => 'auto',
				'notifications' => true,
				'compact_mode'  => false,
			);
		}

		// Whitelist and sanitize allowed preference keys.
		$allowed = array( 'language', 'notifications', 'compact_mode' );
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				if ( 'language' === $key ) {
					$existing[ $key ] = sanitize_text_field( (string) $incoming[ $key ] );
				} else {
					$existing[ $key ] = (bool) $incoming[ $key ];
				}
			}
		}

		// Handle enabled_post_types: array of post-type slugs or null (show all).
		if ( array_key_exists( 'enabled_post_types', $incoming ) ) {
			if ( is_array( $incoming['enabled_post_types'] ) ) {
				$existing['enabled_post_types'] = array_values(
					array_map( 'sanitize_key', $incoming['enabled_post_types'] )
				);
			} else {
				// null or non-array resets to "show all".
				unset( $existing['enabled_post_types'] );
			}
		}

		update_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', $existing );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	* Link the current Telegram-provisioned WP user to an existing WordPress
	* account by verifying the target account's credentials.
	*
	* After verification the Telegram meta keys are moved from the
	* auto-created user to the target account and the auto-created user is
	* cleaned up to avoid orphaned records.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request with 'username' and 'password'.
	* @param int             $user_id Current WordPress user ID (auto-created Telegram user).
	* @return WP_REST_Response|WP_Error
	*/
	protected function handle_link_account( $request, $user_id ) {
		$username = sanitize_user( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );

		if ( empty( $username ) || empty( $password ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Username and password are required.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Authenticate the target WordPress user.
		$target_user = wp_authenticate( $username, $password );

		if ( is_wp_error( $target_user ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Invalid username or password.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Prevent linking to the same user that is already linked.
		$existing_tg_id = get_user_meta( $target_user->ID, self::META_TELEGRAM_ID, true );
		$current_tg_id  = get_user_meta( $user_id, self::META_TELEGRAM_ID, true );

		if ( ! empty( $existing_tg_id ) && $existing_tg_id !== $current_tg_id ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'This WordPress account is already linked to a different Telegram user.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Move Telegram meta from the auto-created user to the target user.
		if ( $target_user->ID !== $user_id && ! empty( $current_tg_id ) ) {
			$tg_username = get_user_meta( $user_id, self::META_TELEGRAM_USERNAME, true );
			$tg_photo    = get_user_meta( $user_id, '_wp_mcp_ai_telegram_photo_url', true );

			update_user_meta( $target_user->ID, self::META_TELEGRAM_ID, $current_tg_id );
			if ( $tg_username ) {
				update_user_meta( $target_user->ID, self::META_TELEGRAM_USERNAME, $tg_username );
			}
			if ( $tg_photo ) {
				update_user_meta( $target_user->ID, '_wp_mcp_ai_telegram_photo_url', $tg_photo );
			}

			// Clean up the auto-created user's Telegram meta.
			delete_user_meta( $user_id, self::META_TELEGRAM_ID );
			delete_user_meta( $user_id, self::META_TELEGRAM_USERNAME );
			delete_user_meta( $user_id, '_wp_mcp_ai_telegram_photo_url' );
		} elseif ( $target_user->ID === $user_id ) {
			// Already the same user – just ensure the meta is set.
			if ( ! empty( $current_tg_id ) ) {
				update_user_meta( $target_user->ID, self::META_TELEGRAM_ID, $current_tg_id );
			}
		}

		/**
		* Fires after a Telegram identity has been linked to an existing WordPress user.
		*
		* @since 1.0.0
		*
		* @param int    $target_user_id WordPress user the Telegram account was linked to.
		* @param int    $old_user_id    Original auto-created WordPress user (may be the same).
		* @param string $telegram_id    Telegram user ID.
		*/
		do_action( 'wp_mcp_ai_telegram_account_linked', $target_user->ID, $user_id, $current_tg_id );

		return rest_ensure_response(
			array(
				'success'         => true,
				'wp_user_id'      => $target_user->ID,
				'wp_display_name' => $target_user->display_name,
			)
		);
	}

	/**
	* Remove the Telegram↔WordPress account link.
	*
	* @since 1.0.0
	*
	* @param int $user_id Current WordPress user ID.
	* @return WP_REST_Response
	*/
	protected function handle_unlink_account( $user_id ) {
		delete_user_meta( $user_id, self::META_TELEGRAM_ID );
		delete_user_meta( $user_id, self::META_TELEGRAM_USERNAME );
		delete_user_meta( $user_id, '_wp_mcp_ai_telegram_photo_url' );

		/**
		* Fires after a Telegram identity has been unlinked from a WordPress user.
		*
		* @since 1.0.0
		*
		* @param int $user_id WordPress user whose Telegram link was removed.
		*/
		do_action( 'wp_mcp_ai_telegram_account_unlinked', $user_id );

		return rest_ensure_response( array( 'success' => true ) );
	}

	// =========================================================================
	// Analytics endpoint (Home tab dashboard)
	// =========================================================================

	/**
	* Return aggregated analytics data for the Home tab dashboard.
	*
	* Queries the token tracking database for daily usage, cost breakdown
	* by provider, and tool usage over the requested period. Falls back to
	* user-meta totals when the database tracker is unavailable.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return WP_REST_Response
	*/
	public function handle_analytics( $request ) {
		$user_id = get_current_user_id();
		$days    = absint( $request->get_param( 'days' ) );
		if ( $days < 1 || $days > 90 ) {
			$days = 7;
		}

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		$summary     = array(
			'total_tokens'   => 0,
			'total_cost'     => 0,
			'tools_used'     => 0,
			'total_requests' => 0,
		);
		$daily       = array();
		$by_provider = array();
		$by_tool     = array();

		// Try the database tracker first (most detailed data).
		if ( class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			$db = new WP_MCP_AI_Token_Tracking_Database();

			if ( method_exists( $db, 'get_aggregated_by_date' ) ) {
				$daily_raw = $db->get_aggregated_by_date( $start_date, $end_date );
				if ( is_array( $daily_raw ) ) {
					foreach ( $daily_raw as $row ) {
						$date_label = isset( $row['date'] ) ? $row['date'] : '';
						// Shorten to Mon/Tue style for mobile-friendly labels.
						if ( $date_label ) {
							$ts         = strtotime( $date_label . ' UTC' );
							$date_label = $ts ? gmdate( 'D', $ts ) : $date_label;
						}
						$tokens = isset( $row['total_tokens'] ) ? (int) $row['total_tokens'] : 0;
						$cost   = isset( $row['total_cost'] ) ? (float) $row['total_cost'] : 0;
						$daily[] = array(
							'date'         => isset( $row['date'] ) ? $row['date'] : '',
							'label'        => $date_label,
							'total_tokens' => $tokens,
							'total_cost'   => $cost,
						);
						$summary['total_tokens'] += $tokens;
						$summary['total_cost']   += $cost;
					}
				}
			}

			if ( method_exists( $db, 'get_aggregated_by_provider' ) ) {
				$providers_raw = $db->get_aggregated_by_provider( $start_date, $end_date );
				if ( is_array( $providers_raw ) ) {
					foreach ( $providers_raw as $row ) {
						$by_provider[] = array(
							'provider'     => isset( $row['provider'] ) ? $row['provider'] : 'Unknown',
							'total_tokens' => isset( $row['total_tokens'] ) ? (int) $row['total_tokens'] : 0,
							'total_cost'   => isset( $row['total_cost'] ) ? (float) $row['total_cost'] : 0,
						);
					}
				}
			}

			if ( method_exists( $db, 'get_aggregated_by_tool' ) ) {
				$tools_raw = $db->get_aggregated_by_tool( $start_date, $end_date );
				if ( is_array( $tools_raw ) ) {
					foreach ( $tools_raw as $row ) {
						$by_tool[] = array(
							'tool'         => isset( $row['tool'] ) ? $row['tool'] : '—',
							'total_tokens' => isset( $row['total_tokens'] ) ? (int) $row['total_tokens'] : 0,
							'total_cost'   => isset( $row['total_cost'] ) ? (float) $row['total_cost'] : 0,
						);
						++$summary['tools_used'];
					}
				}
			}

			// Count total requests from user usage if available.
			if ( method_exists( $db, 'get_user_usage' ) ) {
				$user_rows = $db->get_user_usage( $user_id, $start_date, $end_date );
				$summary['total_requests'] = is_array( $user_rows ) ? count( $user_rows ) : 0;
			}
		}

		// Fallback to user meta totals when the DB tracker is empty.
		if ( 0 === $summary['total_tokens'] && class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			$usage_tracker = new WP_MCP_AI_Usage_Tracker();
			if ( method_exists( $usage_tracker, 'get_usage_for_user' ) ) {
				$usage = $usage_tracker->get_usage_for_user( $user_id );
				if ( is_array( $usage ) ) {
					foreach ( $usage as $provider => $models ) {
						if ( ! is_array( $models ) ) {
							continue;
						}
						$prov_tokens = 0;
						$prov_reqs   = 0;
						foreach ( $models as $model_data ) {
							if ( ! is_array( $model_data ) ) {
								continue;
							}
							$prov_tokens += isset( $model_data['total_tokens'] ) ? (int) $model_data['total_tokens'] : 0;
							$prov_reqs   += isset( $model_data['requests'] ) ? (int) $model_data['requests'] : 0;
						}
						$summary['total_tokens']   += $prov_tokens;
						$summary['total_requests'] += $prov_reqs;
						if ( $prov_tokens > 0 ) {
							$by_provider[] = array(
								'provider'     => $provider,
								'total_tokens' => $prov_tokens,
								'total_cost'   => 0,
							);
						}
					}
				}
			}
			if ( method_exists( 'WP_MCP_AI_Usage_Tracker', 'calculate_user_total_cost' ) ) {
				$summary['total_cost'] = (float) WP_MCP_AI_Usage_Tracker::calculate_user_total_cost( $user_id );
			}
		}

		return rest_ensure_response(
			array(
				'summary'     => $summary,
				'daily'       => $daily,
				'by_provider' => $by_provider,
				'by_tool'     => $by_tool,
			)
		);
	}

	// =========================================================================
	// initData validation endpoint
	// =========================================================================

	/**
	* Validate Telegram Mini App initData using HMAC-SHA256.
	*
	* The Mini App initData verification algorithm (per Telegram docs) differs
	* from the Login Widget:
	*   1. Parse the initData query string; remove the `hash` field.
	*   2. Sort remaining key=value pairs alphabetically.
	*   3. data_check_string = implode("\n", sorted_pairs)
	*   4. secret_key = HMAC-SHA256("WebAppData", bot_token)  [raw binary]
	*   5. expected_hash = HMAC-SHA256(data_check_string, secret_key)  [hex]
	*   6. Compare expected_hash with the received hash (constant-time).
	*   7. Validate auth_date freshness (max INIT_DATA_MAX_AGE seconds).
	*
	* @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object. Expects JSON body with 'init_data'.
	* @return WP_REST_Response|WP_Error
	*/
	public function handle_validate_init_data( $request ) {
		$raw_init_data = $request->get_param( 'init_data' );

		if ( empty( $raw_init_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_missing_init_data',
				__( 'init_data is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$connection = $this->get_active_telegram_connection();

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_not_configured',
				__( 'No active Telegram connection found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_token_error',
				__( 'Server configuration error.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$result = $this->verify_init_data( $raw_init_data, $bot_token );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'valid'   => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}

		// Return the parsed user data (safe to expose – hash already verified).
		// Also find/create the WordPress user and establish a session so the
		// Mini App can use the returned nonce for Content/Tools/Media API calls.
		$tg_user   = isset( $result['user'] ) ? $result['user'] : array();
		$auth_data = array(
			'id'         => isset( $tg_user['id'] ) ? $tg_user['id'] : '',
			'first_name' => isset( $tg_user['first_name'] ) ? $tg_user['first_name'] : '',
			'last_name'  => isset( $tg_user['last_name'] ) ? $tg_user['last_name'] : '',
			'username'   => isset( $tg_user['username'] ) ? $tg_user['username'] : '',
			'photo_url'  => isset( $tg_user['photo_url'] ) ? $tg_user['photo_url'] : '',
			'auth_date'  => isset( $result['auth_date'] ) ? $result['auth_date'] : '',
		);

		$wp_nonce  = null;
		$tma_token = null;

		$wp_user_id = $this->find_or_create_wp_user( $auth_data, $connection );

		if ( ! is_wp_error( $wp_user_id ) ) {
			wp_set_current_user( $wp_user_id );

			// Sync the logged-in cookie into $_COOKIE so that the
			// wp_create_nonce() call below uses the correct session token.
			// Without this, the nonce is created with an empty/stale session
			// token and subsequent requests that carry the auth cookie fail
			// rest_cookie_check_errors with rest_cookie_invalid_nonce (403).
			$sync_cookie = function ( $value ) {
				// The value originates from wp_generate_auth_cookie() inside
				// wp_set_auth_cookie(); it must be stored verbatim so that
				// wp_get_session_token() extracts the same session token the
				// browser will send.  sanitize_text_field() would corrupt it.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$_COOKIE[ LOGGED_IN_COOKIE ] = $value;
			};
			add_action( 'set_logged_in_cookie', $sync_cookie );
			wp_set_auth_cookie( $wp_user_id, false );
			remove_action( 'set_logged_in_cookie', $sync_cookie );

			$wp_nonce = wp_create_nonce( 'wp_rest' );

			// Generate a short-lived session token so that subsequent
			// Content / Tools / Media requests can authenticate even
			// when the auth cookie does not persist in Telegram's WebView.
			$raw_token = bin2hex( random_bytes( 20 ) );
			$token_hash = hash( 'sha256', $raw_token );
			set_transient( 'wp_mcp_ai_tma_' . $token_hash, $wp_user_id, HOUR_IN_SECONDS );
			$tma_token = $raw_token;

			/**
			* Fires after a Telegram Mini App user has been authenticated as a WordPress user.
			*
			* @since 1.0.0
			*
			* @param int   $wp_user_id WordPress user ID.
			* @param array $auth_data  Telegram user data from verified initData.
			*/
			do_action( 'wp_mcp_ai_telegram_mini_app_wp_user_logged_in', $wp_user_id, $auth_data );
		} else {
			// Log the failure so site operators can diagnose auth issues.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Telegram Mini App: could not find or create WordPress user.',
					array(
						'code'        => $wp_user_id->get_error_code(),
						'message'     => $wp_user_id->get_error_message(),
						'telegram_id' => isset( $auth_data['id'] ) ? $auth_data['id'] : '',
					)
				);
			}
		}

		return rest_ensure_response(
			array(
				'valid'     => true,
				'user'      => $tg_user ? $tg_user : null,
				'auth_date' => isset( $result['auth_date'] ) ? (int) $result['auth_date'] : null,
				'wp_nonce'  => $wp_nonce,
				'tma_token' => $tma_token,
			)
		);
	}

	/**
	* Sanitize the initData parameter for the /validate endpoint.
	*
	* Unlike sanitize_text_field(), this callback preserves percent-encoded
	* characters (e.g. %7B, %22, %3A) that are part of the URL-encoded query
	* string. Stripping them would corrupt the payload and cause HMAC-SHA256
	* verification to fail because the data-check string would no longer match
	* what Telegram originally signed.
	*
	* @since 1.0.0
	*
	* @param string $value Raw initData string from window.Telegram.WebApp.initData.
	* @return string Sanitized initData with URL encoding intact.
	*/
	public function sanitize_init_data( $value ) {
		$value = (string) $value;
		$value = wp_check_invalid_utf8( $value );
		// Strip null bytes only; percent-encoded sequences must remain intact
		// for the HMAC-SHA256 verification in verify_init_data().
		$value = preg_replace( '/\x00/', '', $value );
		// Remove any HTML/PHP tags as a safety measure.
		$value = wp_strip_all_tags( $value );
		return $value;
	}

	/**
	* Verify Telegram Mini App initData using HMAC-SHA256.
	*
	* @since 1.0.0
	*
	* @param string $raw_init_data Raw initData string from window.Telegram.WebApp.initData.
	* @param string $bot_token     Plaintext Telegram bot token.
	* @return array|WP_Error Parsed data on success, WP_Error on failure.
	*/
	public function verify_init_data( $raw_init_data, $bot_token ) {
		// Parse URL-encoded initData into key => value pairs.
		$params = array();
		parse_str( $raw_init_data, $params );

		if ( empty( $params['hash'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_missing_hash',
				__( 'Missing hash in initData.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$received_hash = $params['hash'];
		unset( $params['hash'] );

		// Build data-check string: key=value pairs sorted alphabetically, joined with \n.
		$check_pairs = array();
		foreach ( $params as $key => $value ) {
			$check_pairs[] = $key . '=' . $value;
		}
		sort( $check_pairs );
		$data_check_string = implode( "\n", $check_pairs );

		// Mini App HMAC secret: key="WebAppData", data=bot_token (per Telegram Mini App spec). NOTE: PHP hash_hmac(algo, data, key) order.
		$hmac_secret_raw = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
		$expected_hash   = hash_hmac( 'sha256', $data_check_string, $hmac_secret_raw );

		if ( ! hash_equals( $expected_hash, $received_hash ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_invalid_hash',
				__( 'initData verification failed: invalid hash.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Validate auth_date freshness.
		if ( empty( $params['auth_date'] ) || ( time() - (int) $params['auth_date'] ) > self::INIT_DATA_MAX_AGE ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_expired',
				__( 'initData has expired. Please reopen the Mini App.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Decode user JSON if present.
		$parsed        = $params;
		$parsed['hash'] = $received_hash;
		if ( ! empty( $params['user'] ) ) {
			$decoded = json_decode( $params['user'], true );
			if ( is_array( $decoded ) ) {
				$parsed['user'] = $decoded;
			}
		}

		return $parsed;
	}

	// =========================================================================
	// Inline CSS
	// =========================================================================

	/**
	* Return the inline CSS for the Mini App shell.
	*
	* Uses CSS custom properties mapped to Telegram theme params so every colour
	* automatically matches the user's Telegram app theme (light/dark/custom).
	*
	* @since 1.0.0
	*
	* @return string CSS string.
	*/
	protected function get_mini_app_css() {
		return '
:root{
  --tma-bg:#ffffff;--tma-text:#000000;--tma-hint:#999999;
  --tma-link:#2481cc;--tma-btn:#2481cc;--tma-btn-text:#ffffff;
  --tma-secondary-bg:#f1f1f1;--tma-header-bg:#ffffff;
  --tma-accent:#2481cc;--tma-section-bg:#ffffff;
  --tma-section-header:#6d6d71;--tma-subtitle:#999999;
  --tma-destructive:#e53935;
  --tma-nav-height:60px;--tma-header-height:56px;
  --tma-border:rgba(0,0,0,.1);--tma-shadow:0 1px 3px rgba(0,0,0,.12);
  --tma-radius:12px;--tma-transition:.2s ease;
  --tma-vh:100vh;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%;overflow:hidden;
  background:var(--tma-bg);color:var(--tma-text);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased}
.tma-shell{display:flex;flex-direction:column;height:var(--tma-vh,100vh);max-height:100dvh;overflow:hidden;background:var(--tma-bg)}
/* Header */
.tma-header{display:flex;align-items:center;gap:10px;padding:8px 16px;
  height:var(--tma-header-height);background:var(--tma-header-bg);
  border-bottom:1px solid var(--tma-border);flex-shrink:0;position:relative;z-index:10}
.tma-avatar-wrap{position:relative;flex-shrink:0;width:36px;height:36px}
.tma-avatar-img{width:36px;height:36px;border-radius:50%;object-fit:cover}
.tma-avatar-initials{width:36px;height:36px;border-radius:50%;background:var(--tma-btn);
  color:var(--tma-btn-text);display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:14px}
.tma-header-info{flex:1;min-width:0}
.tma-header-name{font-weight:600;font-size:15px;color:var(--tma-text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}
.tma-header-status{font-size:12px;color:var(--tma-hint);margin-top:1px}
.tma-header-actions{display:flex;gap:6px;flex-shrink:0}
.tma-icon-btn{background:none;border:none;padding:6px;border-radius:8px;cursor:pointer;
  color:var(--tma-hint);display:flex;align-items:center;justify-content:center;
  transition:color var(--tma-transition);-webkit-tap-highlight-color:transparent}
.tma-icon-btn:active{opacity:.6}
/* Content */
.tma-content{flex:1;overflow:hidden;position:relative}
.tma-tab-pane{position:absolute;top:0;left:0;right:0;bottom:0;overflow-y:auto;
  -webkit-overflow-scrolling:touch;opacity:0;pointer-events:none;
  transform:translateX(16px);transition:opacity var(--tma-transition),transform var(--tma-transition)}
.tma-tab-pane.tma-active{opacity:1;pointer-events:auto;transform:translateX(0)}
#tma-tab-chat{padding:0;overflow:hidden}
#tma-tab-chat .wp-mcp-ai-telegram-mini-app-wrapper{height:100%;overflow:hidden}
/* Home tab – analytics dashboard */
#tma-tab-home{display:flex;flex-direction:column;overflow-y:auto;padding:0 0 12px;-webkit-overflow-scrolling:touch}
.tma-home-wrap{padding:0}
.tma-kpi-row{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:12px 12px 4px}
.tma-kpi-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);
  padding:12px 10px;text-align:center;box-shadow:var(--tma-shadow)}
.tma-kpi-icon{font-size:20px;margin-bottom:4px}
.tma-kpi-value{font-size:18px;font-weight:700;color:var(--tma-text);line-height:1.2}
.tma-kpi-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--tma-hint);margin-top:2px}
.tma-chart-section{margin:8px 12px;background:var(--tma-section-bg);border:1px solid var(--tma-border);
  border-radius:var(--tma-radius);padding:12px;box-shadow:var(--tma-shadow)}
.tma-chart-title{font-size:12px;font-weight:600;color:var(--tma-text);margin-bottom:8px}
.tma-chart-wrap{position:relative;height:180px}
.tma-chart-wrap.tma-chart-doughnut{height:200px}
/* Settings tab */
#tma-tab-settings{display:flex;flex-direction:column;overflow-y:auto;padding:0 0 20px}
.tma-settings-wrap{padding:0}
.tma-settings-section{margin:0 0 4px}
.tma-settings-section-title{font-size:11px;font-weight:600;text-transform:uppercase;
  letter-spacing:.08em;color:var(--tma-section-header);padding:14px 16px 6px}
.tma-settings-card{background:var(--tma-section-bg);border-top:1px solid var(--tma-border);
  border-bottom:1px solid var(--tma-border)}
.tma-settings-item{display:flex;align-items:center;gap:12px;padding:12px 16px;
  border-bottom:1px solid var(--tma-border);-webkit-tap-highlight-color:transparent;
  transition:opacity var(--tma-transition)}
.tma-settings-item:last-child{border-bottom:none}
.tma-settings-item:active{opacity:.7}
.tma-settings-item-icon{font-size:20px;width:32px;height:32px;border-radius:8px;
  background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tma-settings-item-body{flex:1;min-width:0}
.tma-settings-item-label{font-size:14px;font-weight:500;color:var(--tma-text)}
.tma-settings-item-value{font-size:12px;color:var(--tma-hint);margin-top:1px}
.tma-settings-item-value.tma-linked{color:#34c759}
.tma-settings-item-value.tma-unlinked{color:var(--tma-hint)}
.tma-settings-arrow{color:var(--tma-hint);font-size:18px;font-weight:300;flex-shrink:0}
.tma-settings-action{flex-shrink:0;padding:4px 14px;border-radius:8px;border:1px solid var(--tma-border);
  background:var(--tma-btn);color:var(--tma-btn-text);font-size:12px;font-weight:600;cursor:pointer}
.tma-destructive-btn{background:var(--tma-destructive);border-color:var(--tma-destructive);color:#fff}
/* Toggle switch */
.tma-toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.tma-toggle input{opacity:0;width:0;height:0}
.tma-toggle-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;
  background:var(--tma-hint);border-radius:24px;transition:var(--tma-transition)}
.tma-toggle-slider::before{content:"";position:absolute;height:18px;width:18px;left:3px;bottom:3px;
  background:#fff;border-radius:50%;transition:var(--tma-transition)}
.tma-toggle input:checked+.tma-toggle-slider{background:var(--tma-btn)}
.tma-toggle input:checked+.tma-toggle-slider::before{transform:translateX(20px)}
/* Select dropdown */
.tma-settings-select{flex-shrink:0;padding:4px 8px;border-radius:8px;border:1px solid var(--tma-border);
  background:var(--tma-section-bg);color:var(--tma-text);font-size:12px;font-family:inherit;
  appearance:auto;-webkit-appearance:auto;cursor:pointer}
/* Link form */
.tma-link-form .tma-settings-card{padding:12px 16px}
.tma-settings-help{font-size:12px;color:var(--tma-hint);line-height:1.5;margin-bottom:12px}
.tma-settings-field{margin-bottom:10px}
.tma-settings-label{display:block;font-size:12px;font-weight:600;color:var(--tma-text);margin-bottom:4px}
.tma-settings-input{width:100%;padding:8px 10px;border:1px solid var(--tma-border);border-radius:8px;
  background:var(--tma-bg);color:var(--tma-text);font-size:14px;font-family:inherit;box-sizing:border-box}
.tma-settings-input:focus{outline:none;border-color:var(--tma-btn)}
.tma-settings-error{font-size:12px;color:var(--tma-destructive);padding:6px 0}
.tma-settings-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:4px}
.tma-settings-btn{padding:8px 18px;border-radius:8px;border:none;font-size:13px;font-weight:600;
  cursor:pointer;font-family:inherit}
.tma-btn-primary{background:var(--tma-btn);color:var(--tma-btn-text)}
.tma-btn-primary:disabled{opacity:.5;cursor:default}
.tma-btn-secondary{background:var(--tma-secondary-bg);color:var(--tma-text)}
/* Toast */
.tma-settings-toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);
  padding:8px 20px;border-radius:20px;background:var(--tma-btn);color:var(--tma-btn-text);
  font-size:13px;font-weight:600;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,.2);
  animation:tmaToastIn .3s ease}
.tma-toast-error{background:var(--tma-destructive)}
@keyframes tmaToastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
/* Nav */
.tma-nav{display:flex;height:var(--tma-nav-height);background:var(--tma-secondary-bg);
  border-top:1px solid var(--tma-border);flex-shrink:0;padding-bottom:env(safe-area-inset-bottom,0)}
.tma-nav-btn{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:2px;border:none;background:none;cursor:pointer;color:var(--tma-hint);
  padding:6px 4px;-webkit-tap-highlight-color:transparent;
  transition:color var(--tma-transition);font-family:inherit;position:relative}
.tma-nav-btn.tma-active{color:var(--tma-btn)}
.tma-nav-icon{font-size:20px;line-height:1}
.tma-nav-label{font-size:10px;font-weight:500;letter-spacing:.02em}
.tma-nav-btn.tma-active::after{content:"";position:absolute;top:0;left:50%;
  transform:translateX(-50%);width:32px;height:2px;border-radius:0 0 2px 2px;background:var(--tma-btn)}
/* History */
#tma-tab-history{padding:12px}
.tma-section-title{font-size:11px;font-weight:600;text-transform:uppercase;
  letter-spacing:.08em;color:var(--tma-section-header);padding:0 4px 8px}
.tma-history-item{background:var(--tma-section-bg);border-radius:var(--tma-radius);
  padding:12px;margin-bottom:8px;border:1px solid var(--tma-border);
  box-shadow:var(--tma-shadow);transition:opacity var(--tma-transition)}
.tma-history-item:active{opacity:.7}
.tma-history-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.tma-history-id{font-size:13px;font-weight:600;color:var(--tma-text)}
.tma-history-date{font-size:11px;color:var(--tma-hint)}
.tma-history-preview{font-size:13px;color:var(--tma-subtitle);line-height:1.4;margin-bottom:6px}
.tma-history-count{font-size:11px;color:var(--tma-hint)}
.tma-empty{text-align:center;padding:48px 24px;color:var(--tma-hint);font-size:14px;line-height:1.6}
/* About */
#tma-tab-about{padding:0}
.tma-about-hero{background:var(--tma-btn);padding:28px 20px 20px;text-align:center;color:var(--tma-btn-text)}
.tma-about-icon{font-size:52px;line-height:1;margin-bottom:10px}
.tma-about-name{font-size:19px;font-weight:700;margin-bottom:4px}
.tma-about-tagline{font-size:13px;opacity:.85}
.tma-about-section{margin:12px;background:var(--tma-section-bg);border-radius:var(--tma-radius);
  overflow:hidden;border:1px solid var(--tma-border);box-shadow:var(--tma-shadow)}
.tma-about-item{display:flex;align-items:center;gap:14px;padding:14px 16px;
  border-bottom:1px solid var(--tma-border);cursor:pointer;
  -webkit-tap-highlight-color:transparent;transition:opacity var(--tma-transition)}
.tma-about-item:last-child{border-bottom:none}
.tma-about-item:active{opacity:.7}
.tma-about-item-icon{font-size:22px;width:40px;height:40px;border-radius:10px;
  background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tma-about-item-info{flex:1;min-width:0}
.tma-about-item-label{font-size:14px;font-weight:500;color:var(--tma-text)}
.tma-about-item-sub{font-size:12px;color:var(--tma-hint);margin-top:2px}
.tma-about-item-arrow{color:var(--tma-hint);font-size:18px;font-weight:300}
.tma-about-footer{padding:20px;text-align:center;color:var(--tma-hint);font-size:12px}
/* Scrollbars */
.tma-tab-pane::-webkit-scrollbar{width:4px}
.tma-tab-pane::-webkit-scrollbar-thumb{background:var(--tma-hint);border-radius:2px;opacity:.5}
/* Search bar */
.tma-search-bar{display:flex;align-items:center;padding:6px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border);flex-shrink:0}
.tma-search-bar input{flex:1;border:none;outline:none;background:var(--tma-bg);color:var(--tma-text);font-size:14px;padding:6px 10px;border-radius:8px}
/* Filter bars */
.tma-filter-bar{display:flex;gap:6px;padding:8px 12px;overflow-x:auto;flex-shrink:0;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.tma-filter-bar::-webkit-scrollbar{display:none}
.tma-filter-btn{flex-shrink:0;padding:4px 12px;border-radius:16px;border:1px solid var(--tma-border);background:var(--tma-section-bg);color:var(--tma-text);font-size:12px;font-weight:500;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:background var(--tma-transition),color var(--tma-transition)}
.tma-filter-btn.tma-active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}
/* CPT bar */
.tma-cpt-bar{flex-shrink:0;border-bottom:1px solid var(--tma-border);background:var(--tma-secondary-bg)}
.tma-cpt-scroll{display:flex;gap:6px;padding:8px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.tma-cpt-scroll::-webkit-scrollbar{display:none}
.tma-cpt-btn{flex-shrink:0;padding:4px 12px;border-radius:16px;border:1px solid var(--tma-border);background:var(--tma-section-bg);color:var(--tma-text);font-size:12px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:4px;-webkit-tap-highlight-color:transparent}
.tma-cpt-btn.tma-active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}
.tma-badge{background:rgba(0,0,0,.15);border-radius:10px;padding:0 5px;font-size:10px;font-weight:700;min-width:16px;text-align:center}
.tma-tk-dot{font-size:8px;color:var(--tma-accent);margin-left:2px}
/* Post cards */
.tma-post-list{flex:1;overflow-y:auto;padding:8px 12px;-webkit-overflow-scrolling:touch}
.tma-post-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:12px;margin-bottom:8px;box-shadow:var(--tma-shadow)}
.tma-post-header{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px}
.tma-post-title{font-size:14px;font-weight:600;color:var(--tma-text);flex:1;line-height:1.3}
.tma-post-status{flex-shrink:0;font-size:10px;font-weight:600;padding:2px 6px;border-radius:8px}
.tma-status-pub{background:#d4edda;color:#155724}
.tma-status-draft{background:#fff3cd;color:#856404}
.tma-post-excerpt{font-size:12px;color:var(--tma-subtitle);line-height:1.4;margin-bottom:6px}
.tma-post-meta{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--tma-hint)}
.tma-post-edit{color:var(--tma-link);text-decoration:none;font-weight:500}
.tma-post-actions{display:flex;align-items:center;gap:8px}
.tma-post-edit-btn{background:none;border:1px solid var(--tma-btn);color:var(--tma-btn);font-size:11px;padding:2px 8px;border-radius:6px;cursor:pointer;font-weight:500}
.tma-post-edit-btn:active{opacity:.7}
.tma-content-actions{display:flex;justify-content:flex-end;padding:4px 12px 0}
.tma-btn-new-post{background:var(--tma-btn);color:var(--tma-btn-text);border:none;padding:6px 14px;border-radius:var(--tma-radius);font-size:13px;font-weight:600;cursor:pointer}
.tma-btn-new-post:active{opacity:.7}
/* Editor overlay */
.tma-editor-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:200;align-items:flex-end;justify-content:center}
.tma-editor-panel{background:var(--tma-bg);border-radius:16px 16px 0 0;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 -4px 24px rgba(0,0,0,.2)}
.tma-editor-header{display:flex;align-items:center;gap:10px;padding:14px 16px 8px;border-bottom:1px solid var(--tma-border)}
.tma-editor-close{background:none;border:none;font-size:18px;color:var(--tma-hint);cursor:pointer;padding:0 4px}
.tma-editor-title{font-size:16px;font-weight:600;color:var(--tma-text);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tma-editor-body{padding:12px 16px 24px}
.tma-editor-field{margin-bottom:12px}
.tma-editor-label{display:block;font-size:12px;font-weight:600;color:var(--tma-hint);margin-bottom:4px}
.tma-editor-input,.tma-editor-select,.tma-editor-textarea{width:100%;background:var(--tma-secondary-bg);border:1px solid var(--tma-border);border-radius:8px;padding:10px 12px;font-size:14px;color:var(--tma-text);box-sizing:border-box;font-family:inherit}
.tma-editor-textarea{resize:vertical;min-height:100px;line-height:1.5}
.tma-editor-input:focus,.tma-editor-select:focus,.tma-editor-textarea:focus{outline:none;border-color:var(--tma-btn)}
.tma-editor-error{color:var(--tma-destructive);font-size:12px;padding:6px 0}
.tma-editor-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}
/* Tool cards */
.tma-cards-list{padding:8px 12px;overflow-y:auto}
.tma-tool-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px 12px;margin-bottom:8px;box-shadow:var(--tma-shadow)}
.tma-slash-card{border-left:3px solid var(--tma-btn)}
.tma-card-title{font-size:14px;font-weight:600;color:var(--tma-text);margin-bottom:3px;display:flex;align-items:center;flex-wrap:wrap;gap:6px}
.tma-card-desc{font-size:12px;color:var(--tma-subtitle);line-height:1.4;margin-bottom:4px}
.tma-card-usage{font-size:11px;color:var(--tma-hint);font-family:monospace}
.tma-card-group{font-size:10px;color:var(--tma-hint);background:var(--tma-secondary-bg);padding:1px 6px;border-radius:6px}
.tma-card-badge{font-size:10px;background:var(--tma-btn);color:var(--tma-btn-text);padding:1px 6px;border-radius:6px;font-weight:500}
.tma-mono{font-family:monospace;color:var(--tma-btn)}
.tma-mt{margin-top:8px}
.tma-card-actions{margin-top:6px;display:flex;gap:6px}
.tma-tool-exec-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer}
.tma-tool-exec-btn:active{opacity:.7}
.tma-tool-result{margin-top:12px;border-top:1px solid var(--tma-border);padding-top:12px}
.tma-tool-result-header{font-size:13px;font-weight:600;color:var(--tma-text);margin-bottom:6px}
.tma-tool-result-pre{background:var(--tma-secondary-bg);border:1px solid var(--tma-border);border-radius:8px;padding:10px;font-size:12px;color:var(--tma-text);white-space:pre-wrap;word-break:break-word;max-height:300px;overflow-y:auto;font-family:monospace}
/* Media grid */
.tma-media-grid{padding:8px 12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;overflow-y:auto;-webkit-overflow-scrolling:touch;align-content:start}
.tma-media-item{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);overflow:hidden;cursor:pointer;transition:opacity var(--tma-transition);min-height:60px}
.tma-media-item:active{opacity:.7}
.tma-media-thumb{width:100%;aspect-ratio:1;object-fit:cover;object-position:center;display:block}
.tma-media-icon{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;font-size:36px;background:var(--tma-secondary-bg)}
.tma-media-icon-wrap{position:relative;width:100%;aspect-ratio:1}
.tma-media-icon-wrap .tma-media-thumb{width:100%;height:100%;object-fit:cover;object-position:center}
.tma-media-video-preview{width:100%;height:100%;object-fit:cover;object-position:center;display:block}
.tma-media-play-badge{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:28px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.5);pointer-events:none}
.tma-media-icon--pdf{background:rgba(231,76,60,.15)}
.tma-media-icon--word{background:rgba(43,87,154,.15)}
.tma-media-icon--excel{background:rgba(33,114,96,.15)}
.tma-media-icon--ppt{background:rgba(210,71,38,.15)}
.tma-media-icon--audio{background:rgba(155,89,182,.15)}
.tma-media-icon--audio.tma-media-icon{flex-direction:column;gap:4px;font-size:28px;padding:6px;box-sizing:border-box}
.tma-media-icon--audio .tma-media-icon-emoji{line-height:1}
.tma-media-audio-preview{width:100%;height:28px;min-width:0;display:block}
.tma-media-icon--archive{background:rgba(230,126,34,.15)}
.tma-media-icon--text{background:rgba(52,73,94,.15)}
.tma-media-icon--font{background:rgba(22,160,133,.15)}
.tma-media-icon--epub{background:rgba(39,174,96,.15)}
.tma-media-icon--image{background:rgba(52,152,219,.15)}
.tma-media-icon--video{background:rgba(52,73,94,.15)}
.tma-media-info{padding:6px 8px}
.tma-media-title{font-size:11px;font-weight:500;color:var(--tma-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tma-media-meta{font-size:10px;color:var(--tma-hint)}
/* Shop tab */
.tma-shop-wrap{padding:12px}
.tma-shop-balance-card{background:linear-gradient(135deg,var(--tma-btn),var(--tma-accent,var(--tma-btn)));border-radius:16px;padding:24px;text-align:center;margin-bottom:16px;color:var(--tma-btn-text)}
.tma-shop-balance-icon{font-size:36px;margin-bottom:4px}
.tma-shop-balance-amount{font-size:42px;font-weight:700;line-height:1.2}
.tma-shop-balance-label{font-size:13px;opacity:.85;margin-top:4px}
.tma-shop-pricing{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:4px 0}
.tma-shop-price-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:16px 12px;text-align:center;position:relative}
.tma-shop-popular{border-color:var(--tma-btn);box-shadow:0 0 0 1px var(--tma-btn)}
.tma-shop-popular-badge{position:absolute;top:-8px;left:50%;transform:translateX(-50%);background:var(--tma-btn);color:var(--tma-btn-text);font-size:10px;font-weight:600;padding:2px 10px;border-radius:8px}
.tma-shop-price-stars{font-size:22px;font-weight:700;color:var(--tma-text)}
.tma-shop-price-label{font-size:14px;font-weight:600;color:var(--tma-text);margin:4px 0 2px}
.tma-shop-price-desc{font-size:11px;color:var(--tma-hint);margin-bottom:10px}
.tma-shop-buy-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;padding:8px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;width:100%}
.tma-shop-buy-btn:active{opacity:.7}
/* Pagination */
.tma-pagination{display:flex;align-items:center;justify-content:center;gap:10px;padding:10px 12px;flex-shrink:0;border-top:1px solid var(--tma-border)}
.tma-page-btn{padding:5px 14px;border-radius:var(--tma-radius);border:1px solid var(--tma-border);background:var(--tma-section-bg);color:var(--tma-text);font-size:13px;cursor:pointer;-webkit-tap-highlight-color:transparent}
.tma-page-btn:active{opacity:.7}
.tma-page-info{font-size:13px;color:var(--tma-hint)}
/* Login prompt */
.tma-login-prompt{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px;height:100%;text-align:center}
.tma-login-icon{font-size:52px;margin-bottom:16px}
.tma-login-title{font-size:18px;font-weight:700;color:var(--tma-text);margin-bottom:6px}
.tma-login-sub{font-size:13px;color:var(--tma-hint);margin-bottom:20px}
.tma-login-btn{display:inline-block;padding:10px 28px;background:var(--tma-btn);color:var(--tma-btn-text);text-decoration:none;border-radius:var(--tma-radius);font-size:14px;font-weight:600}
/* Error state */
.tma-error{color:var(--tma-destructive) !important}
/* Nav SVG icons */
.tma-nav-svg{width:22px;height:22px;flex-shrink:0}
/* Content tab layout: column flex so CPT bar + list + pagination stack */
#tma-tab-content{display:flex;flex-direction:column;overflow:hidden}
#tma-tab-tools{display:flex;flex-direction:column;overflow:hidden}
#tma-tab-tools .tma-cards-list{flex:1;overflow-y:auto}
#tma-tab-tools .tma-section-title{flex-shrink:0}
#tma-tab-tools .tma-filter-bar{flex-shrink:0}
#tma-tab-media{display:flex;flex-direction:column;overflow:hidden}
#tma-tab-media .tma-media-grid{flex:1}
#tma-tab-commands{display:flex;flex-direction:column;overflow:hidden}
#tma-tab-commands .tma-cards-list{flex:1;overflow-y:auto}
.tma-date-range-bar{display:flex;gap:6px;padding:8px 12px;overflow-x:auto;flex-shrink:0}
.tma-range-btn{padding:6px 14px;border-radius:16px;border:1px solid var(--tma-border);background:var(--tma-section-bg);color:var(--tma-text);font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;transition:all var(--tma-transition)}
.tma-range-btn.tma-active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}
.tma-toolkit-pills{display:flex;gap:4px;padding:0 0 6px;overflow-x:auto;scrollbar-width:none}
.tma-toolkit-pills::-webkit-scrollbar{display:none}
.tma-cpt-buttons{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none}
.tma-cpt-buttons::-webkit-scrollbar{display:none}
.tma-editor-fullscreen{background:none;border:none;color:var(--tma-hint);font-size:18px;cursor:pointer;padding:4px 8px;margin-left:auto}
';
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	* Build a list of every active pro toolkit along with its human-readable
	* label, the WordPress post-type names it registers, and the tool slugs it
	* contributes – so the /content and /tools endpoints can group and annotate
	* their responses.
	*
	* Each entry in the returned array has the shape:
	*   [
	*     'key'        => 'enable_ecommerce_toolkit',   // wp_mcp_ai_settings key
	*     'label'      => 'E-commerce Toolkit',
	*     'post_types' => [ 'mcp_ai_product', … ],
	*     'tool_slugs' => [ 'woo_products', 'woo_orders', … ],
	*   ]
	*
	* @since 1.0.0
	*
	* @return array Active toolkits. Empty array when the Pro addon is absent.
	*/
	public function get_active_toolkits() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return array();
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$is_base  = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

		/*
		 * Master toolkit registry.
		 *
		 * Format: setting_key => [ label, always, post_types[], tool_slugs[] ]
		 * post_types  – CPTs the toolkit registers (show_ui=true types only).
		 * tool_slugs  – Tool slugs the toolkit adds to the registry.
		 *
		 * Only the Password Vault uses always=true (always loaded by the Pro plugin).
		 * All other toolkits are controlled via the wp_mcp_ai_settings option.
		 */
		$toolkit_registry = array(
			// ── Always-on (Password Vault is loaded unconditionally by Pro) ───
			'_always_vault'    => array(
				'label'      => __( 'Password Vault', 'mcp-ai-wpoos-pro' ),
				'setting'    => '',
				'always'     => true,
				'post_types' => array(),
				'tool_slugs' => array( 'get_vault_entry', 'create_vault_entry', 'update_vault_entry', 'delete_vault_entry', 'list_vault_entries' ),
			),

			// ── Media Toolkit (file always loaded, activated via setting) ──────
			'_always_media'    => array(
				'label'      => __( 'Media Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_media_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_media_collection', 'mcp_ai_media_template' ),
				'tool_slugs' => array(
					'analyze_image', 'generate_image_alt_text', 'generate_image_caption',
					'extract_image_text', 'convert_image_format', 'remove_background',
					'rotate_image', 'resize_image', 'vectorize_image',
					'generate_gemini_image', 'edit_gemini_image', 'generate_openai_image',
				),
			),

			// ── Setting-gated toolkits ─────────────────────────────────────────
			'enable_ecommerce_toolkit'              => array(
				'label'      => __( 'E-commerce Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_ecommerce_toolkit',
				'always'     => false,
				'post_types' => array(),
				'tool_slugs' => array( 'woo_products', 'woo_orders', 'product_actualization', 'lookup_product_price', 'create_woo_product', 'create_woo_variable_product' ),
			),
			'enable_social_media_toolkit'           => array(
				'label'      => __( 'Social Media Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_social_media_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_social_post' ),
				'tool_slugs' => array( 'schedule_social_post', 'get_social_analytics', 'publish_social_post' ),
			),
			'enable_analytics_toolkit'              => array(
				'label'      => __( 'Analytics Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_analytics_toolkit',
				'always'     => false,
				'post_types' => array(),
				'tool_slugs' => array( 'get_site_analytics', 'get_traffic_report', 'get_conversion_report' ),
			),
			'enable_multilingual_toolkit'           => array(
				'label'      => __( 'Multilingual Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_multilingual_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_translation_memory', 'mcp_ai_glossary' ),
				'tool_slugs' => array( 'translate_content', 'get_translation_memory', 'manage_glossary' ),
			),
			'enable_video_production_toolkit'       => array(
				'label'      => __( 'Video Production Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_video_production_toolkit',
				'always'     => false,
				'post_types' => array(),
				'tool_slugs' => array( 'generate_veo_video', 'generate_openai_video', 'analyze_video', 'check_video_status' ),
			),
			'enable_financial_planner_toolkit'      => array(
				'label'      => __( 'Financial Planner Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_financial_planner_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_financial_account', 'mcp_ai_budget' ),
				'tool_slugs' => array( 'get_financial_account', 'create_budget', 'get_financial_report' ),
			),
			'enable_dj_management_toolkit'         => array(
				'label'      => __( 'DJ Management Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_dj_management_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_dj_equipment', 'mcp_ai_dj_package' ),
				'tool_slugs' => array(),
			),
			'enable_image_production_toolkit'       => array(
				'label'      => __( 'Image Production Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_image_production_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_image_template' ),
				'tool_slugs' => array( 'generate_image_from_template', 'batch_generate_images' ),
			),
			'enable_ai_tool_builder_toolkit'        => array(
				'label'      => __( 'AI Tool Builder Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_ai_tool_builder_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_custom_tool' ),
				'tool_slugs' => array( 'create_custom_tool', 'test_custom_tool', 'deploy_custom_tool' ),
			),
			'enable_architect_agent_toolkit'        => array(
				'label'      => __( 'Architect Agent Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_architect_agent_toolkit',
				'always'     => false,
				'post_types' => array(),
				'tool_slugs' => array( 'read_file', 'write_file', 'run_command', 'scaffold_component' ),
			),
			'enable_architectural_design_toolkit'   => array(
				'label'      => __( 'Architectural Design Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_architectural_design_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_architectural_project', 'mcp_ai_architectural_drawing', 'mcp_ai_architectural_specification' ),
				'tool_slugs' => array(),
			),
			'enable_site_creator_toolkit'           => array(
				'label'      => __( 'Site Creator Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_site_creator_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_site_template' ),
				'tool_slugs' => array( 'scaffold_theme_structure', 'create_site_from_template' ),
			),
			'enable_document_generation_toolkit'    => array(
				'label'      => __( 'Document Generation Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_document_generation_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_document_template' ),
				'tool_slugs' => array( 'generate_document', 'import_products_from_excel', 'export_data_to_excel' ),
			),
			'enable_crm_toolkit'                    => array(
				'label'      => __( 'CRM Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_crm_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_company', 'mcp_ai_contact' ),
				'tool_slugs' => array( 'get_crm_contact', 'create_crm_contact', 'update_crm_contact' ),
			),
			'enable_regulatory_registration_toolkit' => array(
				'label'      => __( 'Regulatory Registration Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_regulatory_registration_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_regulatory_registration' ),
				'tool_slugs' => array(),
			),
			'enable_chat_channels_toolkit'          => array(
				'label'      => __( 'Chat Channels Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_chat_channels_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_channel_message', 'mcp_ai_channel_contact' ),
				'tool_slugs' => array(
					'send_telegram_message', 'get_telegram_updates', 'manage_telegram_webhook',
					'add_telegram_message_reaction',
				),
			),
			'enable_fantasy_football'               => array(
				'label'      => __( 'Fantasy Football Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_fantasy_football',
				'always'     => false,
				'post_types' => array(),
				'tool_slugs' => array( 'get_espn_fantasy_league', 'get_espn_fantasy_roster', 'get_espn_fantasy_scoreboard' ),
			),
			'enable_health_wellness_management'     => array(
				'label'      => __( 'Health & Wellness Management', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_health_wellness_management',
				'always'     => false,
				'post_types' => array( 'mcp_ai_health_record', 'mcp_ai_wellness_plan' ),
				'tool_slugs' => array(),
			),
			'enable_places_management'              => array(
				'label'      => __( 'Places Management', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_places_management',
				'always'     => false,
				'post_types' => array( 'mcp_ai_place' ),
				'tool_slugs' => array(),
			),
			'enable_eca_management'                 => array(
				'label'      => __( 'ECA Management', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_eca_management',
				'always'     => false,
				'post_types' => array( 'mcp_ai_eca' ),
				'tool_slugs' => array(),
			),
			'enable_quiz_system'                    => array(
				'label'      => __( 'Quiz System', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_quiz_system',
				'always'     => false,
				'post_types' => array( 'mcp_ai_quiz', 'mcp_ai_question' ),
				'tool_slugs' => array(),
			),
			'enable_project_management'             => array(
				'label'      => __( 'Project Management', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_project_management',
				'always'     => false,
				'post_types' => array( 'mcp_ai_project', 'mcp_ai_task' ),
				'tool_slugs' => array( 'create_project', 'get_project', 'update_project', 'delete_project' ),
			),
			'enable_calendar_booking_toolkit'       => array(
				'label'      => __( 'Calendar & Booking Toolkit', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_calendar_booking_toolkit',
				'always'     => false,
				'post_types' => array( 'mcp_ai_event', 'mcp_ai_booking' ),
				'tool_slugs' => array(),
			),
			'enable_webchat_integration'            => array(
				'label'      => __( 'WebChat Integration', 'mcp-ai-wpoos-pro' ),
				'setting'    => 'enable_webchat_integration',
				'always'     => false,
				'post_types' => array( 'mcp_ai_webchat' ),
				'tool_slugs' => array(),
			),
		);

		$active = array();

		foreach ( $toolkit_registry as $key => $tk ) {
			// Always-on toolkits with no setting gate.
			if ( $tk['always'] ) {
				$active[ $key ] = array(
					'key'        => $key,
					'label'      => $tk['label'],
					'post_types' => $tk['post_types'],
					'tool_slugs' => $tk['tool_slugs'],
				);
				continue;
			}

			// Media toolkit: always included in Pro unless base-only.
			if ( '_always_media' === $key && ! $is_base ) {
				if ( ! empty( $settings['enable_media_toolkit'] ) ) {
					$active[ $key ] = array(
						'key'        => $key,
						'label'      => $tk['label'],
						'post_types' => $tk['post_types'],
						'tool_slugs' => $tk['tool_slugs'],
					);
				}
				continue;
			}

			// Skip always-on sentinels that were processed above.
			if ( 0 === strpos( $key, '_always_' ) ) {
				continue;
			}

			// Setting-gated toolkit: only include when flag is enabled.
			$setting_key = $tk['setting'];
			if ( ! empty( $setting_key ) && ! empty( $settings[ $setting_key ] ) ) {
				$active[ $key ] = array(
					'key'        => $key,
					'label'      => $tk['label'],
					'post_types' => $tk['post_types'],
					'tool_slugs' => $tk['tool_slugs'],
				);
			}
		}

		return $active;
	}

	/**
	* Resolve the assistant identifier to use for the Mini App chat UI.
	*
	* Resolution order:
	*   1. The explicit `?assistant=` query parameter on the request.
	*   2. The first entry in `assigned_assistant_ids` on the active Telegram connection.
	*   3. The `default_assistant_id` from the global chat-channels automation rules.
	*   4. The `default_assistant` from the Chat Channels Toolkit settings page.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request    Incoming REST request.
	* @param array|null      $connection Active Telegram connection array, or null.
	* @return string Assistant slug or numeric ID string, or empty string if none resolved.
	*/
	protected function resolve_mini_app_assistant( $request, $connection ) {
		// 1. Honour an explicit query-parameter override.
		$assistant = $request->get_param( 'assistant' );
		if ( ! empty( $assistant ) ) {
			return (string) $assistant;
		}

		// 2. Use the first assistant assigned to the active Telegram connection.
		if ( $connection && ! empty( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) ) {
			$ids      = array_values( $connection['assigned_assistant_ids'] );
			$first_id = absint( $ids[0] );
			if ( $first_id ) {
				return (string) $first_id;
			}
		}

		// 3. Fall back to the global default from the automation rules option.
		$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
		if ( ! empty( $automation_rules['default_assistant_id'] ) ) {
			return (string) absint( $automation_rules['default_assistant_id'] );
		}

		// 4. Fall back to the default assistant saved in the Chat Channels Toolkit settings page.
		$toolkit_settings = get_option( 'wp_mcp_ai_chat_channels_toolkit_settings', array() );
		if ( ! empty( $toolkit_settings['default_assistant'] ) ) {
			return (string) absint( $toolkit_settings['default_assistant'] );
		}

		return '';
	}

	/**
	* Find the first active (enabled) Telegram connection.
	*
	* @since 1.0.0
	*
	* @return array|null Connection array or null if none found.
	*/
	protected function get_active_telegram_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return null;
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'telegram' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			return $connection;
		}

		return null;
	}

	/**
	* Return the public Mini App URL for this site.
	*
	* This is the URL that should be provided to BotFather when configuring
	* a Telegram bot's Web App (Mini App) menu button.
	*
	* @since 1.0.0
	*
	* @return string Fully-qualified HTTPS URL to the Mini App endpoint.
	*/
	public static function get_mini_app_url() {
		return rest_url( 'mcp-ai/v1/telegram-mini-app' );
	}

	/**
	* Find an existing WordPress user by Telegram ID, or create one.
	*
	* Shares the same meta key (_wp_mcp_ai_telegram_id) as the Login Widget
	* controller so that a user linked via either auth method is recognised
	* by both. Mirrors the pattern used by the Auth0/GitHub integration.
	*
	* @since 1.0.0
	*
	* @param array $auth_data Telegram user data (id, first_name, last_name, username, photo_url).
	* @return int|WP_Error WordPress user ID, or WP_Error on failure.
	*/
	protected function find_or_create_wp_user( array $auth_data, array $connection = array() ) {
		$telegram_id = ! empty( $auth_data['id'] ) ? (string) absint( $auth_data['id'] ) : '';
		if ( '' === $telegram_id ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_no_id',
				__( 'Telegram user ID is missing.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// 1. Look up by stored Telegram ID meta.
		$user_ids = get_users(
			array(
				'meta_key'   => self::META_TELEGRAM_ID,
				'meta_value' => $telegram_id,
				'fields'     => 'ids',
				'number'     => 1,
			)
		);

		if ( ! empty( $user_ids ) ) {
			$this->sync_telegram_user_meta( (int) $user_ids[0], $auth_data );
			return (int) $user_ids[0];
		}

		// Determine auto-create setting: connection setting takes precedence over the
		// filter default (true) when explicitly saved. New connections default to true.
		$connection_auto_create = ! isset( $connection['auto_create_wp_user'] ) || ! empty( $connection['auto_create_wp_user'] );

		/**
		* Filters whether a new WordPress user should be created for an
		* unrecognised Telegram Mini App identity.
		*
		* Return false to prevent automatic account creation.
		*
		* @since 1.0.0
		*
		* @param bool  $auto_create Whether to create a new user. Connection admin setting used as default.
		* @param array $auth_data   Telegram user data from verified initData.
		*/
		if ( ! apply_filters( 'wp_mcp_ai_telegram_mini_app_auto_create_user', $connection_auto_create, $auth_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_user_not_found',
				__( 'No WordPress account is linked to this Telegram identity.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// 2. Generate a unique username: telegram_{id}.
		$base_login = 'telegram_' . $telegram_id;
		$login      = $base_login;
		$suffix     = 1;
		while ( username_exists( $login ) ) {
			$login = $base_login . '_' . $suffix;
			++$suffix;
		}

		// 3. Build display name from Telegram first/last name.
		$first_name   = ! empty( $auth_data['first_name'] ) ? sanitize_text_field( $auth_data['first_name'] ) : '';
		$last_name    = ! empty( $auth_data['last_name'] )  ? sanitize_text_field( $auth_data['last_name'] )  : '';
		$display_name = trim( $first_name . ' ' . $last_name );
		if ( '' === $display_name ) {
			$display_name = $login;
		}

		// Telegram does not expose user e-mail addresses; use a placeholder that
		// follows the IANA-reserved `.invalid` domain convention.
		$placeholder_email = sanitize_email( $telegram_id . '@telegram.users.invalid' );

		// Role: connection admin setting takes precedence; filter allows code override.
		$connection_role = ! empty( $connection['new_user_role'] ) ? sanitize_key( $connection['new_user_role'] ) : 'subscriber';

		$user_data = array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => $placeholder_email,
			'display_name' => $display_name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			/**
			* Filters the WordPress role assigned to newly-created Mini App users.
			*
			* @since 1.0.0
			*
			* @param string $role      Role slug. Connection admin setting used as default.
			* @param array  $auth_data Telegram user data from verified initData.
			*/
			'role'         => apply_filters( 'wp_mcp_ai_telegram_mini_app_new_user_role', $connection_role, $auth_data ),
		);

		$user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_mini_app_user_creation_failed',
				$user_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$this->sync_telegram_user_meta( (int) $user_id, $auth_data );

		/**
		* Fires after a new WordPress user has been created for a Telegram Mini App identity.
		*
		* @since 1.0.0
		*
		* @param int   $user_id   Newly-created WordPress user ID.
		* @param array $auth_data Telegram user data from verified initData.
		*/
		do_action( 'wp_mcp_ai_telegram_wp_user_created', (int) $user_id, $auth_data );

		return (int) $user_id;
	}

	/**
	* Persist Telegram identity metadata on the WordPress user record.
	*
	* @since 1.0.0
	*
	* @param int   $user_id   WordPress user ID.
	* @param array $auth_data Telegram user data.
	*/
	protected function sync_telegram_user_meta( $user_id, array $auth_data ) {
		if ( ! empty( $auth_data['id'] ) ) {
			update_user_meta( $user_id, self::META_TELEGRAM_ID, (string) absint( $auth_data['id'] ) );
		}
		if ( ! empty( $auth_data['username'] ) ) {
			update_user_meta( $user_id, self::META_TELEGRAM_USERNAME, sanitize_text_field( $auth_data['username'] ) );
		}
		if ( ! empty( $auth_data['photo_url'] ) ) {
			update_user_meta( $user_id, '_wp_mcp_ai_telegram_photo_url', esc_url_raw( $auth_data['photo_url'] ) );
		}

		// Keep display name in sync when it has changed.
		$first_name   = ! empty( $auth_data['first_name'] ) ? sanitize_text_field( $auth_data['first_name'] ) : '';
		$last_name    = ! empty( $auth_data['last_name'] )  ? sanitize_text_field( $auth_data['last_name'] )  : '';
		$display_name = trim( $first_name . ' ' . $last_name );
		if ( '' !== $display_name ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User && $user->display_name !== $display_name ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $display_name,
						'first_name'   => $first_name,
						'last_name'    => $last_name,
					)
				);
			}
		}
	}
}

new WP_MCP_AI_Telegram_Mini_App_Controller();
