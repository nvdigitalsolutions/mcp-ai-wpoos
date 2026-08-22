<?php
/**
 * NV oOS Addons Admin Page
 *
 * Registers a standalone admin page under the NV oOS Pro Dashboard menu that
 * lists every standalone NV oOS addon and offers one-click install / activate
 * from the addon ZIPs bundled in the plugin's build/ directory. This
 * generalises the canvas addon install flow that previously lived on the
 * Pro Packages settings page.
 *
 * - One status card per addon: Active / Installed (inactive) / Installable /
 *   Upload manually.
 * - Single delegated AJAX handler (`wp_mcp_ai_install_addon`) that resolves
 *   the requested addon against an allowlist registry, then installs from the
 *   bundled ZIP (Plugin_Upgrader) and activates it.
 * - Non-WordPress components (Docker sidecars, Cloudflare Workers, SPA
 *   frontends) are listed read-only under "External Components".
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.63
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Addons_Page' ) ) {
	/**
	 * Standalone admin page for installing NV oOS addons.
	 *
	 * @since 1.1.63
	 */
	class WP_MCP_AI_Addons_Page {

		/**
		 * Admin page slug.
		 *
		 * @var string
		 */
		const PAGE_SLUG = 'wp-mcp-ai-addons';

		/**
		 * AJAX nonce action name.
		 *
		 * @var string
		 */
		const NONCE_ACTION = 'wp_mcp_ai_install_addon';

		/**
		 * Actual WordPress hook name returned by add_submenu_page().
		 *
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Priority 26: the parent nvoos-pro-dashboard menu registers at priority 25.
			add_action( 'admin_menu', array( $this, 'register_page' ), 26 );

			// AJAX handler for one-click addon install / activate.
			add_action( 'wp_ajax_wp_mcp_ai_install_addon', array( $this, 'ajax_install_addon' ) );
		}

		/**
		 * Register the admin submenu page under NV oOS Pro Dashboard.
		 *
		 * @return void
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Addons', 'mcp-ai-wpoos-pro' ),
				__( 'Addons', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Addon registry.
		 *
		 * Every entry maps a safe slug (the AJAX allowlist key) to the metadata
		 * needed to detect and install the addon:
		 *
		 * - name:        Display name.
		 * - icon:        Emoji used on the status card.
		 * - description: Short one-liner for the card body.
		 * - plugin_file: Plugin file relative to WP_PLUGIN_DIR, e.g.
		 *                "nvoos-canvas/nvoos-canvas.php".
		 * - zip_pattern: Glob pattern matched inside WP_MCP_AI_PATH/build/.
		 *                "{arch}" resolves to x64 / arm64 (canvas only).
		 * - requires:    Human-readable dependency note.
		 * - license:     License label.
		 * - external:    True for non-WordPress components that cannot be
		 *                installed from this page.
		 *
		 * @return array<string, array<string, string|bool>>
		 */
		public function get_addon_definitions() {
			$definitions = array(
				'canvas'                     => array(
					'name'        => __( 'Canvas', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🖼️',
					'description' => __( 'Pre-compiled canvas native binaries enabling Tesseract PDF OCR without system library installation.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-canvas/nvoos-canvas.php',
					'zip_pattern' => 'nvoos-canvas-linux-{arch}-v*.zip',
					'requires'    => __( 'NV oOS Pro', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'algorave'                   => array(
					'name'        => __( 'Algorave', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🎛️',
					'description' => __( 'Live-coding music extension with AI pattern generation, browser audio synthesis, and MIDI export.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-algorave/nvoos-algorave.php',
					'zip_pattern' => 'nvoos-algorave-linux-x64-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'AGPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'cornerstone3d'              => array(
					'name'        => __( 'Cornerstone3D', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🩻',
					'description' => __( 'Pre-built Cornerstone3D ESM bundles for DICOM medical imaging, removing the runtime CDN dependency.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-cornerstone3d/nvoos-cornerstone3d.php',
					'zip_pattern' => 'nvoos-cornerstone3d-v*.zip',
					'requires'    => __( 'NV oOS Pro', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'embedded'                   => array(
					'name'        => __( 'Embedded', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🧠',
					'description' => __( 'Server-side (llama.cpp GGUF) and client-side (WebLLM/WebGPU) inference, WebRTC chat rooms, voice tools and MCP abilities.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-embedded/nvoos-embedded.php',
					'zip_pattern' => 'nvoos-embedded-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'graphify'                   => array(
					'name'        => __( 'Graphify', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🕸️',
					'description' => __( 'Knowledge graph builder extracting entities and relationships from content into navigable graphs.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-graphify/nvoos-graphify.php',
					'zip_pattern' => 'nvoos-graphify-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'content-graph'              => array(
					'name'        => __( 'Content Graph', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🧬',
					'description' => __( 'Standalone knowledge graph plugin (the renamed Graphify core).', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-content-graph/nvoos-content-graph.php',
					'zip_pattern' => 'nvoos-content-graph-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'content-graph-ai'           => array(
					'name'        => __( 'Content Graph AI', 'mcp-ai-wpoos-pro' ),
					'icon'        => '✨',
					'description' => __( 'AI-powered knowledge graph features for Content Graph.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-content-graph-ai/nvoos-content-graph-ai.php',
					'zip_pattern' => 'nvoos-content-graph-ai-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'content-graph-ai-platform'  => array(
					'name'        => __( 'Content Graph AI Platform', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🧩',
					'description' => __( 'Federation and platform features for Content Graph AI.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-content-graph-ai-platform/nvoos-content-graph-ai-platform.php',
					'zip_pattern' => 'nvoos-content-graph-ai-platform-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'docs-hub'                   => array(
					'name'        => __( 'Docs Hub', 'mcp-ai-wpoos-pro' ),
					'icon'        => '📚',
					'description' => __( 'GitBook-style React documentation browser for every installed plugin and addon.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-docs-hub/nvoos-docs-hub.php',
					'zip_pattern' => 'nvoos-docs-hub-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'chat-spa'                   => array(
					'name'        => __( 'Chat SPA', 'mcp-ai-wpoos-pro' ),
					'icon'        => '💬',
					'description' => __( 'React chat surface built on the Vercel AI SDK with shortcode and Gutenberg block.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-chat-spa/nvoos-chat-spa.php',
					'zip_pattern' => 'nvoos-chat-spa-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'librechat'                  => array(
					'name'        => __( 'LibreChat', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🗣️',
					'description' => __( 'Sandboxed code interpreter, speech services (TTS/STT), and web search reranker.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-librechat/nvoos-librechat.php',
					'zip_pattern' => 'nvoos-librechat-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'saas-controller'            => array(
					'name'        => __( 'SaaS Controller', 'mcp-ai-wpoos-pro' ),
					'icon'        => '☁️',
					'description' => __( 'Operator toolkit for provisioning and managing the NV oOS Cloud control plane.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-saas-controller/nvoos-saas-controller.php',
					'zip_pattern' => 'nvoos-saas-controller-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'cloudways-dashboard'        => array(
					'name'        => __( 'Cloudways Dashboard', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🖥️',
					'description' => __( 'Operator dashboard for managing Cloudways servers, WordPress sites, and NV oOS toolkits.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-cloudways-dashboard/nvoos-cloudways-dashboard.php',
					'zip_pattern' => 'nvoos-cloudways-dashboard-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'funiq-bridge'               => array(
					'name'        => __( 'Funiq Bridge', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🛍️',
					'description' => __( 'Payload CMS-to-WordPress bridge for the Funiq React PWA with products, promotions and promocodes.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'funiq-bridge/funiq-bridge.php',
					'zip_pattern' => 'nvoos-funiq-bridge-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'comic-reader'               => array(
					'name'        => __( 'Comic Reader', 'mcp-ai-wpoos-pro' ),
					'icon'        => '📖',
					'description' => __( 'React comic reader and creator supporting CBR/CBZ/CB7/CBT with AI creation tools.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-comic-reader/nvoos-comic-reader.php',
					'zip_pattern' => 'nvoos-comic-reader-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'fantasy-football'           => array(
					'name'        => __( 'Fantasy Football', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🏈',
					'description' => __( 'ESPN and Yahoo Fantasy Sports integration: team management, research, trade analysis and reports.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-fantasy-football/nvoos-fantasy-football.php',
					'zip_pattern' => 'nvoos-fantasy-football-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'schedule-anything-platform' => array(
					'name'        => __( 'Schedule Anything', 'mcp-ai-wpoos-pro' ),
					'icon'        => '📅',
					'description' => __( 'SaaS booking platform with Stripe payments, calendars and multi-tenant architecture.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'schedule-anything-platform/schedule-anything-platform.php',
					'zip_pattern' => 'nvoos-schedule-anything-platform-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
				),
				'crocoblock-ds'              => array(
					'name'        => __( 'Crocoblock DS', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🎨',
					'description' => __( 'Design token system for the Crocoblock suite with DTCG export and accessibility tokens.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-crocoblock-ds/nvoos-crocoblock-ds.php',
					'zip_pattern' => 'nvoos-crocoblock-ds-v*.zip',
					'requires'    => __( 'None', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'page-agent'                 => array(
					'name'        => __( 'Page Agent', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🤖',
					'description' => __( 'AI browser page copilot that can click, type and navigate any WordPress page via natural language.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-page-agent/nvoos-page-agent.php',
					'zip_pattern' => 'nvoos-page-agent-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'fleet-operator'             => array(
					'name'        => __( 'Fleet Operator', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🚀',
					'description' => __( 'External-operator governance with scoped credentials, MCP tool scoping and WP-CLI.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'fleet-operator/fleet-operator.php',
					'zip_pattern' => 'nvoos-fleet-operator-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'canvas-toolkit'             => array(
					'name'        => __( 'Canvas Toolkit', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🧰',
					'description' => __( 'Manifest-driven React SPA providing a canvas-based surface for the plugin.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-canvas-toolkit/nvoos-canvas-toolkit.php',
					'zip_pattern' => 'nvoos-canvas-toolkit-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'document-editor'            => array(
					'name'        => __( 'Document Editor', 'mcp-ai-wpoos-pro' ),
					'icon'        => '📝',
					'description' => __( 'Manifest-driven React SPA document editing surface.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-document-editor/nvoos-document-editor.php',
					'zip_pattern' => 'nvoos-document-editor-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'media-studio'               => array(
					'name'        => __( 'Media Studio', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🎞️',
					'description' => __( 'Manifest-driven React SPA media surface with zoom, pan and drawing tools.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-media-studio/nvoos-media-studio.php',
					'zip_pattern' => 'nvoos-media-studio-v*.zip',
					'requires'    => __( 'NV oOS', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),
				'toolkit-shell'              => array(
					'name'        => __( 'Toolkit Shell', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🧱',
					'description' => __( 'Manifest-driven React SPA shell powering multiple toolkit SPAs from one bundle.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => 'nvoos-toolkit-shell/nvoos-toolkit-shell.php',
					'zip_pattern' => 'nvoos-toolkit-shell-v*.zip',
					'requires'    => __( 'NV oOS Pro', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
				),

				// Non-WordPress components — listed read-only.
				'media-worker'               => array(
					'name'        => __( 'Media Worker', 'mcp-ai-wpoos-pro' ),
					'icon'        => '⚙️',
					'description' => __( 'Docker-based Node.js sidecar for heavy media processing (Puppeteer, OCR, PDF, video). Deployed as a container, not a WordPress plugin.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => '',
					'zip_pattern' => '',
					'requires'    => __( 'None', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'GPL-3.0', 'mcp-ai-wpoos-pro' ),
					'external'    => true,
				),
				'cloud-worker'               => array(
					'name'        => __( 'Cloud Worker', 'mcp-ai-wpoos-pro' ),
					'icon'        => '☁️',
					'description' => __( 'Cloudflare Worker powering the NV oOS Cloud SaaS backend. Deployed independently — never runs inside WordPress.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => '',
					'zip_pattern' => '',
					'requires'    => __( 'None', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Reference only', 'mcp-ai-wpoos-pro' ),
					'external'    => true,
				),
				'tenant-router'              => array(
					'name'        => __( 'Tenant Router', 'mcp-ai-wpoos-pro' ),
					'icon'        => '🧭',
					'description' => __( 'Cloudflare edge worker routing Schedule Anything tenants to the right WordPress Multisite tenant.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => '',
					'zip_pattern' => '',
					'requires'    => __( 'Schedule Anything', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Reference only', 'mcp-ai-wpoos-pro' ),
					'external'    => true,
				),
				'schedule-anything-spa'      => array(
					'name'        => __( 'Schedule Anything SPA', 'mcp-ai-wpoos-pro' ),
					'icon'        => '📅',
					'description' => __( 'React frontend for the Schedule Anything SaaS platform, built and deployed from its own npm project.', 'mcp-ai-wpoos-pro' ),
					'plugin_file' => '',
					'zip_pattern' => '',
					'requires'    => __( 'Schedule Anything', 'mcp-ai-wpoos-pro' ),
					'license'     => __( 'Proprietary', 'mcp-ai-wpoos-pro' ),
					'external'    => true,
				),
			);

			/**
			 * Filter the addon registry shown on the Addons admin page.
			 *
			 * @since 1.1.63
			 *
			 * @param array $definitions Addon definitions keyed by slug.
			 */
			return apply_filters( 'wp_mcp_ai_addons_definitions', $definitions );
		}

		/**
		 * Resolve the current status of a single addon definition.
		 *
		 * @param array $definition Addon definition from the registry.
		 * @return array{active:bool, installed:bool, zip_path:string, version:string, zip_name:string}
		 */
		public function get_addon_status( $definition ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$status = array(
				'active'    => false,
				'installed' => false,
				'zip_path'  => '',
				'zip_name'  => '',
				'version'   => '',
			);

			$plugin_file = isset( $definition['plugin_file'] ) ? (string) $definition['plugin_file'] : '';
			if ( '' !== $plugin_file ) {
				$status['active'] = is_plugin_active( $plugin_file );

				if ( ! $status['active'] && function_exists( 'get_plugins' ) ) {
					$plugins = get_plugins();
					if ( isset( $plugins[ $plugin_file ] ) ) {
						$status['installed'] = true;
						$status['version']   = isset( $plugins[ $plugin_file ]['Version'] ) ? $plugins[ $plugin_file ]['Version'] : '';
					}
				}
			}

			if ( $status['active'] && function_exists( 'get_plugin_data' ) ) {
				$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
				if ( file_exists( $plugin_path ) ) {
					$data              = get_plugin_data( $plugin_path, false, false );
					$status['version'] = isset( $data['Version'] ) ? $data['Version'] : '';
				}
			}

			if ( ! $status['active'] ) {
				$status['zip_path'] = $this->get_addon_zip_path( $definition );
				if ( '' !== $status['zip_path'] ) {
					$status['zip_name'] = basename( $status['zip_path'] );
					if ( preg_match( '/-v([0-9][0-9a-z.\-]*)\.zip$/i', $status['zip_name'], $match ) ) {
						$status['version'] = $match[1];
					}
				}
			}

			return $status;
		}

		/**
		 * Locate the newest bundled addon ZIP matching a definition.
		 *
		 * @param array $definition Addon definition from the registry.
		 * @return string Absolute path to the ZIP, or '' if not found.
		 */
		protected function get_addon_zip_path( $definition ) {
			if ( ! defined( 'WP_MCP_AI_PATH' ) || empty( $definition['zip_pattern'] ) ) {
				return '';
			}

			$pattern = (string) $definition['zip_pattern'];

			// Map uname -m to the slug used in ZIP filenames (canvas only today).
			if ( false !== strpos( $pattern, '{arch}' ) ) {
				$machine = php_uname( 'm' );
				if ( false !== strpos( $machine, 'aarch64' ) || false !== strpos( $machine, 'arm64' ) ) {
					$arch = 'arm64';
				} else {
					$arch = 'x64';
				}
				$pattern = str_replace( '{arch}', $arch, $pattern );
			}

			$matches = glob( WP_MCP_AI_PATH . 'build/' . $pattern );
			if ( empty( $matches ) ) {
				return '';
			}

			usort( $matches, 'strnatcmp' );
			return end( $matches );
		}

		/**
		 * Render the Addons admin page.
		 *
		 * @return void
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
			}

			$definitions = $this->get_addon_definitions();
			$installable = array();
			$external    = array();

			foreach ( $definitions as $slug => $definition ) {
				if ( ! empty( $definition['external'] ) ) {
					$external[ $slug ] = $definition;
				} else {
					$installable[ $slug ] = $definition;
				}
			}
			?>
			<div class="wrap">
				<h1>🧩 <?php esc_html_e( 'NV oOS Addons', 'mcp-ai-wpoos-pro' ); ?></h1>
				<p>
					<?php esc_html_e( 'Install and activate the standalone NV oOS addons. Addons bundled with this installation can be installed in one click; otherwise upload the addon ZIP via Plugins → Add New → Upload Plugin.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p class="description" style="margin-top:-6px;">
					<?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?>
					✅ <?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?> ·
					⚠️ <?php esc_html_e( 'Installed (inactive)', 'mcp-ai-wpoos-pro' ); ?> ·
					⬇️ <?php esc_html_e( 'Ready to install', 'mcp-ai-wpoos-pro' ); ?> ·
					❌ <?php esc_html_e( 'Not bundled — upload manually', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:16px;margin-top:16px;">
					<?php foreach ( $installable as $slug => $definition ) : ?>
						<?php $this->render_addon_card( $slug, $definition ); ?>
					<?php endforeach; ?>
				</div>

				<h2 style="margin-top:36px;"><?php esc_html_e( 'External Components', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'These components do not run as WordPress plugins and cannot be installed from this page. They are deployed independently (Docker, Cloudflare Workers, or their own frontend builds).', 'mcp-ai-wpoos-pro' ); ?></p>

				<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:16px;">
					<?php foreach ( $external as $slug => $definition ) : ?>
						<?php $this->render_external_card( $slug, $definition ); ?>
					<?php endforeach; ?>
				</div>
			</div>

			<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				$( document ).on( 'click', '.wp-mcp-ai-addon-action', function( e ) {
					e.preventDefault();
					var $button = $( this );
					var slug    = $button.data( 'addon' );
					var $result = $( '#wp-mcp-ai-addon-result-' + slug );
					var nonce   = $button.data( 'nonce' );

					$button.prop( 'disabled', true );
					$button.find( '.dashicons' )
						.removeClass( 'dashicons-download dashicons-yes dashicons-admin-plugins' )
						.addClass( 'dashicons-update' );
					$result.hide().html( '' );

					$.ajax( {
						url:     ajaxurl,
						type:    'POST',
						timeout: 120000,
						data: {
							action: 'wp_mcp_ai_install_addon',
							nonce:  nonce,
							addon:  slug
						},
						success: function( response ) {
							if ( response.success ) {
								$result.html( '<span style="color:green;font-weight:bold;">✅ ' + response.data.message + '</span>' ).show();
								setTimeout( function() { location.reload(); }, 2000 );
							} else {
								$result.html( '<span style="color:red;">✗ ' + response.data.message + '</span>' ).show();
								$button.prop( 'disabled', false );
								$button.find( '.dashicons' )
									.removeClass( 'dashicons-update' )
									.addClass( 'dashicons-download dashicons-yes dashicons-admin-plugins' );
							}
						},
						error: function() {
							$result.html( '<span style="color:red;">✗ <?php echo esc_js( __( 'Request failed — network error.', 'mcp-ai-wpoos-pro' ) ); ?></span>' ).show();
							$button.prop( 'disabled', false );
							$button.find( '.dashicons' )
								.removeClass( 'dashicons-update' )
								.addClass( 'dashicons-download dashicons-yes dashicons-admin-plugins' );
						}
					} );
				} );
			} );
			</script>
			<?php
		}

		/**
		 * Render one installable addon status card.
		 *
		 * @param string $slug       Addon registry key.
		 * @param array  $definition Addon definition.
		 * @return void
		 */
		protected function render_addon_card( $slug, $definition ) {
			$status = $this->get_addon_status( $definition );

			if ( $status['active'] ) {
				$status_color = '#46b450';
				$status_icon  = '✅';
				$status_label = __( 'Active', 'mcp-ai-wpoos-pro' );
			} elseif ( $status['installed'] ) {
				$status_color = '#f0b849';
				$status_icon  = '⚠️';
				$status_label = __( 'Installed (Inactive)', 'mcp-ai-wpoos-pro' );
			} elseif ( '' !== $status['zip_path'] ) {
				$status_color = '#2271b1';
				$status_icon  = '⬇️';
				$status_label = __( 'Ready to Install', 'mcp-ai-wpoos-pro' );
			} else {
				$status_color = '#dc3232';
				$status_icon  = '❌';
				$status_label = __( 'Not Bundled', 'mcp-ai-wpoos-pro' );
			}

			$nonce = wp_create_nonce( self::NONCE_ACTION );
			?>
			<div class="wp-mcp-ai-addon-card" style="background:#fff;border:1px solid #ddd;border-left:4px solid <?php echo esc_attr( $status_color ); ?>;padding:20px;display:flex;flex-direction:column;gap:8px;">
				<h3 style="margin:0;">
					<?php echo esc_html( $definition['icon'] . ' ' . $definition['name'] ); ?>
					<span style="font-weight:normal;font-size:13px;color:<?php echo esc_attr( $status_color ); ?>;margin-left:8px;">
						<?php echo esc_html( $status_icon . ' ' . $status_label ); ?>
					</span>
				</h3>

				<p style="margin:0;flex-grow:1;"><?php echo esc_html( $definition['description'] ); ?></p>

				<p style="margin:0;font-size:12px;color:#666;">
					<?php if ( '' !== $status['version'] ) : ?>
						<strong><?php esc_html_e( 'Version:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<code><?php echo esc_html( $status['version'] ); ?></code>
						·
					<?php endif; ?>
					<?php
					printf(
						/* translators: 1: License label, 2: Requires label */
						esc_html__( 'License: %1$s · Requires: %2$s', 'mcp-ai-wpoos-pro' ),
						esc_html( $definition['license'] ),
						esc_html( $definition['requires'] )
					);
					?>
				</p>

				<?php if ( $status['active'] ) : ?>
					<p style="margin:0;color:#46b450;">
						<strong><?php esc_html_e( '✅ Active — managed from the Plugins screen.', 'mcp-ai-wpoos-pro' ); ?></strong>
					</p>
				<?php elseif ( $status['installed'] ) : ?>
					<button
						type="button"
						class="button button-primary wp-mcp-ai-addon-action"
						data-addon="<?php echo esc_attr( $slug ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
					>
						<span class="dashicons dashicons-yes" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Activate', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				<?php elseif ( '' !== $status['zip_path'] ) : ?>
					<button
						type="button"
						class="button button-primary wp-mcp-ai-addon-action"
						data-addon="<?php echo esc_attr( $slug ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
					>
						<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Install', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<p style="margin:0;color:#666;font-size:12px;">
						<?php
						printf(
							/* translators: %s: ZIP filename */
							esc_html__( 'ZIP found: %s', 'mcp-ai-wpoos-pro' ),
							esc_html( $status['zip_name'] )
						);
						?>
					</p>
				<?php else : ?>
					<a
						href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=upload' ) ); ?>"
						class="button button-primary"
					>
						<span class="dashicons dashicons-upload" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Upload Plugin', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<p style="margin:0;color:#666;font-size:12px;">
						<?php esc_html_e( 'Download the addon ZIP from the NV oOS releases and upload via Plugins → Add New → Upload Plugin.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				<?php endif; ?>

				<div id="wp-mcp-ai-addon-result-<?php echo esc_attr( $slug ); ?>" style="display:none;"></div>
			</div>
			<?php
		}

		/**
		 * Render one external component card (read-only).
		 *
		 * @param string $slug       Component registry key.
		 * @param array  $definition Component definition.
		 * @return void
		 */
		protected function render_external_card( $slug, $definition ) {
			?>
			<div class="wp-mcp-ai-addon-card" style="background:#f9f9f9;border:1px solid #ddd;border-left:4px solid #999;padding:20px;display:flex;flex-direction:column;gap:8px;">
				<h3 style="margin:0;">
					<?php echo esc_html( $definition['icon'] . ' ' . $definition['name'] ); ?>
					<span style="font-weight:normal;font-size:13px;color:#999;margin-left:8px;">
						📦 <?php esc_html_e( 'External', 'mcp-ai-wpoos-pro' ); ?>
					</span>
				</h3>

				<p style="margin:0;flex-grow:1;"><?php echo esc_html( $definition['description'] ); ?></p>

				<p style="margin:0;font-size:12px;color:#666;">
					<?php
					printf(
						/* translators: 1: License label, 2: Requires label */
						esc_html__( 'License: %1$s · Requires: %2$s', 'mcp-ai-wpoos-pro' ),
						esc_html( $definition['license'] ),
						esc_html( $definition['requires'] )
					);
					?>
				</p>
			</div>
			<?php
		}

		/**
		 * AJAX handler — install and activate a standalone NV oOS addon.
		 *
		 * The addon slug is resolved against the registry allowlist, so this
		 * handler cannot be used to install arbitrary plugins.
		 *
		 * @return void
		 */
		public function ajax_install_addon() {
			// Verify nonce.
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mcp-ai-wpoos-pro' ) ) );
			}

			// Require install_plugins capability.
			if ( ! current_user_can( 'install_plugins' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
			}

			// Allowlist lookup: never act on a slug outside the registry.
			$slug        = isset( $_POST['addon'] ) ? sanitize_key( wp_unslash( $_POST['addon'] ) ) : '';
			$definitions = $this->get_addon_definitions();
			if ( '' === $slug || ! isset( $definitions[ $slug ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Unknown addon.', 'mcp-ai-wpoos-pro' ) ) );
			}

			$definition = $definitions[ $slug ];

			// External components are not WordPress plugins.
			if ( ! empty( $definition['external'] ) ) {
				wp_send_json_error( array( 'message' => __( 'This component cannot be installed from this page.', 'mcp-ai-wpoos-pro' ) ) );
			}

			$status = $this->get_addon_status( $definition );

			// Already active — nothing to do.
			if ( $status['active'] ) {
				wp_send_json_success( array( 'message' => __( 'Addon is already installed and active.', 'mcp-ai-wpoos-pro' ) ) );
			}

			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Installed but inactive — just activate.
			if ( $status['installed'] ) {
				$activated = activate_plugin( $definition['plugin_file'] );
				if ( is_wp_error( $activated ) ) {
					wp_send_json_error( array( 'message' => $activated->get_error_message() ) );
				}
				wp_send_json_success( array( 'message' => __( 'Addon activated successfully.', 'mcp-ai-wpoos-pro' ) ) );
			}

			// ZIP not available.
			if ( '' === $status['zip_path'] ) {
				wp_send_json_error(
					array(
						'message' => __( 'Addon ZIP not found. Please download the addon ZIP for your platform from the NV oOS releases and upload via Plugins → Add New → Upload Plugin.', 'mcp-ai-wpoos-pro' ),
					)
				);
			}

			// Install from the bundled ZIP.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

			WP_Filesystem();

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $status['zip_path'] );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! $result ) {
				$errors = $skin->get_errors();
				$msg    = is_wp_error( $errors ) && $errors->has_errors()
					? $errors->get_error_message()
					: __( 'Installation failed. Check file permissions.', 'mcp-ai-wpoos-pro' );
				wp_send_json_error( array( 'message' => $msg ) );
			}

			// Activate after successful install.
			$activated = activate_plugin( $definition['plugin_file'] );
			if ( is_wp_error( $activated ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Addon installed but activation failed: %s', 'mcp-ai-wpoos-pro' ),
							$activated->get_error_message()
						),
					)
				);
			}

			wp_send_json_success( array( 'message' => __( 'Addon installed and activated successfully.', 'mcp-ai-wpoos-pro' ) ) );
		}
	}
}

// Instantiate the page (loaded in admin context only — see the Pro module registry's
// "admin_sections" module).
new WP_MCP_AI_Addons_Page();
