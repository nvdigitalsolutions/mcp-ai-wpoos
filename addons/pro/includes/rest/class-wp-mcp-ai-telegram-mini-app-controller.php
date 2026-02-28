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
						'sanitize_callback' => 'sanitize_text_field',
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
	}

	// =========================================================================
	// Permission check for authenticated CMS endpoints
	// =========================================================================

	/**
	* Check that the current user can edit posts.
	*
	* Used as the permission_callback for the /content, /tools, and /media
	* sub-endpoints. This mirrors the minimum capability required by the base
	* WordPress editor role.
	*
	* @since 1.0.0
	*
	* @return bool True when the current user has the 'edit_posts' capability.
	*/
	public function check_permission() {
		return current_user_can( 'edit_posts' );
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
		// lookup and for extracting the bot username shown in the About tab.
		$connection = $this->get_active_telegram_connection();

		// Resolve the assistant to use, honouring the explicit query parameter
		// first, then per-connection settings, then the global automation default.
		$assistant_slug = $this->resolve_mini_app_assistant( $request, $connection );

		// Build the shortcode so the existing chat UI is rendered inside the Mini App.
		$shortcode = '[mcp_ai_chat';
		if ( ! empty( $assistant_slug ) ) {
			$shortcode .= ' assistant="' . esc_attr( $assistant_slug ) . '"';
		}
		$shortcode .= ' allow_guests="true" enable_streaming="true"]';

		$chat_html = do_shortcode( $shortcode );

		// Collect styles and scripts enqueued by the shortcode.
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
		$login_url    = wp_login_url( rest_url( $this->namespace . '/' . $this->rest_base ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone HTML page; individual values escaped inline.
		echo '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>' . esc_html( $page_title ) . '</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
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

    <!-- Content tab (default) -->
    <div class="tma-tab-pane tma-active" id="tma-tab-content">
      <div class="tma-cpt-bar" id="tma-cpt-bar">
        <div class="tma-cpt-scroll" id="tma-cpt-scroll">
          <div class="tma-empty">Loading types…</div>
        </div>
      </div>
      <div class="tma-post-list" id="tma-post-list">
        <div class="tma-empty">Loading content…</div>
      </div>
      <div class="tma-pagination" id="tma-content-pagination"></div>
    </div>

    <!-- Tools tab -->
    <div class="tma-tab-pane" id="tma-tab-tools">
      <div class="tma-section-title">Available Tools</div>
      <div id="tma-toolkit-filter" class="tma-filter-bar"></div>
      <div id="tma-tools-list" class="tma-cards-list">
        <div class="tma-empty">Loading tools…</div>
      </div>
      <div class="tma-section-title tma-mt">Slash Commands</div>
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

    <!-- Chat tab -->
    <div class="tma-tab-pane" id="tma-tab-chat">
      <div class="wp-mcp-ai-telegram-mini-app-wrapper">
        ' . $chat_html . '
      </div>
    </div>

  </div><!-- /.tma-content -->

  <!-- ── Bottom navigation ────────────────────────────────────── -->
  <nav class="tma-nav" role="navigation" aria-label="Tabs">
    <button class="tma-nav-btn tma-active" id="tma-nav-content" data-tab="content" onclick="tmaSwitchTab(\'content\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
      <span class="tma-nav-label">Content</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-tools" data-tab="tools" onclick="tmaSwitchTab(\'tools\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      <span class="tma-nav-label">Tools</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-media" data-tab="media" onclick="tmaSwitchTab(\'media\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <span class="tma-nav-label">Media</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-chat" data-tab="chat" onclick="tmaSwitchTab(\'chat\')">
      <svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      <span class="tma-nav-label">Chat</span>
    </button>
  </nav>

</div><!-- /.tma-shell -->

' . $footer_output . '
<script>
/* =========================================================
   NV oOS – Telegram Mini App Shell (CMS Edition)
   Content, Tools, Media & Chat management for Telegram.
   ========================================================= */
(function () {
  \'use strict\';

  /* ── Config injected by PHP ── */
  var TMA_BOT_USERNAME   = ' . wp_json_encode( $bot_username ) . ';
  var TMA_VALIDATE_URL   = ' . wp_json_encode( $validate_url ) . ';
  var TMA_CONTENT_URL    = ' . wp_json_encode( $content_url ) . ';
  var TMA_TOOLS_URL      = ' . wp_json_encode( $tools_url ) . ';
  var TMA_MEDIA_URL      = ' . wp_json_encode( $media_url ) . ';
  var TMA_LOGIN_URL      = ' . wp_json_encode( $login_url ) . ';
  var TMA_SITE_NAME      = ' . wp_json_encode( get_bloginfo( 'name' ) ) . ';
  var TMA_NONCE          = ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';
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
  var activeTab         = \'content\';
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
    if (tabName === \'content\') loadContent();
    if (tabName === \'tools\')   loadTools();
    if (tabName === \'media\')   loadMedia();
    /* Reset search bar context */
    clearSearch();
  };

  /* ── Back Button ── */
  function updateBackButton() {
    if (!twa || !twa.BackButton) return;
    if (activeTab === \'content\') {
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
    return fetch(url, {
      credentials : \'same-origin\',
      headers     : { \'X-WP-Nonce\': TMA_NONCE },
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
      }
    }, 300);
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
        if (r.status === 401 || r.status === 403) { showLoginPrompt(\'content\'); return null; }
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

  function renderCptBar(types) {
    var scroll = document.getElementById(\'tma-cpt-scroll\');
    if (!scroll || !types.length) return;
    var html = \'\';
    types.forEach(function (t) {
      var active = t.name === contentPostType ? \' tma-active\' : \'\';
      var badge  = t.count > 0 ? \'<span class="tma-badge">\' + escHtml(String(t.count)) + \'</span>\' : \'\';
      var tk     = t.toolkit ? \'<span class="tma-tk-dot" title="\' + escHtml(t.toolkit) + \'">●</span>\' : \'\';
      html += \'<button class="tma-cpt-btn\' + active + \'" onclick="tmaSelectCpt(\\\'\' + escHtml(t.name) + \'\\\')">\' +
              escHtml(t.label) + badge + tk + \'</button>\';
    });
    scroll.innerHTML = html;
  }

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

    if (!posts.length) {
      listEl.innerHTML = \'<div class="tma-empty">No items found.</div>\';
      if (pageEl) pageEl.innerHTML = \'\';
      return;
    }

    var html = \'\';
    posts.forEach(function (p) {
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
          (p.link ? \'<a class="tma-post-edit" href="\' + escHtml(p.link) + \'" target="_blank">Edit ›</a>\' : \'\') +
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
        if (r.status === 401 || r.status === 403) { showLoginPrompt(\'tools\'); return null; }
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
    var slashEl = document.getElementById(\'tma-slash-list\');
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
          \'</div>\';
        });
        toolsEl.innerHTML = html;
      }
    }

    /* Slash commands – filtered only by search, not by toolkit */
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
        if (r.status === 401 || r.status === 403) { showLoginPrompt(\'media\'); return null; }
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
      var thumb  = isImg && item.thumb ? \'<img src="\' + escHtml(item.thumb) + \'" alt="\' + escHtml(item.title) + \'" class="tma-media-thumb" loading="lazy">\' :
                   \'<div class="tma-media-icon">\' + mimeIcon(item.mime_type) + \'</div>\';
      html += \'<div class="tma-media-item" onclick="tmaOpenMedia(\\\'\' + escHtml(item.url) + \'\\\')">\' +
        thumb +
        \'<div class="tma-media-info">\' +
          \'<div class="tma-media-title">\' + escHtml(item.title || \'(untitled)\') + \'</div>\' +
          \'<div class="tma-media-meta">\' + escHtml(item.filesize || \'\') + \'</div>\' +
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
    if (mime.indexOf(\'application/pdf\') === 0) return \'📕\';
    if (mime.indexOf(\'application/\') === 0) return \'📦\';
    return \'📄\';
  }

  /* =========================================================
     AUTH / LOGIN PROMPT
     ========================================================= */
  function showLoginPrompt(tabName) {
    var el = document.getElementById(\'tma-tab-\' + tabName);
    if (!el) return;
    el.innerHTML = \'<div class="tma-login-prompt">\' +
      \'<div class="tma-login-icon">🔒</div>\' +
      \'<div class="tma-login-title">Login Required</div>\' +
      \'<div class="tma-login-sub">Sign in to manage your content.</div>\' +
      \'<a class="tma-login-btn" href="\' + escHtml(TMA_LOGIN_URL) + \'" target="_blank">Sign In</a>\' +
    \'</div>\';
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
          var statusEl = document.getElementById(\'tma-header-status\');
          if (statusEl) {
            statusEl.textContent = json.wp_nonce ? \'✓ Signed In\' : \'✓ Verified\';
          }
        }
      })
      .catch(function () { /* silent – validation is best-effort */ });
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
          if (activeTab !== \'content\') tmaSwitchTab(\'content\');
        });
      }
      if (twa.MainButton) {
        twa.MainButton.hide();
      }
      /* Validate initData first so the WP auth cookie and nonce are ready
         before the Content/Tools/Media tab API calls are made. */
      validateInitData().then(loadContent).catch(loadContent);
    } else {
      /* No Telegram WebApp context (e.g. direct browser access). */
      loadContent();
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
		if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->edit_posts ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_forbidden',
				__( 'Insufficient permissions for this post type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

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
		$posts = array();

		foreach ( $query->posts as $post ) {
			$excerpt  = $post->post_excerpt;
			if ( empty( $excerpt ) ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '…' );
			}
			$posts[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'status'   => $post->post_status,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
				'link'     => (string) get_edit_post_link( $post->ID, 'raw' ),
				'excerpt'  => $excerpt,
			);
		}

		// Build the list of all accessible CPTs, enriched with active-toolkit info.
		$all_types      = get_post_types( array( 'show_ui' => true ), 'objects' );
		$active_toolkits = $this->get_active_toolkits();
		$cpt_list       = array();

		foreach ( $all_types as $type ) {
			if ( ! current_user_can( $type->cap->edit_posts ) ) {
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
				'total'      => (int) $query->found_posts,
				'pages'      => (int) $query->max_num_pages,
				'post_types' => $cpt_list,
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

					$result['tools'][] = array(
						'slug'        => $slug,
						'name'        => method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug,
						'description' => method_exists( $tool, 'get_description' ) ? $tool->get_description() : '',
						'group'       => isset( $group_map[ $slug ] ) ? $group_map[ $slug ] : '',
						'toolkit'     => $toolkit_label,
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
	* Return a paginated list of media library items.
	*
	* @since 1.0.0
	*
	* @param WP_REST_Request $request Request object.
	* @return WP_REST_Response|WP_Error
	*/
	public function handle_media( $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_forbidden',
				__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
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

		$wp_nonce   = null;
		$wp_user_id = $this->find_or_create_wp_user( $auth_data, $connection );

		if ( ! is_wp_error( $wp_user_id ) ) {
			wp_set_current_user( $wp_user_id );
			wp_set_auth_cookie( $wp_user_id, false );
			$wp_nonce = wp_create_nonce( 'wp_rest' );

			/**
			* Fires after a Telegram Mini App user has been authenticated as a WordPress user.
			*
			* @since 1.0.0
			*
			* @param int   $wp_user_id WordPress user ID.
			* @param array $auth_data  Telegram user data from verified initData.
			*/
			do_action( 'wp_mcp_ai_telegram_mini_app_wp_user_logged_in', $wp_user_id, $auth_data );
		}

		return rest_ensure_response(
			array(
				'valid'     => true,
				'user'      => $tg_user ? $tg_user : null,
				'auth_date' => isset( $result['auth_date'] ) ? (int) $result['auth_date'] : null,
				'wp_nonce'  => $wp_nonce,
			)
		);
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
/* Media grid */
.tma-media-grid{padding:8px 12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;overflow-y:auto;-webkit-overflow-scrolling:touch;align-content:start}
.tma-media-item{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);overflow:hidden;cursor:pointer;transition:opacity var(--tma-transition)}
.tma-media-item:active{opacity:.7}
.tma-media-thumb{width:100%;aspect-ratio:1;object-fit:cover;display:block}
.tma-media-icon{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;font-size:36px;background:var(--tma-secondary-bg)}
.tma-media-info{padding:6px 8px}
.tma-media-title{font-size:11px;font-weight:500;color:var(--tma-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tma-media-meta{font-size:10px;color:var(--tma-hint)}
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
