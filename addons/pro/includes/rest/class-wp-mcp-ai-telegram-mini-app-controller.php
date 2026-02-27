<?php
/**
 * Telegram Mini App REST Controller
 *
 * Provides a dedicated Mini App URL (Telegram Web App) for BotFather configuration.
 * This endpoint returns a standalone HTML page that integrates with Telegram's
 * Web App JavaScript SDK, enabling the bot's "Open App" / menu button feature.
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
 * Registers GET /mcp-ai/v1/telegram-mini-app which serves a standalone HTML
 * page containing the Telegram Web App JavaScript SDK and the AI chat interface.
 * This URL is provided to BotFather as the bot's Web App URL.
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
	}

	/**
	 * Handle the Telegram Mini App request.
	 *
	 * Returns a standalone HTML page with the Telegram Web App JavaScript SDK
	 * and the AI chat interface. Telegram opens this page inside its built-in
	 * browser when the user taps the bot's "Open App" / menu button.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void Outputs the HTML page directly and exits.
	 */
	public function handle_mini_app( $request ) {
		$assistant_slug = $request->get_param( 'assistant' );

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

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML page output; individual values are escaped below.
		echo '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . esc_html( $page_title ) . '</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
html,body{margin:0;padding:0;height:100%;overflow:hidden;background:var(--tg-theme-bg-color,#fff);color:var(--tg-theme-text-color,#000);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
.wp-mcp-ai-telegram-mini-app-wrapper{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
</style>
' . $head_output . '
</head>
<body class="wp-mcp-ai-telegram-mini-app">
<div class="wp-mcp-ai-telegram-mini-app-wrapper">
' . $chat_html . '
</div>
' . $footer_output . '
<script>
(function(){
	if(window.Telegram&&window.Telegram.WebApp){
		var twa=window.Telegram.WebApp;
		twa.ready();
		twa.expand();
		document.documentElement.style.setProperty("--tg-theme-bg-color",twa.themeParams.bg_color||"#ffffff");
		document.documentElement.style.setProperty("--tg-theme-text-color",twa.themeParams.text_color||"#000000");
	}
})();
</script>
</body>
</html>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
