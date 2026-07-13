<?php
/**
 * Pro SPA v2 Loader — Registers the React Single Page Application admin page
 * and enqueues the TypeScript/esbuild SPA assets.
 *
 * The SPA v2 replaces the legacy webpack-based Pro SPA with a modern
 * TypeScript + esbuild + React 19 + AI SDK architecture, mirroring the
 * chat-spa addon's patterns.
 *
 * @package NV_oOS_Pro
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Loader
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_SPA_Loader {

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.7.0
	 * @var string|null
	 */
	private $hook_suffix = null;

	/**
	 * Register the SPA admin menu page and enqueue hooks.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 20 );
	}

	/**
	 * Add the SPA admin page as a top-level menu item.
	 *
	 * Registered with a different slug than the base plugin's admin page
	 * so both can coexist.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function add_admin_page() {
		$this->hook_suffix = add_menu_page(
			__( 'NV oOS AI', 'mcp-ai-wpoos' ),
			__( 'NV oOS AI', 'mcp-ai-wpoos' ),
			'read',
			'wp-mcp-ai-spa',
			array( $this, 'render' ),
			'dashicons-superhero',
			30
		);

		add_action( 'load-' . $this->hook_suffix, array( $this, 'enqueue' ) );
	}

	/**
	 * Render the SPA root div.
	 *
	 * The SPA entry point (index.tsx) mounts into #wp-mcp-ai-pro-spa-root
	 * or any element with [data-config] attribute for multi-instance support.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function render() {
		echo '<div id="wp-mcp-ai-pro-spa-root"></div>';
	}

	/**
	 * Enqueue SPA JavaScript and CSS assets.
	 *
	 * Loads the esbuild-built IIFE bundle (pro-spa.js / pro-spa.css) and
	 * passes the NVOOS_PRO_SPA runtime configuration via wp_localize_script.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function enqueue() {
		$dist_dir = WP_MCP_AI_PRO_PATH . 'assets/spa-v2/assets/dist/';
		$dist_url = WP_MCP_AI_PRO_URL . 'assets/spa-v2/assets/dist/';

		$js_path  = $dist_dir . 'pro-spa.js';
		$css_path = $dist_dir . 'pro-spa.css';

		$js_url  = $dist_url . 'pro-spa.js';
		$css_url = $dist_url . 'pro-spa.css';

		if ( ! file_exists( $js_path ) ) {
			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'NV oOS Pro SPA v2 assets not found. Run `npm run build` in addons/pro/assets/spa-v2/.', 'mcp-ai-wpoos' )
					);
				}
			);
			return;
		}

		// Use file modification time for cache-busting so browsers pick up
		// new builds immediately, even on sites where WP_MCP_AI_PRO_VERSION
		// hasn't been bumped.
		$version = defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '2.0.0';
		$js_ver  = filemtime( $js_path ) ? filemtime( $js_path ) : $version;
		$css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : $version;

		wp_enqueue_script(
			'wp-mcp-ai-pro-spa-v2',
			$js_url,
			array( 'wp-i18n' ),
			$js_ver,
			true
		);

		wp_set_script_translations(
			'wp-mcp-ai-pro-spa-v2',
			'nvoos-pro-spa',
			WP_MCP_AI_PRO_PATH . 'languages'
		);

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-pro-spa-v2',
				$css_url,
				array(),
				$css_ver
			);
		}

		/**
		 * Runtime configuration passed to the SPA via NVOOS_PRO_SPA global.
		 *
		 * Mirrors the structure expected by src/api/config.ts → readProSpaConfig().
		 */
		$user     = wp_get_current_user();
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		$assistant_id = 0;
		if ( class_exists( 'WP_MCP_AI_Assistant_Manager' ) ) {
			$default = WP_MCP_AI_Assistant_Manager::get_default_assistant( $user_id );
			if ( $default ) {
				$assistant_id = $default;
			}
		}

		// Pre-load published assistants so the SPA sidebar can render the
		// dropdown immediately without a separate REST API round-trip.
		$assistants = array();
		if ( post_type_exists( 'mcp_ai_assistant' ) && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $post ) {
				$config   = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post->ID );
				$provider = isset( $config['provider'] ) ? sanitize_key( $config['provider'] ) : '';
				$model    = isset( $config['model'] ) ? (string) $config['model'] : '';

				$assistants[] = array(
					'id'       => $post->ID,
					'title'    => get_the_title( $post ),
					'provider' => $provider,
					'model'    => $model,
				);
			}
		}

		$runtime = array(
			'apiUrl'       => esc_url_raw( rest_url( 'mcp-ai/v1' ) ),
			'proApi'       => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'config'       => array(
				'assistantId'         => $assistant_id,
				'theme'               => 'auto',
				'allowSensitiveTools' => true,
			),
			'endpoints'    => array(
				// Core chat endpoints (mcp-ai/v1).
				'chat'          => esc_url_raw( rest_url( 'mcp-ai/v1/chat' ) ),
				'chatClient'    => esc_url_raw( rest_url( 'mcp-ai/v1/chat-client' ) ),
				'transcripts'   => esc_url_raw( rest_url( 'mcp-ai/v1/chat-transcripts' ) ),
				'memory'        => esc_url_raw( rest_url( 'mcp-ai/v1/chat-memory' ) ),
				'threads'       => esc_url_raw( rest_url( 'mcp-ai/v1/threads' ) ),
				'tools'         => esc_url_raw( rest_url( 'mcp-ai/v1/tools' ) ),
				'assistants'    => esc_url_raw( rest_url( 'mcp-ai/v1/assistants' ) ),
				'settings'      => esc_url_raw( rest_url( 'mcp-ai/v1/settings' ) ),

				// WordPress media upload endpoint (matches legacy chat-spa).
				'upload'        => esc_url_raw( rest_url( 'wp/v2/media' ) ),

				// Pro endpoints (mcp-ai-pro/v1).
				'workflows'     => class_exists( 'WP_MCP_AI_Pro_Workflow_Controller' )
					? esc_url_raw( rest_url( 'mcp-ai-pro/v1/workflows' ) )
					: '',
				'analytics'     => $is_admin
					? esc_url_raw( rest_url( 'mcp-ai-pro/v1/analytics' ) )
					: '',
				'approvals'     => $is_admin
					? esc_url_raw( rest_url( 'mcp-ai/v1/approvals' ) )
					: '',
				'shortcuts'     => esc_url_raw( rest_url( 'mcp-ai-pro/v1/tool-shortcuts' ) ),
				'slashCommands' => esc_url_raw( rest_url( 'mcp-ai-pro/v1/slash-commands' ) ),
			),
			'user'         => array(
				'id'           => $user_id,
				'login'        => $user->user_login,
				'displayName'  => $user->display_name,
				'capabilities' => array_keys( $user->allcaps ),
				'assistant_id' => $assistant_id,
			),
			'mentionTypes' => array(),
			// Pre-loaded assistants so the sidebar renders immediately.
			'assistants'   => $assistants,
		);

		// Populate mention types if the resolver is available.
		if ( class_exists( 'WP_MCP_AI_Context_Mention_Resolver' ) ) {
			$resolver                = new WP_MCP_AI_Context_Mention_Resolver();
			$types                   = $resolver->get_registered_types();
			$runtime['mentionTypes'] = is_array( $types ) ? $types : array();
		}

		wp_localize_script(
			'wp-mcp-ai-pro-spa-v2',
			'NVOOS_PRO_SPA',
			$runtime
		);
	}
}
