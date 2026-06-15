<?php
/**
 * Unified Blueprints Admin Page
 *
 * Browse and install curated AI assistant blueprints from all Pro toolkits
 * in one place. Scans every `examples/` directory under `includes/tools/`,
 * groups blueprints by toolkit, and provides one-click install plus detail
 * preview panels.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.3.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified Blueprints Page — single browser for all toolkit blueprints.
 *
 * @since 2.3.1
 */
class WP_MCP_AI_Unified_Blueprints_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-pro-blueprints';

	/**
	 * WordPress hook name returned by add_submenu_page().
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Map of human-readable toolkit labels keyed by directory name.
	 *
	 * @var array<string,string>
	 */
	private static $toolkit_labels = array();

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 27 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_unified_install_blueprint', array( __CLASS__, 'ajax_install_blueprint' ) );
		add_action( 'wp_ajax_wp_mcp_ai_unified_get_blueprint_details', array( __CLASS__, 'ajax_get_blueprint_details' ) );

		self::$toolkit_labels = array(
			'crm'                     => __( 'CRM & Email Marketing', 'mcp-ai-wpoos-pro' ),
			'healthcare'              => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
			'ecommerce'               => __( 'E-commerce', 'mcp-ai-wpoos-pro' ),
			'law-firm'                => __( 'Law Firm', 'mcp-ai-wpoos-pro' ),
			'financial-planning'      => __( 'Financial Planning', 'mcp-ai-wpoos-pro' ),
			'cre-debt'                => __( 'CRE Debt & Securitization', 'mcp-ai-wpoos-pro' ),
			'calendar-booking'        => __( 'Calendar & Booking', 'mcp-ai-wpoos-pro' ),
			'dj-management'           => __( 'DJ Management', 'mcp-ai-wpoos-pro' ),
			'project-management'      => __( 'Project Management', 'mcp-ai-wpoos-pro' ),
			'regulatory-registration' => __( 'Regulatory Registration', 'mcp-ai-wpoos-pro' ),
			'social-media'            => __( 'Social Media', 'mcp-ai-wpoos-pro' ),
			'video-production'        => __( 'Video Production', 'mcp-ai-wpoos-pro' ),
			'architectural-design'    => __( 'Architectural Design', 'mcp-ai-wpoos-pro' ),
			'eca-management'          => __( 'ECA Management', 'mcp-ai-wpoos-pro' ),
			'image-production'        => __( 'Image Production', 'mcp-ai-wpoos-pro' ),
			'document-generation'     => __( 'Document Generation', 'mcp-ai-wpoos-pro' ),
			'analytics'               => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
			'chat-channels'           => __( 'Chat Channels', 'mcp-ai-wpoos-pro' ),
			'comic-creation'          => __( 'Comic Creation', 'mcp-ai-wpoos-pro' ),
			'site-creator-toolkit'    => __( 'Site Creator', 'mcp-ai-wpoos-pro' ),
			'multilingual'            => __( 'Multilingual', 'mcp-ai-wpoos-pro' ),
			'ai-tool-builder'         => __( 'AI Tool Builder', 'mcp-ai-wpoos-pro' ),
			'extended-cognition'      => __( 'Extended Cognition', 'mcp-ai-wpoos-pro' ),
			'media'                   => __( 'Media Toolkit', 'mcp-ai-wpoos-pro' ),
			'email-marketing'         => __( 'Email Marketing', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register submenu page under NV oOS Pro Dashboard.
	 */
	public static function register_page() {
		self::$page_hook = add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Assistant Blueprints', 'mcp-ai-wpoos-pro' ),
			__( 'Blueprints', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin styles and scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::$page_hook !== $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
		}

		add_action(
			'admin_head',
			function () {
				?>
				<style>
				.nv-bp-wrap { margin: 20px 20px 0 0; }
				.nv-bp-header { margin-bottom: 20px; }
				.nv-bp-filters { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
				.nv-bp-filters select, .nv-bp-filters input { min-width: 200px; }
				.nv-bp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
				.nv-bp-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 20px; display: flex; flex-direction: column; transition: box-shadow 0.2s; }
				.nv-bp-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); border-color: #2271b1; }
				.nv-bp-card h3 { margin: 0 0 6px; font-size: 15px; }
				.nv-bp-toolkit-badge { display: inline-block; background: #e7f0f9; color: #2271b1; font-size: 11px; padding: 1px 8px; border-radius: 3px; margin-bottom: 8px; }
				.nv-bp-desc { color: #646970; font-size: 13px; line-height: 1.5; margin-bottom: 12px; flex: 1; }
				.nv-bp-meta { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; }
				.nv-bp-tag { background: #f0f0f1; color: #50575e; font-size: 11px; padding: 2px 8px; border-radius: 3px; white-space: nowrap; }
				.nv-bp-tag.installed { background: #d4edda; color: #155724; }
				.nv-bp-stats { font-size: 12px; color: #8c8f94; margin-bottom: 12px; }
				.nv-bp-actions { display: flex; gap: 8px; }
				.nv-bp-spinner { display: none; margin: 0; float: none; }
				.nv-bp-section-title { font-size: 16px; font-weight: 600; margin: 30px 0 12px; padding-bottom: 8px; border-bottom: 1px solid #dcdcde; }
				.nv-bp-empty { text-align: center; padding: 40px; color: #646970; }
				.nv-bp-count { font-size: 14px; color: #8c8f94; margin-bottom: 20px; }
				</style>
				<?php
			}
		);

		$js_path = WP_MCP_AI_PRO_PATH . 'assets/js/unified-blueprints.js';
		$js_url  = WP_MCP_AI_PRO_URL . 'assets/js/unified-blueprints.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script( 'wp-mcp-ai-unified-blueprints', $js_url, array( 'jquery' ), WP_MCP_AI_PRO_VERSION, true );
		} else {
			// Inline fallback — allows the page to function without a separate JS file.
			wp_add_inline_script(
				'jquery',
				self::get_inline_js(),
				'after'
			);
		}

		wp_localize_script(
			'jquery',
			'wpMcpAiUnifiedBlueprints',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'editUrl' => admin_url( 'post.php?action=edit&post=0' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_unified_bp' ),
				'i18n'    => array(
					'installing'    => __( 'Installing...', 'mcp-ai-wpoos-pro' ),
					'installed'     => __( 'Installed!', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'Error installing blueprint.', 'mcp-ai-wpoos-pro' ),
					'duplicate'     => __( 'Blueprint already exists.', 'mcp-ai-wpoos-pro' ),
					'overwrite'     => __( 'Overwrite existing?', 'mcp-ai-wpoos-pro' ),
					'installLabel'  => __( 'Install Blueprint', 'mcp-ai-wpoos-pro' ),
					'viewDetails'   => __( 'View Details', 'mcp-ai-wpoos-pro' ),
					'hideDetails'   => __( 'Hide Details', 'mcp-ai-wpoos-pro' ),
					'loading'       => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
					'instructions'  => __( 'Instructions', 'mcp-ai-wpoos-pro' ),
					'tools'         => __( 'Tools', 'mcp-ai-wpoos-pro' ),
					'defaults'      => __( 'Defaults', 'mcp-ai-wpoos-pro' ),
					'model'         => __( 'Model', 'mcp-ai-wpoos-pro' ),
					'temperature'   => __( 'Temperature', 'mcp-ai-wpoos-pro' ),
					'maxTokens'     => __( 'Max Tokens', 'mcp-ai-wpoos-pro' ),
					'noDetails'     => __( 'No additional details available.', 'mcp-ai-wpoos-pro' ),
					'viewAssistant' => __( 'View Assistant', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the unified blueprints page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$toolkits  = self::get_all_blueprints_grouped();
		$installed = self::get_installed_blueprint_map();
		$total     = 0;

		foreach ( $toolkits as $bp_list ) {
			$total += count( $bp_list );
		}
		?>
		<div class="nv-bp-wrap">
			<div class="nv-bp-header">
				<h1>
					<span class="dashicons dashicons-layout" style="font-size: 28px; width: 28px; height: 28px; vertical-align: middle;"></span>
					<?php esc_html_e( 'Assistant Blueprints', 'mcp-ai-wpoos-pro' ); ?>
				</h1>
				<p class="description" style="max-width: 700px;">
					<?php esc_html_e( 'Pre-configured AI assistants for every professional toolkit. Each blueprint includes tailored instructions, tool sets, and model defaults. Click "Install" to create a ready-to-use assistant in seconds.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>

			<?php if ( empty( $toolkits ) ) : ?>
				<div class="nv-bp-empty">
					<span class="dashicons dashicons-cloud" style="font-size: 48px; color: #c3c4c7; display: block; margin-bottom: 12px;"></span>
					<p><?php esc_html_e( 'No blueprints found. Enable a toolkit to see its blueprints here.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php else : ?>
				<div class="nv-bp-filters">
					<select id="nv-bp-toolkit-filter">
						<option value=""><?php esc_html_e( 'All Toolkits', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $toolkits as $dir => $bp_list ) : ?>
							<option value="<?php echo esc_attr( $dir ); ?>">
								<?php echo esc_html( self::$toolkit_labels[ $dir ] ?? ucfirst( $dir ) ); ?>
								(<?php echo count( $bp_list ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<input type="search" id="nv-bp-search" placeholder="<?php esc_attr_e( 'Search blueprints...', 'mcp-ai-wpoos-pro' ); ?>" style="flex:1; max-width:300px;">
					<button type="button" class="button" id="nv-bp-clear-filters"><?php esc_html_e( 'Clear', 'mcp-ai-wpoos-pro' ); ?></button>
					<span class="nv-bp-count"><?php /* translators: %d: number of blueprints */ echo esc_html( sprintf( _n( '%d blueprint', '%d blueprints', $total, 'mcp-ai-wpoos-pro' ), $total ) ); ?></span>
				</div>

				<?php foreach ( $toolkits as $dir => $bp_list ) : ?>
					<div class="nv-bp-section" data-toolkit="<?php echo esc_attr( $dir ); ?>">
						<h2 class="nv-bp-section-title">
							<?php echo esc_html( self::$toolkit_labels[ $dir ] ?? ucfirst( $dir ) ); ?>
							<span style="font-weight:400; font-size:13px; color:#8c8f94;">(<?php echo count( $bp_list ); ?>)</span>
						</h2>
						<div class="nv-bp-grid">
							<?php foreach ( $bp_list as $slug => $bp ) : ?>
								<?php
								$is_installed = isset( $installed[ $bp['name'] ] );
								$tool_count   = 0;
								$meta         = $bp['meta'] ?? $bp['meta_input'] ?? array();
								$tools        = $meta['available_tools'] ?? $meta['_wp_mcp_ai_tools'] ?? array();
								if ( is_array( $tools ) ) {
									$tool_count = count( $tools );
								}
								?>
								<div class="nv-bp-card" data-blueprint="<?php echo esc_attr( $slug ); ?>" data-toolkit="<?php echo esc_attr( $dir ); ?>" data-name="<?php echo esc_attr( strtolower( $bp['name'] ) ); ?>" data-desc="<?php echo esc_attr( strtolower( $bp['description'] ?? '' ) ); ?>">
									<span class="nv-bp-toolkit-badge"><?php echo esc_html( self::$toolkit_labels[ $dir ] ?? ucfirst( $dir ) ); ?></span>
									<h3><?php echo esc_html( $bp['name'] ); ?></h3>
									<div class="nv-bp-desc"><?php echo esc_html( $bp['description'] ?? '' ); ?></div>
									<?php if ( $tool_count > 0 ) : ?>
										<div class="nv-bp-stats">
											<span class="dashicons dashicons-admin-tools" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span>
											<?php /* translators: %d: number of tools */ echo esc_html( sprintf( _n( '%d tool', '%d tools', $tool_count, 'mcp-ai-wpoos-pro' ), $tool_count ) ); ?>
										</div>
									<?php endif; ?>
									<div class="nv-bp-meta">
										<?php if ( $is_installed ) : ?>
											<span class="nv-bp-tag installed"><?php esc_html_e( 'Installed', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php endif; ?>
									</div>
									<div class="nv-bp-actions">
										<?php if ( $is_installed ) : ?>
											<a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . absint( $installed[ $bp['name'] ] ) ) ); ?>" class="button">
												<?php esc_html_e( 'View Assistant', 'mcp-ai-wpoos-pro' ); ?>
											</a>
										<?php else : ?>
											<button type="button" class="button button-primary nv-bp-install-btn" data-slug="<?php echo esc_attr( $slug ); ?>" data-toolkit="<?php echo esc_attr( $dir ); ?>">
												<?php esc_html_e( 'Install Blueprint', 'mcp-ai-wpoos-pro' ); ?>
											</button>
										<?php endif; ?>
										<button type="button" class="button nv-bp-view-btn" data-slug="<?php echo esc_attr( $slug ); ?>" data-toolkit="<?php echo esc_attr( $dir ); ?>">
											<?php esc_html_e( 'View Details', 'mcp-ai-wpoos-pro' ); ?>
										</button>
									</div>
									<div class="nv-bp-spinner"></div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get all blueprints grouped by toolkit directory.
	 *
	 * @return array<string,array>
	 */
	private static function get_all_blueprints_grouped() {
		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			return array();
		}

		$tools_dir    = WP_MCP_AI_PRO_PATH . 'includes/tools/';
		$toolkit_dirs = glob( $tools_dir . '*', GLOB_ONLYDIR );
		$result       = array();

		foreach ( $toolkit_dirs as $toolkit_path ) {
			$examples_dir = $toolkit_path . '/examples';
			if ( ! is_dir( $examples_dir ) ) {
				continue;
			}

			$dir_name = basename( $toolkit_path );
			$slugs    = WP_MCP_AI_Blueprint_Installer::list_blueprints( $examples_dir );

			foreach ( $slugs as $slug ) {
				$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( $examples_dir, $slug );
				if ( is_wp_error( $data ) ) {
					continue;
				}
				$result[ $dir_name ][ $slug ] = $data;
			}
		}

		return $result;
	}

	/**
	 * Build a map of installed blueprint name → assistant post ID.
	 *
	 * @return array<string,int>
	 */
	private static function get_installed_blueprint_map() {
		$posts = get_posts(
			array(
				'post_type'        => 'mcp_ai_assistant',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => '_blueprint_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => 'EXISTS',
			)
		);

			$map = array();
		foreach ( $posts as $post_id ) {
			$name = get_the_title( $post_id );
			if ( $name ) {
				$map[ $name ] = $post_id;
			}
		}

		return $map;
	}

	/**
	 * Resolve the examples directory for a given toolkit.
	 *
	 * @param string $toolkit_dir Toolkit directory name.
	 * @return string Absolute path to examples/.
	 */
	private static function resolve_examples_dir( $toolkit_dir ) {
		return WP_MCP_AI_PRO_PATH . 'includes/tools/' . basename( $toolkit_dir ) . '/examples';
	}

	// ─────────────────────────────────────────────
	// AJAX handlers
	// ─────────────────────────────────────────────

	/**
	 * AJAX: Install a blueprint.
	 */
	public static function ajax_install_blueprint() {
		check_ajax_referer( 'wp_mcp_ai_unified_bp', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$slug      = isset( $_POST['blueprint_slug'] ) ? sanitize_key( wp_unslash( $_POST['blueprint_slug'] ) ) : '';
		$toolkit   = isset( $_POST['toolkit'] ) ? sanitize_key( wp_unslash( $_POST['toolkit'] ) ) : '';
		$overwrite = ! empty( $_POST['overwrite'] );

		if ( empty( $slug ) || empty( $toolkit ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$examples_dir = self::resolve_examples_dir( $toolkit );

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			wp_send_json_error( array( 'message' => __( 'Installer not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( $examples_dir, $slug );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		$result = WP_MCP_AI_Blueprint_Installer::install( $data, $slug, $overwrite );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get blueprint details.
	 */
	public static function ajax_get_blueprint_details() {
		check_ajax_referer( 'wp_mcp_ai_unified_bp', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$slug    = isset( $_POST['blueprint_slug'] ) ? sanitize_key( wp_unslash( $_POST['blueprint_slug'] ) ) : '';
		$toolkit = isset( $_POST['toolkit'] ) ? sanitize_key( wp_unslash( $_POST['toolkit'] ) ) : '';

		if ( empty( $slug ) || empty( $toolkit ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$examples_dir = self::resolve_examples_dir( $toolkit );

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			wp_send_json_error( array( 'message' => __( 'Installer not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( $examples_dir, $slug );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		// Normalise across CRM-style and Healthcare-style schemas.
		$meta = $data['meta'] ?? $data['meta_input'] ?? array();

		$instructions = $meta['instructions']
			?? $meta['_wp_mcp_ai_system_prompt']
			?? $data['post_content']
			?? $data['instructions']
			?? '';

		$tools = $meta['available_tools']
			?? $meta['_wp_mcp_ai_tools']
			?? $data['tools']
			?? array();

		$defaults = array();
		if ( ! empty( $meta['_wp_mcp_ai_model'] ) ) {
			$defaults['model'] = $meta['_wp_mcp_ai_model'];
		}
		if ( isset( $meta['_wp_mcp_ai_temperature'] ) ) {
			$defaults['temperature'] = $meta['_wp_mcp_ai_temperature'];
		}
		if ( ! empty( $meta['_wp_mcp_ai_provider'] ) ) {
			$defaults['provider'] = $meta['_wp_mcp_ai_provider'];
		}
		if ( ! empty( $meta['profession'] ) ) {
			$defaults['profession'] = $meta['profession'];
		}
		if ( ! empty( $meta['framework'] ) ) {
			$defaults['framework'] = $meta['framework'];
		}
		if ( ! empty( $meta['channels'] ) ) {
			$defaults['channels'] = $meta['channels'];
		}

		$result = array(
			'name'         => $data['name'] ?? $data['post_title'] ?? '',
			'description'  => $data['description'] ?? $data['post_content'] ?? '',
			'instructions' => $instructions,
			'tools'        => is_array( $tools ) ? $tools : array(),
			'defaults'     => ! empty( $defaults ) ? $defaults : null,
		);

		wp_send_json_success( $result );
	}

	/**
	 * Inline JS fallback for the unified blueprints page.
	 *
	 * Used when the standalone JS file is not present.
	 *
	 * @return string
	 */
	private static function get_inline_js() {
		// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed -- Inline JS fallback; nowdoc avoids escaping issues.
		return <<<'JS'
(function($){'use strict';var cfg=window.wpMcpAiUnifiedBlueprints||{};if(!cfg.ajaxUrl)return;
function esc(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function nl2br(s){return esc(s).replace(/\n/g,'<br>');}
function install(slug,toolkit,$btn,$card){$btn.prop('disabled',true).text(cfg.i18n.installing);
$.post(cfg.ajaxUrl,{action:'wp_mcp_ai_unified_install_blueprint',blueprint_slug:slug,toolkit:toolkit,nonce:cfg.nonce})
.done(function(r){if(r.success){$btn.text(cfg.i18n.installed).removeClass('button-primary').addClass('button');
if(r.data&&r.data.assistant_id){$btn.replaceWith('<a href="'+cfg.editUrl.replace('0',r.data.assistant_id)+'" class="button">'+cfg.i18n.viewAssistant+'</a>');}
}else{var m=r.data&&r.data.message?r.data.message:cfg.i18n.error;
if(m.indexOf('already exists')!==-1||m.indexOf('duplicate')!==-1){if(window.confirm(cfg.i18n.overwrite)){
$.post(cfg.ajaxUrl,{action:'wp_mcp_ai_unified_install_blueprint',blueprint_slug:slug,toolkit:toolkit,overwrite:1,nonce:cfg.nonce})
.done(function(r2){if(r2.success){$btn.text(cfg.i18n.installed).removeClass('button-primary').addClass('button').prop('disabled',true);
}else{showErr($btn,m);}}).fail(function(){showErr($btn,cfg.i18n.error);});return;}}
showErr($btn,m);}}).fail(function(){showErr($btn,cfg.i18n.error);});}
function showErr($btn,msg){$btn.text(msg).prop('disabled',false);setTimeout(function(){$btn.text(cfg.i18n.installLabel);},3000);}
function toggleDetails(slug,toolkit,$btn){var $card=$btn.closest('.nv-bp-card'),$details=$card.find('.nv-bp-details');
if($details.length){$details.slideToggle(200);$btn.text($details.is(':visible')?cfg.i18n.hideDetails:cfg.i18n.viewDetails);return;}
$btn.prop('disabled',true).text(cfg.i18n.loading);
$.post(cfg.ajaxUrl,{action:'wp_mcp_ai_unified_get_blueprint_details',blueprint_slug:slug,toolkit:toolkit,nonce:cfg.nonce})
.done(function(r){var h='';if(r.success&&r.data){var bp=r.data;
h+='<div class="nv-bp-details" style="margin-top:16px;padding-top:16px;border-top:1px solid #c3c4c7;">';
if(bp.instructions){h+='<h4 style="margin:0 0 8px;">'+cfg.i18n.instructions+'</h4>';
h+='<div style="font-size:13px;line-height:1.6;color:#50575e;max-height:200px;overflow-y:auto;background:#f0f0f1;padding:12px;border-radius:4px;margin-bottom:12px;">'+nl2br(bp.instructions)+'</div>';}
if(bp.tools&&bp.tools.length){h+='<h4 style="margin:12px 0 4px;">'+cfg.i18n.tools+' ('+bp.tools.length+')</h4>';
h+='<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;">';
$.each(bp.tools,function(i,t){h+='<span class="nv-bp-tag" style="font-size:11px;">'+esc(t)+'</span>';});
h+='</div>';}
if(bp.defaults){h+='<h4 style="margin:12px 0 4px;">'+cfg.i18n.defaults+'</h4>';
h+='<div style="font-size:12px;color:#646970;">';
if(bp.defaults.provider){h+='Provider: <strong>'+esc(bp.defaults.provider)+'</strong><br>';}
if(bp.defaults.model){h+=cfg.i18n.model+': <strong>'+esc(bp.defaults.model)+'</strong><br>';}
if(bp.defaults.temperature!==undefined){h+=cfg.i18n.temperature+': <strong>'+esc(bp.defaults.temperature)+'</strong><br>';}
if(bp.defaults.profession){h+='Profession: <strong>'+esc(bp.defaults.profession)+'</strong><br>';}
if(bp.defaults.framework){h+='Framework: <strong>'+esc(bp.defaults.framework.toUpperCase())+'</strong><br>';}
if(bp.defaults.channels&&bp.defaults.channels.length){h+='Channels: ';
$.each(bp.defaults.channels,function(i,c){h+='<span class="nv-bp-tag" style="font-size:11px;">'+esc(c)+'</span> ';});
h+='<br>';}
h+='</div>';}
h+='</div>';}else{h+='<div class="nv-bp-details" style="margin-top:16px;padding-top:16px;border-top:1px solid #c3c4c7;">';
h+='<p style="color:#646970;">'+cfg.i18n.noDetails+'</p></div>';}
$card.append(h);$card.find('.nv-bp-details').slideDown(200);$btn.text(cfg.i18n.hideDetails);})
.fail(function(){$btn.text(cfg.i18n.viewDetails);}).always(function(){$btn.prop('disabled',false);});}
function filterCards(){var toolkit=$('#nv-bp-toolkit-filter').val(),search=$('#nv-bp-search').val().toLowerCase().trim();
$('.nv-bp-section').each(function(){var $sec=$(this),tk=$sec.data('toolkit'),visible=0;
var showSec=!toolkit||tk===toolkit;$sec.find('.nv-bp-card').each(function(){var $c=$(this),
name=$c.data('name')||'',desc=$c.data('desc')||'',
match=(!search||name.indexOf(search)!==-1||desc.indexOf(search)!==-1);
if(showSec&&match){$c.show();visible++;}else{$c.hide();}});
$sec.toggle(visible>0&&showSec);});}
$(document).on('click','.nv-bp-install-btn',function(){var $b=$(this);install($b.data('slug'),$b.data('toolkit'),$b,$b.closest('.nv-bp-card'));});
$(document).on('click','.nv-bp-view-btn',function(){var $b=$(this);toggleDetails($b.data('slug'),$b.data('toolkit'),$b);});
$('#nv-bp-toolkit-filter, #nv-bp-search').on('change input',filterCards);
$('#nv-bp-clear-filters').on('click',function(){$('#nv-bp-toolkit-filter').val('');$('#nv-bp-search').val('');filterCards();});
})(jQuery);
JS;
	}
}
