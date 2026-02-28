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
		$site_name    = esc_js( get_bloginfo( 'name' ) );
		$site_desc    = esc_js( get_bloginfo( 'description' ) );
		$bot_js       = esc_js( $bot_username );
		$validate_js  = esc_js( $validate_url );

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
      <div class="tma-avatar-initials" id="tma-avatar-initials">🤖</div>
    </div>
    <div class="tma-header-info">
      <div class="tma-header-name" id="tma-user-name">' . esc_html( $page_title ) . '</div>
      <div class="tma-header-status" id="tma-header-status">AI Assistant</div>
    </div>
    <div class="tma-header-actions">
      <button class="tma-icon-btn" id="tma-share-btn" title="Share" onclick="tmaShareBot()">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      </button>
    </div>
  </header>

  <!-- ── Tab content ──────────────────────────────────────────── -->
  <div class="tma-content">

    <!-- Chat tab -->
    <div class="tma-tab-pane tma-active" id="tma-tab-chat">
      <div class="wp-mcp-ai-telegram-mini-app-wrapper">
        ' . $chat_html . '
      </div>
    </div>

    <!-- History tab -->
    <div class="tma-tab-pane" id="tma-tab-history">
      <div class="tma-section-title">Recent Conversations</div>
      <div id="tma-history-list"><div class="tma-empty">Loading…</div></div>
    </div>

    <!-- About tab -->
    <div class="tma-tab-pane" id="tma-tab-about">
      <div class="tma-about-hero">
        <div class="tma-about-icon">🤖</div>
        <div class="tma-about-name" id="tma-about-name">' . esc_html( $page_title ) . '</div>
        <div class="tma-about-tagline" id="tma-about-tagline">' . esc_html( get_bloginfo( 'description' ) ) . '</div>
      </div>

      <div class="tma-about-section">
        <div class="tma-about-item" onclick="tmaShareBot()">
          <div class="tma-about-item-icon">🔗</div>
          <div class="tma-about-item-info">
            <div class="tma-about-item-label">Share this Bot</div>
            <div class="tma-about-item-sub">Invite friends to chat with the AI</div>
          </div>
          <div class="tma-about-item-arrow">›</div>
        </div>
        <div class="tma-about-item" onclick="tmaSwitchTab(\'chat\')">
          <div class="tma-about-item-icon">💬</div>
          <div class="tma-about-item-info">
            <div class="tma-about-item-label">Start New Chat</div>
            <div class="tma-about-item-sub">Chat with the AI assistant</div>
          </div>
          <div class="tma-about-item-arrow">›</div>
        </div>
        <div class="tma-about-item" onclick="tmaSwitchTab(\'history\')">
          <div class="tma-about-item-icon">📋</div>
          <div class="tma-about-item-info">
            <div class="tma-about-item-label">Conversation History</div>
            <div class="tma-about-item-sub">View past conversations</div>
          </div>
          <div class="tma-about-item-arrow">›</div>
        </div>
        <div class="tma-about-item" onclick="tmaClearHistory()">
          <div class="tma-about-item-icon">🗑️</div>
          <div class="tma-about-item-info">
            <div class="tma-about-item-label">Clear History</div>
            <div class="tma-about-item-sub">Remove all saved conversations</div>
          </div>
          <div class="tma-about-item-arrow">›</div>
        </div>
      </div>

      <div class="tma-about-section">
        <div class="tma-about-item">
          <div class="tma-about-item-icon">⚡</div>
          <div class="tma-about-item-info">
            <div class="tma-about-item-label">Powered by NV oOS</div>
            <div class="tma-about-item-sub" id="tma-about-version">AI-powered assistant</div>
          </div>
        </div>
      </div>

      <div class="tma-about-footer" id="tma-about-footer">
        Tap <strong>Chat</strong> to start a conversation
      </div>
    </div>

  </div><!-- /.tma-content -->

  <!-- ── Bottom navigation ────────────────────────────────────── -->
  <nav class="tma-nav" role="navigation" aria-label="Tabs">
    <button class="tma-nav-btn tma-active" id="tma-nav-chat" data-tab="chat" onclick="tmaSwitchTab(\'chat\')">
      <span class="tma-nav-icon">💬</span>
      <span class="tma-nav-label">Chat</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-history" data-tab="history" onclick="tmaSwitchTab(\'history\')">
      <span class="tma-nav-icon">📋</span>
      <span class="tma-nav-label">History</span>
    </button>
    <button class="tma-nav-btn" id="tma-nav-about" data-tab="about" onclick="tmaSwitchTab(\'about\')">
      <span class="tma-nav-icon">ℹ️</span>
      <span class="tma-nav-label">About</span>
    </button>
  </nav>

</div><!-- /.tma-shell -->

' . $footer_output . '
<script>
/* =========================================================
   NV oOS – Telegram Mini App Shell
   Industry-standard Telegram Web App SDK integration.
   ========================================================= */
(function () {
  \'use strict\';

  /* ── Config injected by PHP ── */
  var TMA_BOT_USERNAME   = ' . wp_json_encode( $bot_username ) . ';
  var TMA_VALIDATE_URL   = ' . wp_json_encode( $validate_url ) . ';
  var TMA_SITE_NAME      = ' . wp_json_encode( get_bloginfo( 'name' ) ) . ';
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
  var activeTab = \'chat\';

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
    if (tabName === \'history\') loadHistory();
  };

  /* ── Back Button ── */
  function updateBackButton() {
    if (!twa || !twa.BackButton) return;
    if (activeTab === \'chat\') {
      twa.BackButton.hide();
    } else {
      twa.BackButton.show();
    }
  }

  /* ── Main Button ── */
  function updateMainButton() {
    if (!twa || !twa.MainButton) return;
    if (activeTab === \'about\') {
      twa.MainButton.setText(\'Share Bot 🤖\');
      twa.MainButton.show();
    } else {
      twa.MainButton.hide();
    }
  }

  /* ── Share bot ── */
  window.tmaShareBot = function () {
    haptic(\'medium\');
    if (twa) {
      if (TMA_BOT_USERNAME) {
        twa.switchInlineQuery(\'\', [\'users\', \'groups\', \'channels\']);
      } else {
        twa.showAlert(\'Share this AI assistant with your friends and colleagues!\');
      }
    }
  };

  /* ── Clear history ── */
  window.tmaClearHistory = function () {
    haptic(\'medium\');
    if (twa) {
      twa.showConfirm(\'Clear all conversation history from this device?\', function (confirmed) {
        if (!confirmed) return;
        var keys = [];
        for (var i = 0; i < localStorage.length; i++) {
          var k = localStorage.key(i);
          if (k && k.indexOf(TMA_STORAGE_PREFIX) === 0) keys.push(k);
        }
        keys.forEach(function (k) { localStorage.removeItem(k); });
        haptic(\'notificationOccurred\', \'success\');
        twa.showAlert(\'Conversation history cleared.\');
        if (activeTab === \'history\') loadHistory();
      });
    } else {
      if (confirm(\'Clear all conversation history?\')) {
        for (var i = localStorage.length - 1; i >= 0; i--) {
          var k = localStorage.key(i);
          if (k && k.indexOf(TMA_STORAGE_PREFIX) === 0) localStorage.removeItem(k);
        }
        if (activeTab === \'history\') loadHistory();
      }
    }
  };

  /* ── History tab ── */
  function loadHistory() {
    var container = document.getElementById(\'tma-history-list\');
    if (!container) return;
    var conversations = [];
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var key = localStorage.key(i);
        if (!key || key.indexOf(TMA_STORAGE_PREFIX) !== 0) continue;
        var raw = localStorage.getItem(key);
        if (!raw) continue;
        var data = JSON.parse(raw);
        if (data && Array.isArray(data.messages) && data.messages.length > 0) {
          conversations.push({
            assistantId : key.replace(TMA_STORAGE_PREFIX, \'\'),
            messages    : data.messages,
            timestamp   : data.timestamp || 0,
          });
        }
      }
    } catch (e) {}
    conversations.sort(function (a, b) { return b.timestamp - a.timestamp; });
    if (conversations.length === 0) {
      container.innerHTML = \'<div class="tma-empty">No conversations yet.<br>Tap <strong>Chat</strong> to start!</div>\';
      return;
    }
    var html = \'\';
    conversations.forEach(function (conv) {
      var msgs  = conv.messages;
      var last  = msgs[msgs.length - 1];
      var preview = last ? escHtml((last.content || \'\').substring(0, 100)) : \'\';
      if (last && last.content && last.content.length > 100) preview += \'…\';
      var date  = conv.timestamp ? new Date(conv.timestamp).toLocaleDateString(undefined, { month: \'short\', day: \'numeric\' }) : \'\';
      var count = msgs.length;
      var role  = last ? (last.role === \'assistant\' ? \'🤖\' : \'👤\') : \'\';
      html += \'<div class="tma-history-item">\' +
        \'<div class="tma-history-meta">\' +
          \'<span class="tma-history-id">🤖 Assistant\' + (conv.assistantId ? \' #\' + escHtml(String(conv.assistantId)) : \'\') + \'</span>\' +
          \'<span class="tma-history-date">\' + escHtml(date) + \'</span>\' +
        \'</div>\' +
        \'<div class="tma-history-preview">\' + role + \' \' + preview + \'</div>\' +
        \'<div class="tma-history-count">\' + count + \' message\' + (count !== 1 ? \'s\' : \'\') + \'</div>\' +
      \'</div>\';
    });
    container.innerHTML = html;
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
    } else if (initEl) {
      initEl.textContent = (user.first_name || \'?\')[0].toUpperCase();
    }
  }

  /* ── initData server-side validation ── */
  function validateInitData() {
    if (!twa || !twa.initData || !TMA_VALIDATE_URL) return;
    fetch(TMA_VALIDATE_URL, {
      method  : \'POST\',
      headers : { \'Content-Type\': \'application/json\' },
      body    : JSON.stringify({ init_data: twa.initData }),
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.valid) {
          var statusEl = document.getElementById(\'tma-header-status\');
          if (statusEl && !statusEl.textContent.startsWith(\'@\')) {
            statusEl.textContent = \'✓ Verified\';
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

  /* ── HTML escaping ── */
  function escHtml(str) {
    var d = document.createElement(\'div\');
    d.textContent = String(str);
    return d.innerHTML;
  }

  /* ── Init ── */
  function init() {
    if (twa) {
      twa.ready();
      twa.expand();
      applyTheme();
      updateViewport();
      renderUserInfo();
      validateInitData();

      twa.onEvent(\'themeChanged\',    applyTheme);
      twa.onEvent(\'viewportChanged\', updateViewport);

      if (twa.BackButton) {
        twa.BackButton.onClick(function () {
          if (activeTab !== \'chat\') tmaSwitchTab(\'chat\');
        });
      }
      if (twa.MainButton) {
        twa.MainButton.onClick(tmaShareBot);
        twa.MainButton.hide();
      }
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
		return rest_ensure_response(
			array(
				'valid'     => true,
				'user'      => isset( $result['user'] ) ? $result['user'] : null,
				'auth_date' => isset( $result['auth_date'] ) ? (int) $result['auth_date'] : null,
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
';
	}

	// =========================================================================
	// Helpers
	// =========================================================================

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
}

new WP_MCP_AI_Telegram_Mini_App_Controller();
